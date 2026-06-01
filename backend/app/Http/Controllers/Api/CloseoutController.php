<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Closeout;
use App\Models\CloseoutItem;
use App\Models\Project;
use App\Services\ProjectActivityService;
use Illuminate\Http\Request;

class CloseoutController extends Controller
{
    /**
     * Get or auto-create the closeout record for a project, with its items.
     */
    public function show(Request $request, Project $project)
    {
        $closeout = Closeout::where('project_id', $project->id)
            ->with('items')
            ->first();

        if (!$closeout) {
            $closeout = $this->createDefaultCloseout($project, $request->user());
        }

        return response()->json($closeout->load('items'));
    }

    /**
     * Update closeout header (status, notes, title).
     */
    public function update(Request $request, Project $project)
    {
        $closeout = Closeout::where('project_id', $project->id)->firstOrFail();

        $validated = $request->validate([
            'title'  => 'sometimes|string|max:255',
            'status' => 'sometimes|in:pending,in_progress,completed,approved',
            'notes'  => 'nullable|string',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'completed' && $closeout->status !== 'completed') {
            $validated['completed_at'] = now();
        }

        $closeout->update($validated);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'closeout_updated',
            "Closeout status updated to {$closeout->fresh()->status}",
            null,
            $closeout
        );

        return response()->json($closeout->fresh()->load('items'));
    }

    /**
     * Update a single closeout item (toggle status, update notes).
     */
    public function updateItem(Request $request, Project $project, CloseoutItem $item)
    {
        $validated = $request->validate([
            'status'   => 'sometimes|in:pending,in_progress,completed,approved',
            'notes'    => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        if (isset($validated['status']) && $validated['status'] === 'completed' && $item->status !== 'completed') {
            $validated['completed_at'] = now();
        } elseif (isset($validated['status']) && $validated['status'] !== 'completed') {
            $validated['completed_at'] = null;
        }

        $item->update($validated);

        // Recalculate closeout overall status
        $closeout = $item->closeout;
        $this->recalculateCloseoutStatus($closeout);

        ProjectActivityService::record(
            $project,
            $request->user(),
            'closeout_item_updated',
            "Closeout item '{$item->title}' marked as {$item->fresh()->status}",
            null,
            $closeout
        );

        return response()->json($closeout->fresh()->load('items'));
    }

    /**
     * Add a custom closeout item.
     */
    public function addItem(Request $request, Project $project)
    {
        $closeout = Closeout::where('project_id', $project->id)->firstOrFail();

        $validated = $request->validate([
            'category' => 'required|string|max:100',
            'title'    => 'required|string|max:255',
            'due_date' => 'nullable|date',
            'notes'    => 'nullable|string',
        ]);

        $maxOrder = $closeout->items()->max('sort_order') ?? 0;

        $item = $closeout->items()->create(array_merge($validated, [
            'status'     => 'pending',
            'sort_order' => $maxOrder + 1,
        ]));

        return response()->json($closeout->fresh()->load('items'));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createDefaultCloseout(Project $project, $user): Closeout
    {
        $closeout = Closeout::create([
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'created_by'      => $user->id,
            'title'           => 'Project Closeout',
            'status'          => 'pending',
        ]);

        $defaultItems = [
            ['category' => 'Warranties',         'title' => 'Collateral warranties collected',          'sort_order' => 1],
            ['category' => 'Warranties',         'title' => 'Manufacturer warranties collected',         'sort_order' => 2],
            ['category' => 'O&M Manuals',        'title' => 'O&M manuals issued to client',              'sort_order' => 3],
            ['category' => 'O&M Manuals',        'title' => 'Commissioning records complete',            'sort_order' => 4],
            ['category' => 'As-Built Drawings',  'title' => 'As-built drawings issued',                  'sort_order' => 5],
            ['category' => 'As-Built Drawings',  'title' => 'Structural drawings updated',               'sort_order' => 6],
            ['category' => 'Certificates',       'title' => 'Practical completion certificate issued',   'sort_order' => 7],
            ['category' => 'Certificates',       'title' => 'Building regulations sign-off obtained',    'sort_order' => 8],
            ['category' => 'QA Completion',      'title' => 'All QA reports closed',                     'sort_order' => 9],
            ['category' => 'QA Completion',      'title' => 'Final inspection completed',                'sort_order' => 10],
            ['category' => 'Final Snagging',     'title' => 'All snag items resolved',                   'sort_order' => 11],
            ['category' => 'Final Snagging',     'title' => 'Defects liability period scheduled',        'sort_order' => 12],
            ['category' => 'Handover Documents', 'title' => 'Health & Safety file handed over',          'sort_order' => 13],
            ['category' => 'Handover Documents', 'title' => 'Project documents archived in system',      'sort_order' => 14],
            ['category' => 'Handover Documents', 'title' => 'Client training completed',                 'sort_order' => 15],
        ];

        foreach ($defaultItems as $item) {
            $closeout->items()->create(array_merge($item, ['status' => 'pending']));
        }

        return $closeout;
    }

    private function recalculateCloseoutStatus(Closeout $closeout): void
    {
        $items = $closeout->items;
        if ($items->isEmpty()) return;

        $total     = $items->count();
        $completed = $items->whereIn('status', ['completed', 'approved'])->count();

        if ($completed === 0) {
            $status = 'pending';
        } elseif ($completed === $total) {
            $status = 'completed';
        } else {
            $status = 'in_progress';
        }

        $closeout->update(['status' => $status]);
    }
}
