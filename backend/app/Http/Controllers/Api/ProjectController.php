<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectFolder;
use App\Services\OperationalIntelligenceService;
use App\Services\ProjectActivityService;
use App\Services\ProjectHealthService;
use App\Services\ProjectStatsService;
use App\Services\ProjectStorageService;
use App\Services\TimezoneResolver;
use App\Services\UpcomingActionsService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Super Admin and Admin can query any org by passing organization_id
        if (($user->hasRole('Super Admin') || $user->hasRole('Admin')) && $request->filled('organization_id')) {
            $query = Project::with(['creator:id,name', 'contacts', 'client:id,name', 'organization:id,name'])
                ->where('organization_id', $request->organization_id);
        } elseif ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            // System users with no org filter: return all projects
            $query = Project::with(['creator:id,name', 'contacts', 'client:id,name', 'organization:id,name']);
        } else {
            // Regular clients: scope to their own organisation
            $query = Project::with(['creator:id,name', 'contacts', 'client:id,name', 'organization:id,name'])
                ->where('organization_id', $user->organization_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Consultancy Phase C2, Batch 5 — a narrowly-scoped search, added to
        // this existing endpoint rather than a second, Consultancy-specific
        // search architecture (per internal-docs/commercial/
        // suresign-consultancy-phase-c2-specification-v1.md §6/§16). Matches
        // only project name, project code, and client name — nothing
        // broader. Grouped inside its own closure so these OR conditions can
        // never widen the organisation/role scope already established above.
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $projects = $query->latest()->paginate($request->integer('per_page', 20));
        return response()->json($projects);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'                      => 'required|string|max:255',
            'code'                      => 'nullable|string|max:50',
            'description'               => 'nullable|string',
            'type'                      => 'nullable|string',
            'contract_type'             => 'nullable|string|max:100',
            'status'                    => 'nullable|in:active,on_hold,completed,cancelled',
            'client_id'                 => 'nullable|integer|exists:clients,id',
            'contract_value'            => 'nullable|numeric|min:0',
            'retention_percentage'      => 'nullable|numeric|min:0|max:100',
            'retention_cap_percentage'  => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'        => 'nullable|integer|min:0',
            'start_date'                => 'nullable|date',
            'end_date'                  => 'nullable|date|after_or_equal:start_date',
            'address'                   => 'nullable|string',
            'city'                      => 'nullable|string',
            'state'                     => 'nullable|string',
            'postcode'                  => 'nullable|string',
            // Optional explicit override — never inferred from country/location.
            // Omitted (null) means "inherit from organisation, then platform,
            // then GBP" (see CurrencyService::resolveCode). Explicitly included
            // in the create() array below (even when null) so it always wins
            // over the `projects.currency` column's own default, regardless of
            // database driver.
            'currency'                  => 'nullable|string|size:3',
        ]);

        $project = Project::create(array_merge($validated, [
            'organization_id' => $request->user()->organization_id,
            'created_by'      => $request->user()->id,
            'status'          => $validated['status'] ?? 'active',
            'currency'        => isset($validated['currency']) ? strtoupper($validated['currency']) : null,
        ]));

        // Auto-create standard folder structure (DB records)
        $this->createDefaultFolders($project);

        // Create actual suresign/ directory structure on disk
        ProjectStorageService::createProjectFolders($project);

        // Add creator as project manager
        $project->users()->attach($request->user()->id, ['role' => 'project_manager']);

        // Record activity
        ProjectActivityService::record(
            $project,
            $request->user(),
            'project_created',
            "Project created: {$project->name}",
            null,
            $project
        );

        return response()->json($project->load(['creator:id,name', 'folders']), 201);
    }

    /**
     * Admin: create a project on behalf of a client company.
     * Route: POST /admin/companies/{organization}/projects
     */
    public function storeForCompany(Request $request, Organization $organization)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'code'             => 'nullable|string|max:50',
            'description'      => 'nullable|string',
            'status'           => 'nullable|in:active,on_hold,completed,cancelled',
            'contract_value'   => 'nullable|numeric|min:0',
            'start_date'       => 'nullable|date',
            'end_date'         => 'nullable|date|after_or_equal:start_date',
            'address'          => 'nullable|string',
            'city'             => 'nullable|string',
            'state'            => 'nullable|string',
            'postcode'         => 'nullable|string',
            'country'          => 'nullable|string',
            'currency'         => 'nullable|string|size:3',
        ]);

        $project = Project::create(array_merge($validated, [
            'organization_id' => $organization->id,
            'created_by'      => $admin->id,
            'status'          => $validated['status'] ?? 'active',
            'currency'        => isset($validated['currency']) ? strtoupper($validated['currency']) : null,
        ]));

        $this->createDefaultFolders($project);
        ProjectStorageService::createProjectFolders($project);

        ProjectActivityService::record(
            $project,
            $admin,
            'project_created',
            "Project \"{$project->name}\" created by admin {$admin->name} on behalf of {$organization->name}.",
            null,
            $project
        );

        return response()->json($project->load(['creator:id,name', 'folders', 'organization:id,name']), 201);
    }

    public function show(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        return response()->json(
            $project->load(['creator:id,name', 'contacts', 'contracts', 'folders', 'users:id,name,email', 'organization:id,name', 'client:id,name'])
        );
    }

    public function update(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $validated = $request->validate([
            'name'                     => 'sometimes|string|max:255',
            'code'                     => 'nullable|string|max:50',
            'description'              => 'nullable|string',
            'status'                   => 'sometimes|in:active,on_hold,completed,cancelled',
            'type'                     => 'nullable|string',
            'contract_type'            => 'nullable|string|max:100',
            'contract_value'           => 'nullable|numeric|min:0',
            'retention_percentage'     => 'nullable|numeric|min:0|max:100',
            'payment_terms_days'       => 'nullable|integer|min:0',
            'start_date'               => 'nullable|date',
            'end_date'                 => 'nullable|date',
            'practical_completion_date'=> 'nullable|date',
            'address'                  => 'nullable|string',
            // 'sometimes' — omitting `currency` entirely from an update
            // request (e.g. editing only the name) must leave the project's
            // existing currency untouched, not reset it to null/inherited.
            // Explicitly sending `currency: null` clears the override back to
            // "inherit from organisation".
            'currency'                 => 'sometimes|nullable|string|size:3',
        ]);
        if (array_key_exists('currency', $validated) && $validated['currency'] !== null) {
            $validated['currency'] = strtoupper($validated['currency']);
        }
        $project->update($validated);
        return response()->json($project->fresh());
    }

    public function destroy(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $project->delete();
        return response()->json(['message' => 'Project archived.']);
    }

    public function folders(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        $folders = $project->folders()->orderBy('order')->get();

        $counts = FileUpload::where('project_id', $project->id)
            ->selectRaw('folder_path, COUNT(*) as file_count')
            ->groupBy('folder_path')
            ->pluck('file_count', 'folder_path');

        $folders = $folders->map(function ($folder) use ($counts) {
            $folder->file_count = $counts[$folder->path] ?? 0;
            return $folder;
        });

        return response()->json($folders);
    }

    private function createDefaultFolders(Project $project): void
    {
        $folders = [
            ['number' => '01', 'name' => 'Contracts',             'path' => '01_Contracts'],
            ['number' => '02', 'name' => 'Commercial',            'path' => '02_Commercial'],
            ['number' => '03', 'name' => 'Payment Applications',  'path' => '03_Payment_Applications'],
            ['number' => '04', 'name' => 'Variations',            'path' => '04_Variations'],
            ['number' => '05', 'name' => 'Notices',               'path' => '05_Notices'],
            ['number' => '06', 'name' => 'RFIs',                  'path' => '06_RFIs'],
            ['number' => '07', 'name' => 'Meetings',              'path' => '07_Meetings'],
            ['number' => '08', 'name' => 'QA Reports',            'path' => '08_QA_Reports'],
            ['number' => '09', 'name' => 'Snagging',              'path' => '09_Snagging'],
            ['number' => '10', 'name' => 'Closeout',              'path' => '10_Closeout'],
            ['number' => '11', 'name' => 'Adjudication',          'path' => '11_Adjudication'],
            ['number' => '12', 'name' => 'Site Reports',          'path' => '12_Site_Reports'],
            ['number' => '13', 'name' => 'AI Generated',          'path' => '13_AI_Generated'],
        ];

        foreach ($folders as $i => $folder) {
            ProjectFolder::create([
                'project_id'      => $project->id,
                'name'            => $folder['name'],
                'path'            => $folder['path'],
                'folder_number'   => $folder['number'],
                'order'           => $i + 1,
                'is_auto_created' => true,
            ]);
        }
    }

    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();
        // System users (Super Admin, Admin) can access any project
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    public function stats(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        return response()->json(ProjectStatsService::getStats($project));
    }

    public function dashboardIntelligence(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);

        // Lead with the most recent confirmed/completed AI analysis on this project.
        // This ensures the dashboard shows the contract that was actually analysed,
        // rather than the newest contract which may have no analysis yet.
        $aiAnalysis = \App\Models\ContractAiAnalysis::where('project_id', $project->id)
            ->whereIn('status', ['confirmed', 'completed'])
            ->with('creator:id,name')
            ->latest()
            ->first();

        // Use the analysed contract if one exists, otherwise fall back to any main contract.
        if ($aiAnalysis) {
            $mainContract = \App\Models\Contract::with([
                'fileUploads' => fn($q) => $q->select(['id','attachable_type','attachable_id','original_name','mime_type'])->latest(),
            ])->find($aiAnalysis->contract_id);
        } else {
            $mainContract = \App\Models\Contract::where('project_id', $project->id)
                ->where('type', 'main_contract')
                ->with(['fileUploads' => fn($q) => $q->select(['id','attachable_type','attachable_id','original_name','mime_type'])->latest()])
                // CASE, not MySQL's FIELD(), so this runs identically under sqlite too.
                ->orderByRaw("
                    CASE status
                        WHEN 'active'     THEN 0
                        WHEN 'draft'      THEN 1
                        WHEN 'complete'   THEN 2
                        WHEN 'expired'    THEN 3
                        WHEN 'terminated' THEN 4
                        ELSE 5
                    END
                ")
                ->latest()
                ->first();
        }

        // Commercial summary
        $pid = $project->id;

        $commercial = [
            'total_applications' => \App\Models\PaymentApplication::where('project_id', $pid)->count(),
            'total_submitted'    => \App\Models\PaymentApplication::where('project_id', $pid)
                ->whereIn('status', ['submitted', 'certified', 'paid'])->count(),
            'total_certified'    => (float) \App\Models\PaymentApplication::where('project_id', $pid)
                ->whereNotNull('certified_amount')->sum('certified_amount'),
            'total_paid'         => (float) \App\Models\PaymentApplication::where('project_id', $pid)
                ->whereNotNull('paid_amount')->sum('paid_amount'),
            // Variation intelligence — sourced directly from the Variation table (single source of truth)
            'approved_variations_count'      => \App\Models\Variation::where('project_id', $pid)
                ->where('status', \App\Models\Variation::STATUS_APPROVED)->count(),
            'approved_variations_value'      => (float) \App\Models\Variation::where('project_id', $pid)
                ->where('status', \App\Models\Variation::STATUS_APPROVED)->sum('agreed_amount'),
            'pending_variations_count'       => \App\Models\Variation::where('project_id', $pid)
                ->whereIn('status', \App\Models\Variation::IN_PROGRESS_STATUSES)->count(),
            'pending_variations_value'       => (float) \App\Models\Variation::where('project_id', $pid)
                ->whereIn('status', \App\Models\Variation::IN_PROGRESS_STATUSES)->sum('quoted_amount'),
            'rejected_variations_count'      => \App\Models\Variation::where('project_id', $pid)
                ->where('status', \App\Models\Variation::STATUS_REJECTED)->count(),
            // Approved but not yet included in any Payment Application — outstanding commercial exposure
            'approved_not_included_count'    => \App\Models\Variation::where('project_id', $pid)
                ->where('status', \App\Models\Variation::STATUS_APPROVED)
                ->whereDoesntHave('paymentApplicationVariations')
                ->count(),
            'approved_not_included_value'    => (float) \App\Models\Variation::where('project_id', $pid)
                ->where('status', \App\Models\Variation::STATUS_APPROVED)
                ->whereDoesntHave('paymentApplicationVariations')
                ->sum('agreed_amount'),
        ];

        // Document count
        $documentsCount = \App\Models\FileUpload::where('project_id', $project->id)->count();

        // Upcoming payment deadlines — next 30 days, non-cancelled apps only.
        // "Today"/"the next 30 days" is a business-day concept scoped to this
        // project's own organisation, not the server's UTC calendar day.
        $todayCarbon = TimezoneResolver::today(null, $project->organization);
        $today       = $todayCarbon->toDateString();
        $horizon     = $todayCarbon->copy()->addDays(30)->toDateString();

        $deadlineApps = \App\Models\PaymentApplication::where('project_id', $project->id)
            ->whereNotIn('status', ['cancelled', 'paid'])
            ->where(function ($q) use ($today, $horizon) {
                $q->whereBetween('pay_less_notice_deadline',  [$today, $horizon])
                  ->orWhereBetween('payment_notice_deadline', [$today, $horizon])
                  ->orWhereBetween('final_date_for_payment',  [$today, $horizon])
                  ->orWhere('pay_less_notice_deadline',  '<', $today)
                  ->orWhere('payment_notice_deadline',   '<', $today)
                  ->orWhere('final_date_for_payment',    '<', $today);
            })
            ->select([
                'id', 'application_number', 'status',
                'application_date', 'due_date', 'final_date_for_payment',
                'payment_notice_deadline', 'pay_less_notice_deadline',
            ])
            ->orderBy('pay_less_notice_deadline')
            ->get();

        $upcomingDeadlines = [];
        foreach ($deadlineApps as $app) {
            $deadlineFields = [
                'pay_less_notice_deadline'  => 'Pay Less Notice',
                'payment_notice_deadline'   => 'Payment Notice',
                'final_date_for_payment'    => 'Final Date for Payment',
                'due_date'                  => 'Due Date',
            ];
            foreach ($deadlineFields as $field => $label) {
                if (!empty($app->$field)) {
                    $date = \Carbon\Carbon::parse($app->$field);
                    // $today already resolved for this project's organisation above.
                    $daysUntil = (int) Carbon::parse($today)->diffInDays(Carbon::parse($date->toDateString()), false);
                    $upcomingDeadlines[] = [
                        'application_id'     => $app->id,
                        'application_number' => $app->application_number,
                        'application_status' => $app->status,
                        'type'               => $field,
                        'label'              => $label,
                        'date'               => $app->$field,
                        'days_until'         => $daysUntil,
                        'is_overdue'         => $daysUntil < 0,
                        'is_urgent'          => $daysUntil >= 0 && $daysUntil <= 3,
                    ];
                }
            }
        }

        // Sort: overdue first, then by date ascending
        usort($upcomingDeadlines, fn($a, $b) => $a['days_until'] <=> $b['days_until']);

        // ── Operational intelligence ─────────────────────────────────────────
        $contractId = $mainContract?->id;

        $intelligence     = app(OperationalIntelligenceService::class);
        $upcomingActions  = app(UpcomingActionsService::class);
        $healthService    = app(ProjectHealthService::class);

        $operationalSummary = $intelligence->getSummary($project->id, $contractId);
        $actionsSummary     = $upcomingActions->getDashboardSummary($project->id, $contractId);
        $projectHealth      = $healthService->getHealth($project->id, $contractId);

        // ── Risk summary ─────────────────────────────────────────────────────
        // Widened in Sprint 6F to also include Trade Package risks (which have
        // a null contract_id) — same ContractRisk table, no parallel query/
        // calculation, just a broader scope for the existing risk_summary shape.
        $contractIds = $contractId
            ? collect([$contractId])
            : \App\Models\Contract::where('project_id', $project->id)->pluck('id');

        $tradePackageIds = \App\Models\TradePackage::where('project_id', $project->id)->pluck('id');

        $risks = \App\Models\ContractRisk::where(function ($q) use ($contractIds, $tradePackageIds) {
                $q->whereIn('contract_id', $contractIds)
                  ->orWhereIn('trade_package_id', $tradePackageIds);
            })
            ->where('status', '!=', 'resolved')
            // CASE, not MySQL's FIELD(), so this runs identically under sqlite too.
            ->orderByRaw("
                CASE severity
                    WHEN 'critical' THEN 0
                    WHEN 'high'     THEN 1
                    WHEN 'medium'   THEN 2
                    WHEN 'low'      THEN 3
                    ELSE 4
                END
            ")
            ->get(['id', 'title', 'severity', 'urgency', 'is_non_standard_amendment', 'category', 'clause_reference', 'commercial_impact', 'recommended_action', 'trade_package_id', 'is_ai_generated'])
            ->map(function ($risk) use ($project) {
                $risk->action_url = \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                    $project->id, \App\Models\CalendarEvent::SOURCE_CONTRACT_RISK, $risk->id, $risk->trade_package_id
                );
                return $risk;
            });

        $riskSummary = [
            'critical'              => $risks->where('severity', 'critical')->count(),
            'high'                  => $risks->where('severity', 'high')->count(),
            'medium'                => $risks->where('severity', 'medium')->count(),
            'non_standard_amendments' => $risks->where('is_non_standard_amendment', true)->count(),
            'top_risks'             => $risks->take(5)->values(),
        ];

        // ── Final Accounts ────────────────────────────────────────────────────
        // Deliberately project-wide (not scoped to $contractId like the blocks
        // above) — a project can have a Final Account per main contract AND per
        // trade package, mirroring the Commercial tab's "one card per contract/
        // trade package" presentation rather than only the single main contract.
        $finalAccountService = app(\App\Services\FinalAccountService::class);

        $finalAccounts = \App\Models\FinalAccount::where('project_id', $project->id)
            ->with(['contract:id,title', 'tradePackage:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (\App\Models\FinalAccount $fa) use ($finalAccountService, $today) {
                $totals = $fa->isSnapshotted()
                    ? ['final_balance_due' => (float) $fa->final_balance_due, 'retention_outstanding' => (float) $fa->retention_outstanding]
                    : $finalAccountService->calculateCurrentTotals($fa);

                // $today already resolved for this project's organisation above.
                $disputeRemaining = $fa->dispute_window_expires_at
                    ? (int) Carbon::parse($today)->diffInDays(Carbon::parse($fa->dispute_window_expires_at->toDateString()), false)
                    : null;

                return [
                    'id'                       => $fa->id,
                    'reference'                => $fa->reference,
                    'status'                   => $fa->status,
                    'source_name'              => $fa->contract->title ?? $fa->tradePackage->name ?? null,
                    'is_trade_package'         => $fa->is_trade_package,
                    'final_balance_due'        => $totals['final_balance_due'] ?? null,
                    'retention_outstanding'    => $totals['retention_outstanding'] ?? null,
                    'is_snapshotted'           => $fa->isSnapshotted(),
                    'final_certificate_status' => $fa->isFinalCertificateIssued() ? 'issued' : 'not_issued',
                    'dispute_window_expires_at'    => $fa->dispute_window_expires_at,
                    'dispute_window_remaining_days' => $disputeRemaining,
                    'close_out_progress'      => $finalAccountService->getCloseOutProgress($fa),
                    'action_url'              => \App\Services\TradePackages\WorkspaceNavigationResolver::actionUrl(
                        $fa->project_id, \App\Models\CalendarEvent::SOURCE_FINAL_ACCOUNT, $fa->id, $fa->trade_package_id
                    ),
                ];
            });

        return response()->json([
            'main_contract'       => $mainContract,
            'ai_analysis'         => $aiAnalysis,
            'commercial'          => $commercial,
            'documents_count'     => $documentsCount,
            'upcoming_deadlines'  => $upcomingDeadlines,
            'operational_summary' => $operationalSummary,
            'upcoming_actions'    => $actionsSummary,
            'project_health'      => $projectHealth,
            'risk_summary'        => $riskSummary,
            'final_accounts'      => $finalAccounts,
        ]);
    }
}
