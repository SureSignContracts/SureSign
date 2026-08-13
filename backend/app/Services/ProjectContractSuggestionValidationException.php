<?php

namespace App\Services;

/**
 * Thrown by ProjectContractSetupSyncService::apply() when the requested
 * selection would produce an invalid resulting Project state (currently:
 * only the start/end date consistency check). The message is already
 * customer-safe — callers may return it verbatim as a 422.
 */
class ProjectContractSuggestionValidationException extends \RuntimeException
{
}
