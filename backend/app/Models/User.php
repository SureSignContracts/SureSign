<?php

namespace App\Models;

use App\Services\EmailNotificationService;
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
        'address', 'city', 'province', 'postal_code', 'country', 'timezone',
        'is_active', 'last_login_at', 'email_verified_at',
        'banned_at', 'banned_reason', 'must_change_password', 'tours_reset_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    // Mirrors the DB-level column defaults (see the users table migrations)
    // in the in-memory model — without this, a freshly `create()`d instance
    // reflects these attributes as `null` in PHP until the row is re-fetched
    // from the database (Eloquent doesn't read back column defaults after
    // INSERT), even though the actual stored row already has `is_active = 1`
    // / `must_change_password = 0`. That mismatch was previously invisible
    // since nothing checked these per-request; EnsureAccountIsActive now
    // does, so an in-memory `null` (falsy for is_active's `!$user->is_active`
    // check) would otherwise look identical to a genuinely deactivated user.
    protected $attributes = [
        'is_active'            => true,
        'must_change_password' => false,
    ];

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

    /**
     * Override the default mail-driven notification (MAIL_MAILER=log would
     * silently swallow it) so password resets go out through the same
     * Brevo pipeline as every other transactional email in the app.
     */
    public function sendPasswordResetNotification($token)
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $resetUrl    = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($this->email);

        EmailNotificationService::sendDirect(
            $this->email,
            'Reset your SureSign password',
            "We received a request to reset your SureSign password.\n\n"
                . "Reset it here: {$resetUrl}\n\n"
                . "This link expires in 60 minutes. If you didn't request this, you can safely ignore this email."
        );
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function projects()     { return $this->belongsToMany(Project::class, 'project_users')->withPivot('role')->withTimestamps(); }
    public function auditLogs()    { return $this->hasMany(AuditLog::class); }
    public function aiConversations() { return $this->hasMany(AiConversation::class); }
    public function projectActivities() { return $this->hasMany(ProjectActivity::class); }
}
