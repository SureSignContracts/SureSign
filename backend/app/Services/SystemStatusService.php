<?php

namespace App\Services;

use App\Models\SuresignSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Customer-facing platform status — every signal here is either a cheap
 * local check (DB ping, disk write, extension_loaded) or a single cached
 * external call (Brevo account endpoint, never a real send). Two components
 * (Automated Contract Analysis, Background Processing) have no safe/cheap
 * way to verify live state today — see the class-level notes on each below —
 * so they honestly report "unavailable" rather than a guessed status. This
 * mirrors the Batch 4 instruction: never fabricate monitoring, and prefer
 * "status unavailable" (or omitting a component) over pretending something
 * is operational.
 */
class SystemStatusService
{
    public const STATUS_OPERATIONAL    = 'operational';
    public const STATUS_DEGRADED       = 'degraded';
    public const STATUS_DELAYED        = 'delayed';
    public const STATUS_PARTIAL_OUTAGE = 'partial_outage';
    public const STATUS_MAJOR_OUTAGE   = 'major_outage';
    public const STATUS_MAINTENANCE    = 'maintenance';
    public const STATUS_UNAVAILABLE    = 'unavailable';

    private const CACHE_KEY = 'system_status_v1';

    // Long enough to keep the Brevo check to roughly once every few minutes
    // regardless of how many users open the Help Center, short enough that a
    // real outage doesn't show stale "operational" for long.
    private const CACHE_SECONDS = 120;

    public static function current(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function () {
            return [
                'checked_at' => now()->toIso8601String(),
                'components' => [
                    ['name' => 'SureSign Platform', 'status' => self::checkDatabase()],
                    ['name' => 'File Uploads', 'status' => self::checkFileStorage()],
                    ['name' => 'Document Generation', 'status' => self::checkDocumentGeneration()],
                    ['name' => 'Email Delivery', 'status' => self::checkEmailDelivery()],
                    ['name' => 'Automated Contract Analysis', 'status' => self::checkAiAnalysis()],
                    ['name' => 'Background Processing', 'status' => self::STATUS_UNAVAILABLE],
                ],
            ];
        });
    }

    private static function checkDatabase(): string
    {
        try {
            DB::connection()->getPdo();
            return self::STATUS_OPERATIONAL;
        } catch (\Throwable $e) {
            Log::warning('SystemStatusService: database check failed: '.$e->getMessage());
            return self::STATUS_MAJOR_OUTAGE;
        }
    }

    private static function checkFileStorage(): string
    {
        try {
            $probe = '.system-status-probe';
            Storage::disk('local')->put($probe, (string) now()->timestamp);
            $ok = Storage::disk('local')->exists($probe);
            Storage::disk('local')->delete($probe);

            return $ok ? self::STATUS_OPERATIONAL : self::STATUS_DEGRADED;
        } catch (\Throwable $e) {
            Log::warning('SystemStatusService: file storage check failed: '.$e->getMessage());
            return self::STATUS_DEGRADED;
        }
    }

    // PHPWord/PhpSpreadsheet/DomPDF all need zip + gd — a cheap proxy for
    // "can this environment generate documents at all," not a real render.
    private static function checkDocumentGeneration(): string
    {
        $ready = extension_loaded('zip') && extension_loaded('gd');

        return $ready ? self::STATUS_OPERATIONAL : self::STATUS_DEGRADED;
    }

    // A single cached read-only call to Brevo's account endpoint — verifies
    // the configured API key is live without sending an email. Never called
    // more often than once per CACHE_SECONDS regardless of traffic.
    private static function checkEmailDelivery(): string
    {
        $settings = SuresignSetting::instance();

        if (empty($settings->brevo_api_key)) {
            return self::STATUS_UNAVAILABLE;
        }

        try {
            $response = Http::withHeaders(['api-key' => $settings->brevo_api_key])
                ->timeout(3)
                ->get('https://api.brevo.com/v3/account');

            if ($response->successful()) {
                return self::STATUS_OPERATIONAL;
            }

            return $response->status() >= 500 ? self::STATUS_MAJOR_OUTAGE : self::STATUS_DEGRADED;
        } catch (\Throwable $e) {
            Log::warning('SystemStatusService: Brevo check failed: '.$e->getMessage());
            return self::STATUS_UNAVAILABLE;
        }
    }

    // No free/cheap way to verify live Claude reachability without spending
    // tokens, so this is a configuration check, not a real uptime signal —
    // reported as "unavailable" rather than implying we've verified it.
    private static function checkAiAnalysis(): string
    {
        return self::STATUS_UNAVAILABLE;
    }
}
