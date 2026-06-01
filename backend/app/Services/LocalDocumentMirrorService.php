<?php

namespace App\Services;

use App\Models\AdjudicationDocument;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * LocalDocumentMirrorService
 * ──────────────────────────
 * Mirrors uploaded and generated SureSign files to a configured local
 * directory (e.g. ~/Documents/SureSign) so desktop tools like Claude/Cowork
 * can access project documents.
 *
 * SOURCE OF TRUTH: Laravel storage (storage/app/suresign/…)
 * MIRROR:          {local_export_path}/{Company}/{Project}/{Module Folder}/
 *
 * Safety contract
 * ───────────────
 *  • Never throws — all public methods are catch-all safe.
 *  • If mirror fails, the original upload is NOT affected.
 *  • Folder names are sanitized via SureSignFolderPathService::sanitizeSegment().
 *  • Path traversal is prevented: no user input reaches the filesystem directly.
 *  • Only Super Admin can configure the mirror path (enforced in the controller).
 *
 * Docker note
 * ───────────
 * Inside a container, set SURESIGN_LOCAL_MIRROR_PATH to the container-side
 * mount point (e.g. /var/www/html/storage/app/local-mirror/SureSign).
 * Map that path to the host Documents folder via a docker-compose volume:
 *   - "C:/Users/Admin/Documents/SureSign:/var/www/html/storage/app/local-mirror/SureSign"
 */
class LocalDocumentMirrorService
{
    // ── Configuration ─────────────────────────────────────────────────────────

    /**
     * Determine whether mirroring is currently enabled.
     * DB settings take precedence over env/config so Super Admin can
     * toggle the feature at runtime without a deployment.
     */
    public static function isEnabled(): bool
    {
        $settings = self::settings();
        if ($settings !== null) {
            return (bool) $settings->local_export_enabled;
        }
        // Fall back to env config
        return (bool) config('suresign.local_mirror_enabled', false);
    }

    /**
     * Return the configured mirror root path.
     * DB value takes precedence over env config.
     */
    public static function getMirrorPath(): ?string
    {
        $settings = self::settings();
        if ($settings && !empty($settings->local_export_path)) {
            return $settings->local_export_path;
        }
        $env = config('suresign.local_mirror_path');
        return !empty($env) ? $env : null;
    }

    // ── Public mirror methods ─────────────────────────────────────────────────

    /**
     * Mirror an uploaded FileUpload record to the configured local path.
     *
     * Maps the stored file path back to the correct module folder using the
     * file's module_key and folder_key (for contract sub-folders).
     * Updates mirror_status / mirror_path / mirrored_at on the record.
     *
     * @param  FileUpload  $upload   The persisted FileUpload record
     * @param  Project     $project  The owning project
     * @return bool  true = mirrored successfully, false = skipped or failed
     */
    public static function mirrorFileUpload(FileUpload $upload, Project $project): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $mirrorRoot = self::getMirrorPath();
        if (empty($mirrorRoot)) {
            Log::warning('[Mirror] Enabled but no mirror path is configured.');
            return false;
        }

        try {
            [$orgSegment, $projectSegment] = self::orgProjectSegments($project);

            // Determine module folder from module_key + optional folder_key
            $moduleKey  = $upload->module_key  ?? 'general';
            $folderKey  = $upload->folder_key  ?? $moduleKey;
            $moduleDir  = self::resolveModuleDir($moduleKey, $folderKey, $upload);

            $mirrorDir  = self::buildAbsPath($mirrorRoot, $orgSegment, $projectSegment, $moduleDir);
            $mirrorFile = $mirrorDir . DIRECTORY_SEPARATOR . self::safeFileName($upload->original_name);

            self::ensureDir($mirrorDir);
            self::writeFromStorage($upload->file_path, $mirrorFile);

            $upload->update([
                'mirror_status' => 'mirrored',
                'mirror_path'   => $mirrorFile,
                'mirrored_at'   => now(),
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::warning("[Mirror] FileUpload #{$upload->id}: {$e->getMessage()}");
            $upload->update(['mirror_status' => 'failed']);
            return false;
        }
    }

    /**
     * Mirror a generated Document (PDF etc.) to the appropriate module folder.
     *
     * The Document's `type` field (e.g. 'payment_app', 'variation') is mapped
     * to the physical folder name via SureSignFolderPathService::moduleKeyToFolderName().
     *
     * @param  Document  $document
     * @param  Project   $project
     * @return bool
     */
    public static function mirrorDocument(Document $document, Project $project): bool
    {
        if (!self::isEnabled()) {
            return false;
        }

        $mirrorRoot = self::getMirrorPath();
        if (empty($mirrorRoot)) {
            return false;
        }

        try {
            [$orgSegment, $projectSegment] = self::orgProjectSegments($project);

            $moduleDir  = SureSignFolderPathService::moduleKeyToFolderName($document->type ?? 'general');
            $mirrorDir  = self::buildAbsPath($mirrorRoot, $orgSegment, $projectSegment, $moduleDir);
            $fileName   = self::safeFileName($document->file_name ?? basename($document->file_path ?? ''));
            $mirrorFile = $mirrorDir . DIRECTORY_SEPARATOR . $fileName;

            self::ensureDir($mirrorDir);
            self::writeFromStorage($document->file_path, $mirrorFile);

            $document->update([
                'mirror_status' => 'mirrored',
                'mirror_path'   => $mirrorFile,
                'mirrored_at'   => now(),
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::warning("[Mirror] Document #{$document->id}: {$e->getMessage()}");
            $document->update(['mirror_status' => 'failed']);
            return false;
        }
    }

    /**
     * Mirror an AdjudicationDocument to the 11_Adjudication folder.
     *
     * @param  AdjudicationDocument  $document
     * @param  Project               $project
     * @return bool
     */
    public static function mirrorAdjudicationDocument(
        AdjudicationDocument $document,
        Project $project
    ): bool {
        if (!self::isEnabled()) {
            return false;
        }

        $mirrorRoot = self::getMirrorPath();
        if (empty($mirrorRoot)) {
            return false;
        }

        try {
            [$orgSegment, $projectSegment] = self::orgProjectSegments($project);

            $mirrorDir  = self::buildAbsPath($mirrorRoot, $orgSegment, $projectSegment, '11_Adjudication');
            $fileName   = self::safeFileName($document->file_name ?? basename($document->file_path ?? ''));
            $mirrorFile = $mirrorDir . DIRECTORY_SEPARATOR . $fileName;

            self::ensureDir($mirrorDir);
            self::writeFromStorage($document->file_path, $mirrorFile);

            $document->update([
                'mirror_status' => 'mirrored',
                'mirror_path'   => $mirrorFile,
                'mirrored_at'   => now(),
            ]);

            return true;

        } catch (\Throwable $e) {
            Log::warning("[Mirror] AdjudicationDocument #{$document->id}: {$e->getMessage()}");
            $document->update(['mirror_status' => 'failed']);
            return false;
        }
    }

    /**
     * Pre-create the full mirror folder tree for a newly created project.
     * Called during project creation — never fails the project creation on error.
     *
     * @param  Project  $project
     */
    public static function createProjectFolders(Project $project): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $mirrorRoot = self::getMirrorPath();
        if (empty($mirrorRoot)) {
            return;
        }

        try {
            [$orgSegment, $projectSegment] = self::orgProjectSegments($project);

            foreach (SureSignFolderPathService::allMirrorFolders() as $folder) {
                $absPath = self::buildAbsPath($mirrorRoot, $orgSegment, $projectSegment, $folder);
                self::ensureDir($absPath);
            }

        } catch (\Throwable $e) {
            Log::warning("[Mirror] Could not create project folders for project #{$project->id}: {$e->getMessage()}");
        }
    }

    /**
     * Test whether a given path is writable without mirroring anything.
     * Used by the admin "Test Path" UI button.
     *
     * @param  string  $path  The absolute path to test
     * @return array{ok: bool, message: string}
     */
    public static function testPath(string $path): array
    {
        // Security: prevent path traversal or suspicious inputs
        if (str_contains($path, '..')) {
            return ['ok' => false, 'message' => 'Path must not contain ".."'];
        }

        try {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }

            $testFile = rtrim($path, '/\\') . DIRECTORY_SEPARATOR . '.suresign-writetest-' . time();
            file_put_contents($testFile, 'ok');
            unlink($testFile);

            return ['ok' => true, 'message' => 'Path is writable.'];

        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Path is not writable: ' . $e->getMessage()];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Return the SuresignSetting singleton (cached after first call per request).
     */
    private static ?SuresignSetting $settingsCache = null;

    private static function settings(): ?SuresignSetting
    {
        if (self::$settingsCache === null) {
            self::$settingsCache = SuresignSetting::first();
        }
        return self::$settingsCache;
    }

    /**
     * Build sanitized [orgSegment, projectSegment] from a Project.
     */
    private static function orgProjectSegments(Project $project): array
    {
        $org         = $project->organization ?? Organization::find($project->organization_id);
        $orgName     = $org ? $org->name : "org-{$project->organization_id}";
        $orgSegment  = SureSignFolderPathService::sanitizeSegment($orgName);

        $projectSegment = SureSignFolderPathService::projectFolderName(
            $project->name,
            $project->code
        );

        return [$orgSegment, $projectSegment];
    }

    /**
     * Resolve the module directory path (relative, may include a sub-folder)
     * from a module_key and folder_key.
     *
     * For trade package files, routes into Subcontracts/{Package Name}/ instead of
     * the generic Subcontracts/ folder.
     *
     * Examples:
     *   module_key='contracts', folder_key='contracts/main_contract'  → '01_Contracts/Main Contract'
     *   module_key='contracts', folder_key='contracts/subcontract', trade_package_id set
     *                                                                → '01_Contracts/Subcontracts/{Package Name}'
     *   module_key='variations', folder_key='variations'              → '04_Variations'
     */
    private static function resolveModuleDir(string $moduleKey, string $folderKey, ?FileUpload $upload = null): string
    {
        $moduleDir = SureSignFolderPathService::moduleKeyToFolderName($moduleKey);

        if ($moduleKey === 'contracts' && str_contains($folderKey, '/')) {
            $contractDocType = explode('/', $folderKey, 2)[1];

            // Trade package files → route into the package folder directly
            if ($contractDocType === 'subcontract' && $upload && $upload->trade_package_id) {
                $package = \App\Models\TradePackage::find($upload->trade_package_id);
                if ($package) {
                    $packageFolder = SureSignFolderPathService::sanitizeSegment($package->name);
                    return $moduleDir . DIRECTORY_SEPARATOR . 'Subcontracts' . DIRECTORY_SEPARATOR . $packageFolder;
                }
            }

            $subFolder = SureSignFolderPathService::contractSubFolderName($contractDocType);
            return $moduleDir . DIRECTORY_SEPARATOR . $subFolder;
        }

        return $moduleDir;
    }

    /**
     * Assemble an absolute OS path from root + org + project + sub-path.
     * Sub-path forward-slashes are converted to DIRECTORY_SEPARATOR.
     */
    private static function buildAbsPath(
        string $root,
        string $orgSegment,
        string $projectSegment,
        string $subPath
    ): string {
        $subPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $subPath);
        return rtrim($root, '/\\')
            . DIRECTORY_SEPARATOR . $orgSegment
            . DIRECTORY_SEPARATOR . $projectSegment
            . DIRECTORY_SEPARATOR . $subPath;
    }

    /**
     * Create a directory (and all parents) if it does not already exist.
     */
    private static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }

    /**
     * Read a file from Laravel local storage and write it to an absolute path.
     */
    private static function writeFromStorage(string $storagePath, string $destination): void
    {
        $contents = Storage::disk('local')->get($storagePath);
        if ($contents === null) {
            throw new \RuntimeException("Source file not readable: {$storagePath}");
        }
        file_put_contents($destination, $contents);
    }

    /**
     * Ensure a file name segment is safe.
     * Falls back to 'file' if the name is empty after sanitization.
     */
    private static function safeFileName(string $name): string
    {
        $safe = SureSignFolderPathService::sanitizeSegment($name);
        return $safe ?: 'file';
    }
}
