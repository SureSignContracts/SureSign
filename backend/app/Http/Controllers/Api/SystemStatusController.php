<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemStatusService;

class SystemStatusController extends Controller
{
    // GET /system-status — authenticated (any role); customer-safe labels
    // and statuses only, see SystemStatusService for what's actually checked.
    public function index()
    {
        return response()->json(SystemStatusService::current());
    }
}
