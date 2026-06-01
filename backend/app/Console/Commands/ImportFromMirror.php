<?php

namespace App\Console\Commands;

use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use App\Services\LocalDocumentMirrorService;
use App\Services\SureSignFolderPathService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ImportFromMirror
 * ────────────────
 * Scans the local mirror folder for files that were dropped there
 * manually (not by SureSign) and imports them back into SureSign.
 *
 * Path convention expected:
 *   {mirrorRoot}/{Org Folder}/{Project Folder}/{Module Folder}/{file}
 *   {mirrorRoot}/{Org Folder}/{Project Folder}/01_Contracts/{Sub Folder}/{file}
 *
 * Deduplication: files whose absolute path already exists in
 *   file_uploads.mirror_path are silently skipped.
 *
 * Usage:
 *   php artisan suresign:import-from-mirror
 *   php artisan suresign:import-from-mirror --dry-run
 */
class ImportFromMirror extends Command
{
    protected $signature   = 'suresign:import-from-mirror
                                {--dry-run : List what would be imported without writing anything}';
    protected $description = 'Import files dropped into the local mirror folder back into SureSign';

    // ── Entry point ───────────────────────────────────────────────────────────

    public function handle(): int
    {
        if (!LocalDocumentMirrorService::isEnabled()) {
            $this->warn('Local mirror is disabled. Enable it in Admin → SureSign settings.');
            return self::FAILURE;
        }

        $root = LocalDocumentMirrorService::getMirrorPath();
        if (!$root || !is_dir($root)) {
            $this->error("Mirror path not found or not a directory: {$root}");
            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');

        // Resolve a system user to attribute imported files to (Super Admin or first user)
        $systemUserId = User::role('Super Admin')->value('id')
            ?? User::orderBy('id')->value('id');

        // Pre-build lookup maps and known paths
        $orgMap     = $this->buildOrgMap();
        $projectMap = $this->buildProjectMap();

        // Reverse: '01_Contracts' → 'contracts', etc.
        // array_unique keeps first key, flip gives folder_name → module_key
        $moduleMap = [];
        foreach (SureSignFolderPathService::MODULE_FOLDER_MAP as $key => $folder) {
            if (!isset($moduleMap[$folder])) {
                $moduleMap[$folder] = $key;
            }
        }

        $contractSubMap = array_flip(SureSignFolderPathService::CONTRACT_SUBFOLDER_MAP);

        // Collect all mirror_paths already tracked to skip them cheaply
        $knownPaths = FileUpload::whereNotNull('mirror_path')
            ->pluck('mirror_path')
            ->flip()
            ->all();

        // Build a lookup: [projectId => [packageFolderName => TradePackage]]
        $tradePackageMap = $this->buildTradePackageMap();

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        // Walk: root/{org}/{project}/{module}/{file_or_subfolder}
        foreach (glob("{$root}/*", GLOB_ONLYDIR) ?: [] as $orgDir) {
            $orgKey = basename($orgDir);
            $org    = $orgMap[$orgKey] ?? null;

            foreach (glob("{$orgDir}/*", GLOB_ONLYDIR) ?: [] as $projectDir) {
                $projectKey = basename($projectDir);
                $project    = $projectMap[$orgKey][$projectKey] ?? null;

                foreach (glob("{$projectDir}/*", GLOB_ONLYDIR) ?: [] as $moduleDir) {
                    $moduleFolderName = basename($moduleDir);
                    $moduleKey        = $moduleMap[$moduleFolderName] ?? 'general';

                    // Special handling for Subcontracts: scan one level deeper for package folders
                    if ($moduleKey === 'contracts') {
                        foreach (glob("{$moduleDir}/*", GLOB_ONLYDIR) ?: [] as $contractSubDir) {
                            $subName = basename($contractSubDir);

                            if ($subName === 'Subcontracts') {
                                // New flat structure: Subcontracts/{PackageName}/{files}
                                $this->processSubcontractsDir(
                                    subcontractsDir: $contractSubDir,
                                    project: $project,
                                    org: $org,
                                    tradePackageMap: $tradePackageMap,
                                    knownPaths: $knownPaths,
                                    dryRun: $dryRun,
                                    imported: $imported,
                                    skipped: $skipped,
                                    errors: $errors,
                                    systemUserId: $systemUserId,
                                );
                                continue;
                            }

                            // Other contract sub-folders (Main Contract, etc.) — standard 1-level scan
                            $subFolderKey = $contractSubMap[$subName] ?? null;
                            $fullSubFolderKey = $subFolderKey ? "contracts/{$subFolderKey}" : null;
                            $this->processDir(
                                dir: $contractSubDir,
                                moduleKey: 'contracts',
                                folderKey: $fullSubFolderKey,
                                org: $org,
                                project: $project,
                                knownPaths: $knownPaths,
                                dryRun: $dryRun,
                                imported: $imported,
                                skipped: $skipped,
                                errors: $errors,
                                contractSubMap: $contractSubMap,
                                systemUserId: $systemUserId,
                            );
                        }
                        continue;
                    }

                    $this->processDir(
                        dir: $moduleDir,
                        moduleKey: $moduleKey,
                        folderKey: null,
                        org: $org,
                        project: $project,
                        knownPaths: $knownPaths,
                        dryRun: $dryRun,
                        imported: $imported,
                        skipped: $skipped,
                        errors: $errors,
                        contractSubMap: $contractSubMap,
                        systemUserId: $systemUserId,
                    );
                }
            }
        }

        $this->info("Import complete — imported: {$imported}, skipped: {$skipped}, errors: " . count($errors));

        foreach ($errors as $e) {
            $this->error("  {$e}");
        }

        return count($errors) === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ── Subcontracts walker ───────────────────────────────────────────────────

    /**
     * Process {mirrorRoot}/…/01_Contracts/Subcontracts/
     *
     * New flat structure: each immediate subdirectory is a trade package folder.
     * Files inside are imported directly into that trade package (no subfolder key).
     *
     * Also handles the "General Subcontract Files" pattern: any files sitting
     * directly inside the Subcontracts/ folder (not in a package subdirectory)
     * are imported with folder_key = 'contracts/subcontract' and no trade_package_id.
     */
    private function processSubcontractsDir(
        string $subcontractsDir,
        ?Organization $org,
        ?Project $project,
        array $tradePackageMap,
        array &$knownPaths,
        bool $dryRun,
        int &$imported,
        int &$skipped,
        array &$errors,
        ?int $systemUserId = null,
    ): void {
        $items = @scandir($subcontractsDir);
        if ($items === false) {
            $errors[] = "Cannot read directory: {$subcontractsDir}";
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (str_starts_with($item, '.')) continue;

            $fullPath = $subcontractsDir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                // This is a trade package folder (e.g. "Concrete Frame")
                $packageName = $item;
                $tradePackage = null;

                if ($project) {
                    $tradePackage = $tradePackageMap[$project->id][$packageName] ?? null;
                }

                $this->processTradePackageDir(
                    dir: $fullPath,
                    tradePackage: $tradePackage,
                    org: $org,
                    project: $project,
                    knownPaths: $knownPaths,
                    dryRun: $dryRun,
                    imported: $imported,
                    skipped: $skipped,
                    errors: $errors,
                    systemUserId: $systemUserId,
                );
                continue;
            }

            // Files directly in Subcontracts/ → general subcontract files
            if (!is_file($fullPath)) continue;
            if (isset($knownPaths[$fullPath])) { $skipped++; continue; }
            if (!$org || !$project) { $errors[] = "Skipped (unrecognised org/project): {$fullPath}"; $skipped++; continue; }

            if ($dryRun) {
                $this->line("  [dry-run] Would import (general subcontract): {$fullPath}");
                $imported++;
                continue;
            }

            try {
                $this->importFile($fullPath, $item, $org, $project, 'contracts', 'contracts/subcontract', $systemUserId);
                $knownPaths[$fullPath] = true;
                $imported++;
                $this->line('  Imported (general subcontract): ' . $item);
            } catch (\Throwable $e) {
                $errors[] = $item . ': ' . $e->getMessage();
            }
        }
    }

    /**
     * Process a single trade package folder: import all files directly into
     * the trade package (no subfolder key).
     *
     * Also handles the legacy structure where old 9-subfolder names exist
     * inside the package dir — it recurses one level and maps the folder name
     * to a document_type.
     */
    private function processTradePackageDir(
        string $dir,
        ?TradePackage $tradePackage,
        ?Organization $org,
        ?Project $project,
        array &$knownPaths,
        bool $dryRun,
        int &$imported,
        int &$skipped,
        array &$errors,
        ?int $systemUserId = null,
    ): void {
        // Legacy subfolder name → document_type mapping
        $legacyFolderMap = [
            '01 Tender Enquiry'        => 'tender_enquiry_letter',
            '01_Tender Enquiry'        => 'tender_enquiry_letter',
            '02 Schedule of Documents' => 'schedule_of_documents',
            '02_Schedule of Documents' => 'schedule_of_documents',
            '03 Drawings'              => 'drawings',
            '03_Drawings'              => 'drawings',
            '04 Specifications'        => 'specification',
            '04_Specifications'        => 'specification',
            '05 Pricing Documents'     => 'pricing_document',
            '05_Pricing Documents'     => 'pricing_document',
            '06 Contract Draft'        => 'subcontract_draft',
            '06_Contract Draft'        => 'subcontract_draft',
            '07 Correspondence'        => 'correspondence',
            '07_Correspondence'        => 'correspondence',
            '08 Returned Tender'       => 'returned_tender',
            '08_Returned Tender'       => 'returned_tender',
            '09 Executed Contract'     => 'executed_contract',
            '09_Executed Contract'     => 'executed_contract',
        ];

        $items = @scandir($dir);
        if ($items === false) {
            $errors[] = "Cannot read directory: {$dir}";
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (str_starts_with($item, '.')) continue;

            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                // Legacy subfolder — recurse and import with mapped document_type
                $documentType = $legacyFolderMap[$item] ?? null;
                $subItems = @scandir($fullPath) ?: [];
                foreach ($subItems as $subItem) {
                    if ($subItem === '.' || $subItem === '..') continue;
                    if (str_starts_with($subItem, '.')) continue;
                    $subPath = $fullPath . DIRECTORY_SEPARATOR . $subItem;
                    if (!is_file($subPath)) continue;
                    if (isset($knownPaths[$subPath])) { $skipped++; continue; }
                    if (!$org || !$project) { $errors[] = "Skipped (unrecognised org/project): {$subPath}"; $skipped++; continue; }

                    if ($dryRun) {
                        $this->line("  [dry-run] Would import (legacy subfolder {$item}): {$subPath}");
                        $imported++;
                        continue;
                    }

                    try {
                        $this->importFile(
                            $subPath, $subItem, $org, $project, 'contracts', 'contracts/subcontract',
                            $systemUserId, $tradePackage?->id, $documentType
                        );
                        $knownPaths[$subPath] = true;
                        $imported++;
                        $this->line("  Imported (legacy {$item}): {$subItem}");
                    } catch (\Throwable $e) {
                        $errors[] = $subItem . ': ' . $e->getMessage();
                    }
                }
                continue;
            }

            // Direct file in package folder
            if (!is_file($fullPath)) continue;
            if (isset($knownPaths[$fullPath])) { $skipped++; continue; }
            if (!$org || !$project) { $errors[] = "Skipped (unrecognised org/project): {$fullPath}"; $skipped++; continue; }

            if ($dryRun) {
                $this->line("  [dry-run] Would import (trade package): {$fullPath}");
                $imported++;
                continue;
            }

            try {
                $this->importFile(
                    $fullPath, $item, $org, $project, 'contracts', 'contracts/subcontract',
                    $systemUserId, $tradePackage?->id
                );
                $knownPaths[$fullPath] = true;
                $imported++;
                $this->line('  Imported (trade package): ' . $item);
            } catch (\Throwable $e) {
                $errors[] = $item . ': ' . $e->getMessage();
            }
        }
    }

    // ── Directory walker ──────────────────────────────────────────────────────

    /**
     * Process all items in $dir. Recurses one level deep for contract sub-folders.
     */
    private function processDir(
        string $dir,
        string $moduleKey,
        ?string $folderKey,
        ?Organization $org,
        ?Project $project,
        array &$knownPaths,
        bool $dryRun,
        int &$imported,
        int &$skipped,
        array &$errors,
        array $contractSubMap,
        ?int $systemUserId = null,
        int $depth = 0,
    ): void {
        $items = @scandir($dir);
        if ($items === false) {
            $errors[] = "Cannot read directory: {$dir}";
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            if (str_starts_with($item, '.'))    continue; // hidden files

            $fullPath = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                // Recurse into contract sub-folders (one level only)
                if ($depth === 0) {
                    $subFolderKey = $contractSubMap[$item] ?? null;
                    // Prefix with module key to match the format used by ProjectStorageService
                    // e.g. 'subcontract' → 'contracts/subcontract'
                    $fullSubFolderKey = $subFolderKey ? "{$moduleKey}/{$subFolderKey}" : null;
                    $this->processDir(
                        dir: $fullPath,
                        moduleKey: $moduleKey,
                        folderKey: $fullSubFolderKey,
                        org: $org,
                        project: $project,
                        knownPaths: $knownPaths,
                        dryRun: $dryRun,
                        imported: $imported,
                        skipped: $skipped,
                        errors: $errors,
                        contractSubMap: $contractSubMap,
                        systemUserId: $systemUserId,
                        depth: $depth + 1,
                    );
                }
                continue;
            }

            if (!is_file($fullPath)) continue;

            // Already tracked in SureSign
            if (isset($knownPaths[$fullPath])) {
                $skipped++;
                continue;
            }

            if (!$org || !$project) {
                $errors[] = "Skipped (unrecognised org/project path): {$fullPath}";
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] Would import: {$fullPath}");
                $imported++;
                continue;
            }

            try {
                $this->importFile($fullPath, $item, $org, $project, $moduleKey, $folderKey, $systemUserId);
                // Add to known paths so duplicate files in the same run are skipped
                $knownPaths[$fullPath] = true;
                $imported++;
                $this->line('  Imported: ' . $item);
            } catch (\Throwable $e) {
                $errors[] = $item . ': ' . $e->getMessage();
            }
        }
    }

    // ── File importer ─────────────────────────────────────────────────────────

    private function importFile(
        string $absolutePath,
        string $originalName,
        Organization $org,
        Project $project,
        string $moduleKey,
        ?string $folderKey,
        ?int $uploadedBy = null,
        ?int $tradePackageId = null,
        ?string $documentType = null,
    ): void {
        $ext         = pathinfo($originalName, PATHINFO_EXTENSION);
        $uuid        = (string) Str::uuid();
        $storedName  = $ext ? "{$uuid}.{$ext}" : $uuid;
        $storagePath = "projects/{$project->id}/{$moduleKey}/{$storedName}";

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new \RuntimeException("Cannot read file: {$absolutePath}");
        }

        Storage::disk('local')->put($storagePath, $contents);

        $mime = @mime_content_type($absolutePath) ?: 'application/octet-stream';
        $size = filesize($absolutePath);

        FileUpload::create([
            'project_id'       => $project->id,
            'organization_id'  => $org->id,
            'uploaded_by'      => $uploadedBy,
            'original_name'    => $originalName,
            'stored_name'      => $storedName,
            'file_path'        => $storagePath,
            'mime_type'        => $mime,
            'file_size'        => $size,
            'disk'             => 'local',
            'module_key'       => $moduleKey,
            'folder_key'       => $folderKey,
            'trade_package_id' => $tradePackageId,
            'document_type'    => $documentType,
            'source_type'      => 'local_import',
            'status'           => 'active',
            'mirror_status'    => 'mirrored',
            'mirror_path'      => $absolutePath,
            'mirrored_at'      => now(),
        ]);
    }

    // ── Lookup map builders ───────────────────────────────────────────────────

    /**
     * Returns ['Sanitized Org Folder Name' => Organization]
     */
    private function buildOrgMap(): array
    {
        $map = [];
        foreach (Organization::all() as $org) {
            $key       = SureSignFolderPathService::sanitizeSegment($org->name);
            $map[$key] = $org;
        }
        return $map;
    }

    /**
     * Returns ['Sanitized Org Key' => ['Sanitized Project Folder Name' => Project]]
     */
    private function buildProjectMap(): array
    {
        $map = [];
        foreach (Project::with('organization')->get() as $project) {
            $orgKey  = SureSignFolderPathService::sanitizeSegment($project->organization->name ?? '');
            $projKey = SureSignFolderPathService::projectFolderName($project->name, $project->code);
            $map[$orgKey][$projKey] = $project;
        }
        return $map;
    }

    /**
     * Returns [projectId => ['Trade Package Name' => TradePackage]]
     * Used to match local folder names to DB trade packages.
     */
    private function buildTradePackageMap(): array
    {
        $map = [];
        foreach (TradePackage::all() as $pkg) {
            $folderName = SureSignFolderPathService::sanitizeSegment($pkg->name);
            $map[$pkg->project_id][$folderName] = $pkg;
        }
        return $map;
    }
}
