<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Google Integration Foundation (Stage 4A)
    |--------------------------------------------------------------------------
    |
    | Deliberately env-only, mirroring config/billing.php's own Stripe
    | credential convention — OAuth client secrets are a direct
    | credential-exposure risk, not a Super-Admin-editable business
    | setting. `redirect_uri` must point to a FRONTEND page (matching the
    | existing consultancy.checkout_success_url/billing.checkout_success_url
    | convention exactly) — Google redirects the bare browser here, and
    | that page makes an authenticated API call
    | (POST /admin/google/oauth/callback) with the returned code/state,
    | rather than Google ever hitting an unauthenticated backend route
    | directly. See App\Services\Google\GoogleOAuthService.
    */
    'client_id'     => env('GOOGLE_OAUTH_CLIENT_ID', ''),
    'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET', ''),
    'redirect_uri'  => env('GOOGLE_OAUTH_REDIRECT_URI', ''),

];
