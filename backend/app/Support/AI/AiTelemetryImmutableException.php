<?php

namespace App\Support\AI;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown by AiTelemetryIntegrityGuard when application code attempts to
 * change a protected execution-telemetry field on an AI analysis after it
 * has already reached a terminal status. Deliberately its own exception
 * type (not a bare RuntimeException) so a caller/test can distinguish "the
 * telemetry-integrity guard fired" from any other runtime failure.
 */
class AiTelemetryImmutableException extends RuntimeException
{
    public function __construct(Model $model, string $field)
    {
        parent::__construct(sprintf(
            'Refusing to change protected AI telemetry field "%s" on %s #%d — its status ("%s") is already terminal. '
                . 'Execution telemetry is treated as immutable historical evidence once an analysis completes (Phase G4C.2C-2).',
            $field,
            class_basename($model),
            $model->getKey(),
            $model->getOriginal('status'),
        ));
    }
}
