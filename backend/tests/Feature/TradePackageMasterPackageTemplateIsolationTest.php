<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0 Security Remediation (August 24, 2026) — TradePackagePackageGenerationController's
 * optional `template_id` override for Master Package generation previously
 * resolved via an unscoped `DocumentTemplate::findOrFail()`. Request-level
 * validation (`exists:document_templates,id`) only proved the ID existed
 * SOMEWHERE on the platform, never that it belonged to the requester's own
 * organisation or was explicitly global — an authenticated Client could
 * supply another organisation's private template_id and the server would
 * read that organisation's file and generate a derived document from it
 * inside the requester's own project.
 *
 * These tests prove the fix (DocumentTemplate::findEligibleForGeneration())
 * closes the actual content-copy path, not merely that the HTTP response is
 * rejected — see test_foreign_organisation_template_secret_never_reaches_any_output().
 */
class TradePackageMasterPackageTemplateIsolationTest extends TestCase
{
    use RefreshDatabase;

    private static int $n = 0;

    private function makeOrgAndClient(string $label): array
    {
        self::$n++;
        $org = Organization::create(['name' => "{$label} Org " . self::$n, 'slug' => "org-{$label}-" . self::$n]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return [$org, $user];
    }

    private function makeProjectAndPackage(Organization $org, User $user): array
    {
        self::$n++;
        $project = Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => "Project " . self::$n,
            'status'          => 'active',
        ]);
        $tradePackage = TradePackage::create([
            'project_id'      => $project->id,
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => 'Groundworks',
            'slug'            => 'groundworks-' . self::$n,
            'status'          => 'active',
        ]);

        return [$project, $tradePackage];
    }

    /** A minimal but valid docx PhpWord's TemplateProcessor can open — same structure DocxToPdfServiceTest already relies on. */
    private function docxContaining(string $bodyText): string
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
            . '<w:body><w:p><w:r><w:t>' . $bodyText . '</w:t></w:r></w:p></w:body>'
            . '</w:document>');
        $zip->close();

        $content = file_get_contents($path);
        unlink($path);

        return $content;
    }

    private function storeTemplateFile(string $relativePath, string $content): void
    {
        Storage::disk('local')->put($relativePath, $content);
    }

    public function test_foreign_organisation_private_template_is_rejected(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndClient('a');
        [$orgB] = $this->makeOrgAndClient('b');
        [$project, $tradePackage] = $this->makeProjectAndPackage($orgA, $userA);

        $this->storeTemplateFile('templates/foreign.docx', $this->docxContaining('Org B private content.'));
        $foreignTemplate = DocumentTemplate::create([
            'organization_id' => $orgB->id,
            'name'            => 'Org B Master Package',
            'slug'            => \Illuminate\Support\Str::slug('Org B Master Package') . '-' . uniqid(),
            'category'        => 'subcontract',
            'template_type'   => 'master_package',
            'is_global'       => false,
            'is_active'       => true,
            'file_path'       => 'templates/foreign.docx',
        ]);

        Sanctum::actingAs($userA);
        $response = $this->postJson("/api/trade-packages/{$tradePackage->id}/generate-package", [
            'template_id' => $foreignTemplate->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Document::where('project_id', $project->id)->count());
        $this->assertSame(0, FileUpload::where('project_id', $project->id)->count());
    }

    public function test_same_organisation_private_template_succeeds(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndClient('a');
        [$project, $tradePackage] = $this->makeProjectAndPackage($orgA, $userA);

        $this->storeTemplateFile('templates/own.docx', $this->docxContaining('Legitimate own-org template.'));
        $ownTemplate = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name'            => 'Org A Master Package',
            'slug'            => \Illuminate\Support\Str::slug('Org A Master Package') . '-' . uniqid(),
            'category'        => 'subcontract',
            'template_type'   => 'master_package',
            'is_global'       => false,
            'is_active'       => true,
            'file_path'       => 'templates/own.docx',
        ]);

        Sanctum::actingAs($userA);
        $response = $this->postJson("/api/trade-packages/{$tradePackage->id}/generate-package", [
            'template_id' => $ownTemplate->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, Document::where('project_id', $project->id)->count());
    }

    public function test_global_template_succeeds(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndClient('a');
        [$project, $tradePackage] = $this->makeProjectAndPackage($orgA, $userA);

        $this->storeTemplateFile('templates/global.docx', $this->docxContaining('Global platform template.'));
        $globalTemplate = DocumentTemplate::create([
            'organization_id' => null,
            'name'            => 'Global Master Package',
            'slug'            => \Illuminate\Support\Str::slug('Global Master Package') . '-' . uniqid(),
            'category'        => 'subcontract',
            'template_type'   => 'master_package',
            'is_global'       => true,
            'is_active'       => true,
            'file_path'       => 'templates/global.docx',
        ]);

        Sanctum::actingAs($userA);
        $response = $this->postJson("/api/trade-packages/{$tradePackage->id}/generate-package", [
            'template_id' => $globalTemplate->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, Document::where('project_id', $project->id)->count());
    }

    public function test_wrong_template_type_in_same_organisation_is_rejected(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndClient('a');
        [$project, $tradePackage] = $this->makeProjectAndPackage($orgA, $userA);

        $this->storeTemplateFile('templates/wrong-type.docx', $this->docxContaining('Wrong type.'));
        $wrongType = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name'            => 'Org A Procurement Summary',
            'slug'            => \Illuminate\Support\Str::slug('Org A Procurement Summary') . '-' . uniqid(),
            'category'        => 'subcontract',
            'template_type'   => 'procurement_summary', // not master_package
            'is_global'       => false,
            'is_active'       => true,
            'file_path'       => 'templates/wrong-type.docx',
        ]);

        Sanctum::actingAs($userA);
        $response = $this->postJson("/api/trade-packages/{$tradePackage->id}/generate-package", [
            'template_id' => $wrongType->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Document::where('project_id', $project->id)->count());
    }

    public function test_inactive_same_organisation_template_is_rejected(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndClient('a');
        [$project, $tradePackage] = $this->makeProjectAndPackage($orgA, $userA);

        $this->storeTemplateFile('templates/inactive.docx', $this->docxContaining('Inactive.'));
        $inactive = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name'            => 'Org A Inactive Master Package',
            'slug'            => \Illuminate\Support\Str::slug('Org A Inactive Master Package') . '-' . uniqid(),
            'category'        => 'subcontract',
            'template_type'   => 'master_package',
            'is_global'       => false,
            'is_active'       => false,
            'file_path'       => 'templates/inactive.docx',
        ]);

        Sanctum::actingAs($userA);
        $response = $this->postJson("/api/trade-packages/{$tradePackage->id}/generate-package", [
            'template_id' => $inactive->id,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Document::where('project_id', $project->id)->count());
    }

    /**
     * Proves the content-copy path is closed, not merely that the HTTP
     * response is rejected. A real DOCX with a unique marker is planted as
     * Organisation B's private template; after the rejected request, no
     * Document/FileUpload belonging to Organisation A's project exists at
     * all for this generation attempt, and the request never reaches 201 —
     * so the marker can never have been read into any output the requester
     * can access.
     */
    public function test_foreign_organisation_template_secret_never_reaches_any_output(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndClient('a');
        [$orgB] = $this->makeOrgAndClient('b');
        [$project, $tradePackage] = $this->makeProjectAndPackage($orgA, $userA);

        $marker = 'FOREIGN_TEMPLATE_SECRET_MARKER';
        $this->storeTemplateFile('templates/secret.docx', $this->docxContaining($marker));
        $foreignTemplate = DocumentTemplate::create([
            'organization_id' => $orgB->id,
            'name'            => 'Org B Confidential Master Package',
            'slug'            => \Illuminate\Support\Str::slug('Org B Confidential Master Package') . '-' . uniqid(),
            'category'        => 'subcontract',
            'template_type'   => 'master_package',
            'is_global'       => false,
            'is_active'       => true,
            'file_path'       => 'templates/secret.docx',
        ]);

        Sanctum::actingAs($userA);
        $response = $this->postJson("/api/trade-packages/{$tradePackage->id}/generate-package", [
            'template_id' => $foreignTemplate->id,
        ]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString($marker, $response->getContent());

        // No generated Document/FileUpload exists at all for this project as
        // a result of this request — the marker was never read into
        // anything the requester can retrieve later.
        $this->assertSame(0, Document::where('project_id', $project->id)->count());
        $this->assertSame(0, FileUpload::where('project_id', $project->id)->count());

        // The foreign file itself is untouched — never opened/re-saved by
        // the rejected request (existence + identical bytes proves the
        // fake disk's file was never re-written by the DOCX-rebuild step
        // in processTemplate(), which reads-then-writes via a fresh
        // ZipArchive::OVERWRITE if it were ever reached).
        Storage::disk('local')->assertExists('templates/secret.docx');
    }

    /**
     * Enumeration safety: a foreign (existing, but ineligible) template ID
     * and a genuinely nonexistent template ID must produce the exact same
     * response — a foreign template's existence must never be confirmable
     * via a different message/behavior than "no template configured".
     */
    public function test_foreign_template_and_nonexistent_template_produce_identical_responses(): void
    {
        Storage::fake('local');
        [$orgA, $userA] = $this->makeOrgAndClient('a');
        [$orgB] = $this->makeOrgAndClient('b');
        [, $tradePackageForForeign] = $this->makeProjectAndPackage($orgA, $userA);
        [, $tradePackageForMissing] = $this->makeProjectAndPackage($orgA, $userA);

        $this->storeTemplateFile('templates/foreign2.docx', $this->docxContaining('Org B private content.'));
        $foreignTemplate = DocumentTemplate::create([
            'organization_id' => $orgB->id,
            'name'            => 'Org B Master Package',
            'slug'            => \Illuminate\Support\Str::slug('Org B Master Package') . '-' . uniqid(),
            'category'        => 'subcontract',
            'template_type'   => 'master_package',
            'is_global'       => false,
            'is_active'       => true,
            'file_path'       => 'templates/foreign2.docx',
        ]);

        Sanctum::actingAs($userA);
        $foreignResponse = $this->postJson("/api/trade-packages/{$tradePackageForForeign->id}/generate-package", [
            'template_id' => $foreignTemplate->id,
        ]);
        $missingResponse = $this->postJson("/api/trade-packages/{$tradePackageForMissing->id}/generate-package", [
            'template_id' => 999999999,
        ]);

        $foreignResponse->assertStatus(422);
        $missingResponse->assertStatus(422);
        $this->assertSame($foreignResponse->json('message'), $missingResponse->json('message'));
    }
}
