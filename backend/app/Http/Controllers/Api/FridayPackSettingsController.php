<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FridayPackSettings;
use App\Models\Project;
use App\Services\ProjectActivityService;
use App\Support\FridayPack\FridayPackSections;
use Illuminate\Http\Request;

/**
 * Automated Friday Pack, V1B — project Friday Pack settings. One row per
 * project, created on first write (GET returns V1 defaults when no row
 * exists yet, mirroring FeatureAvailabilityService's own "no row = default"
 * convention) rather than requiring every project to be pre-seeded with a
 * settings row.
 */
class FridayPackSettingsController extends Controller
{
    private function authorize(Request $request, Project $project): void
    {
        $user = $request->user();
        if ($user->hasRole('Super Admin') || $user->hasRole('Admin')) return;
        if ($user->organization_id !== $project->organization_id) abort(403, 'Access denied.');
    }

    public function show(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $settings = FridayPackSettings::where('project_id', $project->id)->first();

        if (!$settings) {
            return response()->json([
                'project_id'                    => $project->id,
                'enabled'                       => true,
                'included_sections'             => FridayPackSections::defaults(),
                // V1E — automatic generation always defaults OFF, even for
                // the virtual "no row yet" default response (production
                // safety requirement: no project starts generating
                // automatically without an explicit opt-in save).
                'automatic_generation_enabled'  => false,
                'generation_hour_local'         => 15,
                // V1F — recipients are operational delivery settings; no
                // row yet means no recipients configured.
                'recipients'                    => [],
                'is_default'                    => true,
            ]);
        }

        return response()->json($settings->load(['creator:id,name', 'updatedBy:id,name']));
    }

    public function update(Request $request, Project $project)
    {
        $this->authorize($request, $project);

        $validated = $request->validate([
            'enabled'                       => 'required|boolean',
            'included_sections'             => 'required|array',
            'included_sections.*'           => 'string',
            // V1E — deliberately no timezone/weekday field: schedule day is
            // always Friday, timezone is always the organisation's own.
            'automatic_generation_enabled'  => 'required|boolean',
            'generation_hour_local'         => 'required|integer|min:0|max:23',
            // V1F — operational delivery recipients. Optional (a project
            // may have automatic/manual generation configured with no
            // recipients yet); name is optional, email is required.
            'recipients'                    => 'nullable|array',
            'recipients.*.name'             => 'nullable|string|max:255',
            'recipients.*.email'            => 'required|email|max:255',
        ]);

        if (!FridayPackSections::isValidSet($validated['included_sections'])) {
            abort(422, 'included_sections contains an unrecognised section key.');
        }

        $existing = FridayPackSettings::where('project_id', $project->id)->first();

        $settings = FridayPackSettings::updateOrCreate(
            ['project_id' => $project->id],
            [
                'organization_id'               => $project->organization_id,
                'enabled'                       => $validated['enabled'],
                'included_sections'             => array_values(array_unique($validated['included_sections'])),
                'automatic_generation_enabled'  => $validated['automatic_generation_enabled'],
                'generation_hour_local'         => $validated['generation_hour_local'],
                'recipients'                    => array_values($validated['recipients'] ?? []),
                // Preserve the real original creator on update — created_by
                // must never be overwritten by whoever happens to save
                // settings next; only updated_by reflects the current actor.
                'created_by'        => $existing?->created_by ?? $request->user()->id,
                'updated_by'        => $request->user()->id,
            ],
        );

        ProjectActivityService::record(
            $project,
            $request->user(),
            'friday_pack_settings_updated',
            'Friday Pack settings updated',
            null,
            $settings,
        );

        return response()->json($settings->fresh()->load(['creator:id,name', 'updatedBy:id,name']));
    }
}
