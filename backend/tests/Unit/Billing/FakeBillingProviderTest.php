<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\Exceptions\InvalidWebhookSignatureException;
use App\Services\Billing\FakeBillingProvider;
use Tests\TestCase;

class FakeBillingProviderTest extends TestCase
{
    public function test_creates_and_retrieves_a_customer(): void
    {
        $provider = new FakeBillingProvider();

        $customer = $provider->createCustomer(['email' => 'a@example.com', 'name' => 'Acme Ltd']);

        $this->assertNotEmpty($customer['id']);
        $this->assertSame('a@example.com', $customer['email']);
        $this->assertSame($customer, $provider->retrieveCustomer($customer['id']));
    }

    public function test_retrieving_an_unknown_customer_returns_null(): void
    {
        $provider = new FakeBillingProvider();
        $this->assertNull($provider->retrieveCustomer('cus_does_not_exist'));
    }

    public function test_updates_a_customer_field_without_touching_others(): void
    {
        $provider = new FakeBillingProvider();
        $customer = $provider->createCustomer(['email' => 'a@example.com', 'name' => 'Acme Ltd']);

        $updated = $provider->updateCustomer($customer['id'], ['name' => 'Acme Construction Ltd']);

        $this->assertSame('Acme Construction Ltd', $updated['name']);
        $this->assertSame('a@example.com', $updated['email']);
    }

    public function test_updating_an_unknown_customer_throws(): void
    {
        $provider = new FakeBillingProvider();

        $this->expectException(\RuntimeException::class);
        $provider->updateCustomer('cus_does_not_exist', ['name' => 'x']);
    }

    public function test_isLivemode_defaults_false_and_is_stamped_onto_created_objects(): void
    {
        $provider = new FakeBillingProvider();
        $this->assertFalse($provider->isLivemode());

        $customer = $provider->createCustomer(['email' => 'a@example.com']);
        $this->assertFalse($customer['livemode']);

        $provider->livemode = true;
        $this->assertTrue($provider->isLivemode());

        $liveCustomer = $provider->createCustomer(['email' => 'b@example.com']);
        $this->assertTrue($liveCustomer['livemode']);
    }

    public function test_creates_and_retrieves_a_product(): void
    {
        $provider = new FakeBillingProvider();

        $product = $provider->createProduct(['name' => 'Professional']);

        $this->assertNotEmpty($product['id']);
        $this->assertSame('Professional', $product['name']);
        $this->assertSame($product, $provider->retrieveProduct($product['id']));
    }

    public function test_creates_and_retrieves_a_price(): void
    {
        $provider = new FakeBillingProvider();
        $product = $provider->createProduct(['name' => 'Professional']);

        $price = $provider->createPrice([
            'product_id' => $product['id'],
            'unit_amount' => 2999,
            'currency' => 'gbp',
            'recurring_interval' => 'month',
            'idempotency_key' => 'test-key-1',
        ]);

        $this->assertNotEmpty($price['id']);
        $this->assertSame(2999, $price['unit_amount']);
        $this->assertTrue($price['active']);
        $this->assertSame($price, $provider->retrievePrice($price['id']));
    }

    public function test_deactivate_price_marks_it_inactive_without_deleting_it(): void
    {
        $provider = new FakeBillingProvider();
        $product = $provider->createProduct(['name' => 'Professional']);
        $price = $provider->createPrice([
            'product_id' => $product['id'],
            'unit_amount' => 2999,
            'currency' => 'gbp',
            'recurring_interval' => 'month',
            'idempotency_key' => 'test-key-2',
        ]);

        $result = $provider->deactivatePrice($price['id']);

        $this->assertFalse($result['active']);
        $this->assertNotNull($provider->retrievePrice($price['id']));
        $this->assertFalse($provider->retrievePrice($price['id'])['active']);
    }

    public function test_deactivating_an_unknown_price_throws(): void
    {
        $provider = new FakeBillingProvider();

        $this->expectException(\RuntimeException::class);
        $provider->deactivatePrice('price_does_not_exist');
    }

    public function test_creates_and_retrieves_a_checkout_session(): void
    {
        $provider = new FakeBillingProvider();
        $customer = $provider->createCustomer(['email' => 'a@example.com']);

        $session = $provider->createCheckoutSession([
            'customer_id' => $customer['id'],
            'price_id' => 'price_fake_1',
            'quantity' => 1,
            'success_url' => 'https://app.test/success',
            'cancel_url' => 'https://app.test/cancel',
            'idempotency_key' => 'checkout:1:1:abc',
        ]);

        $this->assertSame('open', $session['status']);
        $this->assertStringContainsString($session['id'], $session['url']);
        $this->assertSame($session, $provider->retrieveCheckoutSession($session['id']));
    }

    public function test_creates_a_portal_session_url(): void
    {
        $provider = new FakeBillingProvider();
        $result = $provider->createPortalSession('cus_fake_1', 'https://app.test/billing');

        $this->assertStringContainsString('cus_fake_1', $result['url']);
    }

    public function test_cancel_subscription_at_period_end_keeps_status(): void
    {
        $provider = new FakeBillingProvider();
        $provider->seedSubscription('sub_fake_1', ['status' => 'active', 'cancel_at_period_end' => false]);

        $result = $provider->cancelSubscription('sub_fake_1', atPeriodEnd: true);

        $this->assertSame('active', $result['status']);
        $this->assertTrue($result['cancel_at_period_end']);
    }

    public function test_cancel_subscription_immediately_marks_canceled(): void
    {
        $provider = new FakeBillingProvider();
        $provider->seedSubscription('sub_fake_1', ['status' => 'active', 'cancel_at_period_end' => false]);

        $result = $provider->cancelSubscription('sub_fake_1', atPeriodEnd: false);

        $this->assertSame('canceled', $result['status']);
    }

    public function test_cancelling_an_unknown_subscription_throws(): void
    {
        $provider = new FakeBillingProvider();

        $this->expectException(\RuntimeException::class);
        $provider->cancelSubscription('sub_does_not_exist');
    }

    public function test_verify_webhook_signature_accepts_the_matching_fake_signature(): void
    {
        $provider = new FakeBillingProvider();
        $payload = json_encode(['id' => 'evt_1', 'type' => 'checkout.session.completed']);

        $event = $provider->verifyWebhookSignature($payload, 'valid:whsec_test', 'whsec_test');

        $this->assertSame('evt_1', $event['id']);
    }

    public function test_verify_webhook_signature_rejects_a_mismatched_signature(): void
    {
        $provider = new FakeBillingProvider();
        $payload = json_encode(['id' => 'evt_1']);

        $this->expectException(InvalidWebhookSignatureException::class);
        $provider->verifyWebhookSignature($payload, 'valid:wrong_secret', 'whsec_test');
    }

    public function test_verify_webhook_signature_rejects_non_json_payload(): void
    {
        $provider = new FakeBillingProvider();

        $this->expectException(InvalidWebhookSignatureException::class);
        $provider->verifyWebhookSignature('not json', 'valid:whsec_test', 'whsec_test');
    }
}
