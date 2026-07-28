<?php

namespace App\Support\Entitlements;

use InvalidArgumentException;

/**
 * A single resolved entitlement value — the in-memory shape of what a
 * future `subscription_entitlements` row would persist (Entitlement
 * Specification v1, Section 8), returned by `FeatureGate` today without
 * that table existing yet (see this checkpoint's report on why
 * persistence was deferred).
 *
 * Enforces Section 5/6's typing discipline at construction time — this is
 * the ONE place an entitlement value is validated against its declared
 * type, so no two callers can ever interpret the same value differently.
 * `is_unlimited` is a separate flag layered on top of `valueType`, never
 * a value type of its own and never a magic number (Section 6) — `value`
 * is required to be `null` whenever `isUnlimited` is `true`.
 */
final class EntitlementValue
{
    private function __construct(
        public readonly string $key,
        public readonly string $valueType,
        public readonly bool|int|float|string|null $value,
        public readonly bool $isUnlimited,
        public readonly string $source,
        public readonly ?string $unit = null,
        public readonly bool $isNegotiatedOverride = false,
    ) {
    }

    public static function make(
        string $key,
        string $valueType,
        bool|int|float|string|null $value,
        bool $isUnlimited,
        string $source,
        ?string $unit = null,
        bool $isNegotiatedOverride = false,
    ): self {
        if (!EntitlementValueType::isValid($valueType)) {
            throw new InvalidArgumentException("Invalid entitlement value_type: \"{$valueType}\".");
        }

        if (!EntitlementSource::isValid($source)) {
            throw new InvalidArgumentException("Invalid entitlement source: \"{$source}\".");
        }

        if ($isUnlimited && $value !== null) {
            throw new InvalidArgumentException(
                "Entitlement \"{$key}\" is marked unlimited but also carries a finite value — "
                . 'is_unlimited=true must always pair with value=null (Section 6).'
            );
        }

        if (!$isUnlimited && $value !== null) {
            self::assertValueMatchesType($key, $valueType, $value);
        }

        return new self($key, $valueType, $value, $isUnlimited, $source, $unit, $isNegotiatedOverride);
    }

    /**
     * A boolean feature flag that is simply off/not included — distinct
     * from `notApplicable()` below (a key that doesn't apply at all, e.g.
     * `accounting_exports` before the feature exists).
     */
    public static function notIncluded(string $key, string $source): self
    {
        return self::make($key, Feature::valueType($key), false, false, $source);
    }

    /**
     * Section 5's `null`/`not_applicable`: this entitlement key does not
     * apply to this subscription at all (e.g. a reserved dormant key, or
     * a feature that doesn't exist yet) — distinct from `false`, which
     * means "applies, and is explicitly not included."
     */
    public static function notApplicable(string $key): self
    {
        return self::make($key, Feature::valueType($key), null, false, EntitlementSource::NONE);
    }

    public static function unlimited(string $key, string $source, ?string $unit = null): self
    {
        return self::make($key, Feature::valueType($key), null, true, $source, $unit);
    }

    private static function assertValueMatchesType(string $key, string $valueType, bool|int|float|string $value): void
    {
        $matches = match ($valueType) {
            EntitlementValueType::BOOLEAN => is_bool($value),
            EntitlementValueType::INTEGER => is_int($value),
            EntitlementValueType::DECIMAL => is_float($value) || is_int($value),
            EntitlementValueType::STRING, EntitlementValueType::ENUM => is_string($value),
            default => false,
        };

        if (!$matches) {
            $actualType = get_debug_type($value);

            throw new InvalidArgumentException(
                "Entitlement \"{$key}\" declares value_type \"{$valueType}\" but received a {$actualType} value."
            );
        }
    }

    public function asBoolean(): bool
    {
        if ($this->valueType !== EntitlementValueType::BOOLEAN) {
            throw new InvalidArgumentException("Entitlement \"{$this->key}\" is not a boolean feature flag.");
        }

        return (bool) $this->value;
    }
}
