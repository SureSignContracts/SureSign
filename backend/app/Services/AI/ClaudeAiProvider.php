<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ClaudeAiProvider implements AiProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $effort = 'high',
    ) {}

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('AI analysis is not configured. Please contact your administrator.');
        }

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout(config('ai.anthropic.timeout', 300))
            ->post(config('ai.anthropic.base_url', 'https://api.anthropic.com/v1') . '/messages', [
                'model'         => $this->model,
                'max_tokens'    => (int) config('ai.anthropic.max_tokens', 16000),
                'system'        => $systemPrompt,
                'output_config' => ['effort' => $this->effort],
                'messages'      => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            // Full provider status/body is only ever logged server-side — the
            // exception message below is what ends up persisted to
            // ContractAiAnalysis/TradePackageAiAnalysis.error_message and
            // shown to the user, so it must never carry the raw provider
            // response, and must not name the underlying AI provider.
            Log::error('AI provider request failed', [
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
            ]);
            throw new RuntimeException('The AI request could not be completed. Please try again later.');
        }

        $body = $response->json();

        // Content blocks are NOT always [text] — with output_config.effort set (this
        // pipeline always sets one), Claude may prepend a 'thinking' block ahead of
        // the actual 'text' block for a sufficiently complex/long request. Blindly
        // reading content[0] silently broke on any response that included one,
        // wrongly reporting a real, complete answer as "unexpected response".
        $textBlock = collect($body['content'] ?? [])->firstWhere('type', 'text');

        $text = (is_array($textBlock) ? $textBlock['text'] ?? null : null)
            ?? throw new RuntimeException('The AI service returned an unexpected response.');

        return [
            'text'          => $text,
            'tokens_input'  => (int) ($body['usage']['input_tokens']  ?? 0),
            'tokens_output' => (int) ($body['usage']['output_tokens'] ?? 0),
            // 'end_turn' = complete; 'max_tokens' = response was truncated by the token cap.
            'stop_reason'   => $body['stop_reason'] ?? null,
        ];
    }
}
