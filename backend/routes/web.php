<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Readiness probe for rolling deployments — distinct from Laravel's own
// /up (registered via bootstrap/app.php's `health:` param), which only
// proves the process booted and never touches the database. A new backend
// container must not be routed real traffic until it can actually reach
// MySQL, since every request needs it. Kept minimal (one PDO check, no
// query) so it stays cheap enough to poll on the interval Swarm/Dokploy use
// for start-first rolling updates. Deliberately returns no internal detail
// on failure — just a status, per the "don't leak internals" health-check
// rule.
Route::get('/readyz', function () {
    try {
        DB::connection()->getPdo();
    } catch (\Throwable) {
        return response()->json(['status' => 'not ready'], 503);
    }

    return response()->json(['status' => 'ready']);
});
