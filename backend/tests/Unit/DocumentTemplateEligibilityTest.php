<?php

namespace Tests\Unit;

use App\Models\DocumentTemplate;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P0 Security Remediation (August 24, 2026) — direct, storage-independent
 * proof that DocumentTemplate::findEligibleForGeneration() (the fix for
 * TradePackagePackageGenerationController's previously-unscoped
 * `template_id` override) applies the exact same eligibility rules as the
 * existing, already-safe findForGeneration() lookup. Deliberately hits only
 * the database, never Storage::fake('local') — the equivalent HTTP-level
 * tests in TradePackageMasterPackageTemplateIsolationTest exercise the full
 * route/controller/storage path, but cannot execute in this sandbox due to
 * a pre-existing, unrelated environment limitation (a stale root-owned
 * `storage/framework/testing/disks/local/trade-packages` directory from an
 * earlier container run, unreadable by the current user — the exact same
 * limitation documented across this session's other test runs). This file
 * is the executable proof of the actual security-critical logic in this
 * environment.
 */
class DocumentTemplateEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(string $label): Organization
    {
        return Organization::create(['name' => "{$label} Org", 'slug' => 'org-' . strtolower($label) . '-' . uniqid()]);
    }

    public function test_same_organisation_private_template_is_eligible(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name' => 'A Master Package', 'slug' => Str::slug('A Master Package') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'master_package',
            'is_global' => false, 'is_active' => true, 'file_path' => 'templates/a.docx',
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNotNull($result);
        $this->assertSame($template->id, $result->id);
    }

    public function test_global_template_is_eligible_for_any_organisation(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => null,
            'name' => 'Global Master Package', 'slug' => Str::slug('Global Master Package') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'master_package',
            'is_global' => true, 'is_active' => true, 'file_path' => 'templates/global.docx',
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNotNull($result);
        $this->assertSame($template->id, $result->id);
    }

    /** The exact P0 case — a private template belonging to a DIFFERENT organisation must never be eligible. */
    public function test_foreign_organisation_private_template_is_not_eligible(): void
    {
        $orgA = $this->makeOrg('A');
        $orgB = $this->makeOrg('B');
        $foreignTemplate = DocumentTemplate::create([
            'organization_id' => $orgB->id,
            'name' => 'B Private Master Package', 'slug' => Str::slug('B Private Master Package') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'master_package',
            'is_global' => false, 'is_active' => true, 'file_path' => 'templates/b.docx',
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($foreignTemplate->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNull($result);
    }

    public function test_wrong_template_type_in_same_organisation_is_not_eligible(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name' => 'A Procurement Summary', 'slug' => Str::slug('A Procurement Summary') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'procurement_summary',
            'is_global' => false, 'is_active' => true, 'file_path' => 'templates/a2.docx',
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNull($result);
    }

    public function test_wrong_category_in_same_organisation_is_not_eligible(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name' => 'A Variation Template', 'slug' => Str::slug('A Variation Template') . '-' . uniqid(), 'category' => 'variation', 'template_type' => 'master_package',
            'is_global' => false, 'is_active' => true, 'file_path' => 'templates/a3.docx',
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNull($result);
    }

    public function test_inactive_same_organisation_template_is_not_eligible(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name' => 'A Inactive', 'slug' => Str::slug('A Inactive') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'master_package',
            'is_global' => false, 'is_active' => false, 'file_path' => 'templates/a4.docx',
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNull($result);
    }

    public function test_inactive_global_template_is_not_eligible(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => null,
            'name' => 'Inactive Global', 'slug' => Str::slug('Inactive Global') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'master_package',
            'is_global' => true, 'is_active' => false, 'file_path' => 'templates/g2.docx',
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNull($result);
    }

    public function test_template_with_no_file_path_is_not_eligible(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name' => 'A No File', 'slug' => Str::slug('A No File') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'master_package',
            'is_global' => false, 'is_active' => true, 'file_path' => null,
        ]);

        $result = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNull($result);
    }

    public function test_nonexistent_template_id_is_not_eligible(): void
    {
        $orgA = $this->makeOrg('A');

        $result = DocumentTemplate::findEligibleForGeneration(999999999, 'subcontract', 'master_package', $orgA->id);

        $this->assertNull($result);
    }

    /**
     * findEligibleForGeneration() must resolve the exact same row
     * findForGeneration() would have picked automatically, for the same
     * organisation/category/type — proving the explicit-ID path is no more
     * (and no less) permissive than the safe automatic path it replaces.
     */
    public function test_matches_findForGeneration_for_the_same_eligible_template(): void
    {
        $orgA = $this->makeOrg('A');
        $template = DocumentTemplate::create([
            'organization_id' => $orgA->id,
            'name' => 'A Master Package', 'slug' => Str::slug('A Master Package') . '-' . uniqid(), 'category' => 'subcontract', 'template_type' => 'master_package',
            'is_global' => false, 'is_active' => true, 'file_path' => 'templates/a5.docx',
        ]);

        $automatic = DocumentTemplate::findForGeneration('subcontract', 'master_package', $orgA->id);
        $explicit = DocumentTemplate::findEligibleForGeneration($template->id, 'subcontract', 'master_package', $orgA->id);

        $this->assertNotNull($automatic);
        $this->assertNotNull($explicit);
        $this->assertSame($automatic->id, $explicit->id);
    }
}
