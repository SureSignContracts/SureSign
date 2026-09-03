<?php

namespace App\Services\FridayPack;

use App\Models\FridayPackDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Automated Friday Pack, V1F — mirrors AppointmentPublicLinkService's own
 * pattern exactly: Laravel's built-in temporarySignedRoute() only, keyed
 * on the delivery row's own opaque public_token (never the numeric id).
 *
 * TTL policy mirrors AppointmentPublicLinkService::consultationViewApiUrl()
 * — a flat TTL counted from now(), via a dedicated config value
 * (suresign.friday_pack_delivery_link_ttl_days), NOT the
 * expiryFor()/cutoff-based formula used for cancel/reschedule links: a
 * report download has no "event" to expire against, it just needs to keep
 * working for a fixed, generous window after it was sent.
 *
 * expiresAt() is computed once, at Send-initiation time, and persisted
 * onto the FridayPackDelivery row itself (link_expires_at) — so the
 * signed URL and the row's own recorded expiry always agree, and a
 * regenerated link (should one ever be needed) can be produced against
 * the SAME already-recorded expiry rather than silently extending it.
 */
class FridayPackDeliveryLinkService
{
    public function expiresAt(): Carbon
    {
        return Carbon::now()->addDays((int) config('suresign.friday_pack_delivery_link_ttl_days', 7));
    }

    public function downloadUrl(FridayPackDelivery $delivery): string
    {
        return URL::temporarySignedRoute(
            'public.friday-packs.download',
            $delivery->link_expires_at,
            ['token' => $delivery->public_token],
        );
    }
}
