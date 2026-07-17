<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class SupportTicketMessage extends Model
{
    public const SENDER_CUSTOMER = 'customer';
    public const SENDER_SUPPORT = 'support';

    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_INTERNAL = 'internal';

    protected $fillable = ['support_ticket_id', 'user_id', 'sender_type', 'body', 'visibility'];

    public function supportTicket() { return $this->belongsTo(SupportTicket::class); }
    public function user()          { return $this->belongsTo(User::class); }

    /** At most one screenshot per reply — same one-per-message convention as SupportTicket::screenshot(). */
    public function screenshot() { return $this->morphOne(FileUpload::class, 'attachable'); }

    public function isInternal(): bool
    {
        return $this->visibility === self::VISIBILITY_INTERNAL;
    }

    /** Mirrors SupportTicket::booted() — a reply's screenshot is a diagnostic aid, not an audit record, so it's hard-deleted with the message. */
    protected static function booted(): void
    {
        static::deleting(function (SupportTicketMessage $message) {
            $upload = $message->screenshot;

            if ($upload) {
                Storage::disk($upload->disk)->delete($upload->file_path);
                $upload->delete();
            }
        });
    }
}
