<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Billing Enabled / Enforcement Flags
    |--------------------------------------------------------------------------
    |
    | 'enabled' gates whether any billing feature (checkout, portal, webhook
    | processing) is reachable at all. 'enforcement_enabled' is a separate,
    | later switch that gates whether an organisation can actually be
    | blocked from the platform for a lapsed subscription — see
    | App\Support\Billing\SubscriptionAccessPolicy (Phase 5+). Both default
    | false and must stay false until a deliberate go-live decision; see
    | internal-docs/super-admin/subscription-billing.md's live-mode
    | readiness checklist.
    */
    'enabled' => (bool) env('BILLING_ENABLED', false),
    'enforcement_enabled' => (bool) env('BILLING_ENFORCEMENT_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | Only 'stripe' is supported — see App\Support\Billing\BillingProviders.
    | Kept configurable (rather than hardcoded) purely so
    | BillingProviderManager has one place to read it from, matching how
    | every other provider-backed service in this codebase (AI, email) is
    | configured.
    */
    'provider' => env('BILLING_PROVIDER', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    |
    | Deliberately env-only — never stored in suresign_settings like the
    | Anthropic/Brevo provider keys are. Stripe secret/webhook keys are a
    | direct financial-exposure risk, not an AI-cost risk, so they stay an
    | infrastructure-level concern that a boot-time guard
    | (App\Support\Billing\BillingConfigGuard) can validate, rather than a
    | Super Admin-editable database value.
    */
    'stripe' => [
        'key' => env('STRIPE_KEY', ''),
        'secret' => env('STRIPE_SECRET', ''),

        // Separate test/live webhook signing secrets — Stripe issues a
        // DIFFERENT signing secret per webhook endpoint, and a test-mode
        // endpoint and a live-mode endpoint are always registered
        // separately in the Stripe Dashboard, each with its own secret.
        // Never the Stripe API secret key (`secret` above) — a webhook
        // signing secret and an API key are different credentials with
        // different purposes. See App\Services\Billing\WebhookIngestionService,
        // which selects exactly ONE of these two based on the
        // application's own currently-configured mode
        // (BillingProviderInterface::isLivemode()) — never both, never
        // inferred from the incoming payload before verification.
        'webhook_secret_test' => env('STRIPE_WEBHOOK_SECRET_TEST', ''),
        'webhook_secret_live' => env('STRIPE_WEBHOOK_SECRET_LIVE', ''),

        // Left null (unpinned) unless explicitly set — Stripe defaults to
        // the account's current API version when omitted.
        'api_version' => env('STRIPE_API_VERSION') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    */
    'default_currency' => env('BILLING_DEFAULT_CURRENCY', 'GBP'),

    /*
    |--------------------------------------------------------------------------
    | Checkout / Portal URLs
    |--------------------------------------------------------------------------
    |
    | Validated against App\Rules\SafeUrl wherever a request is allowed to
    | influence them — these config defaults are the operator-trusted
    | fallback, not user input.
    */
    'checkout_success_url' => env('BILLING_CHECKOUT_SUCCESS_URL', ''),
    'checkout_cancel_url' => env('BILLING_CHECKOUT_CANCEL_URL', ''),
    'portal_return_url' => env('BILLING_PORTAL_RETURN_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Trial & Grace Period
    |--------------------------------------------------------------------------
    */
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 0),
    'grace_period_days' => (int) env('BILLING_GRACE_PERIOD_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Entitlement Snapshot Support Boundary
    |--------------------------------------------------------------------------
    |
    | The moment immutable entitlement snapshots (Subscription Commercial
    | State Automation checkpoint) became available in this codebase — a
    | single, honest, global boundary, NOT a fabricated per-row timestamp.
    | App\Services\Entitlements\SnapshotIntegrityClassifier uses this to
    | distinguish a subscription that genuinely predates snapshot support
    | (`starts_at` earlier than this — the documented live-PlanEntitlements
    | compatibility fallback remains correct) from one that started AFTER
    | support existed but is still missing a snapshot (a genuine data
    | inconsistency, never silently treated the same as the legacy case).
    | See internal-docs/super-admin/subscription-billing.md for the full
    | reasoning behind using one fixed boundary instead of stamping
    | historical rows.
    */
    'entitlement_snapshot_introduced_at' => env('BILLING_ENTITLEMENT_SNAPSHOT_INTRODUCED_AT', '2026-07-23 00:00:00'),

    /*
    |--------------------------------------------------------------------------
    | Live-Mode Protection
    |--------------------------------------------------------------------------
    |
    | In local/testing environments, App\Support\Billing\BillingConfigGuard
    | refuses to boot with a live-looking Stripe key (sk_live_.../
    | pk_live_...) unless this is explicitly true. Automated tests must
    | never make a real Stripe API request regardless of this flag — the
    | test suite binds App\Services\Billing\FakeBillingProvider instead of
    | ever constructing a real Stripe client.
    */
    'allow_live_keys_in_testing' => (bool) env('BILLING_ALLOW_LIVE_KEYS_IN_TESTING', false),

];
