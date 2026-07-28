<?php

namespace App\Support\Entitlements;

/**
 * Supported entitlement value types — Entitlement Specification v1,
 * Section 5. Deliberately does NOT include `unlimited` as a type: per
 * that section's refined (2026-07-23) representation, "unlimited" is a
 * separate `is_unlimited` boolean layered on top of whichever real type
 * applies (see `EntitlementValue`), never a value type or a magic-number
 * stand-in.
 */
class EntitlementValueType
{
    public const BOOLEAN = 'boolean';
    public const INTEGER = 'integer';
    public const DECIMAL = 'decimal';
    public const STRING = 'string';
    public const ENUM = 'enum';

    public const ALL = [
        self::BOOLEAN,
        self::INTEGER,
        self::DECIMAL,
        self::STRING,
        self::ENUM,
    ];

    public static function isValid(string $valueType): bool
    {
        return in_array($valueType, self::ALL, true);
    }
}
