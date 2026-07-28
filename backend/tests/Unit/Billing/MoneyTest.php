<?php

namespace Tests\Unit\Billing;

use App\Support\Billing\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    public function test_converts_major_units_to_minor_units_for_two_decimal_currency(): void
    {
        $this->assertSame(2999, Money::toMinorUnits('29.99', 'GBP'));
        $this->assertSame(2999, Money::toMinorUnits(29.99, 'usd'));
        $this->assertSame(0, Money::toMinorUnits('0', 'GBP'));
        $this->assertSame(10, Money::toMinorUnits('0.10', 'GBP'));
    }

    public function test_converts_minor_units_back_to_major_units_string(): void
    {
        $this->assertSame('29.99', Money::toMajorUnits(2999, 'GBP'));
        $this->assertSame('0.10', Money::toMajorUnits(10, 'GBP'));
        $this->assertSame('100.00', Money::toMajorUnits(10000, 'EUR'));
    }

    public function test_zero_decimal_currency_is_not_multiplied(): void
    {
        // JPY has no minor unit at all — 1500 yen is stored/sent as 1500, not 150000.
        $this->assertSame(1500, Money::toMinorUnits('1500', 'JPY'));
        $this->assertSame('1500', Money::toMajorUnits(1500, 'JPY'));
        $this->assertTrue(Money::isZeroDecimal('JPY'));
        $this->assertTrue(Money::isZeroDecimal('krw'));
        $this->assertFalse(Money::isZeroDecimal('GBP'));
    }

    public function test_rejects_negative_amounts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::toMinorUnits('-5.00', 'GBP');
    }

    public function test_round_trip_is_stable_across_many_values(): void
    {
        foreach (['0.01', '9.99', '149.00', '1999.95'] as $amount) {
            $minor = Money::toMinorUnits($amount, 'GBP');
            $this->assertSame($amount, Money::toMajorUnits($minor, 'GBP'));
        }
    }
}
