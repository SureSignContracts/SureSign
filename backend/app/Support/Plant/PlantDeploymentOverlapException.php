<?php

namespace App\Support\Plant;

use RuntimeException;

/**
 * Friday Pack Realignment, R1E.2D — thrown by
 * App\Services\Plant\PlantDeploymentService when a proposed deployment
 * interval overlaps an existing one for the SAME plant item. Mirrors
 * FridayPackImmutableException's exact shape (a plain RuntimeException,
 * caught in the controller and mapped to 409) — never a raw validation
 * error, since this is a genuine domain conflict, not malformed input.
 */
class PlantDeploymentOverlapException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This site presence period overlaps an existing period recorded for this plant item.');
    }
}
