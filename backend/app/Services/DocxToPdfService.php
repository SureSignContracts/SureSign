<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocxToPdfService
{
    // Candidate binary names, checked in order
    private const BINARIES = ['soffice', 'libreoffice'];

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

            $cmd = implode(' ', [
                escapeshellcmd($binary),
                '--headless',
                '--norestore',
                '--convert-to', 'pdf',
                '--outdir', escapeshellarg($tmpDir),
                escapeshellarg($tmpDocx),
                '2>&1',
            ]);

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
