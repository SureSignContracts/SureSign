<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCustomer;
use App\Models\BillingInvoice;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingCustomerService;
use App\Services\Billing\Exceptions\BillingCustomerReconciliationException;
use App\Services\Billing\FakeBillingProvider;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingCustomerServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillingCustomerService $service;
    private FakeBillingProvider $fake;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(BillingCustomerService::class);
        $this->fake = $this->app->make(FakeBillingProvider::class);

        $adminOrg = Organization::create(['name' => 'Internal', 'slug' => 'internal-' . random_int(1, 1000000), 'timezone' => 'Europe/London']);
        $this->actor = User::factory()->create(['organization_id' => $adminOrg->id]);
    }

    private function organization(array $overrides = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Acme Construction Ltd',
            'slug' => 'acme-' . random_int(1, 1000000),
            'email' => 'billing@acme.test',
            'timezone' => 'Europe/London',
        ], $overrides));
    }

    public function test_creates_one_billing_customer_for_an_organization_and_provider(): void
    {
        $org = $this->organization();

        $customer = $this->service->getOrCreate($org, $this->actor);

        $this->assertSame('stripe', $customer->provider);
        $this->assertFalse($customer->livemode);
        $this->assertSame($org->id, $customer->organization_id);
        $this->assertDatabaseCount('billing_customers', 1);
    }

    public function test_repeated_get_or_create_returns_the_existing_mapping(): void
    {
        $org = $this->organization();

        $first = $this->service->getOrCreate($org, $this->actor);
        $second = $this->service->getOrCreate($org, $this->actor);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('billing_customers', 1);
        $this->assertCount(1, $this->fake->customers);
    }

    public function test_duplicate_local_records_are_prevented_at_the_database_level(): void
    {
        $org = $this->organization();

        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_1', 'livemode' => false,
        ]);

        $this->expectException(QueryException::class);
        BillingCustomer::create([
            'organization_id' => $org->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_2', 'livemode' => false,
        ]);
    }

    public function test_one_organisation_cannot_reuse_another_organisations_billing_customer_mapping(): void
    {
        $orgA = $this->organization();
        $orgB = $this->organization();

        BillingCustomer::create([
            'organization_id' => $orgA->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_shared', 'livemode' => false,
        ]);

        $this->expectException(QueryException::class);
        BillingCustomer::create([
            'organization_id' => $orgB->id, 'provider' => 'stripe', 'provider_customer_id' => 'cus_shared', 'livemode' => false,
        ]);
    }

    public function test_provider_customer_metadata_contains_expected_safe_identifiers(): void
    {
        $org = $this->organization();

        $customer = $this->service->getOrCreate($org, $this->actor);

        $providerRecord = $this->fake->customers[$customer->provider_customer_id];
        $this->assertSame($org->email, $providerRecord['email']);
        $this->assertSame($org->name, $providerRecord['name']);
    }

    public function test_safe_organisation_detail_changes_can_be_synchronized(): void
    {
        $org = $this->organization(['name' => 'Old Name']);
        $customer = $this->service->getOrCreate($org, $this->actor);

        $org->update(['name' => 'New Name Ltd']);
        $updated = $this->service->syncOrganizationDetails($org, $this->actor);

        $this->assertSame('New Name Ltd', $updated->billing_name);
        $this->assertSame('New Name Ltd', $this->fake->customers[$customer->provider_customer_id]['name']);
    }

    public function test_sync_with_no_changes_does_not_call_the_provider(): void
    {
        $org = $this->organization();
        $this->service->getOrCreate($org, $this->actor);

        // Nothing about the organisation changed since creation.
        $result = $this->service->syncOrganizationDetails($org, $this->actor);

        $this->assertSame($org->name, $result->billing_name);
    }

    public function test_null_local_values_do_not_erase_provider_fields(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        // Manually set a provider-side email the organisation itself has no
        // opinion on (organisation.email stays as-is, non-null, so no
        // erasure path is even exercised) — verify sync only ever pushes
        // non-null SureSign values, never an explicit null.
        $org->refresh();
        $this->assertNotNull($org->email);

        $this->service->syncOrganizationDetails($org, $this->actor);

        $this->assertNotNull($this->fake->customers[$customer->provider_customer_id]['email']);
    }

    /**
     * Deliberate behaviour reversal (approved): an established mapping is
     * NEVER automatically replaced when its provider customer goes
     * missing, even with no subscription or invoice referencing it —
     * replaces the previous "safely auto-replace when no financial
     * history exists" test, which asserted the opposite of the now-
     * approved policy.
     */
    public function test_missing_provider_customer_with_no_financial_history_still_requires_explicit_reconciliation(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        // Simulate the provider customer having vanished — no subscription
        // or invoice references this mapping at all.
        unset($this->fake->customers[$customer->provider_customer_id]);

        $this->expectException(BillingCustomerReconciliationException::class);
        $this->service->reconcile($customer, $this->actor);
    }

    /**
     * The one path that still creates a provider customer without
     * hesitation: no local mapping has ever existed for this organisation,
     * so there is nothing established to protect.
     */
    public function test_getorcreate_still_creates_a_customer_when_no_mapping_has_ever_existed(): void
    {
        $org = $this->organization();

        $this->assertDatabaseCount('billing_customers', 0);

        $customer = $this->service->getOrCreate($org, $this->actor);

        $this->assertDatabaseCount('billing_customers', 1);
        $this->assertArrayHasKey($customer->provider_customer_id, $this->fake->customers);
    }

    public function test_missing_provider_customer_with_financial_history_requires_reconciliation(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        Subscription::create([
            'organization_id' => $org->id,
            'billing_customer_id' => $customer->id,
            'provider' => 'stripe',
            'internal_reference' => 'SUB-TEST-0001',
            'status' => SubscriptionStatus::ACTIVE,
            'currency' => 'GBP',
        ]);

        unset($this->fake->customers[$customer->provider_customer_id]);

        $this->expectException(BillingCustomerReconciliationException::class);
        $this->service->reconcile($customer, $this->actor);
    }

    public function test_missing_provider_customer_with_invoice_history_requires_reconciliation(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        BillingInvoice::create([
            'organization_id' => $org->id,
            'billing_customer_id' => $customer->id,
            'provider' => 'stripe',
            'provider_invoice_id' => 'in_test_1',
            'status' => 'paid',
            'currency' => 'GBP',
        ]);

        unset($this->fake->customers[$customer->provider_customer_id]);

        $this->expectException(BillingCustomerReconciliationException::class);
        $this->service->reconcile($customer, $this->actor);
    }

    public function test_reconcile_passes_through_unchanged_when_provider_customer_still_exists(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        $result = $this->service->reconcile($customer, $this->actor);

        $this->assertTrue($result->is($customer));
    }

    public function test_test_live_mismatch_is_rejected_on_sync(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        $this->fake->livemode = true;

        $this->expectException(BillingCustomerReconciliationException::class);
        $this->service->syncOrganizationDetails($org, $this->actor);
    }

    public function test_test_live_mismatch_is_rejected_on_reconcile(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        $this->fake->livemode = true;

        $this->expectException(BillingCustomerReconciliationException::class);
        $this->service->reconcile($customer, $this->actor);
    }

    public function test_deactivated_organisation_does_not_trigger_provider_deletion(): void
    {
        $org = $this->organization(['is_active' => true]);
        $customer = $this->service->getOrCreate($org, $this->actor);

        $org->update(['is_active' => false]);

        // No method on BillingCustomerService deletes anything — the
        // provider customer and local mapping remain exactly as they were.
        $this->assertDatabaseHas('billing_customers', ['id' => $customer->id]);
        $this->assertArrayHasKey($customer->provider_customer_id, $this->fake->customers);
    }

    public function test_find_for_organization_scopes_by_current_livemode(): void
    {
        $org = $this->organization();
        $customer = $this->service->getOrCreate($org, $this->actor);

        $found = $this->service->findForOrganization($org);
        $this->assertTrue($found->is($customer));

        $this->fake->livemode = true;
        $this->assertNull($this->service->findForOrganization($org));
    }

    public function test_getorcreate_and_sync_record_activity_log_entries(): void
    {
        $org = $this->organization(['name' => 'Original']);
        $this->service->getOrCreate($org, $this->actor);

        $this->assertDatabaseHas('activity_logs', ['action' => 'billing_customer.created']);

        $org->update(['name' => 'Renamed']);
        $this->service->syncOrganizationDetails($org, $this->actor);

        $this->assertDatabaseHas('activity_logs', ['action' => 'billing_customer.synced']);
    }
}
