<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Manages the SureSign filesystem folder structure.
 *
 * Primary storage:  storage/app/suresign/{org_slug}/{project_slug}/
 *
 * Standard project folder layout:
 *   01_Contracts/
 *     Main Contract/
 *     Subcontracts/
 *     Consultant Agreements/
 *     Supplier Agreements/
 *   02_Commercial/
 *   03_Payment Applications/
 *   04_Variations/
 *   05_Notices/
 *   06_RFIs/
 *   07_Meetings/
 *   08_QA Reports/
 *   09_Snagging/
 *   10_Closeout/
 *   11_Adjudication/
 *   12_Site Reports/
 *   13_AI Generated/
 */
class ProjectStorageService
{
    /**
     * All folders created per project (relative to project root).
     */
    private const PROJECT_FOLDERS = [
        '01_Contracts/Main Contract',
        '01_Contracts/Subcontracts',
        '01_Contracts/Consultant Agreements',
        '01_Contracts/Supplier Agreements',
        'Subcontracts',
        '02_Commercial',
        '03_Payment Applications',
        '04_Variations',
        '05_Notices',
        '06_RFIs',
        '07_Meetings',
        '08_QA Reports',
        '09_Snagging',
        '10_Closeout',
        '11_Adjudication',
        '12_Site Reports',
        '13_AI Generated',
    ];

    /**
     * Map module_key → storage folder name (relative to project root).
     */
    private const MODULE_PATHS = [
        'contracts'            => '01_Contracts',
        'subcontracts'         => 'Subcontracts',
        'commercial'           => '02_Commercial',
        'payment_applications' => '03_Payment Applications',
        'variations'           => '04_Variations',
        'notices'              => '05_Notices',
        'rfis'                 => '06_RFIs',
        'meetings'             => '07_Meetings',
        'qa_reports'           => '08_QA Reports',
        'snagging'             => '09_Snagging',
        'closeout'             => '10_Closeout',
        'adjudication'         => '11_Adjudication',
        'site_reports'         => '12_Site Reports',
        'ai_generated'         => '13_AI Generated',
        'general'              => 'General',
    ];

    /**
     * Map contract_document_type → sub-folder under 01_Contracts.
     */
    private const CONTRACT_SUBFOLDERS = [
        'main_contract'        => 'Main Contract',
        'subcontract'          => 'Subcontracts',
        'consultant_agreement' => 'Consultant Agreements',
        'supplier_agreement'   => 'Supplier Agreements',
        'other'                => '',    // root of 01_Contracts
    ];

    // ── Path helpers ─────────────────────────────────────────────────────────

    /**
     * Build the storage root for a project:
     * suresign/{org_slug}/{project_slug}
     */
    public static function projectRoot(Project $project): string
    {
        $org = $project->organization ?? Organization::find($project->organization_id);
        $orgSlug     = $org ? Str::slug($org->slug ?? $org->name) : "org-{$project->organization_id}";
        $projectSlug = Str::slug($project->code ?: $project->name) ?: "project-{$project->id}";

        return "suresign/{$orgSlug}/{$projectSlug}";
    }

    /**
     * Get the storage path for a file given a module key (and optional contract doc type).
     *
     * @param  Project     $project
     * @param  string      $moduleKey           e.g. 'contracts'
     * @param  string|null $contractDocType     e.g. 'main_contract'
     * @return string                           e.g. 'suresign/acme/test-project/01_Contracts/Main Contract'
     */
    public static function modulePath(
        Project $project,
        string  $moduleKey,
        ?string $contractDocType = null
    ): string {
        $root       = self::projectRoot($project);
        $modulePath = self::MODULE_PATHS[$moduleKey] ?? Str::slug($moduleKey);

        if ($moduleKey === 'contracts' && $contractDocType && isset(self::CONTRACT_SUBFOLDERS[$contractDocType])) {
            $sub = self::CONTRACT_SUBFOLDERS[$contractDocType];
            return $sub ? "{$root}/{$modulePath}/{$sub}" : "{$root}/{$modulePath}";
        }

        return "{$root}/{$modulePath}";
    }

    /**
     * Build the folder_key value stored on the FileUpload record.
     */
    public static function folderKey(string $moduleKey, ?string $contractDocType = null): string
    {
        if ($moduleKey === 'contracts' && $contractDocType && $contractDocType !== 'other') {
            return "contracts/{$contractDocType}";
        }
        return $moduleKey;
    }

    // ── Folder creation ──────────────────────────────────────────────────────

    /**
     * Create the full standard folder structure for a project in storage/app.
     * Stores a .gitkeep placeholder in each folder so git tracks empty dirs.
     */
    public static function createProjectFolders(Project $project): void
    {
        $root = self::projectRoot($project);

        foreach (self::PROJECT_FOLDERS as $folder) {
            $path = "{$root}/{$folder}/.gitkeep";
            if (!Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, '');
            }
        }
    }

    // ── File storage ─────────────────────────────────────────────────────────

    /**
     * Produce the full storage path (including filename) for a new upload.
     *
     * Returns: 'suresign/{org_slug}/{project_slug}/{moduleFolder}/{uuid}.ext'
     */
    public static function buildFilePath(
        Project $project,
        string  $moduleKey,
        string  $extension,
        ?string $contractDocType = null
    ): string {
        $folder = self::modulePath($project, $moduleKey, $contractDocType);
        $uuid   = \Illuminate\Support\Str::uuid();
        return "{$folder}/{$uuid}.{$extension}";
    }

    // ── Read helpers ─────────────────────────────────────────────────────────

    /**
     * Return all module folder definitions (for API responses).
     */
    public static function moduleFolders(): array
    {
        return [
            ['key' => 'contracts',            'name' => '01 Contracts',             'number' => '01'],
            ['key' => 'commercial',            'name' => '02 Commercial',             'number' => '02'],
            ['key' => 'payment_applications',  'name' => '03 Payment Applications',   'number' => '03'],
            ['key' => 'variations',            'name' => '04 Variations',             'number' => '04'],
            ['key' => 'notices',               'name' => '05 Notices',                'number' => '05'],
            ['key' => 'rfis',                  'name' => '06 RFIs',                   'number' => '06'],
            ['key' => 'meetings',              'name' => '07 Meetings',               'number' => '07'],
            ['key' => 'qa_reports',            'name' => '08 QA Reports',             'number' => '08'],
            ['key' => 'snagging',              'name' => '09 Snagging',               'number' => '09'],
            ['key' => 'closeout',              'name' => '10 Closeout',               'number' => '10'],
            ['key' => 'adjudication',          'name' => '11 Adjudication',           'number' => '11'],
            ['key' => 'site_reports',          'name' => '12 Site Reports',           'number' => '12'],
            ['key' => 'ai_generated',          'name' => '13 AI Generated',           'number' => '13'],
        ];
    }

    /**
     * Contract sub-folder definitions (nested under 'contracts' module).
     */
    public static function contractSubFolders(): array
    {
        return [
            ['key' => 'contracts/main_contract',        'name' => 'Main Contract',          'parent' => 'contracts'],
            ['key' => 'contracts/subcontract',           'name' => 'Subcontracts',           'parent' => 'contracts'],
            ['key' => 'contracts/consultant_agreement',  'name' => 'Consultant Agreements',  'parent' => 'contracts'],
            ['key' => 'contracts/supplier_agreement',    'name' => 'Supplier Agreements',    'parent' => 'contracts'],
        ];
    }
}
