<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SuresignSetting;
use App\Services\EmailNotificationService;
use App\Services\FileSecurityService;
use App\Services\NotificationService;
use App\Services\SupportTicketStatusService;
use App\Traits\AuthorizesSupportTickets;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The threaded conversation on a single support ticket. One shared
 * controller for both the Client and the platform-operator side (same
 * pattern SupportTicketController::show()/screenshot() already use) rather
 * than duplicating the authorization/creation logic across two controllers
 * — the role check inside each method decides what's allowed/returned, the
 * *access* rule itself (AuthorizesSupportTickets) is defined once.
 */
class SupportTicketMessageController extends Controller
{
    use AuthorizesSupportTickets;

    private const MAX_BODY_LENGTH = 5000;

    /** Same narrower-than-general-images allowlist as ticket screenshots. */
    private const SCREENSHOT_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];
    private const SCREENSHOT_MAX_KB = 5120;

    // Chronological thread. Internal notes are stripped out entirely for a
    // non-operator caller — not just hidden client-side — so they never
    // reach a Client API response in the first place.
    public function index(Request $request, SupportTicket $supportTicket)
    {
        $user = $request->user();
        $this->authorizeTicketAccess($user, $supportTicket);
        $isOperator = $this->isSupportOperator($user);

        if ($isOperator) {
            $supportTicket->update(['support_last_read_at' => now()]);
        } elseif ($user->id === $supportTicket->user_id) {
            $supportTicket->update(['client_last_read_at' => now()]);
        }

        $messages = $supportTicket->messages()
            ->when(!$isOperator, fn ($q) => $q->where('visibility', SupportTicketMessage::VISIBILITY_PUBLIC))
            ->with(['user:id,name', 'screenshot'])
            ->get()
            ->map(fn ($m) => $this->present($m, $isOperator));

        return response()->json(['data' => $messages]);
    }

    public function store(Request $request, SupportTicket $supportTicket)
    {
        $user = $request->user();
        $this->authorizeTicketAccess($user, $supportTicket);
        $isOperator = $this->isSupportOperator($user);

        $rules = [
            'body'       => 'required|string|max:'.self::MAX_BODY_LENGTH,
            'screenshot' => 'nullable|file|max:'.min(self::SCREENSHOT_MAX_KB, SuresignSetting::maxUploadKb()),
        ];
        // Only an operator may ever choose visibility — a Client request
        // body simply has no field for it, so 'visibility' below always
        // resolves to VISIBILITY_PUBLIC for a Client regardless of what a
        // crafted request tries to send (the rule is absent, so it's never
        // read from $validated for a non-operator at all).
        if ($isOperator) {
            $rules['visibility'] = 'nullable|string|in:public,internal';
        }
        $validated = $request->validate($rules);

        if (!$isOperator && !SupportTicketStatusService::canClientReply($supportTicket->status)) {
            throw ValidationException::withMessages([
                'status' => ['This request is closed. Please submit a new request if you need further help.'],
            ]);
        }

        $screenshotFile = $request->file('screenshot');
        if ($screenshotFile instanceof UploadedFile) {
            FileSecurityService::assertSafe($screenshotFile, self::SCREENSHOT_EXTENSIONS);
        }

        $visibility = $isOperator ? ($validated['visibility'] ?? SupportTicketMessage::VISIBILITY_PUBLIC) : SupportTicketMessage::VISIBILITY_PUBLIC;
        $senderType = $isOperator ? SupportTicketMessage::SENDER_SUPPORT : SupportTicketMessage::SENDER_CUSTOMER;

        $storedScreenshotPath = null;

        try {
            $message = DB::transaction(function () use ($supportTicket, $user, $validated, $visibility, $senderType, $isOperator, $screenshotFile, &$storedScreenshotPath) {
                $message = SupportTicketMessage::create([
                    'support_ticket_id' => $supportTicket->id,
                    'user_id'           => $user->id,
                    'sender_type'       => $senderType,
                    'body'              => $validated['body'],
                    'visibility'        => $visibility,
                ]);

                if ($screenshotFile instanceof UploadedFile) {
                    $storedName = FileSecurityService::randomStorageName($screenshotFile);
                    $storedScreenshotPath = "support-tickets/{$supportTicket->id}/messages/{$message->id}/{$storedName}";

                    Storage::disk('local')->put($storedScreenshotPath, file_get_contents($screenshotFile->getRealPath()));

                    FileUpload::create([
                        'project_id'       => $supportTicket->project_id,
                        'organization_id'  => $supportTicket->organization_id,
                        'uploaded_by'      => $user->id,
                        'attachable_type'  => SupportTicketMessage::class,
                        'attachable_id'    => $message->id,
                        'trade_package_id' => $supportTicket->trade_package_id,
                        'original_name'    => FileSecurityService::sanitizeDisplayName($screenshotFile->getClientOriginalName()),
                        'stored_name'      => $storedName,
                        'file_path'        => $storedScreenshotPath,
                        'mime_type'        => $screenshotFile->getMimeType(),
                        'file_size'        => $screenshotFile->getSize(),
                        'disk'             => 'local',
                    ]);
                }

                if ($isOperator) {
                    $supportTicket->support_last_read_at = now();
                    if ($visibility === SupportTicketMessage::VISIBILITY_PUBLIC) {
                        $next = SupportTicketStatusService::afterSupportReply($supportTicket->status);
                        if ($next) {
                            $supportTicket->status = $next;
                        }
                    }
                } else {
                    $supportTicket->client_last_read_at = now();
                    $supportTicket->status = SupportTicketStatusService::afterClientReply();
                }
                $supportTicket->save();

                return $message;
            });
        } catch (\Throwable $e) {
            if ($storedScreenshotPath && Storage::disk('local')->exists($storedScreenshotPath)) {
                Storage::disk('local')->delete($storedScreenshotPath);
            }
            throw $e;
        }

        $message->load('screenshot');

        if ($isOperator) {
            if ($visibility === SupportTicketMessage::VISIBILITY_PUBLIC && $supportTicket->user) {
                NotificationService::send(
                    $supportTicket->user,
                    NotificationService::SUPPORT_TICKET_REPLY,
                    'New reply to your support request',
                    "SureSign Support replied to \"{$supportTicket->subject}\" (Ref: {$supportTicket->reference}).",
                    ['ticket_id' => $supportTicket->id, 'reference' => $supportTicket->reference],
                    ['action_url' => "/app/help/support/{$supportTicket->id}"]
                );

                SupportTicketController::emailTicketOwner(
                    $supportTicket,
                    'New reply to your support request',
                    "SureSign Support replied to your request \"{$supportTicket->subject}\" (Ref: {$supportTicket->reference}).\n\nSign in to SureSign to view the reply and continue the conversation.",
                    "/app/help/support/{$supportTicket->id}",
                );
            }
        } else {
            SupportTicketController::notifySupportOperators(
                $supportTicket,
                NotificationService::SUPPORT_TICKET_CUSTOMER_REPLY,
                'Customer replied to a support request',
                "{$supportTicket->user?->name} replied to \"{$supportTicket->subject}\" (Ref: {$supportTicket->reference})."
            );
        }

        return response()->json(['data' => $this->present($message, $isOperator)], 201);
    }

    // Screenshot attached to a single reply — same authorization and
    // safe-inline-header handling as SupportTicketController::screenshot(),
    // just scoped to a message instead of the ticket's own top-level one.
    public function screenshot(Request $request, SupportTicketMessage $message)
    {
        $message->load('supportTicket');
        $this->authorizeTicketAccess($request->user(), $message->supportTicket);

        if (!$this->isSupportOperator($request->user()) && $message->isInternal()) {
            abort(404, 'File not found.');
        }

        $upload = $message->screenshot;

        if (!$upload || !Storage::disk($upload->disk)->exists($upload->file_path)) {
            abort(404, 'File not found.');
        }

        $safeInlineMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $headers = [
            'Content-Type'           => $upload->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (!in_array($upload->mime_type, $safeInlineMimes, true)) {
            $headers['Content-Disposition'] = 'attachment';
        }

        return response()->file(Storage::disk($upload->disk)->path($upload->file_path), $headers);
    }

    private function present(SupportTicketMessage $message, bool $forOperator): array
    {
        return [
            'id'          => $message->id,
            'sender_type' => $message->sender_type,
            'sender_name' => $message->user?->name,
            'body'        => $message->body,
            'created_at'  => $message->created_at,
            'visibility'  => $forOperator ? $message->visibility : SupportTicketMessage::VISIBILITY_PUBLIC,
            'screenshot'  => $message->screenshot ? [
                'id'          => $message->screenshot->id,
                'file_size'   => $message->screenshot->file_size,
                'mime_type'   => $message->screenshot->mime_type,
                'preview_url' => "/support-ticket-messages/{$message->id}/screenshot",
            ] : null,
        ];
    }
}
