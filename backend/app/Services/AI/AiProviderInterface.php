<?php

namespace App\Services\AI;

interface AiProviderInterface
{
    /**
     * Send a prompt and return the response with usage metadata.
     *
     * Returns: ['text' => string, 'tokens_input' => int, 'tokens_output' => int, 'stop_reason' => ?string]
     *
     * @throws \RuntimeException on API error or invalid response
     */
    public function complete(string $systemPrompt, string $userPrompt): array;
}
