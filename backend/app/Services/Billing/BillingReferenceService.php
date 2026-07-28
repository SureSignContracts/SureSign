<?php

namespace App\Services\Billing;

use App\Models\BillingReferenceSequence;
use App\Support\Billing\BillingReferenceType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Generates human-readable operator-facing references (SUB-000001,
 * INV-000001, PAY-000001, CHK-000001) via the same atomic
 * lockForUpdate()+increment() pattern App\Services\DocumentNumberService
 * already uses for document numbers — one sequence row per reference type,
 * incremented inside a transaction so concurrent requests never collide.
 */
class BillingReferenceService
{
    public function generate(string $type): string
    {
        if (!BillingReferenceType::isValid($type)) {
            throw new InvalidArgumentException("Unknown billing reference type: {$type}");
        }

        return DB::transaction(function () use ($type) {
            $seq = BillingReferenceSequence::lockForUpdate()->firstOrCreate(
                ['type' => $type],
                ['current_sequence' => 0]
            );

            $seq->increment('current_sequence');
            $seq->refresh();

            return $this->format($type, $seq->current_sequence);
        });
    }

    private function format(string $type, int $sequence): string
    {
        $prefix = BillingReferenceType::PREFIXES[$type];

        return "{$prefix}-" . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }
}
