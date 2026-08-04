<?php

namespace App\Jobs;

use App\Models\ConsultationEnquiry;
use App\Services\Consultancy\ConsultationNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one Consultancy customer email. Mirrors SendAppointmentEmailJob's
 * exact shape and contract: always dispatched with ->afterCommit() by the
 * caller, re-fetches by id rather than risking stale serialized state, and
 * a delivery failure here must never surface as a failure of the
 * triggering write action (which already committed by the time this runs).
 */
class SendConsultationEmailJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];
    public int $timeout = 60;

    /**
     * @param string $kind 'awaiting_customer' — 'summary_published' was
     *                     moved to SendConsultationCommunicationJob in the
     *                     Consultancy Communications & Global Email
     *                     Experience Upgrade, Batch 3 (see
     *                     ConsultationNotificationService's own docblock)
     */
    public function __construct(
        public readonly int $consultationEnquiryId,
        public readonly string $kind,
    ) {
    }

    public function handle(ConsultationNotificationService $notificationService): void
    {
        $enquiry = ConsultationEnquiry::find($this->consultationEnquiryId);
        if (!$enquiry) {
            return;
        }

        match ($this->kind) {
            'awaiting_customer' => $notificationService->sendAwaitingCustomerNotice($enquiry),
            default => null,
        };
    }
}
