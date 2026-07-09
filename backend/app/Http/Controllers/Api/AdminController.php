<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\SuresignNotification;
use App\Models\SuresignSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    public function dashboard(Request $request)
    {
        $totalCompanies  = Organization::count();
        $totalProjects   = Project::count();
        $activeProjects  = Project::where('status', 'active')->count();
        $totalUsers      = User::count();
        $totalDocuments  = FileUpload::count();

        // Storage
        $storageUsedBytes = DB::table('file_uploads')->sum('file_size') ?? 0;
        $storageUsedGB    = round($storageUsedBytes / (1024 ** 3), 2);

        // Monthly AI usage (conversations started this calendar month)
        $monthlyAiUsage = AiConversation::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $recentCompanies = Organization::select('id', 'name', 'email', 'created_at')
            ->withCount(['users', 'projects'])
            ->latest()
            ->limit(8)
            ->get();

        $recentProjects = Project::select('id', 'name', 'code', 'status', 'organization_id', 'created_at')
            ->with('organization:id,name')
            ->latest()
            ->limit(8)
            ->get();

        $recentDocuments = FileUpload::select('id', 'original_name', 'project_id', 'organization_id', 'file_size', 'mime_type', 'created_at')
            ->with([
                'project:id,name,code',
                'uploader:id,first_name,last_name,name',
            ])
            ->latest()
            ->limit(8)
            ->get()
            ->map(function ($doc) {
                return [
                    'id'            => $doc->id,
                    'name'          => $doc->original_name,
                    'project_name'  => $doc->project?->name,
                    'project_code'  => $doc->project?->code,
                    'uploaded_by'   => $doc->uploader
                        ? trim(($doc->uploader->first_name ?? '') . ' ' . ($doc->uploader->last_name ?? '')) ?: $doc->uploader->name
                        : null,
                    'file_size'     => $doc->file_size,
                    'mime_type'     => $doc->mime_type,
                    'created_at'    => $doc->created_at,
                ];
            });

        $recentActivity = ProjectActivity::with([
                'user:id,first_name,last_name,name',
                'project:id,name,code',
                'organization:id,name',
            ])
            ->latest()
            ->limit(15)
            ->get()
            ->map(function ($a) {
                $userName = null;
                if ($a->user) {
                    $userName = trim(($a->user->first_name ?? '') . ' ' . ($a->user->last_name ?? '')) ?: $a->user->name;
                }
                return [
                    'id'           => $a->id,
                    'type'         => $a->activity_type,
                    'title'        => $a->title,
                    'description'  => $a->description,
                    'user_name'    => $userName,
                    'project_name' => $a->project?->name,
                    'org_name'     => $a->organization?->name,
                    'created_at'   => $a->created_at,
                ];
            });

        $recentNotifications = SuresignNotification::select('id', 'user_id', 'type', 'title', 'message', 'is_read', 'created_at')
            ->with('user:id,first_name,last_name,name')
            ->latest()
            ->limit(8)
            ->get()
            ->map(function ($n) {
                $userName = null;
                if ($n->user) {
                    $userName = trim(($n->user->first_name ?? '') . ' ' . ($n->user->last_name ?? '')) ?: $n->user->name;
                }
                return [
                    'id'         => $n->id,
                    'type'       => $n->type,
                    'title'      => $n->title,
                    'message'    => $n->message,
                    'is_read'    => $n->is_read,
                    'user_name'  => $userName,
                    'created_at' => $n->created_at,
                ];
            });

        $unreadNotifications = SuresignNotification::where('is_read', false)->count();

        return response()->json([
            'stats' => [
                'total_companies'  => $totalCompanies,
                'total_projects'   => $totalProjects,
                'active_projects'  => $activeProjects,
                'total_users'      => $totalUsers,
                'total_documents'  => $totalDocuments,
                'monthly_ai_usage' => $monthlyAiUsage,
                'storage_used_gb'  => $storageUsedGB,
                'storage_used'     => $storageUsedGB > 0 ? $storageUsedGB . ' GB' : '0 GB',
            ],
            'recent_companies'    => $recentCompanies,
            'recent_projects'     => $recentProjects,
            'recent_documents'    => $recentDocuments,
            'recent_activity'     => $recentActivity,
            'recent_notifications' => $recentNotifications,
            'activity' => [
                'docs_today'           => FileUpload::whereDate('created_at', today())->count(),
                'ai_requests'          => $monthlyAiUsage,
                'active_sessions'      => 0,
                'support_tickets'      => 0,
                'unread_notifications' => $unreadNotifications,
            ],
        ]);
    }

    public function projects(Request $request)
    {
        $query = Project::with('organization:id,name')
            ->select('id', 'organization_id', 'name', 'code', 'status', 'type', 'contract_type',
                     'contract_value', 'currency', 'start_date', 'end_date', 'created_at');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($orgId = $request->input('organization_id')) {
            $query->where('organization_id', $orgId);
        }

        $projects = $query->latest()->paginate(25);

        return response()->json($projects);
    }

    public function documents(Request $request)
    {
        $query = FileUpload::with(['organization:id,name', 'project:id,name,code'])
            ->select('id', 'organization_id', 'project_id', 'original_name', 'file_size',
                     'mime_type', 'folder_path', 'created_at', 'uploaded_by');

        if ($search = $request->input('search')) {
            $query->where('original_name', 'like', "%{$search}%");
        }

        if ($orgId = $request->input('organization_id')) {
            $query->where('organization_id', $orgId);
        }

        if ($projectId = $request->input('project_id')) {
            $query->where('project_id', $projectId);
        }

        $documents = $query->latest()->paginate(50);

        return response()->json($documents);
    }

    public function organizations(Request $request)
    {
        $orgs = Organization::withCount(['users', 'projects'])
            ->with('branding:organization_id,logo_path')
            ->latest()
            ->paginate(25);

        $orgs->getCollection()->transform(function ($org) {
            $org->logo_url = $org->branding?->logo_path
                ? url('storage/' . $org->branding->logo_path)
                : null;
            return $org;
        });

        return response()->json($orgs);
    }

    public function templates(Request $request)
    {
        // Return document templates scoped to no organization (global templates)
        $templates = DB::table('document_templates')
            ->whereNull('organization_id')
            ->orWhere('is_global', true)
            ->latest()
            ->paginate(25);

        return response()->json($templates);
    }

    public function storage(Request $request)
    {
        $byOrg = Organization::select('id', 'name')
            ->withSum('fileUploads as total_bytes', 'file_size')
            ->orderByDesc('total_bytes')
            ->limit(20)
            ->get();

        $totalBytes = DB::table('file_uploads')->sum('file_size') ?? 0;

        return response()->json([
            'total_bytes'   => $totalBytes,
            'total_gb'      => round($totalBytes / (1024 ** 3), 2),
            'by_organization' => $byOrg,
        ]);
    }

    public function systemLogs(Request $request)
    {
        $logPath = storage_path('logs/laravel.log');

        if (!file_exists($logPath)) {
            return response()->json(['data' => []]);
        }

        $lines = array_reverse(array_filter(explode("\n", file_get_contents($logPath))));
        $entries = [];

        foreach (array_slice($lines, 0, 200) as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.+)$/', $line, $m)) {
                $entries[] = [
                    'timestamp' => $m[1],
                    'channel'   => $m[2],
                    'level'     => strtolower($m[3]),
                    'message'   => $m[4],
                ];
            }
        }

        return response()->json(['data' => $entries]);
    }

    public function settings(Request $request)
    {
        $settings = SuresignSetting::instance();

        return response()->json([
            'platform_name' => $settings->platform_name ?: config('app.name', 'SureSign'),
            'support_email' => $settings->support_email ?: '',
            'max_upload_mb' => $settings->max_upload_mb,
            'doc_gen_enabled'         => $settings->feature_document_generation,
            'white_label_enabled'     => $settings->feature_white_label,
            'self_register_enabled'   => $settings->feature_self_registration,
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'platform_name'          => 'nullable|string|max:255',
            'support_email'          => 'nullable|email|max:255',
            'max_upload_mb'          => 'nullable|integer|min:1|max:2048',
            'doc_gen_enabled'        => 'sometimes|boolean',
            'white_label_enabled'    => 'sometimes|boolean',
            'self_register_enabled'  => 'sometimes|boolean',
        ]);

        $settings = SuresignSetting::instance();
        $settings->update([
            'platform_name'                 => $validated['platform_name'] ?? $settings->platform_name,
            'support_email'                 => $validated['support_email'] ?? $settings->support_email,
            'max_upload_mb'                 => $validated['max_upload_mb'] ?? $settings->max_upload_mb,
            'feature_document_generation'   => $validated['doc_gen_enabled'] ?? $settings->feature_document_generation,
            'feature_white_label'           => $validated['white_label_enabled'] ?? $settings->feature_white_label,
            'feature_self_registration'     => $validated['self_register_enabled'] ?? $settings->feature_self_registration,
        ]);

        return response()->json(['message' => 'Settings updated.']);
    }

    // ── Document Explorer ─────────────────────────────────────────────

    private const MODULE_FOLDERS = [
        'contracts'            => 'Contracts',
        'subcontracts'         => 'Subcontracts',
        'commercial'           => 'Commercial',
        'payment_applications' => 'Payment Applications',
        'variations'           => 'Variations',
        'notices'              => 'Notices',
        'adjudication'         => 'Adjudication',
        'rfis'                 => 'RFIs',
        'meetings'             => 'Meetings',
        'qa_reports'           => 'QA Reports',
        'snagging'             => 'Snagging',
        'closeout'             => 'Closeout',
        'site_reports'         => 'Site Reports',
        'general'              => 'General Documents',
    ];

    /**
     * GET /api/admin/documents/explorer
     * Returns all organizations with file/project counts.
     */
    public function explorerCompanies(Request $request)
    {
        $companies = Organization::select('id', 'name')
            ->withCount('projects')
            ->with('branding:organization_id,logo_path')
            ->get()
            ->map(function ($org) {
                return [
                    'id'             => $org->id,
                    'name'           => $org->name,
                    'projects_count' => $org->projects_count,
                    'files_count'    => FileUpload::where('organization_id', $org->id)->count(),
                    'storage_size'   => (int) FileUpload::where('organization_id', $org->id)->sum('file_size'),
                    'logo_url'       => $org->branding?->logo_path
                        ? url('storage/' . $org->branding->logo_path)
                        : null,
                ];
            });

        return response()->json(['companies' => $companies]);
    }

    /**
     * GET /api/admin/documents/explorer/company/{organization}
     * Returns projects for an organization with file counts.
     */
    public function explorerProjects(Request $request, Organization $organization)
    {
        $projects = Project::where('organization_id', $organization->id)
            ->select('id', 'name', 'code')
            ->get()
            ->map(function ($project) {
                return [
                    'id'            => $project->id,
                    'name'          => $project->name,
                    'code'          => $project->code,
                    'files_count'   => FileUpload::where('project_id', $project->id)->count(),
                    'storage_size'  => (int) FileUpload::where('project_id', $project->id)->sum('file_size'),
                    'last_uploaded' => FileUpload::where('project_id', $project->id)->max('created_at'),
                ];
            });

        return response()->json([
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'projects'     => $projects,
        ]);
    }

    /**
     * GET /api/admin/documents/explorer/project/{project}
     * Returns module folder summaries for a project.
     */
    public function explorerModules(Request $request, Project $project)
    {
        $counts = FileUpload::where('project_id', $project->id)
            ->whereNotNull('module_key')
            ->select('module_key', DB::raw('count(*) as files_count'), DB::raw('max(created_at) as last_updated'))
            ->groupBy('module_key')
            ->get()
            ->keyBy('module_key');

        $generalExtra = FileUpload::where('project_id', $project->id)
            ->whereNull('module_key')
            ->count();

        $folders = [];
        foreach (self::MODULE_FOLDERS as $key => $name) {
            $row   = $counts->get($key);
            if ($key === 'subcontracts') {
                $count = FileUpload::where('project_id', $project->id)
                    ->where(function ($q) {
                        $q->where('module_key', 'subcontracts')
                          ->orWhere(function ($q2) {
                              $q2->where('folder_key', 'contracts/subcontract')
                                  ->whereNotNull('trade_package_id');
                          });
                    })
                    ->count();
            } else {
                $count = ($row ? (int) $row->files_count : 0) + ($key === 'general' ? $generalExtra : 0);
            }
            $folders[] = [
                'key'          => $key,
                'name'         => $name,
                'files_count'  => $count,
                'last_updated' => $row ? $row->last_updated : null,
            ];
        }

        return response()->json([
            'project' => [
                'id'           => $project->id,
                'name'         => $project->name,
                'code'         => $project->code,
                'organization' => $project->organization
                    ? ['id' => $project->organization->id, 'name' => $project->organization->name]
                    : null,
            ],
            'folders' => $folders,
        ]);
    }

    /**
     * GET /api/admin/documents/explorer/project/{project}/module/{moduleKey}
     * Returns paginated files in a module folder for a project.
     * Special handling for 'contracts' module to show subfolders.
     */
    public function explorerModuleFiles(Request $request, Project $project, string $moduleKey)
    {
        // Special handling for contracts module — show subfolders instead of files
        if ($moduleKey === 'contracts') {
            $contractSubfolders = [
                ['key' => 'contracts/main_contract',        'name' => 'Main Contract',          'type' => 'folder', 'files_count' => 0],
                ['key' => 'contracts/consultant_agreement',  'name' => 'Consultant Agreements',  'type' => 'folder', 'files_count' => 0],
                ['key' => 'contracts/supplier_agreement',    'name' => 'Supplier Agreements',    'type' => 'folder', 'files_count' => 0],
            ];

            // Get file counts for each subfolder
            foreach ($contractSubfolders as &$subfolder) {
                $count = FileUpload::where('project_id', $project->id)
                    ->where('folder_key', $subfolder['key'])
                    ->count();
                $subfolder['files_count'] = $count;
            }

            return response()->json([
                'type' => 'folders',
                'folders' => $contractSubfolders,
            ]);
        }

        if ($moduleKey === 'subcontracts') {
            $tradePackages = \App\Models\TradePackage::where('project_id', $project->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description'])
                ->map(function ($pkg) use ($project) {
                    $count = FileUpload::where('project_id', $project->id)
                        ->where('trade_package_id', $pkg->id)
                        ->count();
                    $displayRef = ($project->code && $pkg->package_code)
                        ? "{$project->code}-{$pkg->package_code}"
                        : ($pkg->package_reference ?? $pkg->package_code);
                    return [
                        'type'              => 'trade_package',
                        'id'                => $pkg->id,
                        'name'              => $pkg->name,
                        'package_code'      => $pkg->package_code,
                        'package_reference' => $displayRef,
                        'contractor_name'   => $pkg->contractor_name,
                        'description'       => $pkg->description,
                        'key'               => "subcontracts/package/{$pkg->id}",
                        'files_count'       => $count,
                    ];
                });

            return response()->json([
                'type'           => 'trade_packages',
                'trade_packages' => $tradePackages,
            ]);
        }

        // Special handling for contracts/subcontract — show trade packages as folders + direct files
        if ($moduleKey === 'contracts/subcontract') {
            $tradePackages = \App\Models\TradePackage::where('project_id', $project->id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description'])
                ->map(function ($pkg) use ($project) {
                    $count = FileUpload::where('project_id', $project->id)
                        ->where('trade_package_id', $pkg->id)
                        ->count();
                    $displayRef = ($project->code && $pkg->package_code)
                        ? "{$project->code}-{$pkg->package_code}"
                        : ($pkg->package_reference ?? $pkg->package_code);
                    return [
                        'type' => 'trade_package',
                        'id' => $pkg->id,
                        'name' => $pkg->name,
                        'package_code' => $pkg->package_code,
                        'package_reference' => $displayRef,
                        'contractor_name' => $pkg->contractor_name,
                        'description' => $pkg->description,
                        'key' => "contracts/subcontract/package/{$pkg->id}",
                        'files_count' => $count,
                    ];
                });

            return response()->json([
                'type' => 'trade_packages',
                'trade_packages' => $tradePackages,
            ]);
        }

        if (preg_match('/^subcontracts\/package\/(\d+)$/', $moduleKey, $matches)) {
            $tradePackageId = (int) $matches[1];
            $tradePackage = \App\Models\TradePackage::where('project_id', $project->id)
                ->whereKey($tradePackageId)
                ->firstOrFail(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description']);
            $query = FileUpload::where('project_id', $project->id)
                ->where('trade_package_id', $tradePackageId)
                ->with(['uploader:id,name', 'project:id,name,code', 'organization:id,name'])
                ->latest();

            $paginated = $query->paginate(50);

            return response()->json([
                'type'          => 'files',
                'trade_package' => $tradePackage,
                'data'          => $paginated->items(),
                'total'         => $paginated->total(),
                'per_page'      => $paginated->perPage(),
                'from'          => $paginated->firstItem(),
                'to'            => $paginated->lastItem(),
                'current_page'  => $paginated->currentPage(),
                'last_page'     => $paginated->lastPage(),
            ]);
        }

        // Handle trade package files listing (e.g., 'contracts/subcontract/package/123')
        if (preg_match('/^contracts\/subcontract\/package\/(\d+)$/', $moduleKey, $matches)) {
            $tradePackageId = (int) $matches[1];
            $tradePackage = \App\Models\TradePackage::where('project_id', $project->id)
                ->whereKey($tradePackageId)
                ->firstOrFail(['id', 'name', 'package_code', 'package_reference', 'contractor_name', 'description']);
            $query = FileUpload::where('project_id', $project->id)
                ->where('trade_package_id', $tradePackageId)
                ->with(['uploader:id,name', 'project:id,name,code', 'organization:id,name'])
                ->latest();
            
            $paginated = $query->paginate(50);
            
            // Return with consistent structure
            return response()->json([
                'type' => 'files',
                'trade_package' => $tradePackage,
                'data' => $paginated->items(),
                'total' => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'from' => $paginated->firstItem(),
                'to' => $paginated->lastItem(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
            ]);
        }

        // Handle other contract subfolder files (e.g., 'contracts/main_contract', 'contracts/consultant_agreement')
        if (str_starts_with($moduleKey, 'contracts/')) {
            $query = FileUpload::where('project_id', $project->id)
                ->where('folder_key', $moduleKey)
                ->with(['uploader:id,name', 'project:id,name,code', 'organization:id,name']);
            return response()->json($query->latest()->paginate(50));
        }

        // Standard file listing for other modules
        $query = FileUpload::where('project_id', $project->id)
            ->with(['uploader:id,name', 'project:id,name,code', 'organization:id,name']);

        if ($moduleKey === 'general') {
            $query->where(function ($q) {
                $q->where('module_key', 'general')->orWhereNull('module_key');
            });
        } else {
            $query->where('module_key', $moduleKey);
        }

        return response()->json($query->latest()->paginate(50));
    }

    public function auditLog(Request $request)
    {
        $user = $request->user();

        $query = ActivityLog::with('user:id,name,email')
            ->latest();

        // Both Super Admin and Admin are platform-wide roles that manage every
        // organization's projects — client-role users never reach this
        // middleware-gated endpoint at all, so there's no tenant to scope to
        // here. Optional organization_id filter is just a convenience filter.
        if ($request->filled('organization_id')) {
            $query->where('organization_id', $request->organization_id);
        }

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->project_id);
        }
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->filled('search')) {
            $query->where('description', 'like', '%' . $request->search . '%');
        }

        return response()->json($query->paginate(50));
    }
}
