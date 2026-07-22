<?php

namespace App\Services;

use App\Models\AppointmentNumberSequence;
use Illuminate\Support\Facades\DB;

/**
 * Generates human-friendly appointment references (APT-000001), atomically,
 * following the same lock-and-increment approach as DocumentNumberService
 * — but against a single global counter row, since appointments aren't
 * scoped to a project the way documents are.
 */
class AppointmentReferenceService
{
    public function generate(): string
    {
        return DB::transaction(function () {
            $seq = AppointmentNumberSequence::lockForUpdate()->first()
                ?? AppointmentNumberSequence::create(['current_sequence' => 0]);

            $seq->increment('current_sequence');
            $seq->refresh();

            return 'APT-' . str_pad((string) $seq->current_sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
