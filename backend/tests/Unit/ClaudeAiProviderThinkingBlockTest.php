<?php

namespace Tests\Unit;

use App\Services\AI\ClaudeAiProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * With output_config.effort set (this pipeline always sets one), Claude may
 * prepend a 'thinking' content block ahead of the actual 'text' block for a
 * sufficiently complex/long request — confirmed live against the real API
 * (2026-07-26) while investigating a real analysis failure that reported
 * "The AI service returned an unexpected response." despite the request
 * genuinely succeeding. The bug: ClaudeAiProvider::complete() read
 * content[0]['text'] unconditionally, which is null/missing whenever a
 * thinking block occupies that position.
 */
class ClaudeAiProviderThinkingBlockTest extends TestCase
{
    public function test_finds_the_text_block_when_a_thinking_block_precedes_it(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'internal reasoning...', 'signature' => 'abc'],
                    ['type' => 'text', 'text' => '{"contract_summary":"ok"}'],
                ],
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 50],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new ClaudeAiProvider('fake-api-key', 'claude-sonnet-5');
        $result = $provider->complete('system prompt', 'user prompt');

        $this->assertSame('{"contract_summary":"ok"}', $result['text']);
        $this->assertSame(100, $result['tokens_input']);
        $this->assertSame(50, $result['tokens_output']);
    }

    public function test_finds_the_text_block_when_it_is_the_only_block(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content'     => [['type' => 'text', 'text' => 'plain response']],
                'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
                'stop_reason' => 'end_turn',
            ], 200),
        ]);

        $provider = new ClaudeAiProvider('fake-api-key', 'claude-sonnet-5');
        $result = $provider->complete('system prompt', 'user prompt');

        $this->assertSame('plain response', $result['text']);
    }
}
