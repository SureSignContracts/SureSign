<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\TimezoneResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneResolverTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgSeq = 0;

    private function makeOrg(string $timezone = 'Europe/London'): Organization
    {
        $n = ++static::$orgSeq;

        return Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => $timezone]);
    }

    // ── sanitize() ──────────────────────────────────────────────────────────

    public function test_sanitize_accepts_valid_iana_identifiers(): void
    {
        $this->assertSame('Europe/London', TimezoneResolver::sanitize('Europe/London'));
        $this->assertSame('Asia/Manila', TimezoneResolver::sanitize('Asia/Manila'));
        $this->assertSame('America/New_York', TimezoneResolver::sanitize('America/New_York'));
        $this->assertSame('Australia/Sydney', TimezoneResolver::sanitize('Australia/Sydney'));
    }

    public function test_sanitize_rejects_raw_offsets_and_falls_back_to_utc(): void
    {
        $this->assertSame('UTC', TimezoneResolver::sanitize('UTC+8'));
        $this->assertSame('UTC', TimezoneResolver::sanitize('GMT+1'));
        $this->assertSame('UTC', TimezoneResolver::sanitize('+08:00'));
        $this->assertSame('UTC', TimezoneResolver::sanitize('not-a-timezone'));
    }

    public function test_sanitize_treats_null_and_empty_as_utc(): void
    {
        $this->assertSame('UTC', TimezoneResolver::sanitize(null));
        $this->assertSame('UTC', TimezoneResolver::sanitize(''));
    }

    // ── platformTimezone() ──────────────────────────────────────────────────

    public function test_platform_timezone_defaults_to_europe_london(): void
    {
        $this->assertSame('Europe/London', TimezoneResolver::platformTimezone());
    }

    public function test_platform_timezone_falls_back_to_utc_when_setting_is_invalid(): void
    {
        SuresignSetting::instance()->update(['timezone' => 'UTC+8']);

        $this->assertSame('UTC', TimezoneResolver::platformTimezone());
    }

    // ── Full inheritance chain: platform → organisation → user ─────────────

    public function test_chain_falls_through_to_platform_default_with_no_user_or_org(): void
    {
        SuresignSetting::instance()->update(['timezone' => 'Australia/Sydney']);

        $this->assertSame('Australia/Sydney', TimezoneResolver::effectiveTimezone());
    }

    public function test_chain_uses_organisation_timezone_when_user_has_no_override(): void
    {
        $org  = $this->makeOrg('Asia/Manila');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => null]);

        $this->assertSame('Asia/Manila', TimezoneResolver::effectiveTimezone($user, $org));
    }

    public function test_chain_uses_user_override_when_present(): void
    {
        $org  = $this->makeOrg('Asia/Manila');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => 'America/New_York']);

        $this->assertSame('America/New_York', TimezoneResolver::effectiveTimezone($user, $org));
    }

    public function test_chain_resolves_organisation_via_the_users_relationship_when_not_passed_explicitly(): void
    {
        $org  = $this->makeOrg('Australia/Sydney');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => null]);

        // No $organization argument — must fall back to $user->organization.
        $this->assertSame('Australia/Sydney', TimezoneResolver::effectiveTimezone($user));
    }

    public function test_chain_falls_through_to_platform_default_for_a_user_with_no_organisation(): void
    {
        SuresignSetting::instance()->update(['timezone' => 'America/New_York']);
        $user = User::factory()->create(['organization_id' => null, 'timezone' => null]);

        $this->assertSame('America/New_York', TimezoneResolver::effectiveTimezone($user));
    }

    public function test_invalid_stored_user_override_falls_back_rather_than_propagating_garbage(): void
    {
        $org  = $this->makeOrg('Asia/Manila');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => 'UTC+8']);

        // sanitize() is applied to the user override itself, not just the
        // final result — an invalid override must not silently win over a
        // valid organisation timezone by accident of resolution order.
        $this->assertSame('UTC', TimezoneResolver::userTimezone($user));
        $this->assertSame('UTC', TimezoneResolver::effectiveTimezone($user, $org));
    }

    // ── Immediate effect of an organisation timezone change ────────────────

    public function test_changing_organisation_timezone_immediately_affects_users_who_inherit_it(): void
    {
        $org             = $this->makeOrg('Europe/London');
        $inheritingUser  = User::factory()->create(['organization_id' => $org->id, 'timezone' => null]);
        $overrideUser    = User::factory()->create(['organization_id' => $org->id, 'timezone' => 'Asia/Manila']);

        $this->assertSame('Europe/London', TimezoneResolver::effectiveTimezone($inheritingUser, $org));
        $this->assertSame('Asia/Manila', TimezoneResolver::effectiveTimezone($overrideUser, $org));

        $org->update(['timezone' => 'America/New_York']);

        // Re-fetch fresh instances to simulate a brand new request resolving
        // against the now-updated organisation — TimezoneResolver has no
        // caching layer of its own, so this must reflect the change
        // immediately, with no re-save/re-login/cache-bust required.
        $freshOrg            = Organization::find($org->id);
        $freshInheritingUser = User::find($inheritingUser->id);
        $freshOverrideUser   = User::find($overrideUser->id);

        $this->assertSame(
            'America/New_York',
            TimezoneResolver::effectiveTimezone($freshInheritingUser, $freshOrg),
            'A user with no override must immediately pick up the organisation\'s new timezone.'
        );

        $this->assertSame(
            'Asia/Manila',
            TimezoneResolver::effectiveTimezone($freshOverrideUser, $freshOrg),
            'A user with an explicit override must be unaffected by an organisation timezone change.'
        );
    }

    public function test_changing_organisation_timezone_immediately_affects_users_resolved_via_relationship(): void
    {
        $org  = $this->makeOrg('Europe/London');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => null]);

        $this->assertSame('Europe/London', TimezoneResolver::effectiveTimezone($user));

        $org->update(['timezone' => 'Australia/Sydney']);

        // Resolve again through the user's own organisation() relationship
        // (no $organization argument), via a fresh model instance.
        $this->assertSame('Australia/Sydney', TimezoneResolver::effectiveTimezone(User::find($user->id)));
    }

    // ── Batch 3, Part 5: inheritance chain edge cases (deletion) ────────────

    public function test_user_override_survives_organisation_deletion(): void
    {
        SuresignSetting::instance()->update(['timezone' => 'Europe/London']);
        $org  = $this->makeOrg('America/New_York');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => 'Asia/Manila']);

        $this->assertSame('Asia/Manila', TimezoneResolver::effectiveTimezone($user, $org));

        $org->delete(); // Organization uses SoftDeletes — this is the real-world delete path.

        // A soft-deleted organisation no longer resolves via the belongsTo
        // relation (SoftDeletes' global scope excludes it), so this also
        // exercises the "$organization argument omitted" path, not just the
        // explicit-argument one.
        $freshUser = User::find($user->id);
        $this->assertNull($freshUser->organization, 'Sanity check: the relation should not resolve a soft-deleted organisation.');

        $this->assertSame(
            'Asia/Manila',
            TimezoneResolver::effectiveTimezone($freshUser),
            'A user with an explicit override must keep using it even after their organisation is deleted.'
        );
    }

    public function test_falls_back_to_platform_timezone_when_user_has_no_override_and_organisation_is_deleted(): void
    {
        SuresignSetting::instance()->update(['timezone' => 'Europe/London']);
        $org  = $this->makeOrg('America/New_York');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => null]);

        $org->delete();

        $this->assertSame(
            'Europe/London',
            TimezoneResolver::effectiveTimezone(User::find($user->id)),
            'A user with no override and a deleted organisation must fall back to the platform default.'
        );
    }

    // ── Batch 3, Part 1: now() / today() / startOfDay() / endOfDay() / utcNow() ──

    public function test_now_returns_the_current_instant_carrying_the_effective_timezone(): void
    {
        $org  = $this->makeOrg('Asia/Manila');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => null]);

        $result = TimezoneResolver::now($user, $org);

        $this->assertSame('Asia/Manila', $result->getTimezone()->getName());
        // Same absolute instant as Carbon::now() — only the attached
        // timezone differs, not the underlying moment.
        $this->assertEqualsWithDelta(Carbon::now()->getTimestamp(), $result->getTimestamp(), 2);
    }

    public function test_today_returns_midnight_in_the_effective_timezone(): void
    {
        $org  = $this->makeOrg('Australia/Sydney');
        $user = User::factory()->create(['organization_id' => $org->id, 'timezone' => null]);

        $result = TimezoneResolver::today($user, $org);

        $this->assertSame('Australia/Sydney', $result->getTimezone()->getName());
        $this->assertSame(0, $result->hour);
        $this->assertSame(0, $result->minute);
        $this->assertSame(0, $result->second);
    }

    public function test_utc_now_always_carries_the_utc_timezone_regardless_of_effective_timezone(): void
    {
        $this->assertSame('UTC', TimezoneResolver::utcNow()->getTimezone()->getName());
    }

    public function test_start_of_day_and_end_of_day_bracket_a_given_moment_in_the_effective_timezone(): void
    {
        $org     = $this->makeOrg('America/New_York');
        $moment  = Carbon::parse('2026-07-16 15:00:00', 'UTC');

        $start = TimezoneResolver::startOfDay(null, $org, $moment);
        $end   = TimezoneResolver::endOfDay(null, $org, $moment);

        $this->assertSame('America/New_York', $start->getTimezone()->getName());
        $this->assertSame(0, $start->hour);
        $this->assertSame(23, $end->hour);
        $this->assertTrue($start->lte($moment));
        $this->assertTrue($end->gte($moment));
        // Carbon's comparisons operate on the absolute instant, not the
        // attached timezone's wall-clock — this is what makes it safe to
        // compare a startOfDay()/endOfDay() result directly against a
        // UTC-stored value without any manual re-conversion.
        $this->assertTrue($start->lt($end));
    }

    public function test_start_of_day_crosses_the_calendar_boundary_differently_per_timezone(): void
    {
        // 23:30 UTC is already the next calendar day in Manila (UTC+8), but
        // still the same day in New York (UTC-4 in July, EDT) — the exact
        // "midnight boundary differs by timezone" risk the audit flagged.
        $moment = Carbon::parse('2026-07-16 23:30:00', 'UTC');

        $manilaOrg = $this->makeOrg('Asia/Manila');
        $nyOrg     = $this->makeOrg('America/New_York');

        $manilaStart = TimezoneResolver::startOfDay(null, $manilaOrg, $moment);
        $nyStart     = TimezoneResolver::startOfDay(null, $nyOrg, $moment);

        // toDateString() reads the LOCAL calendar day of each result (using
        // its own attached timezone) — this is the actual business-relevant
        // question ("what day is it for this org"), not the UTC date of the
        // underlying instant.
        $this->assertSame('2026-07-17', $manilaStart->toDateString());
        $this->assertSame('2026-07-16', $nyStart->toDateString());

        // Cross-check via the underlying UTC instant: Manila midnight for
        // the 17th is 16:00 UTC on the 16th; New York midnight for the
        // 16th is 04:00 UTC on the 16th (EDT, UTC-4 in July).
        $this->assertSame('2026-07-16 16:00:00', $manilaStart->copy()->setTimezone('UTC')->toDateTimeString());
        $this->assertSame('2026-07-16 04:00:00', $nyStart->copy()->setTimezone('UTC')->toDateTimeString());
    }
}
