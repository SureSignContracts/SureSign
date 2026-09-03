<?php

namespace App\Support\FridayPack;

use RuntimeException;

/**
 * Friday Pack Realignment, R1F.2 — thrown by
 * App\Services\FridayPack\FridayPackLifecycleService::submitForReview()
 * when App\Services\FridayPack\FridayPackReadinessService::evaluate()
 * reports `ready: false`. Mirrors FridayPackImmutableException's own
 * shape (a dedicated exception type so the controller/tests can
 * distinguish "readiness blocked this" from any other transition
 * failure) — carries the deterministic blocker list structurally,
 * never only a flat message string, so the controller can build the
 * approved `{ message, readiness: { ready, blockers } }` response
 * without re-deriving anything.
 */
class FridayPackNotReadyException extends RuntimeException
{
    /** @param array<int, array{section_key: string, subsection_key: ?string, label: string, reason: string}> $blockers */
    public function __construct(private readonly array $blockers)
    {
        parent::__construct('Friday Pack needs attention before it can be submitted for review.');
    }

    public function blockers(): array
    {
        return $this->blockers;
    }
}
