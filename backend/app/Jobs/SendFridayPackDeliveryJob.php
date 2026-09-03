<?php

namespace App\Jobs;

use App\Models\FridayPack;
use App\Models\FridayPackDelivery;
use App\Services\EmailNotificationService;
use App\Services\FridayPack\FridayPackDeliveryLinkService;
use App\Support\FridayPack\FridayPackDeliveryEmailBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Automated Friday Pack, V1F — sends ONE recipient's delivery email and
 * records the real outcome on that row. Mirrors SendAppointmentEmailJob's
 * shape (id-only constructor param, always dispatched ->afterCommit(),
 * never lets a delivery failure surface as a failure of the triggering
 * request).
 *
 * Provider idempotency reality: Brevo's API call itself is not
 * idempotent (no idempotency-key support is used here — see
 * EmailNotificationService, which has none). The real, honest guarantee
 * this codebase makes is application-level: this job only ever
 * transitions a row that is currently 'pending' (never re-sends a row
 * already 'sent'), via a row-locked read-check-write — so an accidental
 * duplicate queue dispatch of the SAME delivery id is a safe no-op, even
 * though a genuine Brevo-side retry of an already-accepted call is not
 * something this codebase can detect or prevent.
 */
class SendFridayPackDeliveryJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    public function __construct(public readonly int $deliveryId)
    {
    }

    public function handle(
        FridayPackDeliveryLinkService $linkService,
        FridayPackDeliveryEmailBuilder $emailBuilder,
    ): void {
        $delivery = FridayPackDelivery::find($this->deliveryId);
        if (!$delivery || $delivery->status !== 'pending') {
            // Already sent, already failed-and-not-retried, or the row
            // vanished entirely (should never happen — deliveries are
            // never deleted) — nothing to do, and never re-sends a
            // completed row.
            return;
        }

        $downloadUrl = $linkService->downloadUrl($delivery);

        $result = EmailNotificationService::sendDirectWithMessageId(
            $delivery->recipient_email,
            $emailBuilder->subject($delivery),
            $emailBuilder->plainText($delivery, $downloadUrl),
            [],
            null,
            'Friday Pack Delivery',
            $emailBuilder->html($delivery, $downloadUrl),
            true,
        );

        DB::transaction(function () use ($delivery, $result) {
            $locked = FridayPackDelivery::whereKey($delivery->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== 'pending') {
                return;
            }

            $locked->attempt_count += 1;
            $locked->last_attempted_at = now();

            if ($result['sent']) {
                $locked->status = 'sent';
                $locked->sent_at = now();
                $locked->provider_message_id = $result['provider_message_id'];
                $locked->failure_reason = null;
                $locked->failed_at = null;
            } else {
                $locked->status = 'failed';
                $locked->failed_at = now();
                $locked->failure_reason = Str::limit('EmailNotificationService reported delivery failure — see application logs for detail.', 250);
            }

            $locked->save();
        });

        $this->finalizePackIfComplete($delivery->friday_pack_id);
    }

    public function failed(\Throwable $exception): void
    {
        $delivery = FridayPackDelivery::find($this->deliveryId);
        if ($delivery && $delivery->status === 'pending') {
            $delivery->update([
                'status'          => 'failed',
                'attempt_count'   => $delivery->attempt_count + 1,
                'last_attempted_at' => now(),
                'failed_at'       => now(),
                'failure_reason'  => Str::limit($exception->getMessage(), 250),
            ]);
        }

        Log::error("SendFridayPackDeliveryJob failed for delivery {$this->deliveryId}", [
            'error' => $exception->getMessage(),
        ]);

        if ($delivery) {
            $this->finalizePackIfComplete($delivery->friday_pack_id);
        }
    }

    /**
     * Transitions the pack to 'sent' (status/sent_at/sent_by) the moment
     * every one of its delivery rows is genuinely 'sent' — never while any
     * row is still 'pending'/'failed' (see V1F's "partial delivery" rule:
     * a pack with any outstanding/failed recipient stays 'approved',
     * never a misleading partial 'sent'). sent_by is taken from the
     * delivery rows' own initiated_by — the real user who clicked Send,
     * never a fabricated system actor (this codebase has no such actor —
     * see ProjectActivityService's own non-nullable User requirement).
     */
    private function finalizePackIfComplete(int $fridayPackId): void
    {
        DB::transaction(function () use ($fridayPackId) {
            /** @var FridayPack|null $pack */
            $pack = FridayPack::whereKey($fridayPackId)->lockForUpdate()->first();
            if (!$pack || $pack->status === 'sent') {
                return;
            }

            $deliveries = FridayPackDelivery::where('friday_pack_id', $fridayPackId)->get();
            if ($deliveries->isEmpty() || $deliveries->contains(fn ($d) => $d->status !== 'sent')) {
                return;
            }

            $initiatedBy = $deliveries->first()->initiated_by;

            $pack->forceFill([
                'status'  => 'sent',
                'sent_at' => now(),
                'sent_by' => $initiatedBy,
            ])->save();
        });
    }
}
