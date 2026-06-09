<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class TradePackage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'name',
        'slug',
        'package_code',
        'package_reference',
        'contractor_name',
        'description',
        'status',
        'created_by',
        'is_custom',
        'created_by_user',
        'original_name',
        'source_type',
    ];

    // ── Standard folders for every trade package ────────────────────────────

    public const STANDARD_FOLDERS = [
        ['key' => 'tender_enquiry',        'name' => '01 Tender Enquiry',        'sort_order' => 1],
        ['key' => 'schedule_of_documents', 'name' => '02 Schedule of Documents', 'sort_order' => 2],
        ['key' => 'drawings',              'name' => '03 Drawings',              'sort_order' => 3],
        ['key' => 'specifications',        'name' => '04 Specifications',        'sort_order' => 4],
        ['key' => 'pricing_documents',     'name' => '05 Pricing Documents',     'sort_order' => 5],
        ['key' => 'contract_draft',        'name' => '06 Contract Draft',        'sort_order' => 6],
        ['key' => 'correspondence',        'name' => '07 Correspondence',        'sort_order' => 7],
        ['key' => 'returned_tender',       'name' => '08 Returned Tender',       'sort_order' => 8],
        ['key' => 'executed_contract',     'name' => '09 Executed Contract',     'sort_order' => 9],
    ];

    // ── Relationships ───────────────────────────────────────────────────────

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function folders()
    {
        return $this->hasMany(TradePackageFolder::class)->orderBy('sort_order');
    }

    public function fileUploads()
    {
        return $this->hasMany(FileUpload::class, 'trade_package_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Generate a unique slug for a project, appending a counter if needed.
     */
    public static function makeSlug(string $name, int $projectId): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 1;

        while (static::where('project_id', $projectId)->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /**
     * Create all standard folders for this package.
     */
    public function createStandardFolders(): void
    {
        foreach (self::STANDARD_FOLDERS as $def) {
            $this->folders()->firstOrCreate(
                ['key' => $def['key']],
                ['name' => $def['name'], 'sort_order' => $def['sort_order']]
            );
        }
    }
}
