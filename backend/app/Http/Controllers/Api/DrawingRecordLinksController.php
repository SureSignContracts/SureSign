<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DrawingHotspotLink;
use App\Models\Project;
use App\Support\Drawings\DrawingLinkableType;
use Illuminate\Http\Request;

/**
 * Drawing Phase 6B — the two record-centric (not hotspot-centric) endpoints
 * that sit alongside DrawingHotspotLinkController:
 *
 * - linkableRecords(): the compact searchable/paginated record selector
 *   behind "Link Record" (Part U) — a dedicated endpoint rather than
 *   stitching together Snag/Rfi/QaReport/Variation's own index() endpoints
 *   client-side, since those four have genuinely incompatible shapes today
 *   (different search support, different page sizes, different response
 *   envelopes) — reported and decided in the Phase 6 final report rather
 *   than silently picked.
 * - forRecord(): the reverse "Drawing Locations" lookup a supported
 *   record's own detail view uses (Part Y) — one endpoint for all four
 *   types rather than duplicating this query onto each record's own
 *   controller.
 */
class DrawingRecordLinksController extends Controller
{
    private function authorize(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) {
            return;
        }
        if ($user->organization_id !== $project->organization_id) {
            abort(403, 'Access denied.');
        }
    }

    /**
     * Search columns mirror each model's own existing index() endpoint
     * (SnagController/RfiController/QaReportController/VariationController)
     * — never a new/different search behaviour invented here.
     */
    private function applySearch($query, string $type, string $search)
    {
        return $query->where(function ($q) use ($type, $search) {
            match ($type) {
                DrawingLinkableType::SNAG => $q->where('snag_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%"),
                DrawingLinkableType::RFI => $q->where('rfi_number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%"),
                DrawingLinkableType::QA_REPORT => $q->where('report_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('area', 'like', "%{$search}%"),
                DrawingLinkableType::VARIATION => $q->where('variation_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%"),
                default => null,
            };
        });
    }

    private function labelFor(string $type, $record): string
    {
        $parts = match ($type) {
            DrawingLinkableType::SNAG => ['S-'.$record->snag_number, $record->title],
            DrawingLinkableType::RFI => ['RFI-'.$record->rfi_number, $record->subject],
            DrawingLinkableType::QA_REPORT => ['QA-'.$record->report_number, $record->title],
            DrawingLinkableType::VARIATION => ['V-'.$record->variation_number, $record->title],
            default => [null, null],
        };

        return trim(implode(' ', array_filter($parts, fn ($p) => filled($p))));
    }

    public function linkableRecords(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'type' => 'required|string',
            'search' => 'nullable|string|max:255',
        ]);

        if (! DrawingLinkableType::isValid($validated['type'])) {
            return response()->json(['message' => 'Unsupported record type.'], 422);
        }

        $modelClass = DrawingLinkableType::modelFor($validated['type']);
        $query = $modelClass::where('project_id', $project->id);

        if (! empty($validated['search'])) {
            $query = $this->applySearch($query, $validated['type'], $validated['search']);
        }

        $records = $query->latest()->limit(20)->get();

        return response()->json([
            'data' => $records->map(fn ($r) => ['id' => $r->id, 'label' => $this->labelFor($validated['type'], $r)])->values(),
        ]);
    }

    public function forRecord(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'type' => 'required|string',
            'record_id' => 'required|integer',
        ]);

        if (! DrawingLinkableType::isValid($validated['type'])) {
            return response()->json(['message' => 'Unsupported record type.'], 422);
        }

        $modelClass = DrawingLinkableType::modelFor($validated['type']);
        $record = $modelClass::find($validated['record_id']);

        if (! $record || (int) $record->project_id !== $project->id) {
            return response()->json(['data' => []]);
        }

        $links = DrawingHotspotLink::where('linkable_type', $modelClass)
            ->where('linkable_id', $record->id)
            ->with(['hotspot.revision.drawing'])
            ->get();

        $presented = $links->map(function ($link) {
            $hotspot = $link->hotspot;
            $revision = $hotspot?->revision;
            $drawing = $revision?->drawing;
            if (! $hotspot || ! $revision || ! $drawing) {
                return null;
            }

            return [
                'link_id' => $link->id,
                'drawing_id' => $drawing->id,
                'drawing_number' => $drawing->drawing_number,
                'revision_id' => $revision->id,
                'revision_code' => $revision->revision_code,
                'page_number' => $hotspot->page_number,
                'hotspot_id' => $hotspot->id,
                'hotspot_label' => $hotspot->label,
                'view_url' => "/app/projects/{$drawing->project_id}/drawings/{$drawing->id}?revision={$revision->id}&page={$hotspot->page_number}&hotspot={$hotspot->id}",
            ];
        })->filter()->values();

        return response()->json(['data' => $presented]);
    }
}
