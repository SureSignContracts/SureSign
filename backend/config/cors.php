<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // MARKETING_URL is separate from FRONTEND_URL because the marketing site
    // (suresigncontracts.app) only needs CORS for the public /demo-requests
    // endpoint — it never authenticates against the app API otherwise.
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('MARKETING_URL'),
    ]),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
