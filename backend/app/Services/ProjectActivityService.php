<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ProjectActivityService
{
    /**
     * Record an activity for a project.
     */
    public static function record(
        Project $project,
        User $user,
        string $activityType,
        string $title,
        ?string $description = null,
        ?Model $relatedModel = null,
        array $metadata = []
    ): ProjectActivity {
        return ProjectActivity::create([
            'organization_id' => $project->organization_id,
            'project_id'      => $project->id,
            'user_id'         => $user->id,
            'activity_type'   => $activityType,
            'title'           => $title,
            'description'     => $description,
            'related_type'    => $relatedModel ? get_class($relatedModel) : null,
            'related_id'      => $relatedModel ? $relatedModel->id : null,
            'metadata'        => empty($metadata) ? null : $metadata,
        ]);
    }
}
