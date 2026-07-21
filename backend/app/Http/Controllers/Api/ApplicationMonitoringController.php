<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Monitoring\ApplicationMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Super Admin Application Monitoring — GET /api/admin/application-monitoring.
 * Gated by the `role:Super Admin` route middleware group (see
 * routes/api.php); Admins and Clients never reach this controller.
 *
 * The summary is cached briefly so a page full of polling widgets (and
 * multiple open Super Admin tabs) doesn't re-run every aggregate query on
 * every request — see ApplicationMonitoringService for what each section
 * covers and how it degrades when a data source is unavailable.
 */
class ApplicationMonitoringController extends Controller
{
    private const CACHE_TTL_SECONDS = 30;

    public function __construct(private readonly ApplicationMonitoringService $service)
    {
    }

    public function index(): JsonResponse
    {
        $payload = Cache::remember(
            'monitoring:application-summary',
            self::CACHE_TTL_SECONDS,
            fn () => $this->service->summary(),
        );

        return response()->json($payload);
    }
}
