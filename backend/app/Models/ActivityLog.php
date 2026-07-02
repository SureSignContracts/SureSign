<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class ActivityLog extends Model
{
    protected $fillable = [
        'user_id', 'organization_id', 'project_id',
        'subject_type', 'subject_id',
        'action', 'description', 'metadata', 'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user()    { return $this->belongsTo(User::class); }
    public function project() { return $this->belongsTo(Project::class); }

    /**
     * Record an activity. Safe to call anywhere — never throws.
     */
    public static function record(
        string  $action,
        string  $description,
        ?User   $user    = null,
        ?Model  $subject = null,
        array   $meta    = [],
        ?int    $projectId = null,
        ?int    $organizationId = null
    ): void {
        try {
            static::create([
                'user_id'         => $user?->id,
                'organization_id' => $organizationId ?? $user?->organization_id,
                'project_id'      => $projectId,
                'subject_type'    => $subject ? get_class($subject) : null,
                'subject_id'      => $subject?->id,
                'action'          => $action,
                'description'     => $description,
                'metadata'        => $meta ?: null,
                'ip_address'      => Request::ip(),
            ]);
        } catch (\Throwable) {
            // Never let audit logging break the main flow
        }
    }
}
