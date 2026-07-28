<?php

namespace App\Support\Billing;

class PlanChangeType
{
    public const UPGRADE = 'upgrade';
    public const DOWNGRADE = 'downgrade';

    public const ALL = [self::UPGRADE, self::DOWNGRADE];

    public static function isValid(string $type): bool
    {
        return in_array($type, self::ALL, true);
    }
}
