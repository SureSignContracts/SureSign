<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Project;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SuresignSetting;
use App\Models\TradePackage;
use App\Models\User;
use App\Services\EmailNotificationService;
use App\Services\FileSecurityService;
use App\Services\NotificationService;
use App\Support\Email\EmailComponents;
use App\Services\RecentActivityService;
use App\Services\SupportTicketStatusService;
use App\Traits\AuthorizesSupportTickets;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupportTicketController extends Controller
{
    use AuthorizesSupportTickets;

    public const CATEGORIES = [
        'technical_issue',
        'account_access',
        'project_or_contract_issue',
        'document_or_file_issue',
        'ai_analysis_issue',
        'commercial_workflow_issue',
        'billing_or_subscription',
        'feature_request',
        'other',
    ];

    /** Screenshots only — deliberately narrower than FileSecurityService::IMAGES (no gif/svg). */
    private const SCREENSHOT_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** Hard ceiling regardless of the org's general document-upload setting (KB). */
    private const SCREENSHOT_MAX_KB = 5120;

    /**
     * Diagnostic fields the frontend is allowed to send — anything else in
     * the submitted array is silently dropped. This is a client-supplied
     * payload (browser/OS/viewport are not verifiable server-side facts), so
     * the allowlist exists to bound *what kind* of data a screenshot-and-
     * diagnostics feature can ever persist or email, not to imply the values
     * are authoritative.
     */
    private const DIAGNOSTIC_FIELDS = [
        'browser', 'os', 'viewport_width', 'viewport_height', 'language', 'timezone', 'app_version',
    ];

    // Any authenticated user can submit a ticket for their own organization.
    // User/organization identity is always taken from the authenticated
    // request, never from client-supplied fields.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'                     => 'required|string|max:255',
            'message'                     => 'required|string|max:5000',
            'category'                    => 'nullable|string|in:'.implode(',', self::CATEGORIES),
            'priority'                    => 'nullable|string|in:low,normal,high',
            'route'                       => 'nullable|string|max:255',
            'module'                      => 'nullable|string|max:100',
            'project_id'                  => 'nullable|integer|exists:projects,id',
            'trade_package_id'            => 'nullable|integer|exists:trade_packages,id',
            'include_diagnostics'         => 'nullable|boolean',
            'diagnostics'                 => 'nullable|array',
            'diagnostics.browser'         => 'nullable|string|max:100',
            'diagnostics.os'              => 'nullable|string|max:100',
            'diagnostics.viewport_width'  => 'nullable|integer|min:0|max:20000',
            'diagnostics.viewport_height' => 'nullable|integer|min:0|max:20000',
            'diagnostics.language'        => 'nullable|string|max:35',
            'diagnostics.timezone'        => 'nullable|string|max:100',
            'diagnostics.app_version'     => 'nullable|string|max:50',
            'screenshot'                  => 'nullable|file|max:'.min(self::SCREENSHOT_MAX_KB, SuresignSetting::maxUploadKb()),
            // Boolean opt-in only — note there is deliberately no
            // "activity" input field at all. The frontend never sends the
            // activity entries themselves; only this flag, and the server
            // resolves the actual snapshot itself via RecentActivityService.
            'include_recent_activity'     => 'nullable|boolean',
        ]);

        $user = $request->user();

        $project = $this->authorizeOwnOrgReference(Project::class, $validated['project_id'] ?? null, $user, 'project_id', 'Invalid project context.');
        $tradePackage = $this->authorizeOwnOrgReference(TradePackage::class, $validated['trade_package_id'] ?? null, $user, 'trade_package_id', 'Invalid trade package context.');

        $diagnostics = null;
        if ($request->boolean('include_diagnostics')) {
            $diagnostics = collect($validated['diagnostics'] ?? [])
                ->only(self::DIAGNOSTIC_FIELDS)
                ->filter(fn ($value) => $value !== null && $value !== '')
                ->all();
            $diagnostics['submitted_at'] = now()->toIso8601String();
        }

        $recentActivity = $request->boolean('include_recent_activity')
            ? RecentActivityService::recentFor($user)
            : null;
        if ($recentActivity === []) {
            $recentActivity = null;
        }

        $screenshotFile = $request->file('screenshot');
        if ($screenshotFile instanceof UploadedFile) {
            // Validated before anything touches disk or the database, so a
            // rejected screenshot fails the whole request with nothing
            // stored — the user can retry without/with a fixed attachment.
            FileSecurityService::assertSafe($screenshotFile, self::SCREENSHOT_EXTENSIONS);
        }

        $storedScreenshotPath = null;

        try {
            $ticket = DB::transaction(function () use ($validated, $user, $project, $tradePackage, $diagnostics, $recentActivity, $screenshotFile, &$storedScreenshotPath) {
                $ticket = SupportTicket::create([
                    'organization_id'  => $user->organization_id,
                    'user_id'          => $user->id,
                    'reference'        => $this->generateReference(),
                    'subject'          => $validated['subject'],
                    'category'         => $validated['category'] ?? 'other',
                    'priority'         => $validated['priority'] ?? 'normal',
                    'message'          => $validated['message'],
                    // A freshly submitted ticket is immediately "waiting on
                    // us" — see SupportTicketStatusService for why there's no
                    // separate unstaffed OPEN step in between.
                    'status'           => SupportTicketStatusService::WAITING_FOR_SUPPORT,
                    'route'            => $validated['route'] ?? null,
                    'module'           => $validated['module'] ?? null,
                    'project_id'       => $project?->id,
                    'trade_package_id' => $tradePackage?->id,
                    'diagnostics'      => $diagnostics,
                    'recent_activity'  => $recentActivity,
                ]);

                if ($screenshotFile instanceof UploadedFile) {
                    $storedName = FileSecurityService::randomStorageName($screenshotFile);
                    $storedScreenshotPath = "support-tickets/{$ticket->id}/{$storedName}";

                    Storage::disk('local')->put($storedScreenshotPath, file_get_contents($screenshotFile->getRealPath()));

                    FileUpload::create([
                        'project_id'      => $project?->id,
                        'organization_id' => $user->organization_id,
                        'uploaded_by'     => $user->id,
                        'attachable_type' => SupportTicket::class,
                        'attachable_id'   => $ticket->id,
                        'trade_package_id' => $tradePackage?->id,
                        'original_name'   => FileSecurityService::sanitizeDisplayName($screenshotFile->getClientOriginalName()),
                        'stored_name'     => $storedName,
                        'file_path'       => $storedScreenshotPath,
                        'mime_type'       => $screenshotFile->getMimeType(),
                        'file_size'       => $screenshotFile->getSize(),
                        'disk'            => 'local',
                    ]);
                }

                return $ticket;
            });
        } catch (\Throwable $e) {
            // Ticket creation (or the FileUpload row) failed after the
            // screenshot was already written to disk — clean up the orphan
            // rather than leaving an unreferenced private file behind.
            if ($storedScreenshotPath && Storage::disk('local')->exists($storedScreenshotPath)) {
                Storage::disk('local')->delete($storedScreenshotPath);
            }
            throw $e;
        }

        $ticket->load('screenshot');
        $this->notifySupportTeam($ticket, $user, $project, $tradePackage);

        // Personal confirmation only — never fanned out to the organization,
        // this is the submitter's own request.
        NotificationService::send(
            $user,
            NotificationService::SUPPORT_TICKET_RECEIVED,
            'Support request received',
            "Your request \"{$ticket->subject}\" has been received. Reference: {$ticket->reference}.",
            ['ticket_id' => $ticket->id, 'reference' => $ticket->reference],
            ['action_url' => "/app/help/support/{$ticket->id}"]
        );

        // In-app personal notification to every platform operator (Super
        // Admin/Admin) — previously only an email went out here
        // (notifySupportTeam(), best-effort and silently skipped if no
        // support_email/admin_email is configured), so an operator with
        // email disabled or unconfigured never saw a new ticket anywhere in
        // the product itself. This closes that gap.
        self::notifySupportOperators(
            $ticket,
            NotificationService::SUPPORT_TICKET_SUBMITTED,
            'New support request submitted',
            "{$user->name} submitted \"{$ticket->subject}\" (Ref: {$ticket->reference})."
        );

        return response()->json(['data' => $this->presentTicketSummary($ticket)], 201);
    }

    // Personal notifications to every platform operator (Super Admin/Admin)
    // — the narrowest recipient set this platform actually has a mechanism
    // for (there's no separate "support team" user group; every Super
    // Admin/Admin already has full ticket access per
    // AuthorizesSupportTickets). Never sendToOrganization(). Shared by this
    // controller (new ticket submitted) and SupportTicketMessageController
    // (customer replied) so the recipient-resolution logic exists in one
    // place, not duplicated across both call sites.
    public static function notifySupportOperators(SupportTicket $ticket, string $type, string $title, string $message): void
    {
        // A plain relational lookup rather than Spatie's role() scope, which
        // throws RoleDoesNotExist if either role name has never been created
        // in the roles table (e.g. a fresh database with only a Client role
        // seeded so far) — this must never break ticket submission just
        // because no admin account exists yet.
        $operators = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Admin', 'Admin']))->get();

        foreach ($operators as $operator) {
            NotificationService::send(
                $operator,
                $type,
                $title,
                $message,
                ['ticket_id' => $ticket->id, 'reference' => $ticket->reference],
                ['action_url' => "/admin/support?ticket={$ticket->id}"]
            );
        }
    }

    /**
     * If $id is present, load the model and verify it belongs to the user's
     * own organization (or the user is a platform operator) — otherwise
     * reject the whole request. Returns null when $id is null (context is
     * optional), never a model the user isn't authorized to reference.
     */
    private function authorizeOwnOrgReference(string $modelClass, ?int $id, $user, string $field, string $message)
    {
        if (!$id) {
            return null;
        }

        $record = $modelClass::find($id);

        if (!$record || ($record->organization_id !== $user->organization_id && !$user->hasRole('Super Admin') && !$user->hasRole('Admin'))) {
            throw ValidationException::withMessages([$field => [$message]]);
        }

        return $record;
    }

    private function generateReference(): string
    {
        do {
            $reference = 'SUP-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        } while (SupportTicket::where('reference', $reference)->exists());

        return $reference;
    }

    // Notifies the internal SureSign support team via the configured
    // support_email, falling back to admin_email. The ticket is always
    // stored regardless of email outcome — this is best-effort delivery
    // on top of the durable record, not a replacement for it.
    private function notifySupportTeam(SupportTicket $ticket, $user, ?Project $project, ?TradePackage $tradePackage): void
    {
        $settings  = SuresignSetting::instance();
        $recipient = $settings->support_email ?: $settings->admin_email;

        if (!$recipient) {
            Log::warning("SupportTicketController: no support_email or admin_email configured — ticket {$ticket->reference} was stored but not emailed");
            return;
        }

        $organization = $user->organization;

        $lines = collect([
            "Reference: {$ticket->reference}",
            "Category: {$ticket->category}",
            "Priority: {$ticket->priority}",
            "Submitted by: {$user->name} ({$user->email})",
            'Organization: '.($organization?->name ?: 'Unknown').' (ID: '.$user->organization_id.')',
            $ticket->route ? "Route: {$ticket->route}" : null,
            $ticket->module ? "Module: {$ticket->module}" : null,
            $project ? "Project: {$project->name} (ID: {$project->id})" : null,
            $tradePackage ? "Trade package: {$tradePackage->name} (ID: {$tradePackage->id})" : null,
            'Submitted at: '.$ticket->created_at?->toDateTimeString(),
        ])->filter();

        if ($ticket->diagnostics) {
            $lines->push('')->push('Diagnostics (user opted in):');
            foreach ($ticket->diagnostics as $key => $value) {
                $lines->push('  '.str_replace('_', ' ', ucfirst($key)).': '.$value);
            }
        }

        if ($ticket->recent_activity) {
            $lines->push('')->push('Recent SureSign activity (user opted in, latest '.count($ticket->recent_activity).'):');
            foreach ($ticket->recent_activity as $entry) {
                $lines->push('  '.$entry['timestamp'].' — '.($entry['project'] ? "[{$entry['project']}] " : '').$entry['description']);
            }
        }

        // The screenshot itself is never attached or linked by URL here — see
        // the class-level note in the Batch 2 report for why (kept intact via
        // project-context.md going forward). Staff open the ticket in
        // SureSign to view it, authorized the same way as everything else.
        if ($ticket->screenshot) {
            $lines->push('')->push('A screenshot was attached to this ticket — view it in SureSign (Admin > Support) using the reference above.');
        }

        $lines = $lines->push('')->push("Subject: {$ticket->subject}")->push('')->push('Message:')->push($ticket->message);

        // Communications Platform, Batch 4 — EmailNotificationService::buildHtml()
        // now escapes the subject itself for every caller, so the local
        // e() this call site used to need here (the ticket subject is
        // fully user-controlled) was removed to avoid double-escaping.
        $sent = EmailNotificationService::sendDirect($recipient, '[SureSign Support] '.$ticket->reference.' — '.$ticket->subject, $lines->implode("\n"));

        if (!$sent) {
            Log::warning("SupportTicketController: failed to email support ticket {$ticket->reference} to {$recipient}");
        }
    }

    // Super Admin / Admin only — both are platform-wide roles that manage
    // every organization's tickets; organization_id is just an optional filter.
    // Paginated (Batch 5) — the old unbounded-but-limited(500) flat array +
    // aggregate-counts shape was a defensive stopgap from an earlier security
    // review, not the intended long-term shape; the admin frontend was
    // updated in the same batch to consume the paginated envelope.
    public function index(Request $request)
    {
        $request->validate([
            'status'   => 'nullable|string|in:'.implode(',', SupportTicketStatusService::ALL),
            'category' => 'nullable|string|in:'.implode(',', self::CATEGORIES),
            'priority' => 'nullable|string|in:low,normal,high',
            'search'   => 'nullable|string|max:255',
        ]);

        $query = SupportTicket::with([
            'organization:id,name',
            'user:id,name,email',
            'project:id,name',
            'tradePackage:id,name',
            'screenshot',
            'latestPublicMessage',
        ])->latest();

        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($q) => $q->where('subject', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%"));
        }

        // Aggregate counts are computed unfiltered (all organizations, every
        // status) so the stat tiles always reflect the whole inbox, not just
        // whatever page/filter is currently applied.
        $tickets = $query->paginate(20)->through(fn ($t) => $this->presentAdminTicketSummary($t));

        return response()->json([
            ...$tickets->toArray(),
            'counts' => $this->buildStatusCounts(),
        ]);
    }

    // Super Admin / Admin only — a lightweight standalone counts fetch (no
    // ticket list/pagination) for surfaces that only need the number, such
    // as the admin sidebar's "Support" badge, so those don't have to pull a
    // full paginated ticket list just to read one field off the response.
    public function counts(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['counts' => $this->buildStatusCounts()]);
    }

    /** @return array<string, int> */
    private function buildStatusCounts(): array
    {
        $counts = SupportTicket::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'waiting_for_support' => (int) ($counts[SupportTicketStatusService::WAITING_FOR_SUPPORT] ?? 0) + (int) ($counts[SupportTicketStatusService::OPEN] ?? 0),
            'waiting_for_you'      => (int) ($counts[SupportTicketStatusService::WAITING_FOR_YOU] ?? 0),
            'resolved'             => (int) ($counts[SupportTicketStatusService::RESOLVED] ?? 0),
            'closed'               => (int) ($counts[SupportTicketStatusService::CLOSED] ?? 0),
            'total'                => (int) $counts->sum(),
        ];
    }

    private function presentAdminTicketSummary(SupportTicket $t): array
    {
        return [
            'id'         => $t->id,
            'reference'  => $t->reference,
            'subject'    => $t->subject,
            'category'   => $t->category,
            'priority'   => $t->priority,
            'status'     => $t->status,
            'company'    => $t->organization ? ['id' => $t->organization->id, 'name' => $t->organization->name] : null,
            'submitted_by'       => $t->user?->name,
            'submitted_by_email' => $t->user?->email,
            'created_at' => $t->created_at,
            'updated_at' => $t->updated_at,
            'has_screenshot' => (bool) $t->screenshot,
            'latest_message_preview' => $t->latestPublicMessage ? Str::limit($t->latestPublicMessage->body, 120) : null,
            // Unread-by-support: a client-authored, public reply exists more
            // recently than the last time an operator opened this ticket
            // (adminShow() is what advances support_last_read_at — never a
            // client-controllable value).
            'unread_by_support' => $t->latestPublicMessage
                && $t->latestPublicMessage->sender_type === SupportTicketMessage::SENDER_CUSTOMER
                && $t->latestPublicMessage->created_at->gt($t->support_last_read_at ?? $t->created_at),
        ];
    }

    // Super Admin / Admin only — single-ticket detail with full context,
    // mirroring show() below but for the admin side; also the point at which
    // support_last_read_at advances (never via a standalone "mark read" POST
    // a client could call unprompted — only as a side effect of the
    // authorized detail fetch itself).
    public function adminShow(Request $request, SupportTicket $supportTicket)
    {
        $supportTicket->load(['organization:id,name', 'user:id,name,email', 'project:id,name', 'tradePackage:id,name', 'screenshot']);
        $supportTicket->update(['support_last_read_at' => now()]);

        return response()->json(['data' => [
            ...$this->presentAdminTicketSummary($supportTicket),
            'message'         => $supportTicket->message,
            'route'           => $supportTicket->route,
            'module'          => $supportTicket->module,
            'project'         => $supportTicket->project ? ['id' => $supportTicket->project->id, 'name' => $supportTicket->project->name] : null,
            'trade_package'   => $supportTicket->tradePackage ? ['id' => $supportTicket->tradePackage->id, 'name' => $supportTicket->tradePackage->name] : null,
            'diagnostics'     => $supportTicket->diagnostics,
            'recent_activity' => $supportTicket->recent_activity,
            'screenshot' => $supportTicket->screenshot ? [
                'id'          => $supportTicket->screenshot->id,
                'file_size'   => $supportTicket->screenshot->file_size,
                'mime_type'   => $supportTicket->screenshot->mime_type,
                'preview_url' => "/support-tickets/{$supportTicket->id}/screenshot",
            ] : null,
        ]]);
    }

    // Super Admin / Admin only — both are platform-wide roles, so any ticket
    // from any organization may be updated. Transitions are validated
    // centrally by SupportTicketStatusService, not a controller conditional —
    // an invalid status string is rejected by the 'in:' rule below, and a
    // structurally valid but disallowed transition (e.g. RESOLVED straight to
    // WAITING_FOR_YOU) is rejected with a 422 by canOperatorTransition().
    public function updateStatus(Request $request, string $id)
    {
        $ticket = SupportTicket::findOrFail($id);
        $previousStatus = $ticket->status;

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', SupportTicketStatusService::ALL),
        ]);

        if (!SupportTicketStatusService::canOperatorTransition($previousStatus, $validated['status'])) {
            throw ValidationException::withMessages([
                'status' => ["Cannot move a ticket from \"{$previousStatus}\" to \"{$validated['status']}\"."],
            ]);
        }

        $ticket->update([
            'status'      => $validated['status'],
            'resolved_at' => $validated['status'] === SupportTicketStatusService::RESOLVED ? now() : $ticket->resolved_at,
        ]);

        // Only RESOLVED is a required notification event per the product's
        // email/notification policy — CLOSED is deliberately silent (it
        // almost always follows a RESOLVED the user was already notified
        // about, and closing itself carries no new information for them),
        // and re-saving the same status was already a no-op above this
        // block's guard, never a duplicate notification.
        if ($validated['status'] === SupportTicketStatusService::RESOLVED && $validated['status'] !== $previousStatus && $ticket->user) {
            NotificationService::send(
                $ticket->user,
                NotificationService::SUPPORT_TICKET_STATUS_CHANGED,
                'Support request resolved',
                "Your request \"{$ticket->subject}\" (Ref: {$ticket->reference}) has been resolved.",
                ['ticket_id' => $ticket->id, 'reference' => $ticket->reference, 'status' => $validated['status']],
                ['action_url' => "/app/help/support/{$ticket->id}"]
            );

            self::emailTicketOwner(
                $ticket,
                'Your support request has been resolved',
                "Your request \"{$ticket->subject}\" (Ref: {$ticket->reference}) has been marked resolved.\n\nIf this doesn't fully address your question, just reply on the request and it will reopen automatically.",
                "/app/help/support/{$ticket->id}",
            );
        }

        return response()->json(['data' => $ticket->fresh()]);
    }

    // Shared by updateStatus() (resolved) and SupportTicketMessageController
    // (support reply) — one place that emails the ticket owner, so both call
    // sites stay consistent. Subject escaping is handled centrally by
    // EmailNotificationService::buildHtml() (Batch 4) — no local e() needed
    // here.
    //
    // Communications Platform, Batch 4 — now built via EmailComponents
    // (a paragraph plus a real "View Request" button) with a genuine
    // plaintext alternative, rather than a bare escaped paragraph. Wording
    // and trigger are unchanged; $actionUrl is the exact same relative path
    // both callers already pass their paired in-app notification.
    public static function emailTicketOwner(SupportTicket $ticket, string $subject, string $bodyText, ?string $actionUrl = null): void
    {
        if (!$ticket->user?->email) {
            return;
        }

        // $bodyText may contain multiple "\n\n"-separated paragraphs
        // (both existing callers pass one) — each becomes its own
        // paragraph() call so the line break survives in HTML, not just
        // in the plaintext part.
        $htmlParts = array_map(
            fn (string $paragraph) => EmailComponents::paragraph($paragraph),
            explode("\n\n", $bodyText),
        );
        $textLines = [$bodyText];

        if ($actionUrl) {
            $absoluteUrl = rtrim(config('suresign.frontend_url'), '/') . $actionUrl;
            $htmlParts[] = EmailComponents::button('View Request', $absoluteUrl, 'secondary');
            $textLines[] = '';
            $textLines[] = "View Request: {$absoluteUrl}";
        }

        $sent = EmailNotificationService::sendDirect(
            $ticket->user->email,
            '[SureSign Support] '.$subject,
            implode("\n", $textLines),
            [],
            null,
            'Support',
            implode("\n", $htmlParts),
            true,
        );

        if (!$sent) {
            Log::warning("SupportTicketController: failed to email ticket owner for {$ticket->reference}");
        }
    }

    // Lets the support form show the user exactly what "Include recent
    // SureSign activity" would attach, before they submit — the same
    // resolver store() itself uses, so the preview can never drift from
    // what actually gets sent.
    public function recentActivityPreview(Request $request)
    {
        return response()->json(['data' => RecentActivityService::recentFor($request->user())]);
    }

    // The authenticated user's own support requests — bounded/paginated,
    // filterable by status/category/priority. Deliberately a separate,
    // narrower endpoint from index() (admin, all organizations) rather than
    // an "if admin, show everything" branch on the same one.
    public function myTickets(Request $request)
    {
        $request->validate([
            'status'   => 'nullable|string|in:'.implode(',', SupportTicketStatusService::ALL),
            'category' => 'nullable|string|in:'.implode(',', self::CATEGORIES),
            'priority' => 'nullable|string|in:low,normal,high',
            'search'   => 'nullable|string|max:255',
        ]);

        // Scoped to the authenticated user's own tickets only — never all of
        // the same organization's tickets. A same-organization Client must
        // never see another user's request here.
        $query = SupportTicket::where('user_id', $request->user()->id)
            ->with(['screenshot', 'latestPublicMessage'])
            ->latest();

        if ($request->filled('status')) $query->where('status', $request->status);
        if ($request->filled('category')) $query->where('category', $request->category);
        if ($request->filled('priority')) $query->where('priority', $request->priority);
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($q) => $q->where('subject', 'like', "%{$search}%")->orWhere('reference', 'like', "%{$search}%"));
        }

        $tickets = $query->paginate(10)->through(fn ($t) => $this->presentTicketSummary($t));

        return response()->json($tickets);
    }

    // Single ticket detail — the ticket's own submitter or a platform
    // operator only, same rule as screenshot() below.
    public function show(Request $request, SupportTicket $supportTicket)
    {
        $this->authorizeTicketAccess($request->user(), $supportTicket);

        $supportTicket->load(['project:id,name', 'tradePackage:id,name', 'screenshot', 'latestPublicMessage']);

        // Marks the ticket read by its own owner — same pattern as
        // adminShow()'s support_last_read_at: only ever advanced as a side
        // effect of this authorized fetch, never a client-supplied value.
        if ($request->user()->id === $supportTicket->user_id) {
            $supportTicket->update(['client_last_read_at' => now()]);
        }

        return response()->json(['data' => [
            ...$this->presentTicketSummary($supportTicket),
            'message'       => $supportTicket->message,
            'route'         => $supportTicket->route,
            'module'        => $supportTicket->module,
            'project'       => $supportTicket->project ? ['id' => $supportTicket->project->id, 'name' => $supportTicket->project->name] : null,
            'trade_package' => $supportTicket->tradePackage ? ['id' => $supportTicket->tradePackage->id, 'name' => $supportTicket->tradePackage->name] : null,
            'diagnostics'   => $supportTicket->diagnostics,
            // Shown back to the ticket's own owner too (their own org's
            // activity, already authorized by authorizeTicketAccess() above)
            // — never to any other Client user.
            'recent_activity' => $supportTicket->recent_activity,
        ]]);
    }

    private function presentTicketSummary(SupportTicket $ticket): array
    {
        return [
            'id'         => $ticket->id,
            'reference'  => $ticket->reference,
            'subject'    => $ticket->subject,
            'category'   => $ticket->category,
            'priority'   => $ticket->priority,
            'status'     => $ticket->status,
            'has_screenshot' => (bool) $ticket->screenshot,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            'latest_message_preview' => $ticket->latestPublicMessage ? Str::limit($ticket->latestPublicMessage->body, 120) : null,
            // Unread-by-client: a support-authored, public reply exists more
            // recently than the last time the owner opened this ticket
            // (show() above is what advances client_last_read_at).
            'unread_by_client' => $ticket->latestPublicMessage
                && $ticket->latestPublicMessage->sender_type === SupportTicketMessage::SENDER_SUPPORT
                && $ticket->latestPublicMessage->created_at->gt($ticket->client_last_read_at ?? $ticket->created_at),
            'screenshot' => $ticket->screenshot ? [
                'id'          => $ticket->screenshot->id,
                'file_size'   => $ticket->screenshot->file_size,
                'mime_type'   => $ticket->screenshot->mime_type,
                'preview_url' => "/support-tickets/{$ticket->id}/screenshot",
            ] : null,
        ];
    }

    // Screenshot preview — restricted to the ticket's own submitter or a
    // platform operator (Super Admin/Admin), deliberately narrower than the
    // general same-organization access FileUpload::previewFile() allows
    // elsewhere: a support screenshot can capture more of a user's screen
    // than an ordinary project document, so a fellow Client in the same org
    // must not be able to view another user's ticket screenshot.
    public function screenshot(Request $request, SupportTicket $supportTicket)
    {
        $this->authorizeTicketAccess($request->user(), $supportTicket);

        $upload = $supportTicket->screenshot;

        if (!$upload || !Storage::disk($upload->disk)->exists($upload->file_path)) {
            abort(404, 'File not found.');
        }

        // Screenshots are always PNG/JPEG/WebP (enforced at upload time), the
        // same "safe to render inline" set DocumentController::previewFile()/
        // previewDocument() already inline via safeInlineHeaders() — matched
        // here rather than assumed, so if this ever serves an unexpected mime
        // type it degrades to a forced download instead of inline rendering.
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
}