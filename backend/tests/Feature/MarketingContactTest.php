<?php

namespace Tests\Feature;

use App\Models\SuresignSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingContactTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Alex Morgan',
            'company' => 'Northfield Construction Ltd',
            'email' => 'alex@northfield.example',
            'phone' => '020 7946 0123',
            'subject' => 'Contract administration rollout',
            'message' => 'We would like to discuss implementation across our live projects.',
            'website' => '',
        ], $overrides);
    }

    public function test_contact_enquiry_is_validated_and_sent_to_marketing_inbox(): void
    {
        config(['mail.marketing_contact_to' => 'tech@suresigncontracts.com']);
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key',
            'email_sender_email' => 'noreply@suresigncontracts.com',
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);

        $this->postJson('/api/marketing-contact', $this->payload())
            ->assertCreated()
            ->assertJsonPath('message', 'Enquiry received.');

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $payload['to'][0]['email'] === 'tech@suresigncontracts.com'
                && $payload['replyTo']['email'] === 'alex@northfield.example'
                && $payload['subject'] === 'New Marketing Contact Enquiry'
                && str_contains($payload['htmlContent'], 'Northfield Construction Ltd')
                && str_contains($payload['htmlContent'], 'Contract administration rollout')
                && str_contains($payload['htmlContent'], 'Submitted:');
        });
    }

    public function test_contact_enquiry_rejects_invalid_input(): void
    {
        Http::fake();

        $this->postJson('/api/marketing-contact', [
            'name' => '',
            'company' => '',
            'email' => 'not-an-email',
            'subject' => '',
            'message' => 'Short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'company', 'email', 'subject', 'message']);

        Http::assertNothingSent();
    }

    public function test_contact_honeypot_is_silently_accepted_without_sending_email(): void
    {
        Http::fake();

        $this->postJson('/api/marketing-contact', $this->payload(['website' => 'https://spam.example']))
            ->assertStatus(202)
            ->assertJsonPath('message', 'Enquiry received.');

        Http::assertNothingSent();
    }

    public function test_contact_returns_a_safe_failure_when_delivery_is_unavailable(): void
    {
        SuresignSetting::instance()->update(['brevo_api_key' => null]);

        $this->postJson('/api/marketing-contact', $this->payload())
            ->assertStatus(503)
            ->assertJsonPath(
                'message',
                'We could not send your enquiry right now. Please email tech@suresigncontracts.com.',
            );
    }
}
