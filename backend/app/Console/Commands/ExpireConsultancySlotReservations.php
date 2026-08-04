<?php

namespace App\Console\Commands;

use App\Services\Consultancy\ConsultancySlotReservationService;
use Illuminate\Console\Command;

/**
 * Consultancy Live Booking Upgrade, Stage 2 — marks every elapsed 'active'
 * ConsultancySlotReservation 'expired'. Purely a durable-state cleanup:
 * an elapsed reservation already stops blocking a slot immediately (see
 * AppointmentSchedulingService::isSlotFree()'s own expires_at check), so a
 * delayed run of this command never causes a stale hold to keep blocking
 * a slot — it only keeps the stored `status` column truthful for
 * reporting/Admin diagnostics.
 *
 * Idempotent (only ever touches rows still 'active'), safe under
 * concurrent execution (a plain bulk UPDATE, no lock contention with
 * reservation creation's own consultant-row lock), and bounded by
 * construction (only rows already past expires_at).
 */
class ExpireConsultancySlotReservations extends Command
{
    protected $signature = 'consultancy:reservations:expire';
    protected $description = 'Mark elapsed active Consultancy slot reservations as expired';

    public function handle(ConsultancySlotReservationService $service): int
    {
        $count = $service->expireDue();

        $this->info("Expired {$count} Consultancy slot reservation(s).");

        return self::SUCCESS;
    }
}
