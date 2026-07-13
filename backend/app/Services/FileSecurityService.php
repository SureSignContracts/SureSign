<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Centralised upload safety checks used by every controller that accepts a
 * file. Laravel's `mimes:` validation rule already cross-checks extension
 * against a finfo-detected MIME type, but several endpoints in this codebase
 * historically validated only `file`/`image` with no extension allow-list at
 * all. This service is the single place that:
 *
 *   - enforces an explicit per-context extension allow-list
 *   - verifies the finfo-detected MIME type matches the extension
 *   - verifies file signature ("magic bytes") for the common formats we accept
 *   - rejects null bytes, path traversal, double extensions and dangerous
 *     extensions hidden anywhere in the original filename
 *   - generates the random storage filename (never the original name)
 *   - produces a display-safe version of the original filename for storage
 *     in a database column
 *
 * Callers still validate `required|file|max:...` via the Form Request /
 * `$request->validate()` as before — this service is the extra layer for
 * "what kind of file is this, really".
 */
class FileSecurityService
{
    /** Construction documents: PDF/Office/CSV/plain text + common raster images. */
    public const DOCUMENTS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'webp'];

    /** Branding/logo/cover images. SVG deliberately excluded — see SvgSanitizer. */
    public const IMAGES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** Favicon — raster + ico. SVG handled separately via SvgSanitizer. */
    public const FAVICON = ['ico', 'png', 'jpg', 'jpeg', 'webp'];

    /**
     * Document template uploads. Matches the existing feature's supported
     * formats (docx/pdf/doc) — macro-enabled .docm/.xlsm/.pptm are
     * intentionally absent and were never accepted.
     */
    public const TEMPLATE = ['docx', 'pdf', 'doc'];

    /**
     * Extensions that must never be accepted anywhere in this application,
     * checked against every path component of the original filename (not
     * just the final extension) to catch double-extension tricks like
     * `invoice.docx.phtml` or `drawing.jpg.exe`.
     */
    private const DANGEROUS_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'inc',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'com',
        'exe', 'msi', 'dll', 'jar', 'war', 'jsp', 'jspx', 'asp', 'aspx', 'cer',
        'htaccess', 'htpasswd', 'env', 'ini', 'conf', 'config', 'yml', 'yaml',
        'toml', 'sql', 'sqlite', 'db', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx',
        'html', 'htm', 'xml', 'xsl', 'xslt', 'swf', 'vbs', 'ps1', 'scr', 'apk',
        'app', 'dmg', 'iso', 'docm', 'xlsm', 'pptm', 'zip', 'rar', '7z', 'gz',
        'tar', 'svg',
    ];

    /** Expected finfo MIME type(s) per extension, for the cross-check. */
    private const MIME_MAP = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'csv'  => ['text/csv', 'text/plain'],
        'txt'  => ['text/plain'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'webp' => ['image/webp'],
        'gif'  => ['image/gif'],
        'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon'],
    ];

    /**
     * ZIP/decompression-bomb thresholds for docx/xlsx uploads (both are ZIP
     * containers under the hood). Calibrated against real SureSign documents
     * rather than invented: sample contracts (.docx) measured 26-28 entries,
     * ~625-804KB uncompressed, ~18-22x compression ratio; a sample payment
     * application (.xlsx) measured 32 entries, ~405KB uncompressed, ~1.3x
     * ratio. Every limit below gives at least an order of magnitude of
     * headroom over those real numbers while still catching pathological
     * archives — genuine compression ratios for office XML rarely exceed
     * double digits; classic decompression bombs run into the hundreds,
     * thousands or more.
     */
    /** ~60-75x the largest real sample (32 entries) — normal Office documents,
     *  even with many embedded images/worksheets, stay in the tens to low
     *  hundreds of entries. Guards against entry-flooding attacks. */
    private const ZIP_MAX_ENTRIES = 2000;

    /** Real docs top out around 803KB uncompressed total; 500MB is a hard
     *  ceiling far below what would risk exhausting container memory during
     *  PHPWord/PhpSpreadsheet/LibreOffice parsing, while comfortably
     *  accommodating documents with many large embedded images. */
    private const ZIP_MAX_TOTAL_UNCOMPRESSED_BYTES = 500 * 1024 * 1024; // 500MB

    /** Largest real single entry measured was ~775KB; 200MB gives huge
     *  headroom for one legitimately large embedded asset while still being
     *  well inside the total-uncompressed ceiling above. */
    private const ZIP_MAX_SINGLE_ENTRY_UNCOMPRESSED_BYTES = 200 * 1024 * 1024; // 200MB

    /** Highest real ratio measured was ~22x (highly-compressible XML text).
     *  200x is ~9x that worst case — comfortably above any legitimate Office
     *  document, while classic zip-bomb ratios run into the hundreds/
     *  thousands or far beyond, so this still rejects them. */
    private const ZIP_MAX_COMPRESSION_RATIO = 200;

    /** File-signature ("magic bytes") checks for the formats where spoofing is easiest. */
    private const MAGIC_BYTES = [
        'pdf'  => ["%PDF-"],
        'png'  => ["\x89PNG\r\n\x1a\n"],
        'jpg'  => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'gif'  => ["GIF87a", "GIF89a"],
        // docx/xlsx are zip containers (PK\x03\x04); doc/xls are OLE (D0CF11E0)
        'docx' => ["PK\x03\x04"],
        'xlsx' => ["PK\x03\x04"],
        'doc'  => ["\xD0\xCF\x11\xE0"],
        'xls'  => ["\xD0\xCF\x11\xE0"],
        'ico'  => ["\x00\x00\x01\x00"],
    ];

    /**
     * Run every check for an uploaded file against a context-specific
     * extension allow-list. Throws a ValidationException with a safe,
     * user-facing message (never a stack trace or path) on any failure.
     */
    public static function assertSafe(UploadedFile $file, array $allowedExtensions): void
    {
        $originalName = (string) $file->getClientOriginalName();

        self::assertCleanFilename($originalName);

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            throw ValidationException::withMessages([
                'file' => ['This file type is not supported.'],
            ]);
        }

        self::assertNoHiddenDangerousExtension($originalName, $extension);

        $detectedMime = (string) $file->getMimeType();
        $expectedMimes = self::MIME_MAP[$extension] ?? null;

        if ($expectedMimes !== null && !in_array($detectedMime, $expectedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file does not match its extension.'],
            ]);
        }

        self::assertMagicBytes($file->getRealPath(), $extension);
        self::assertSafeArchive($file->getRealPath(), $extension);
    }

    /**
     * Generate the physical storage filename. Never derived from the
     * original filename — only the already-validated extension is reused.
     */
    public static function randomStorageName(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return (string) Str::uuid() . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * A display-safe version of the original filename, for storage in a
     * database column and later rendering in the UI. Strips control
     * characters, null bytes, path separators and surrounding whitespace.
     * This is metadata only — it is never used to build a storage path.
     */
    public static function sanitizeDisplayName(string $originalName): string
    {
        // Strip control chars (incl. null bytes) and path separators.
        $clean = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $originalName) ?? '';
        $clean = trim($clean, " .\t\n\r\0\x0B");

        return $clean !== '' ? $clean : 'file';
    }

    // ── Internal checks ────────────────────────────────────────────────────

    private static function assertCleanFilename(string $originalName): void
    {
        if ($originalName === '' || str_contains($originalName, "\0")) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be processed safely.'],
            ]);
        }

        // Path traversal / absolute path attempts in the client-supplied name.
        if (str_contains($originalName, '..') || str_contains($originalName, '/') || str_contains($originalName, '\\')) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be processed safely.'],
            ]);
        }

        // Trailing dots/spaces behave differently across operating systems
        // (Windows silently strips them), which can be used to smuggle a
        // hidden extension past naive checks.
        if ($originalName !== rtrim($originalName, " .")) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be processed safely.'],
            ]);
        }
    }

    /**
     * Reject double extensions such as `contract.pdf.php` or `invoice.docx.phtml`
     * by checking every dot-separated segment of the filename — not just the
     * final extension — against the dangerous list.
     */
    private static function assertNoHiddenDangerousExtension(string $originalName, string $finalExtension): void
    {
        self::assertNoDangerousExtensionSegments($originalName);

        if (in_array($finalExtension, self::DANGEROUS_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['This file type is not supported.'],
            ]);
        }
    }

    /**
     * The "hidden segment" half of assertNoHiddenDangerousExtension(), split
     * out so the dedicated SVG path (assertAndSanitizeSvg()) can reuse it
     * without also rejecting 'svg' as the *final* extension — 'svg' is only
     * in DANGEROUS_EXTENSIONS to keep it out of the general assertSafe()
     * allow-lists (IMAGES/FAVICON/DOCUMENTS never include it), not because
     * an svg extension is unsafe once it goes through SvgSanitizer.
     */
    private static function assertNoDangerousExtensionSegments(string $originalName): void
    {
        $segments = explode('.', strtolower($originalName));

        // Drop the base name (first segment) and the final extension (last
        // segment) — everything left over is a "hidden" extension-like
        // segment that must not match a dangerous type.
        array_shift($segments);
        array_pop($segments);

        foreach ($segments as $segment) {
            if (in_array($segment, self::DANGEROUS_EXTENSIONS, true)) {
                throw ValidationException::withMessages([
                    'file' => ['This file type is not supported.'],
                ]);
            }
        }
    }

    /**
     * The SVG equivalent of assertSafe() + content sanitisation, combined
     * into one call so both branding-upload controllers share a single path
     * instead of each keeping their own copy (previously: near-identical
     * private storeSanitizedSvg() methods in OrganizationController and
     * SuresignSettingController, which could silently drift apart over
     * time). SVG can never appear in assertSafe()'s own allow-lists — MIME/
     * magic-byte cross-checking doesn't apply to textual XML — so it needs
     * its own path, but still gets the same filename-safety and hidden-
     * dangerous-extension checks every other upload type gets, before
     * SvgSanitizer takes over for content validation.
     *
     * Returns the sanitised SVG markup ready to store. Throws
     * ValidationException (same safe, user-facing message pattern as
     * assertSafe()) if the filename or content can't be safely accepted.
     */
    public static function assertAndSanitizeSvg(UploadedFile $file): string
    {
        $originalName = (string) $file->getClientOriginalName();

        self::assertCleanFilename($originalName);

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension !== 'svg') {
            throw ValidationException::withMessages([
                'file' => ['This file type is not supported.'],
            ]);
        }

        self::assertNoDangerousExtensionSegments($originalName);

        $raw = $file->getRealPath() !== false ? file_get_contents($file->getRealPath()) : false;
        $clean = $raw !== false ? SvgSanitizer::sanitize($raw) : null;

        if ($clean === null) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be processed safely.'],
            ]);
        }

        return $clean;
    }

    /**
     * Sanitise and store an SVG upload with a random filename — the SVG
     * counterpart to storeAs()-based storage used for other file types.
     * Shared by every branding/favicon upload endpoint that accepts SVG.
     */
    public static function storeSanitizedSvg(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $clean = self::assertAndSanitizeSvg($file);
        $storedName = (string) Str::uuid() . '.svg';

        \Illuminate\Support\Facades\Storage::disk($disk)->put($directory . '/' . $storedName, $clean);

        return $directory . '/' . $storedName;
    }

    private static function assertMagicBytes(string|false $realPath, string $extension): void
    {
        $signatures = self::MAGIC_BYTES[$extension] ?? null;

        if ($signatures === null || $realPath === false) {
            return; // No signature defined for this type — MIME/extension checks already ran.
        }

        $handle = @fopen($realPath, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be processed safely.'],
            ]);
        }

        $header = fread($handle, 16) ?: '';
        fclose($handle);

        foreach ($signatures as $signature) {
            if (str_starts_with($header, $signature)) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'file' => ['The uploaded file does not match its extension.'],
        ]);
    }

    /**
     * ZIP/decompression-bomb guard for docx/xlsx uploads. Both formats are
     * plain ZIP containers, so before anything (PHPWord, PhpSpreadsheet,
     * LibreOffice) is ever asked to decompress the archive, inspect its
     * central directory metadata only — ZipArchive::open()/statIndex() read
     * entry sizes and names without decompressing any entry's contents, so
     * this cannot itself be used to trigger the bomb it's checking for.
     *
     * docm/xlsm (macro-enabled) never reach here — they're already rejected
     * by DANGEROUS_EXTENSIONS before this point.
     */
    private static function assertSafeArchive(string|false $realPath, string $extension): void
    {
        if ($realPath === false || !in_array($extension, ['docx', 'xlsx'], true)) {
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($realPath) !== true) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be processed safely.'],
            ]);
        }

        $entryCount = $zip->numFiles;

        if ($entryCount > self::ZIP_MAX_ENTRIES) {
            $zip->close();
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be processed safely.'],
            ]);
        }

        $totalUncompressed = 0;
        $totalCompressed = 0;

        for ($i = 0; $i < $entryCount; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $uncompressed = (int) $stat['size'];

            if ($uncompressed > self::ZIP_MAX_SINGLE_ENTRY_UNCOMPRESSED_BYTES) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => ['The uploaded file could not be processed safely.'],
                ]);
            }

            $totalUncompressed += $uncompressed;
            $totalCompressed += (int) $stat['comp_size'];

            if ($totalUncompressed > self::ZIP_MAX_TOTAL_UNCOMPRESSED_BYTES) {
                $zip->close();
                throw ValidationException::withMessages([
                    'file' => ['The uploaded file could not be processed safely.'],
                ]);
            }
        }

        $zip->close();

        if ($totalCompressed > 0 && ($totalUncompressed / $totalCompressed) > self::ZIP_MAX_COMPRESSION_RATIO) {
            throw ValidationException::withMessages([
                'file' => ['The uploaded file could not be processed safely.'],
            ]);
        }
    }
}
