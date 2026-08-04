<?php

namespace App\Support\Organizations;

/**
 * Organisation URL Branding, Phase 2 — the lifecycle of a customer-owned
 * domain (`App\Models\OrganizationDomain`). Mirrors the shape of
 * `App\Support\Billing\SubscriptionSource`/`AiCreditOperatingMode` — a
 * small, closed vocabulary, never a free-text status column.
 *
 *   PENDING        → row just created, verification token generated, no
 *                     DNS check attempted yet.
 *   AWAITING_DNS   → at least one verification attempt made, DNS record(s)
 *                     not yet found/matching.
 *   VERIFIED       → DNS ownership (TXT) and routing (CNAME) both confirmed
 *                     — NOT yet serving traffic. SSL/cutover is a manual
 *                     Super Admin step (this phase builds no automatic SSL
 *                     provisioning — see DomainVerificationService's own
 *                     docblock), moved to ACTIVE only once an operator
 *                     confirms the origin is genuinely ready.
 *   ACTIVE         → the domain is live and eligible for URL generation
 *                     (OrganisationUrlGenerator's top priority).
 *   DISABLED       → temporarily taken out of URL generation without
 *                     losing verification state (e.g. a customer reports an
 *                     issue) — re-activatable without re-verifying.
 *   FAILED         → a verification attempt ran and did NOT find the
 *                     expected DNS records — distinct from AWAITING_DNS
 *                     (which just means "not checked yet / still pending");
 *                     FAILED means a real check ran and came back negative.
 *   REMOVED        → terminal; the hostname stays recorded (never deleted)
 *                     so it can never be silently reused by a different
 *                     organisation, mirroring OrganizationUrlSlugHistory's
 *                     own reasoning.
 */
class DomainStatus
{
    public const PENDING = 'pending';
    public const AWAITING_DNS = 'awaiting_dns';
    public const VERIFIED = 'verified';
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';
    public const FAILED = 'failed';
    public const REMOVED = 'removed';

    public const ALL = [
        self::PENDING,
        self::AWAITING_DNS,
        self::VERIFIED,
        self::ACTIVE,
        self::DISABLED,
        self::FAILED,
        self::REMOVED,
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }
}
