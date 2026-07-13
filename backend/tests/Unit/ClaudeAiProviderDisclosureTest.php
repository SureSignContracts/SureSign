<?php

namespace Tests\Unit;

use App\Services\AI\ClaudeAiProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the M7 fix in ClaudeAiProvider — the root of the AI error_message
 * disclosure: a failed Anthropic call used to throw a RuntimeException whose
 * message embedded the raw provider response body and named the provider
 * ("Claude API error (...): <raw body>"). That message was then persisted
 * verbatim to ContractAiAnalysis/TradePackageAiAnalysis.error_message — a
 * column returned as-is by GET .../ai-analysis — so the raw body reached the
 * client. This test forces the failure via Http::fake() (no real network
 * call) and asserts the exception message is generic and provider-neutral,
 * while the raw detail is still logged server-side.
 */
class ClaudeAiProviderDisclosureTest extends TestCase
{
    public function test_failed_provider_response_does_not_leak_raw_body_or_provider_name(): void
    {
        Log::spy();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['message' => 'internal account balance exhausted for key sk-secret-xyz'],
            ], 500),
        ]);

        $provider = new ClaudeAiProvider('fake-api-key', 'claude-sonnet-5');

        try {
            $provider->complete('system prompt', 'user prompt');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('The AI request could not be completed. Please try again later.', $e->getMessage());
            $this->assertStringNotContainsString('sk-secret-xyz', $e->getMessage());
            $this->assertStringNotContainsString('balance exhausted', $e->getMessage());
            $this->assertStringNotContainsString('Claude', $e->getMessage());
            $this->assertStringNotContainsString('Anthropic', $e->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) =>
                $message === 'AI provider request failed'
                && ($context['status'] ?? null) === 500
                && str_contains(json_encode($context['body'] ?? []), 'balance exhausted')
            )
            ->once();
    }

    public function test_missing_api_key_message_does_not_name_the_provider(): void
    {
        $provider = new ClaudeAiProvider('', 'claude-sonnet-5');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI analysis is not configured. Please contact your administrator.');

        $provider->complete('system prompt', 'user prompt');
    }

    public function test_unexpected_response_shape_message_does_not_name_the_provider(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['unexpected' => 'shape'], 200),
        ]);

        $provider = new ClaudeAiProvider('fake-api-key', 'claude-sonnet-5');

        try {
            $provider->complete('system prompt', 'user prompt');
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('The AI service returned an unexpected response.', $e->getMessage());
            $this->assertStringNotContainsString('Claude', $e->getMessage());
        }
    }
}
