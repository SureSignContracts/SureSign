<?php

namespace App\Support\FridayPack;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Thrown by FridayPackIntegrityGuard when application code attempts to
 * change a protected snapshot/period/commentary field on a Friday Pack
 * after it has already reached `approved` or `sent`. Mirrors
 * App\Support\AI\AiTelemetryImmutableException's exact shape — its own
 * exception type so a caller/test can distinguish "the integrity guard
 * fired" from any other runtime failure.
 */
class FridayPackImmutableException extends RuntimeException
{
    public function __construct(Model $model, string $field)
    {
        parent::__construct(sprintf(
            'Refusing to change protected field "%s" on %s #%d — its status ("%s") is already terminal for reporting purposes. '
                . 'A Friday Pack\'s frozen snapshot and manual commentary are immutable once approved or sent.',
            $field,
            class_basename($model),
            $model->getKey(),
            $model->getOriginal('status'),
        ));
    }
}
