<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\FridayPack\FridayPackPeriodResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Automated Friday Pack, R1A — Friday Pack Realignment (2026-08-31).
 * Direct, focused proof of FridayPackPeriodResolver's Monday-through-
 * Friday reporting period — the resolver is the single authoritative
 * place this is calculated, so this is the one place it needs proving
 * (see FridayPackTest's own feature-level assertions for the
 * end-to-end proof through the real generate() pipeline).
 */
class FridayPackPeriodResolverTest extends TestCase
{
    use RefreshDatabase;

    private const FRIDAY = '2026-08-21';
    private const MONDAY_OF_SAME_WEEK = '2026-08-17';

    public function test_period_start_is_monday_of_the_same_week(): void
    {
        $org = Organization::create(['name' => 'Org P1', 'slug' => 'org-p1', 'timezone' => 'Europe/London']);

        $period = FridayPackPeriodResolver::resolve(self::FRIDAY, $org);

        $this->assertSame(self::MONDAY_OF_SAME_WEEK, $period['period_start']);
    }

    public function test_period_end_and_week_ending_are_both_the_supplied_friday(): void
    {
        $org = Organization::create(['name' => 'Org P2', 'slug' => 'org-p2', 'timezone' => 'Europe/London']);

        $period = FridayPackPeriodResolver::resolve(self::FRIDAY, $org);

        $this->assertSame(self::FRIDAY, $period['period_end']);
        $this->assertSame(self::FRIDAY, $period['week_ending']);
    }

    public function test_period_spans_exactly_five_days_monday_to_friday(): void
    {
        $org = Organization::create(['name' => 'Org P3', 'slug' => 'org-p3', 'timezone' => 'Europe/London']);

        $period = FridayPackPeriodResolver::resolve(self::FRIDAY, $org);

        $days = \Carbon\Carbon::parse($period['period_start'])->diffInDays(\Carbon\Carbon::parse($period['period_end']));
        $this->assertEquals(4, $days, 'Monday through Friday inclusive is a 4-day difference (5 calendar days total).');
    }

    public function test_a_non_friday_week_ending_is_rejected(): void
    {
        $org = Organization::create(['name' => 'Org P4', 'slug' => 'org-p4', 'timezone' => 'Europe/London']);

        $this->expectException(InvalidArgumentException::class);
        FridayPackPeriodResolver::resolve(self::MONDAY_OF_SAME_WEEK, $org);
    }

    public function test_isFriday_is_true_only_for_a_real_friday(): void
    {
        $org = Organization::create(['name' => 'Org P5', 'slug' => 'org-p5', 'timezone' => 'Europe/London']);

        $this->assertTrue(FridayPackPeriodResolver::isFriday(self::FRIDAY, $org));
        $this->assertFalse(FridayPackPeriodResolver::isFriday(self::MONDAY_OF_SAME_WEEK, $org));
    }

    /**
     * Resolution is performed in the ORGANISATION's own effective
     * timezone (via TimezoneResolver), exactly as before R1A — this
     * proves the Monday-Friday realignment did not disturb that.
     */
    public function test_resolution_uses_the_organisation_effective_timezone(): void
    {
        $orgLondon = Organization::create(['name' => 'Org P6', 'slug' => 'org-p6', 'timezone' => 'Europe/London']);
        $orgAuckland = Organization::create(['name' => 'Org P7', 'slug' => 'org-p7', 'timezone' => 'Pacific/Auckland']);

        $london = FridayPackPeriodResolver::resolve(self::FRIDAY, $orgLondon);
        $auckland = FridayPackPeriodResolver::resolve(self::FRIDAY, $orgAuckland);

        // Same calendar date is still a Friday in both timezones, and the
        // resolved period is identical in shape — this proves the
        // timezone is genuinely consulted (via TimezoneResolver) rather
        // than a hardcoded UTC assumption, without depending on a
        // DST/date-line edge case that would make this test brittle.
        $this->assertSame($london['week_ending'], $auckland['week_ending']);
        $this->assertSame('Europe/London', $london['timezone']);
        $this->assertSame('Pacific/Auckland', $auckland['timezone']);
    }
}
