<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocxToPdfService
{
    // Candidate binary names, checked in order
    private const BINARIES = ['soffice', 'libreoffice'];

    // Hard ceiling on a single conversion — a crafted/corrupt DOCX must not
    // be able to hang a request or a queue worker indefinitely.
    private const CONVERSION_TIMEOUT_SECONDS = 60;

    // Address-space (RLIMIT_AS) ceiling for the LibreOffice process, applied
    // via the shell's `ulimit -v` — no systemd, no root, works in any plain
    // Linux container. Ordinary LibreOffice headless conversions of
    // business documents (multi-page contracts, embedded images/tables)
    // typically use well under 500MB RSS; 2GB gives generous headroom for
    // legitimate documents while still bounding the worst case if a
    // maliciously crafted document (e.g. one that survives the ZIP-bomb
    // pre-check but still triggers pathological memory use during
    // rendering) tries to consume unbounded RAM. The kernel kills the
    // process on breach (malloc/mmap failure) well before it can exhaust
    // container/host memory.
    private const CONVERSION_MEMORY_LIMIT_KB = 2 * 1024 * 1024; // 2GB

    /**
     * Convert a stored DOCX to PDF, cache the result in preview_pdf_path, and return
     * the storage-relative PDF path.
     *
     * @param  string $storagePath  e.g. "projects/5/subcontracts/Landscaping/SP-COL-001-LS_...docx"
     * @return string               Storage-relative PDF path
     * @throws \RuntimeException
     */
    public static function generateAndStore(string $storagePath): string
    {
        $fullPath = Storage::disk('local')->path($storagePath);

        if (!is_readable($fullPath)) {
            throw new \RuntimeException("DOCX not readable: {$fullPath}");
        }

        $pdfBytes = self::convertToPdfBytes($fullPath);

        $pdfPath = preg_replace('/\.docx$/i', '_preview.pdf', $storagePath);
        Storage::disk('local')->put($pdfPath, $pdfBytes);

        return $pdfPath;
    }

    /**
     * Convert a DOCX at an absolute path to raw PDF bytes via LibreOffice headless.
     * Throws if LibreOffice is not installed or conversion fails.
     */
    public static function convertToPdfBytes(string $fullPath): string
    {
        if (strtolower(pathinfo($fullPath, PATHINFO_EXTENSION)) !== 'docx') {
            throw new \RuntimeException('DocxToPdfService only handles .docx files.');
        }

        $binary = self::findBinary();

        if ($binary === null) {
            throw new \RuntimeException(
                'PDF preview generation is unavailable because LibreOffice is not installed on the server. '
                . 'Install it with: sudo apt-get install -y libreoffice-headless'
            );
        }

        return self::runLibreOffice($binary, $fullPath);
    }

    // ── Core conversion ───────────────────────────────────────────────────────

    private static function runLibreOffice(string $binary, string $fullPath): string
    {
        // LibreOffice writes the PDF into a directory we specify.
        // We use a unique temp dir per conversion to avoid any concurrency collisions.
        $tmpDir = sys_get_temp_dir() . '/suresign_lo_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0700, true);

        try {
            // Copy the DOCX into the temp dir so LibreOffice has a clean, known path.
            $tmpDocx = $tmpDir . '/' . basename($fullPath);
            copy($fullPath, $tmpDocx);

            // LibreOffice otherwise defaults its user profile to
            // $HOME/.config/libreoffice — under a non-root runtime user
            // (www-data), $HOME (/var/www) isn't writable, so every
            // conversion would fail with no writable profile location.
            // Pointing it at a subdirectory of the per-job temp dir we
            // already create (random name, 0700, cleaned up below) means
            // every conversion gets its own isolated, writable profile with
            // no dependency on the calling user's home directory at all.
            $profileDir = $tmpDir . '/loprofile';

            // `timeout` (coreutils) hard-kills the whole process group if
            // conversion hangs — see CONVERSION_TIMEOUT_SECONDS above.
            $innerCmd = implode(' ', [
                'timeout', '--kill-after=5', (string) self::CONVERSION_TIMEOUT_SECONDS,
                escapeshellcmd($binary),
                '--headless',
                '--norestore',
                escapeshellarg('-env:UserInstallation=file://' . $profileDir),
                '--convert-to', 'pdf',
                '--outdir', escapeshellarg($tmpDir),
                escapeshellarg($tmpDocx),
            ]);

            // `ulimit -v` is a shell builtin (bash/sh), not a separate binary —
            // no new dependency, no root required. It's set in the same shell
            // that then `exec`s into `timeout`/soffice, so the limit is
            // inherited by the whole process tree via standard rlimit
            // inheritance across fork/exec. Wrapping the already-escaped
            // inner command in a single escapeshellarg() for `bash -c` nests
            // safely — each inner token was already shell-escaped in its own
            // right.
            $cmd = 'bash -c ' . escapeshellarg(
                'ulimit -v ' . self::CONVERSION_MEMORY_LIMIT_KB . '; exec ' . $innerCmd
            ) . ' 2>&1';

            $output   = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            $expectedPdf = $tmpDir . '/' . basename($fullPath, '.docx') . '.pdf';

            if ($exitCode !== 0 || !file_exists($expectedPdf)) {
                $log = implode("\n", $output);
                Log::error("LibreOffice conversion failed (exit {$exitCode}): {$log}");
                throw new \RuntimeException(
                    "LibreOffice conversion failed (exit code {$exitCode}). See logs for details."
                );
            }

            $pdfBytes = file_get_contents($expectedPdf);

            if ($pdfBytes === false || strlen($pdfBytes) < 100) {
                throw new \RuntimeException('LibreOffice produced an empty or unreadable PDF.');
            }

            Log::info('LibreOffice converted ' . basename($fullPath) . ' → PDF (' . strlen($pdfBytes) . ' bytes)');

            return $pdfBytes;

        } finally {
            // Always clean up temp files
            self::rmdir($tmpDir);
        }
    }

    // ── Binary detection ──────────────────────────────────────────────────────

    private static function findBinary(): ?string
    {
        foreach (self::BINARIES as $name) {
            $path = trim((string) shell_exec('which ' . escapeshellarg($name) . ' 2>/dev/null'));
            if ($path !== '' && is_executable($path)) {
                return $path;
            }
        }

        // Also check common fixed install paths
        $fixed = [
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/opt/libreoffice/program/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ];

        foreach ($fixed as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function rmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . '/' . $item;
            is_dir($full) ? self::rmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }
}
