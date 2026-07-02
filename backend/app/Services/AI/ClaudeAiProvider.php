<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClaudeAiProvider implements AiProviderInterface
{
    public function __construct(
        private string $apiKey,
        private string $model,
    ) {}

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        $response = Http::withHeaders([
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])
            ->timeout(config('ai.anthropic.timeout', 300))
            ->post(config('ai.anthropic.base_url', 'https://api.anthropic.com/v1') . '/messages', [
                'model'      => $this->model,
                'max_tokens' => (int) config('ai.anthropic.max_tokens', 16000),
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->failed()) {
            $body = $response->json();
            $msg  = $body['error']['message'] ?? $response->body();
            throw new RuntimeException("Claude API error ({$response->status()}): {$msg}");
        }

        $body = $response->json();

        $text = $body['content'][0]['text']
            ?? throw new RuntimeException('Unexpected Claude API response format.');

        return [
            'text'          => $text,
            'tokens_input'  => (int) ($body['usage']['input_tokens']  ?? 0),
            'tokens_output' => (int) ($body['usage']['output_tokens'] ?? 0),
            // 'end_turn' = complete; 'max_tokens' = response was truncated by the token cap.
            'stop_reason'   => $body['stop_reason'] ?? null,
        ];
    }
}
