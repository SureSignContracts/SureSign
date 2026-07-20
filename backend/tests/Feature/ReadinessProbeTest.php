<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadinessProbeTest extends TestCase
{
    public function test_readyz_returns_ready_when_database_is_reachable(): void
    {
        $response = $this->get('/readyz');

        $response->assertStatus(200)->assertJson(['status' => 'ready']);
    }

    public function test_readyz_returns_503_when_database_is_unreachable(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new \RuntimeException('no connection'));

        $response = $this->get('/readyz');

        $response->assertStatus(503)->assertJson(['status' => 'not ready']);
    }
}
