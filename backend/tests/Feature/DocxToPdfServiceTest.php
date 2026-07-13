<?php

namespace Tests\Feature;

use App\Services\DocxToPdfService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the LibreOffice memory-limiting fix (M5): conversions now run under
 * a `ulimit -v` (RLIMIT_AS) ceiling in addition to the pre-existing wall-clock
 * timeout, so a document that survives the ZIP-bomb pre-check but still
 * triggers pathological memory use during rendering gets killed by the
 * kernel rather than exhausting container memory.
 *
 * Skipped automatically where LibreOffice isn't installed (e.g. a sandbox
 * without the full Docker image) — run in CI / the real environment.
 */
class DocxToPdfServiceTest extends TestCase
{
    private function skipIfLibreOfficeMissing(): void
    {
        $found = trim((string) shell_exec('which soffice libreoffice 2>/dev/null'));
        if ($found === '') {
            $this->markTestSkipped('LibreOffice is not installed in this environment.');
        }
    }

    /** A minimal but valid docx — enough for LibreOffice to render a PDF. */
    private function minimalDocx(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'docx_test_') . '.docx';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p><w:r><w:t>Ordinary contract text.</w:t></w:r></w:p></w:body>'
            . '</w:document>');
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    public function test_ordinary_docx_converts_to_pdf_successfully(): void
    {
        $this->skipIfLibreOfficeMissing();
        Storage::fake('local');

        Storage::disk('local')->put('contracts/ordinary.docx', $this->minimalDocx());

        $pdfPath = DocxToPdfService::generateAndStore('contracts/ordinary.docx');

        $this->assertSame('contracts/ordinary_preview.pdf', $pdfPath);
        Storage::disk('local')->assertExists($pdfPath);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($pdfPath));
    }

    public function test_conversion_cleans_up_its_temp_directory_and_profile(): void
    {
        $this->skipIfLibreOfficeMissing();
        Storage::fake('local');

        $before = glob(sys_get_temp_dir() . '/suresign_lo_*');

        Storage::disk('local')->put('contracts/cleanup.docx', $this->minimalDocx());
        DocxToPdfService::generateAndStore('contracts/cleanup.docx');

        $after = glob(sys_get_temp_dir() . '/suresign_lo_*');

        $this->assertSame($before, $after, 'No suresign_lo_* temp directory should survive a completed conversion.');
    }

    public function test_memory_limit_does_not_break_an_ordinary_conversion(): void
    {
        // The 2GB ulimit -v ceiling (DocxToPdfService::CONVERSION_MEMORY_LIMIT_KB)
        // must not interfere with converting a normal, small document — this
        // is the same conversion path as the "ordinary docx" test above, just
        // asserted from the memory-limiting angle explicitly.
        $this->skipIfLibreOfficeMissing();
        Storage::fake('local');

        Storage::disk('local')->put('contracts/small.docx', $this->minimalDocx());

        $pdfPath = DocxToPdfService::generateAndStore('contracts/small.docx');

        Storage::disk('local')->assertExists($pdfPath);
    }
}
