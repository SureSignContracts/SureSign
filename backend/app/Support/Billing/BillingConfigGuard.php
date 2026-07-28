<?php

namespace App\Support\Billing;

use Illuminate\Foundation\Application;

/**
 * Boot-time safety check, in the same spirit as
 * DB::prohibitDestructiveCommands() in AppServiceProvider::boot() — that
 * one stops a destructive artisan command from ever running in production;
 * this one stops a live Stripe key from ever being active in local/testing.
 *
 * A live-looking secret/publishable key (sk_live_.../pk_live_...) present
 * while app()->environment(['local', 'testing']) is true means either a
 * misconfigured .env or a genuine mistake about to make a real charge — both
 * should hard-fail immediately rather than silently proceeding, unless the
 * override (billing.allow_live_keys_in_testing) is deliberately set.
 */
class BillingConfigGuard
{
    public static function assertSafe(Application $app): void
    {
        if (!$app->environment(['local', 'testing'])) {
            return;
        }

        if (config('billing.allow_live_keys_in_testing')) {
            return;
        }

        $secret = (string) config('billing.stripe.secret', '');
        $key = (string) config('billing.stripe.key', '');

        if (self::looksLive($secret) || self::looksLive($key)) {
            throw new \RuntimeException(
                'Refusing to boot: a live-mode Stripe key (sk_live_/pk_live_) is configured '
                . 'in a local/testing environment. Use Stripe test-mode keys (sk_test_/pk_test_) here, '
                . 'or set BILLING_ALLOW_LIVE_KEYS_IN_TESTING=true if this is deliberate.'
            );
        }
    }

    public static function looksLive(string $value): bool
    {
        return str_starts_with($value, 'sk_live_') || str_starts_with($value, 'pk_live_');
    }
}
