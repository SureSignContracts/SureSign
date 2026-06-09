<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\TradePackages\GenerateTradePackageFoldersService;
use Illuminate\Http\Request;

class GenerateTradePackageFoldersController extends Controller
{
    public function __construct(
        private readonly GenerateTradePackageFoldersService $service
    ) {}

    /**
     * Generate multiple trade package folders for a project.
     *
     * POST /api/admin/projects/{project}/subcontracts/generate-trade-packages
     */
    public function store(Request $request, Project $project)
    {
        $user = $request->user();

        if (
            $user->organization_id !== $project->organization_id
            && !$user->hasRole('Super Admin')
            && !$user->hasRole('Admin')
        ) {
            abort(403, 'Access denied.');
        }

        $validated = $request->validate([
            'trade_packages'                => 'required|array|min:1',
            'trade_packages.*.name'         => 'required|string|max:255',
            'trade_packages.*.pkg_code'     => 'nullable|string|max:20',
            'trade_packages.*.is_custom'    => 'nullable|boolean',
            'trade_packages.*.original_name'=> 'nullable|string|max:255',
        ]);

        $result = $this->service->generate($project, $validated['trade_packages'], $user->id);

        $createdCount = count($result['created']);
        $skippedCount = count($result['skipped']);

        $parts = [];
        if ($createdCount > 0) {
            $parts[] = "{$createdCount} trade " . ($createdCount === 1 ? 'package' : 'packages') . ' created';
        }
        if ($skippedCount > 0) {
            $parts[] = "{$skippedCount} already existed";
        }

        $message = implode(', ', $parts) . '.';

        return response()->json([
            'created' => $result['created'],
            'skipped' => $result['skipped'],
            'message' => $message,
        ], 201);
    }
}
