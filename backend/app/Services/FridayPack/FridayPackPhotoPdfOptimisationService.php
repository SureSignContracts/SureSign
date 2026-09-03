<?php

namespace App\Services\FridayPack;

use App\Models\FileUpload;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Friday Pack Realignment, R1G.1 — the ONE place a selected Site
 * Photograph is prepared for embedding into the schema-2 PDF. Uses the
 * already-installed `intervention/image` (^4.1) — no new dependency (R1G.0
 * confirmed this package sits unused in composer.json).
 *
 * Never mutates the original `FileUpload`/its stored file — reads the
 * original via the same safe local-disk resolution
 * `FridayPackPhotoSelectionService`/`BrandingService` already use
 * (`Storage::disk($upload->disk ?? 'local')`), downscales to a maximum of
 * 1600px on the longest edge (never upscales — `scaleDown()`), re-encodes
 * as JPEG at 80% quality (Intervention's own GD `JpegEncoder` blends any
 * source transparency onto a white background automatically — matches
 * this document's own white print background, no extra code needed), and
 * writes the result to a fresh, unpredictable-filename temp file under
 * `storage_path('app/friday-pack-pdf-tmp')` — a genuine subdirectory of
 * Dompdf's own configured `chroot` (confirmed via
 * `php artisan config:show dompdf` — the backend application root), so
 * the rendition is reliably readable by Dompdf.
 *
 * `prepare()` never throws — any failure (missing file, corrupt/
 * unsupported image, write failure) is logged and returns null; the
 * caller (`FridayPackSchemaTwoPdfPresenter`) falls back to a controlled
 * "Photo unavailable" placeholder rather than crashing the whole report
 * over one bad photograph. The caller owns cleanup — `cleanup()` deletes
 * every path it's given, best-effort, and is always called from a
 * `finally` block in `FridayPackPdfService::generate()` regardless of
 * whether generation itself succeeded.
 */
class FridayPackPhotoPdfOptimisationService
{
    private const MAX_DIMENSION_PX = 1600;
    private const JPEG_QUALITY = 80;

    public function prepare(FileUpload $upload): ?string
    {
        $disk = $upload->disk ?: 'local';

        if (!Storage::disk($disk)->exists($upload->file_path)) {
            return null;
        }

        $originalPath = Storage::disk($disk)->path($upload->file_path);

        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->decodePath($originalPath);
            $image->scaleDown(width: self::MAX_DIMENSION_PX, height: self::MAX_DIMENSION_PX);
            $encoded = (string) $image->encode(new JpegEncoder(quality: self::JPEG_QUALITY));
        } catch (Throwable $e) {
            Log::warning("Friday Pack PDF photo optimisation failed for FileUpload #{$upload->id}: " . $e->getMessage());

            return null;
        }

        $tempDir = storage_path('app/friday-pack-pdf-tmp');
        if (!is_dir($tempDir) && !@mkdir($tempDir, 0755, true) && !is_dir($tempDir)) {
            Log::warning("Friday Pack PDF photo optimisation could not create temp directory {$tempDir}");

            return null;
        }

        $tempPath = $tempDir . '/' . Str::uuid()->toString() . '.jpg';

        if (@file_put_contents($tempPath, $encoded) === false) {
            Log::warning("Friday Pack PDF photo optimisation could not write temp file for FileUpload #{$upload->id}");

            return null;
        }

        return $tempPath;
    }

    /**
     * Best-effort, never throws — called from a `finally` block so a
     * partially-failed generation still cleans up whatever renditions
     * were produced before the failure.
     *
     * @param  string[]  $tempPaths
     */
    public function cleanup(array $tempPaths): void
    {
        foreach ($tempPaths as $path) {
            if ($path && is_string($path) && file_exists($path)) {
                @unlink($path);
            }
        }
    }
}
