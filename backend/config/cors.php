<?php
return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // MARKETING_URL is separate from FRONTEND_URL because the marketing site
    // (suresigncontracts.app) only uses public marketing and appointment
    // endpoints. It never authenticates against the app API.
    'allowed_origins' => array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        env('MARKETING_URL'),
    ]),
    // Organisation URL Branding — the login gateway (marketing/) fetches
    // /public/organisation-branding/{host} directly from the browser on a
    // branded subdomain (e.g. https://acme.suresigncontracts.app), which is
    // a distinct Origin from MARKETING_URL/FRONTEND_URL above and would
    // otherwise be rejected by CORS. Derived from the same
    // organisation_branding.root_domain config the feature itself is gated
    // on — empty/off automatically when that isn't configured, matching
    // OrganisationUrlGenerator's own "fully off unless configured" default.
    'allowed_origins_patterns' => array_filter([
        config('organisation_branding.root_domain')
            ? '#^https://[a-z0-9-]+\.' . preg_quote(config('organisation_branding.root_domain'), '#') . '$#'
            : null,
    ]),
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
