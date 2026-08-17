<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'companies_house' => [
        'api_key' => env('COMPANIES_HOUSE_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geoapify (Contract-Assisted Project Location, Phase 2)
    |--------------------------------------------------------------------------
    | Deterministic forward-geocoding provider for turning a confirmed
    | Contract's textual Project Location into map coordinates — see
    | App\Services\Geocoding\GeoapifyGeocodingProvider. Never called for AI
    | extraction/analysis; a completely separate, deterministic external
    | service. min_confidence is config-driven (not hardcoded) so the
    | acceptance threshold can be tuned without a code change if real-world
    | testing shows it's miscalibrated — see that provider's own docblock.
    */
    'geoapify' => [
        'api_key' => env('GEOAPIFY_API_KEY'),
        'base_url' => env('GEOAPIFY_BASE_URL', 'https://api.geoapify.com'),
        'min_confidence' => (float) env('GEOAPIFY_MIN_CONFIDENCE', 0.95),
    ],

];
