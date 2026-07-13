<?php

namespace Tests\Unit;

use App\Services\FileSecurityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FileSecurityServiceTest extends TestCase
{
    private function fakeFile(string $originalName, string $content, ?string $mimeType = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fss_test_');
        file_put_contents($path, $content);

        // UploadedFile::fake test mode = true skips is_uploaded_file() checks
        // and lets getMimeType() run real finfo detection on $path's content.
        return new UploadedFile($path, $originalName, $mimeType, null, true);
    }

    // ── Accepted files ──────────────────────────────────────────────────────

    public function test_valid_pdf_is_accepted(): void
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>";
        $file = $this->fakeFile('contract.pdf', $pdf);

        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
        $this->assertTrue(true); // no exception thrown
    }

    public function test_valid_png_is_accepted(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $file = $this->fakeFile('logo.png', $png);

        FileSecurityService::assertSafe($file, FileSecurityService::IMAGES);
        $this->assertTrue(true);
    }

    public function test_valid_docx_is_accepted(): void
    {
        // A real (if minimal) zip container — docx is a zip under the hood,
        // and finfo needs an actual valid central directory to detect it.
        $docx = base64_decode(
            'UEsDBBQAAAAAAIQ76lyGphA2BQAAAAUAAAAIAAAAdGVzdC50eHRoZWxsb1BLAQI/AxQAAAAAAIQ7'
            . '6lyGphA2BQAAAAUAAAAIAAAAAAAAAAAAAAC2gQAAAAB0ZXN0LnR4dFBLBQYAAAAAAQABADYAAAAr'
            . 'AAAAAAA='
        );
        $file = $this->fakeFile('template.docx', $docx);

        FileSecurityService::assertSafe($file, FileSecurityService::TEMPLATE);
        $this->assertTrue(true);
    }

    // ── Rejected extensions ──────────────────────────────────────────────────

    public function test_php_extension_is_rejected(): void
    {
        $file = $this->fakeFile('shell.php', '<?php system($_GET["c"]); ?>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_phtml_extension_is_rejected(): void
    {
        $file = $this->fakeFile('shell.phtml', '<?php phpinfo(); ?>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_exe_extension_is_rejected(): void
    {
        $file = $this->fakeFile('malware.exe', "MZ\x90\x00");

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_svg_is_rejected_from_generic_document_context(): void
    {
        $file = $this->fakeFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::IMAGES);
    }

    public function test_docm_is_rejected_from_template_context(): void
    {
        $file = $this->fakeFile('macro.docm', "PK\x03\x04" . str_repeat("\0", 30));

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::TEMPLATE);
    }

    // ── Spoofing attempts ─────────────────────────────────────────────────────

    public function test_php_renamed_to_pdf_is_rejected_by_mime_mismatch(): void
    {
        $file = $this->fakeFile('invoice.pdf', '<?php system($_GET["c"]); ?>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_html_renamed_to_jpg_is_rejected(): void
    {
        $file = $this->fakeFile('drawing.jpg', '<html><body><script>alert(1)</script></body></html>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::IMAGES);
    }

    public function test_executable_renamed_to_docx_is_rejected(): void
    {
        $file = $this->fakeFile('report.docx', "MZ\x90\x00\x03\x00\x00\x00");

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::TEMPLATE);
    }

    public function test_double_extension_pdf_php_is_rejected(): void
    {
        $file = $this->fakeFile('contract.pdf.php', "%PDF-1.4\n");

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_double_extension_docx_phtml_is_rejected(): void
    {
        $file = $this->fakeFile('invoice.docx.phtml', "PK\x03\x04");

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_hidden_dangerous_extension_before_an_allowed_final_extension_is_rejected(): void
    {
        // Final extension (.pdf) is allowed, but the embedded ".php" segment
        // must still be caught — this is the actual double-extension bypass
        // the allow-list-on-final-extension-only check alone would miss.
        $file = $this->fakeFile('invoice.php.pdf', "%PDF-1.4\n");

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_uppercase_php_extension_is_rejected(): void
    {
        $file = $this->fakeFile('shell.PHP', '<?php system($_GET["c"]); ?>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_null_byte_in_filename_is_rejected(): void
    {
        $file = $this->fakeFile("evil.pdf\0.php", "%PDF-1.4\n");

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_path_traversal_in_filename_cannot_survive_into_the_stored_or_display_name(): void
    {
        // PHP's SplFileInfo/Symfony UploadedFile already reduce the client-supplied
        // name to its basename before our code ever sees it, so `../../etc/passwd.pdf`
        // arrives as `passwd.pdf` — assert that end-to-end no traversal sequence
        // can reach either the generated storage name or the sanitised display name.
        $file = $this->fakeFile('../../etc/passwd.pdf', "%PDF-1.4\n");

        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);

        $stored = FileSecurityService::randomStorageName($file);
        $display = FileSecurityService::sanitizeDisplayName($file->getClientOriginalName());

        $this->assertStringNotContainsString('..', $stored);
        $this->assertStringNotContainsString('/', $stored);
        $this->assertStringNotContainsString('..', $display);
        $this->assertStringNotContainsString('/', $display);
    }

    public function test_trailing_dot_in_filename_is_rejected(): void
    {
        $file = $this->fakeFile('contract.pdf.', "%PDF-1.4\n");

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_mismatched_mime_and_extension_is_rejected(): void
    {
        // Valid PNG bytes, but named .pdf — extension says one thing, content says another.
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $file = $this->fakeFile('report.pdf', $png);

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    // ── ZIP / decompression-bomb protection (docx/xlsx) ─────────────────────
    //
    // docx/xlsx are ZIP containers. These tests build real, well-formed ZIP
    // archives (via ZipArchive, never hand-crafted bytes) so the checks are
    // exercised exactly as ZipArchive::statIndex() would see them in
    // production — no extraction ever happens, only central-directory
    // metadata is read. Thresholds: see FileSecurityService::ZIP_MAX_*.

    /** Build a real ZIP file on disk from `[entryName => content]` pairs. */
    private function buildZip(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip_test_') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public function test_ordinary_multi_entry_docx_is_accepted(): void
    {
        // A handful of real docx parts (document.xml, headers, rels) — well
        // within every ZIP threshold, and exercises the multi-entry loop.
        $path = $this->buildZip([
            '[Content_Types].xml' => '<Types/>',
            '_rels/.rels'         => '<Relationships/>',
            'word/document.xml'   => '<w:document>' . str_repeat('<w:p>Contract clause text.</w:p>', 200) . '</w:document>',
            'word/header1.xml'    => '<w:hdr/>',
            'word/footer1.xml'    => '<w:ftr/>',
        ]);

        $docx = file_get_contents($path);
        unlink($path);
        $file = $this->fakeFile('contract.docx', $docx);

        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
        $this->assertTrue(true);
    }

    public function test_ordinary_multi_sheet_xlsx_is_accepted(): void
    {
        $path = $this->buildZip([
            '[Content_Types].xml' => '<Types/>',
            '_rels/.rels'         => '<Relationships/>',
            'xl/workbook.xml'     => '<workbook/>',
            'xl/worksheets/sheet1.xml' => '<sheetData>' . str_repeat('<row/>', 500) . '</sheetData>',
            'xl/sharedStrings.xml'     => '<sst/>',
        ]);

        $xlsx = file_get_contents($path);
        unlink($path);
        $file = $this->fakeFile('payment-app.xlsx', $xlsx);

        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
        $this->assertTrue(true);
    }

    public function test_zip_with_excessive_entry_count_is_rejected(): void
    {
        $entries = [];
        for ($i = 0; $i < 2500; $i++) {
            $entries["file{$i}.xml"] = '';
        }
        $path = $this->buildZip($entries);
        $docx = file_get_contents($path);
        unlink($path);

        $file = $this->fakeFile('bomb.docx', $docx);

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_zip_with_extreme_compression_ratio_is_rejected(): void
    {
        // 10MB of a single repeated byte compresses via DEFLATE to well
        // under 50KB — comfortably over the 200x ratio threshold — while
        // staying far under both the total- and single-entry-size ceilings,
        // isolating the ratio check specifically.
        $entries = ['word/document.xml' => str_repeat("\0", 10 * 1024 * 1024)];
        $path = $this->buildZip($entries);
        $docx = file_get_contents($path);
        unlink($path);

        $file = $this->fakeFile('bomb.docx', $docx);

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_zip_with_oversized_single_entry_is_rejected(): void
    {
        // A single entry over the 200MB per-entry ceiling, while the whole
        // archive stays under the 500MB total ceiling — isolates the
        // single-entry check specifically.
        $entries = ['word/document.xml' => str_repeat("\0", 210 * 1024 * 1024)];
        $path = $this->buildZip($entries);
        $docx = file_get_contents($path);
        unlink($path);

        $file = $this->fakeFile('bomb.docx', $docx);

        $this->expectException(ValidationException::class);
        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
    }

    public function test_zip_bomb_check_is_skipped_for_non_zip_extensions(): void
    {
        // Extensions outside {docx, xlsx} never reach ZipArchive::open() at
        // all — e.g. a legitimate PDF must not be affected by this check.
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>";
        $file = $this->fakeFile('contract.pdf', $pdf);

        FileSecurityService::assertSafe($file, FileSecurityService::DOCUMENTS);
        $this->assertTrue(true);
    }

    // ── Storage naming ────────────────────────────────────────────────────────

    public function test_random_storage_name_is_not_the_original_name(): void
    {
        $file = $this->fakeFile('my very sensitive contract.pdf', "%PDF-1.4\n");

        $stored = FileSecurityService::randomStorageName($file);

        $this->assertStringEndsWith('.pdf', $stored);
        $this->assertStringNotContainsString('sensitive', $stored);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$/',
            $stored
        );
    }

    public function test_sanitize_display_name_strips_path_separators_and_control_chars(): void
    {
        $clean = FileSecurityService::sanitizeDisplayName("../../etc/passwd\0.pdf");

        $this->assertStringNotContainsString('/', $clean);
        $this->assertStringNotContainsString("\0", $clean);
        $this->assertStringNotContainsString('..', $clean);
    }

    // ── SVG: shared path (assertAndSanitizeSvg / storeSanitizedSvg) ─────────
    //
    // SVG can never pass assertSafe()'s own allow-lists (see the IMAGES/
    // FAVICON docblocks and test_svg_is_rejected_from_generic_document_context
    // above) — it has its own dedicated path that still applies the same
    // filename-safety and hidden-extension controls before handing off to
    // SvgSanitizer for content validation. See SvgSanitizerTest for the
    // exhaustive content-sanitisation cases; these tests cover only the
    // filename/extension controls specific to assertAndSanitizeSvg().

    public function test_valid_svg_is_accepted_and_returns_sanitised_markup(): void
    {
        $file = $this->fakeFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" /></svg>');

        $clean = FileSecurityService::assertAndSanitizeSvg($file);

        $this->assertStringContainsString('<svg', $clean);
        $this->assertStringContainsString('<rect', $clean);
    }

    public function test_svg_with_non_svg_final_extension_is_rejected(): void
    {
        $file = $this->fakeFile('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertAndSanitizeSvg($file);
    }

    public function test_svg_with_hidden_dangerous_extension_segment_is_rejected(): void
    {
        // Final extension is legitimately .svg, but a hidden ".php" segment
        // earlier in the filename must still be caught — same double-
        // extension protection every other upload type gets.
        $file = $this->fakeFile('logo.php.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertAndSanitizeSvg($file);
    }

    public function test_svg_with_null_byte_in_filename_is_rejected(): void
    {
        $file = $this->fakeFile("logo.svg\0.php", '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertAndSanitizeSvg($file);
    }

    public function test_svg_with_malicious_content_is_sanitised_not_passed_through(): void
    {
        $file = $this->fakeFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script></svg>');

        $clean = FileSecurityService::assertAndSanitizeSvg($file);

        $this->assertStringNotContainsString('onload', $clean);
        $this->assertStringNotContainsString('<script', $clean);
    }

    public function test_svg_with_malformed_xml_content_is_rejected(): void
    {
        $file = $this->fakeFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect></svg>');

        $this->expectException(ValidationException::class);
        FileSecurityService::assertAndSanitizeSvg($file);
    }

    public function test_store_sanitized_svg_writes_a_random_filename_and_returns_its_path(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $file = $this->fakeFile('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10" /></svg>');

        $path = FileSecurityService::storeSanitizedSvg($file, 'suresign/branding');

        $this->assertStringStartsWith('suresign/branding/', $path);
        $this->assertStringEndsWith('.svg', $path);
        $this->assertStringNotContainsString('logo', $path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
    }
}
