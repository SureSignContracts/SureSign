<?php

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * The single conversion boundary between Pricing Management's decimal MAJOR
 * units (e.g. pricing_plans.monthly_price = "29.99", display-oriented) and
 * Billing's integer MINOR units (e.g. subscriptions.unit_amount = 2999,
 * provider-oriented — Stripe's API is minor-unit only). Every place money
 * crosses that boundary must call through here — never a manual `* 100` or
 * `/ 100` in a controller or service.
 *
 * Handles Stripe's zero-decimal currencies (no fractional unit at all, e.g.
 * JPY 1500 is already "1500 yen", not 15.00). Stripe's zero-decimal list:
 * https://stripe.com/docs/currencies#zero-decimal.
 */
class Money
{
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * Converts a decimal major-unit amount (e.g. "29.99", 29.99) into an
     * integer minor-unit amount (e.g. 2999) for the given ISO 4217 currency.
     */
    public static function toMinorUnits(string|int|float $majorUnits, string $currency): int
    {
        $currency = strtoupper($currency);
        $amount = (float) $majorUnits;

        if ($amount < 0) {
            throw new InvalidArgumentException('Amount must not be negative.');
        }

        if (self::isZeroDecimal($currency)) {
            return (int) round($amount);
        }

        // Round in integer cents rather than trusting float multiplication
        // directly (0.1 + 0.2 style drift) — bcmath-free, sufficient at 2dp.
        return (int) round($amount * 100);
    }

    /**
     * Converts an integer minor-unit amount (e.g. 2999) back into a decimal
     * major-unit string (e.g. "29.99") suitable for a decimal:2 cast field.
     */
    public static function toMajorUnits(int $minorUnits, string $currency): string
    {
        $currency = strtoupper($currency);

        if (self::isZeroDecimal($currency)) {
            return (string) $minorUnits;
        }

        return number_format($minorUnits / 100, 2, '.', '');
    }

    public static function isZeroDecimal(string $currency): bool
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true);
    }
}
