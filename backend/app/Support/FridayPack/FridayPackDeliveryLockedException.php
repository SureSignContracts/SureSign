<?php

namespace App\Support\FridayPack;

use RuntimeException;

/**
 * Automated Friday Pack, V1F — thrown by FridayPackPdfService::generate()
 * when a delivery row already exists for the pack. A distinct exception
 * type (rather than a plain RuntimeException) so FridayPackController::pdf()
 * can map ONLY this specific, expected conflict to 409 — never a genuine
 * PDF-generation failure (e.g. DocumentGenerationService being disabled),
 * which must still surface as a 500 exactly as before this phase.
 */
class FridayPackDeliveryLockedException extends RuntimeException
{
}
