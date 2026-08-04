<?php

namespace App\Support\Google;

/**
 * Google Integration Foundation, Stage 4A — the complete, deliberately
 * minimal set of OAuth scopes SureSign ever requests. A single scope,
 * `calendar.events`, covers everything this stage and Stage 4B need:
 * creating/updating/deleting events (Stage 4B) AND reading/listing events
 * on the primary calendar (this stage's "is the calendar actually
 * accessible" health/test-connection check) — there is no need for the
 * broader `calendar` or `calendar.readonly` scope on top of it.
 *
 * Least privilege is enforced here, not by convention: `GoogleOAuthService`
 * requests exactly this list, never anything wider, and
 * `GoogleHealthService` checks a connection's actually-granted scopes
 * (Google may grant a narrower set than requested) against this exact
 * list to determine PERMISSIONS_MISSING.
 */
final class GoogleScopes
{
    public const CALENDAR_EVENTS = 'https://www.googleapis.com/auth/calendar.events';

    public const REQUIRED = [self::CALENDAR_EVENTS];
}
