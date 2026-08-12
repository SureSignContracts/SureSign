<?php

namespace Tests\Feature;

use App\Models\SuresignSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The public, unauthenticated "Contact your administrator" page's enquiry
 * form — mirrors MarketingContactTest's coverage shape, since
 * AccountAccessEnquiryController/SendAccountAccessEnquiryService
 * deliberately mirror MarketingContactController/
 * SendMarketingContactEnquiryService's own architecture.
 */
class AccountAccessEnquiryTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alex Morgan',
            'email' => 'alex@northfield.example',
            'message' => 'I was invited to SureSign a week ago but never received the email.',
            'website' => '',
        ], $overrides);
    }

    public function test_enquiry_is_validated_and_sent_to_the_configured_support_email(): void
    {
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.com',
            'support_email' => 'tech@suresigncontracts.com',
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);

        $this->postJson('/api/account-access-enquiry', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Enquiry received.');

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $payload['to'][0]['email'] === 'tech@suresigncontracts.com'
                && $payload['replyTo']['email'] === 'alex@northfield.example'
                && $payload['subject'] === 'SureSign account access enquiry'
                && str_contains($payload['htmlContent'], 'Alex Morgan')
                && str_contains($payload['htmlContent'], 'never received the email')
                && str_contains($payload['htmlContent'], 'Submitted:');
        });
    }

    public function test_enquiry_falls_back_to_the_default_support_address_when_unconfigured(): void
    {
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.com',
            'support_email' => null,
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);

        $this->postJson('/api/account-access-enquiry', $this->payload())->assertCreated();

        Http::assertSent(fn (Request $request): bool => $request->data()['to'][0]['email'] === 'tech@suresigncontracts.com');
    }

    public function test_enquiry_does_not_require_a_name(): void
    {
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.com',
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);

        $this->postJson('/api/account-access-enquiry', $this->payload(['name' => null]))
            ->assertCreated();

        Http::assertSent(fn (Request $request): bool => str_contains($request->data()['htmlContent'], 'Not provided'));
    }

    public function test_enquiry_rejects_invalid_input(): void
    {
        Http::fake();

        $this->postJson('/api/account-access-enquiry', [
            'email' => 'not-an-email',
            'message' => 'Short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'message']);

        Http::assertNothingSent();
    }

    public function test_honeypot_is_silently_accepted_without_sending_email(): void
    {
        Http::fake();

        $this->postJson('/api/account-access-enquiry', $this->payload(['website' => 'https://spam.example']))
            ->assertStatus(202)
            ->assertJsonPath('message', 'Enquiry received.');

        Http::assertNothingSent();
    }

    public function test_returns_a_safe_failure_when_delivery_is_unavailable(): void
    {
        SuresignSetting::instance()->update(['brevo_api_key' => null]);

        $this->postJson('/api/account-access-enquiry', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'We could not send your enquiry right now. Please try again shortly.',
            );
    }
}
