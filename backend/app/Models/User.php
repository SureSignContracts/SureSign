<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'first_name', 'last_name',
        'email', 'phone', 'job_title', 'avatar', 'password',
        'address', 'city', 'province', 'postal_code', 'country',
        'is_active', 'last_login_at', 'email_verified_at',
        'banned_at', 'banned_reason', 'must_change_password', 'tours_reset_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at'     => 'datetime',
            'last_login_at'         => 'datetime',
            'password'              => 'hashed',
            'is_active'             => 'boolean',
            'banned_at'             => 'datetime',
            'must_change_password'  => 'boolean',
            'tours_reset_at'        => 'datetime',
        ];
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function projects()     { return $this->belongsToMany(Project::class, 'project_users')->withPivot('role')->withTimestamps(); }
    public function auditLogs()    { return $this->hasMany(AuditLog::class); }
    public function aiConversations() { return $this->hasMany(AiConversation::class); }
    public function projectActivities() { return $this->hasMany(ProjectActivity::class); }
}
