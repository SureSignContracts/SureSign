<?php

namespace Tests\Unit;

use App\Services\AI\AiPricingSchedule;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase G4C.1A.1 — effective-dated AI provider pricing. Rate selection must
 * key off the provider-call timestamp passed in, never the current date —
 * these tests specifically exercise the 31 August / 1 September 2026
 * boundary and prove a fixed historical instant always resolves to the same
 * rate regardless of when the test itself runs.
 */
class AiPricingScheduleTest extends TestCase
{
    public function test_resolves_introductory_rate_on_the_last_day_of_its_window(): void
    {
        $schedule = new AiPricingSchedule();

        $rate = $schedule->rateFor('claude-sonnet-5', Carbon::parse('2026-08-31 23:59:59'));

        $this->assertSame(['input_per_million' => 2.0, 'output_per_million' => 10.0], $rate);
    }

    public function test_resolves_standard_rate_on_the_first_day_of_its_window(): void
    {
        $schedule = new AiPricingSchedule();

        $rate = $schedule->rateFor('claude-sonnet-5', Carbon::parse('2026-09-01 00:00:00'));

        $this->assertSame(['input_per_million' => 3.0, 'output_per_million' => 15.0], $rate);
    }

    public function test_resolves_introductory_rate_at_start_of_its_window(): void
    {
        $schedule = new AiPricingSchedule();

        $rate = $schedule->rateFor('claude-sonnet-5', Carbon::parse('2026-01-01 00:00:00'));

        $this->assertSame(['input_per_million' => 2.0, 'output_per_million' => 10.0], $rate);
    }

    public function test_a_fixed_historical_instant_always_resolves_the_same_regardless_of_wall_clock_time(): void
    {
        $schedule = new AiPricingSchedule();

        // The exact real 110-page contract analysis timestamp — must always
        // resolve to the introductory rate, even if this test runs in 2027.
        $historicalInstant = Carbon::parse('2026-07-26 14:53:53');

        $rate = $schedule->rateFor('claude-sonnet-5', $historicalInstant);

        $this->assertSame(['input_per_million' => 2.0, 'output_per_million' => 10.0], $rate);
    }

    public function test_returns_null_for_an_unconfigured_model_rather_than_guessing(): void
    {
        $schedule = new AiPricingSchedule();

        $this->assertNull($schedule->rateFor('some-future-model-v9', Carbon::now()));
    }

    public function test_returns_null_for_an_instant_before_any_configured_period(): void
    {
        $schedule = new AiPricingSchedule();

        $this->assertNull($schedule->rateFor('claude-sonnet-5', Carbon::parse('2020-01-01')));
    }
}
