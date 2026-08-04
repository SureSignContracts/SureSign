<?php

namespace App\Models;

use App\Support\Organizations\DomainStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationDomain extends Model
{
    protected $fillable = [
        'organization_id', 'hostname', 'status', 'verification_token', 'verification_method',
        'last_checked_at', 'last_check_result', 'verified_at', 'activated_at', 'disabled_at', 'removed_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
        'verified_at' => 'datetime',
        'activated_at' => 'datetime',
        'disabled_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    /**
     * Never expose the verification token or raw last_check_result to any
     * customer-facing surface by accident — callers building a
     * customer-facing payload must whitelist fields explicitly (mirrors
     * AiAnalysisPresenter's customerFacing()/internal() discipline)
     * rather than relying on $hidden, since Super Admin tooling legitimately
     * needs the token. This is a reminder, not an enforcement mechanism.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isVerified(): bool
    {
        return in_array($this->status, [DomainStatus::VERIFIED, DomainStatus::ACTIVE], true);
    }

    public function isActive(): bool
    {
        return $this->status === DomainStatus::ACTIVE;
    }
}
