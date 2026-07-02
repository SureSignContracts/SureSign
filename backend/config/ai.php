<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Feature Flag
    |--------------------------------------------------------------------------
    | Master switch. The database setting (suresign_settings.ai_enabled) takes
    | precedence when the platform is running, but this env var can be used to
    | hard-disable AI regardless of the database state (e.g. in CI).
    */
    'enabled' => env('AI_FEATURE_ENABLED', false),

    'provider' => env('AI_PROVIDER', 'anthropic'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-4-6'),
        'base_url' => 'https://api.anthropic.com/v1',
        'timeout'  => 420, // HTTP read timeout. Must be < job timeout (480s) < queue retry_after (600s).
        // Output token ceiling. 8096 was too low and truncated detailed contracts mid-JSON,
        // producing unparseable responses. Raised to give headroom for full analyses.
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 16000),
        // Max characters of contract text sent to the model (~4 chars/token, so ~37k tokens).
        // Raised from 60k so full-length contracts are analysed rather than cut off.
        'max_input_chars' => (int) env('ANTHROPIC_MAX_INPUT_CHARS', 150000),
    ],
];
