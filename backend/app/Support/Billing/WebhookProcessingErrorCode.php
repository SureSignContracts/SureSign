<?php

namespace App\Support\Billing;

/**
 * Concise, stable error codes attached to a `failed`/`conflict`
 * WebhookProcessingResult — never a full exception message or stack trace.
 * See App\Services\Billing\WebhookEventProcessor's class docblock for how
 * each maps to a processing outcome.
 */
class WebhookProcessingErrorCode
{
    // ─── Retryable (failed) ─────────────────────────────────────────────
    public const CORRELATION_NOT_FOUND = 'correlation_not_found';
    public const INTERNAL_ERROR = 'internal_error';

    // ─── Non-retryable (failed) ─────────────────────────────────────────
    public const MALFORMED_PAYLOAD = 'malformed_payload';
    public const UNRECOGNISED_PROVIDER_STATUS = 'unrecognised_provider_status';

    // ─── Conflict (never automatically retried) ─────────────────────────
    public const AMBIGUOUS_CORRELATION = 'ambiguous_correlation';
    public const MISSING_LOCAL_SUBSCRIPTION = 'missing_local_subscription';
    public const LIVEMODE_MISMATCH = 'livemode_mismatch';
    public const ORGANISATION_MISMATCH = 'organisation_mismatch';
    public const COMMERCIAL_SNAPSHOT_MISMATCH = 'commercial_snapshot_mismatch';
    public const UNSUPPORTED_TRANSITION = 'unsupported_transition';
    public const PROVIDER_IDENTITY_CONFLICT = 'provider_identity_conflict';
    public const LIFECYCLE_REJECTED = 'lifecycle_rejected';
}
