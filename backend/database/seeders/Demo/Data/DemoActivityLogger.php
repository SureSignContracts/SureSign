<?php

namespace Database\Seeders\Demo\Data;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a ProjectActivity entry with an arbitrary historical timestamp.
 *
 * ProjectActivityService::record() (the real, production code path) always
 * stamps "now" — correct for live usage, useless for backdating a demo
 * project's 9-month history. This helper mirrors exactly what that service
 * does (same column mapping) but takes an explicit $when, per the schema
 * research confirming project_activities has no observer/auto-generation
 * to worry about conflicting with.
 *
 * Used by every Phase 2 seeder that creates a record which should leave a
 * trace in the project's activity feed — this is what makes the dashboard
 * activity feed emerge from real seeded events rather than being a
 * separately-authored, disconnected list.
 */
class DemoActivityLogger
{
    public static function log(
        Project $project,
        User $user,
        string $activityType,
        string $title,
        ?string $when,
        ?string $description = null,
        ?Model $relatedModel = null,
        array $metadata = []
    ): ProjectActivity {
        $activity = ProjectActivity::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'activity_type' => $activityType,
            'title' => $title,
            'description' => $description,
            'related_type' => $relatedModel ? get_class($relatedModel) : null,
            'related_id' => $relatedModel?->id,
            'metadata' => $metadata ?: null,
        ]);

        if ($when) {
            $activity->forceFill(['created_at' => $when, 'updated_at' => $when])->save();
        }

        return $activity;
    }
}
