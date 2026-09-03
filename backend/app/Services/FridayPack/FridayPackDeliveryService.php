<?php

namespace App\Services\FridayPack;

use App\Jobs\SendFridayPackDeliveryJob;
use App\Models\FridayPack;
use App\Models\FridayPackDelivery;
use App\Models\FridayPackSettings;
use App\Models\User;
use App\Services\ProjectActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Automated Friday Pack, V1F — the ONE place a Send is initiated. Mirrors
 * FridayPackLifecycleService's own transaction+row-lock shape.
 *
 * Send is a single, one-time initiation event per pack — once any
 * FridayPackDelivery row exists for a pack, initiate() always rejects
 * (see retryFailed() for the only way to re-dispatch after that point).
 * This is what makes "recipients are snapshotted once, at Send time" and
 * "the PDF is locked once any delivery row exists" both simple, structural
 * facts rather than something enforced by extra bookkeeping.
 */
class FridayPackDeliveryService
{
    public function __construct(
        private readonly FridayPackDeliveryLinkService $linkService,
    ) {
    }

    /**
     * @throws RuntimeException on any Send prerequisite failure (not
     *   approved, no current PDF, already sent/sending, no recipients
     *   configured) — the caller maps this to 409.
     */
    public function initiate(FridayPack $pack, User $actor): FridayPack
    {
        return DB::transaction(function () use ($pack, $actor) {
            /** @var FridayPack $locked */
            $locked = FridayPack::whereKey($pack->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'approved') {
                throw new RuntimeException('Only an approved Friday Pack can be sent.');
            }

            if ($locked->pdf_document_id === null) {
                throw new RuntimeException('This Friday Pack has no current PDF — generate one before sending.');
            }

            if ($locked->deliveries()->exists()) {
                throw new RuntimeException('This Friday Pack has already been sent. Use "Retry Failed" to re-attempt any failed recipients.');
            }

            $recipients = $this->normalizedRecipients($locked);
            if (empty($recipients)) {
                throw new RuntimeException('No delivery recipients are configured for this project. Add recipients in Friday Pack Settings before sending.');
            }

            $rows = [];
            foreach ($recipients as $recipient) {
                $rows[] = FridayPackDelivery::create([
                    'friday_pack_id'   => $locked->id,
                    'project_id'       => $locked->project_id,
                    'organization_id'  => $locked->organization_id,
                    'document_id'      => $locked->pdf_document_id,
                    'recipient_name'   => $recipient['name'],
                    'recipient_email'  => $recipient['email'],
                    'initiated_by'     => $actor->id,
                    'public_token'     => (string) Str::uuid(),
                    'status'           => 'pending',
                    'link_expires_at'  => $this->linkService->expiresAt(),
                ]);
            }

            $project = $locked->project;
            ProjectActivityService::record(
                $project,
                $actor,
                'friday_pack_send_initiated',
                'Friday Pack sending initiated to ' . count($rows) . ' recipient(s) for week ending ' . $locked->week_ending->toDateString(),
                null,
                $locked,
            );

            DB::afterCommit(function () use ($rows) {
                foreach ($rows as $row) {
                    SendFridayPackDeliveryJob::dispatch($row->id);
                }
            });

            return $locked->fresh();
        });
    }

    /**
     * Re-dispatches only rows currently 'failed' — a successful recipient
     * is never touched, structurally (this query never selects a 'sent'
     * row). Does not create/remove any row; does not re-read
     * FridayPackSettings.recipients — a retry can only ever re-attempt an
     * already-snapshotted recipient.
     */
    public function retryFailed(FridayPack $pack, User $actor): int
    {
        $failed = FridayPackDelivery::where('friday_pack_id', $pack->id)
            ->where('status', 'failed')
            ->get();

        if ($failed->isEmpty()) {
            throw new RuntimeException('There are no failed deliveries to retry for this Friday Pack.');
        }

        foreach ($failed as $delivery) {
            $delivery->update(['status' => 'pending']);
        }

        DB::afterCommit(function () use ($failed) {
            foreach ($failed as $delivery) {
                SendFridayPackDeliveryJob::dispatch($delivery->id);
            }
        });

        ProjectActivityService::record(
            $pack->project,
            $actor,
            'friday_pack_delivery_retry',
            'Retrying ' . $failed->count() . ' failed Friday Pack delivery/deliveries for week ending ' . $pack->week_ending->toDateString(),
            null,
            $pack,
        );

        return $failed->count();
    }

    /**
     * Reads FridayPackSettings.recipients as it stands RIGHT NOW (this is
     * the only place that value is ever read for a Send) and normalises
     * it into a deduplicated list of ['name' => ?string, 'email' =>
     * string] — email lowercased/trimmed, matching the unique index's own
     * effective case-insensitivity.
     */
    private function normalizedRecipients(FridayPack $pack): array
    {
        $settings = FridayPackSettings::where('project_id', $pack->project_id)->first();
        $raw = $settings?->recipientsList() ?? [];

        $seen = [];
        $out = [];
        foreach ($raw as $entry) {
            $email = strtolower(trim((string) ($entry['email'] ?? '')));
            if ($email === '' || isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;
            $out[] = [
                'name'  => trim((string) ($entry['name'] ?? '')) ?: null,
                'email' => $email,
            ];
        }

        return $out;
    }
}
