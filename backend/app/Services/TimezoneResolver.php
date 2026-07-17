<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use Carbon\Carbon;

/**
 * The single authoritative source for "what timezone applies here."
 *
 * Resolution order:
 *   1. User timezone       (App\Models\User::timezone      — added in Batch 2)
 *   2. Organisation timezone (App\Models\Organization::timezone — added in Batch 2)
 *   3. Platform default    (SuresignSetting::instance()->timezone)
 *   4. UTC                 (hard fallback — always valid)
 *
 * Storage/runtime architecture is UTC everywhere (Docker, PHP, Laravel,
 * MySQL, queues, scheduler — see Batch 1). This resolver exists so business
 * logic can answer "what does 'today' mean for this user/org" without ever
 * touching PHP's own runtime timezone, which must stay UTC.
 *
 * `organizations.timezone` (required) and `users.timezone` (nullable
 * override) exist and are read by this resolver (Batch 2). `now()`,
 * `today()`, `startOfDay()`, `endOfDay()`, and `utcNow()` (Batch 3) turn
 * that resolved timezone into ready-to-use Carbon instances.
 *
 * No business logic has been migrated to call any of this yet (Batch 4) —
 * as of this batch, nothing in the codebase calls TimezoneResolver from a
 * controller/service/command's actual date/time computations. It exists so
 * Batch 4 has one place to call instead of `now()`/`Carbon::today()`
 * directly, not because anything depends on it yet.
 */
class TimezoneResolver
{
    public const UTC = 'UTC';

    /**
     * Resolve the effective IANA timezone identifier for a given user/org,
     * following the user → organisation → platform → UTC hierarchy.
     *
     * Both arguments are optional so callers that only have one side of the
     * relationship (e.g. a queued job with just an organisation_id) can
     * still resolve a sensible timezone.
     */
    public static function effectiveTimezone(?User $user = null, ?Organization $organization = null): string
    {
        $candidate = static::userTimezone($user)
            ?? static::organisationTimezone($organization ?? $user?->organization)
            ?? static::platformTimezone();

        return static::sanitize($candidate);
    }

    /**
     * The current instant, as a Carbon instance carrying the effective
     * timezone — i.e. what wall-clock "now" reads for this user/org. The
     * underlying instant is identical to `Carbon::now()`; only the
     * timezone attached to it differs, which is what makes ->format(),
     * ->hour, etc. reflect local time instead of UTC.
     */
    public static function now(?User $user = null, ?Organization $organization = null): Carbon
    {
        return Carbon::now(static::effectiveTimezone($user, $organization));
    }

    /**
     * Today's calendar date (midnight) in the effective timezone. This is
     * the org/user-aware replacement for `Carbon::today()` — use it
     * anywhere business logic needs "what day is it right now for this
     * user/org," not the server's own UTC day.
     */
    public static function today(?User $user = null, ?Organization $organization = null): Carbon
    {
        return Carbon::today(static::effectiveTimezone($user, $organization));
    }

    /**
     * Midnight, in the effective timezone, of the given moment (defaults to
     * now). Pass a UTC instant in (e.g. a stored `datetime` column) to find
     * out which *local* day it falls on — Carbon's comparison/diff methods
     * operate on the underlying absolute instant regardless of which
     * timezone is attached, so the returned value is safe to compare
     * directly against other Carbon instances without any manual
     * conversion back to UTC.
     */
    public static function startOfDay(?User $user = null, ?Organization $organization = null, ?Carbon $moment = null): Carbon
    {
        return ($moment ?? Carbon::now())->copy()
            ->setTimezone(static::effectiveTimezone($user, $organization))
            ->startOfDay();
    }

    /**
     * The last instant of the given moment's calendar day (defaults to
     * now), in the effective timezone. Symmetric with startOfDay().
     */
    public static function endOfDay(?User $user = null, ?Organization $organization = null, ?Carbon $moment = null): Carbon
    {
        return ($moment ?? Carbon::now())->copy()
            ->setTimezone(static::effectiveTimezone($user, $organization))
            ->endOfDay();
    }

    /**
     * Explicit, unambiguous "now, in UTC" — for storage writes (audit
     * timestamps, queue timestamps, etc.) where the caller wants to make
     * the UTC-only storage rule visible at the call site, rather than
     * relying on `now()` being UTC only because `config('app.timezone')`
     * happens to be UTC. Behaviourally identical to `now()`/`Carbon::now()`
     * today — exists for intent, not for a different value.
     */
    public static function utcNow(): Carbon
    {
        return Carbon::now(self::UTC);
    }

    /**
     * Build the real UTC instant for a local wall-clock date + time in a
     * given IANA timezone (e.g. scheduling a meeting: "2026-11-01", "01:30",
     * "America/New_York") — introduced for Batch 6 (timed meetings).
     *
     * Rejects local times that don't exist because of a DST "spring
     * forward" gap (e.g. 02:30 on a US clock-change day) by construction —
     * Carbon/PHP's own default behaviour is to silently roll a nonexistent
     * time forward by the gap instead of erroring, which would otherwise
     * store an instant an hour later than what was actually requested with
     * no indication anything was wrong.
     *
     * Deliberately does NOT special-case ambiguous "fall back" times (e.g.
     * 01:30 occurring twice when clocks go back) — Carbon's native
     * resolution (the first/earlier occurrence, i.e. the offset in effect
     * immediately before the transition) is used as-is and documented here
     * as the policy, rather than adding extra logic to override it.
     *
     * $timezone is assumed already validated (e.g. via Laravel's `timezone`
     * validation rule at the controller boundary) — this does not sanitize
     * or fall back to UTC on an invalid identifier, unlike the rest of this
     * class, since callers here want a hard failure surfaced, not a silent
     * substitution.
     *
     * Returns the instant already converted to UTC — Eloquent's `datetime`
     * cast setter does NOT convert a Carbon instance's offset on assignment,
     * it just stores whatever wall-clock numbers the instance carries and
     * re-labels them UTC on the next read. Handing back an already-UTC
     * Carbon here means every caller gets a value that's safe to assign
     * directly to a model attribute, rather than each call site needing to
     * remember `->setTimezone('UTC')` itself.
     *
     * @throws \InvalidArgumentException if the local date/time does not exist in $timezone
     */
    public static function buildLocalInstant(string $date, string $time, string $timezone): Carbon
    {
        $requested = "{$date} {$time}";
        $instant   = Carbon::createFromFormat('Y-m-d H:i', $requested, $timezone);

        if ($instant->format('Y-m-d H:i') !== $requested) {
            throw new \InvalidArgumentException(
                "The time {$time} on {$date} does not exist in {$timezone} — likely a daylight saving time transition."
            );
        }

        return $instant->setTimezone(self::UTC);
    }

    /**
     * The user's own timezone override, if set. Null means "no override —
     * fall through to the organisation timezone."
     */
    public static function userTimezone(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $value = $user->timezone ?? null;

        return $value ? static::sanitize($value) : null;
    }

    /**
     * The organisation's configured timezone. Organisation timezone is
     * required at the data layer, but this stays defensive so it degrades
     * to the platform default rather than erroring against a detached or
     * null organisation (e.g. a Super Admin/Admin account, which belongs to
     * no single organisation).
     */
    public static function organisationTimezone(?Organization $organization): ?string
    {
        if (! $organization) {
            return null;
        }

        $value = $organization->timezone ?? null;

        return $value ? static::sanitize($value) : null;
    }

    /**
     * Platform-wide default timezone, configured in admin settings.
     * This is the last stop before the hard UTC fallback.
     */
    public static function platformTimezone(): string
    {
        $value = SuresignSetting::instance()->timezone ?? null;

        return $value ? static::sanitize($value) : self::UTC;
    }

    /**
     * Validate a candidate timezone identifier, rejecting anything that
     * isn't a real IANA identifier (no "UTC+8", "GMT+1", numeric offsets,
     * etc.) — falls back to UTC for anything invalid rather than throwing,
     * since this is called from read paths that must never 500.
     */
    public static function sanitize(?string $timezone): string
    {
        if (! $timezone) {
            return self::UTC;
        }

        return in_array($timezone, \DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : self::UTC;
    }
}
