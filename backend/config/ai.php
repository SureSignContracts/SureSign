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
        'model'   => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),
        'base_url' => 'https://api.anthropic.com/v1',
        'timeout'  => 420, // HTTP read timeout. Must be < job timeout (480s) < queue retry_after (600s).
        // Output token ceiling. 8096, then 16000, were both too low and
        // truncated detailed contracts mid-JSON, producing unparseable
        // responses (AiFailureCategory::OUTPUT_TRUNCATED) — confirmed live
        // against a real production failure on a 51,065-char document,
        // which should have been nowhere near truncation risk. Root cause:
        // ANTHROPIC_MAX_TOKENS was never set in production, silently
        // falling back to this default, which hadn't been updated to match
        // the 128,000 ceiling this codebase's own history already
        // documented as the real, API-confirmed fix. Raised the default
        // itself (not just relying on every environment remembering to set
        // the env var) so this can't silently regress again.
        'max_tokens' => (int) env('ANTHROPIC_MAX_TOKENS', 128000),
        // Max characters of contract text sent to the model (~4 chars/token, so ~37k tokens).
        // Raised from 60k so full-length contracts are analysed rather than cut off.
        'max_input_chars' => (int) env('ANTHROPIC_MAX_INPUT_CHARS', 150000),
    ],
];
