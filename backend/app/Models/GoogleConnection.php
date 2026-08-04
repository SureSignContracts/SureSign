<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Google Integration Foundation, Stage 4A — a real domain object for one
 * long-lived OAuth connection to Google, owned by the platform (not
 * Consultancy). See internal-docs/super-admin/google-integration.md.
 *
 * `access_token`/`refresh_token` are encrypted at rest via Laravel's
 * native `encrypted` cast — never logged, never returned in any API
 * response (see App\Support\Google\GoogleConnectionPresenter).
 */
class GoogleConnection extends Model
{
    protected $fillable = [
        'provider', 'purpose', 'status',
        'google_account_id', 'connected_email',
        'access_token', 'refresh_token', 'token_expires_at', 'scopes',
        'connected_at', 'last_refreshed_at', 'last_successful_call_at',
        'last_failed_call_at', 'last_failure_reason', 'consecutive_refresh_failures',
        'connected_by_user_id', 'disconnected_by_user_id', 'disconnected_at', 'revoked_at',
    ];

    protected $casts = [
        'access_token'    => 'encrypted',
        'refresh_token'   => 'encrypted',
        'scopes'          => 'array',
        'token_expires_at' => 'datetime',
        'connected_at'    => 'datetime',
        'last_refreshed_at' => 'datetime',
        'last_successful_call_at' => 'datetime',
        'last_failed_call_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'revoked_at'      => 'datetime',
        'consecutive_refresh_failures' => 'integer',
    ];

    public function connectedBy(): BelongsTo    { return $this->belongsTo(User::class, 'connected_by_user_id'); }
    public function disconnectedBy(): BelongsTo { return $this->belongsTo(User::class, 'disconnected_by_user_id'); }

    public function isActive(): bool
    {
        return $this->status === 'connected';
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at !== null && $this->token_expires_at->isPast();
    }
}
