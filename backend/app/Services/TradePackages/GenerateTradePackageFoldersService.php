<?php

namespace App\Services\TradePackages;

use App\Models\Project;
use App\Models\TradePackage;
use App\Services\ProjectStorageService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateTradePackageFoldersService
{
    /**
     * Generate trade packages for a project from the given array of package definitions.
     *
     * Each entry in $packages should have:
     *   - name        (string, required)
     *   - pkg_code    (string, optional — resolved from standard map or generated)
     *   - is_custom   (bool, optional)
     *   - original_name (string, optional)
     *
     * @param  Project $project
     * @param  array   $packages
     * @param  int     $userId
     * @return array{ created: string[], skipped: string[] }
     */
    public function generate(Project $project, array $packages, int $userId): array
    {
        $created = [];
        $skipped = [];

        foreach ($packages as $packageDef) {
            $name      = trim($packageDef['name'] ?? '');
            $isCustom  = (bool) ($packageDef['is_custom'] ?? false);
            $origName  = $packageDef['original_name'] ?? null;

            if (empty($name)) {
                continue;
            }

            // Skip if a trade package with the same name already exists in this project
            $exists = TradePackage::where('project_id', $project->id)
                ->where('name', $name)
                ->exists();

            if ($exists) {
                $skipped[] = $name;
                continue;
            }

            $pkgCode = $this->resolveCode($name, $packageDef['pkg_code'] ?? null, $isCustom, $project->id);

            $tradePackage = TradePackage::create([
                'organization_id' => $project->organization_id,
                'project_id'      => $project->id,
                'name'            => $name,
                'slug'            => TradePackage::makeSlug($name, $project->id),
                'package_code'    => $pkgCode,
                'package_reference' => $pkgCode,
                'status'          => 'active',
                'created_by'      => $userId,
                'is_custom'       => $isCustom,
                'created_by_user' => true,
                'original_name'   => $origName,
                'source_type'     => $isCustom ? 'custom' : 'standard',
            ]);

            $tradePackage->createStandardFolders();

            $this->createStorageFolders($project, $tradePackage);

            $created[] = $name;
        }

        return compact('created', 'skipped');
    }

    /**
     * Resolve a package code from the provided value, standard map, or initials.
     * Deduplicates against existing codes in the project.
     */
    private function resolveCode(string $name, ?string $providedCode, bool $isCustom, int $projectId): string
    {
        // Use provided code if given
        $base = $providedCode
            ? strtoupper(trim($providedCode))
            : (TradePackageCatalogueService::codeForName($name) ?? $this->initialsCode($name));

        return $this->uniqueCode($base, $projectId);
    }

    /**
     * Generate a code from the initials of each word in the name.
     */
    private function initialsCode(string $name): string
    {
        $words    = preg_split('/[\s&]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $initials = array_map(fn($w) => strtoupper($w[0]), $words);
        return implode('', $initials) ?: 'XX';
    }

    /**
     * Ensure the code is unique within the project, appending -01, -02 etc. if needed.
     */
    private function uniqueCode(string $base, int $projectId): string
    {
        $code = $base;
        $i    = 1;

        while (TradePackage::where('project_id', $projectId)->where('package_code', $code)->exists()) {
            $code = "{$base}-" . str_pad($i, 2, '0', STR_PAD_LEFT);
            $i++;
        }

        return $code;
    }

    /**
     * Create the physical storage folder structure for the trade package.
     */
    private function createStorageFolders(Project $project, TradePackage $tradePackage): void
    {
        $baseDir = ProjectStorageService::modulePath($project, 'subcontracts')
            . '/' . \App\Services\SureSignFolderPathService::sanitizeSegment($tradePackage->name);

        foreach ($tradePackage->folders as $folder) {
            $folderPath = $baseDir . '/' . $folder->name . '/.gitkeep';
            if (!Storage::disk('local')->exists($folderPath)) {
                Storage::disk('local')->put($folderPath, '');
            }
        }
    }
}
