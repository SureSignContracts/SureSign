<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SupportTicket extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'reference', 'subject', 'category', 'priority', 'message', 'status', 'resolved_at',
        'route', 'module', 'project_id', 'trade_package_id', 'diagnostics', 'recent_activity',
        'client_last_read_at', 'support_last_read_at',
    ];

    protected $casts = [
        'resolved_at'           => 'datetime',
        'client_last_read_at'   => 'datetime',
        'support_last_read_at'  => 'datetime',
        'diagnostics'           => 'array',
        'recent_activity'       => 'array',
    ];

    public function organization()  { return $this->belongsTo(Organization::class); }
    public function user()          { return $this->belongsTo(User::class); }
    public function project()      { return $this->belongsTo(Project::class); }
    public function tradePackage() { return $this->belongsTo(TradePackage::class); }

    /** At most one screenshot is ever attached — see SupportTicketController::store(). */
    public function screenshot() { return $this->morphOne(FileUpload::class, 'attachable'); }

    /** Full thread, chronological — visibility filtering (internal notes) happens at the controller/presenter layer, not here. */
    public function messages() { return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at'); }

    /** The single most recent client-visible reply — for list-view previews, without loading the whole thread per row. */
    public function latestPublicMessage()
    {
        return $this->hasOne(SupportTicketMessage::class)
            ->where('visibility', SupportTicketMessage::VISIBILITY_PUBLIC)
            ->latestOfMany();
    }

    /**
     * No destroy() endpoint exists for support tickets today, so this never
     * fires in the current product — it exists so that whenever ticket
     * deletion IS eventually added (or a ticket is removed via tinker/a
     * script), the private screenshot never gets left behind as an orphan.
     * Deliberately deletes the physical file too, unlike the "soft delete,
     * keep the file" convention DocumentController::destroy()/destroyFile()
     * use for project documents — those are retained for audit/compliance
     * value a support screenshot doesn't have.
     *
     * Does NOT fire for organization deletion: support_tickets.organization_id
     * has a DB-level ON DELETE CASCADE, and a raw FK cascade bypasses Eloquent
     * model events entirely. An org-deletion cleanup path, if ever built,
     * would need to separately find and remove any orphaned file_uploads rows.
     */
    protected static function booted(): void
    {
        static::deleting(function (SupportTicket $ticket) {
            $upload = $ticket->screenshot;

            if ($upload) {
                Storage::disk($upload->disk)->delete($upload->file_path);
                $upload->delete();
            }
        });
    }
}
