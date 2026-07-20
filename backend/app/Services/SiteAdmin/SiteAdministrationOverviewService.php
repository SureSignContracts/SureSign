<?php

namespace App\Services\SiteAdmin;

use App\Models\EotRequest;
use App\Models\MeetingMinutes;
use App\Models\Project;
use App\Models\Rfi;
use App\Models\SiteDiary;
use App\Models\SiteInstruction;
use App\Models\User;
use App\Services\Commercial\CommercialAggregationService;
use App\Services\TradePackages\WorkspaceNavigationResolver;
use Illuminate\Support\Collection;

/**
 * Builds the organisation-wide Site Admin overview payload — read-only
 * monitoring/browsing across every RFI, Site Instruction, Site Diary,
 * Meeting and EOT Request the user can see, across every accessible
 * project. Every row deep-links into the Project Workspace, where the
 * actual record is created/edited; this service never mutates anything.
 *
 * Tenant isolation is enforced entirely via
 * CommercialAggregationService::scopedProjectIds() — the same org-scoped,
 * Admin-narrowed rule Global Commercial/Dashboard/Documents already use.
 *
 * Each module's row list is bounded (self::ROW_LIMIT) — this is a triage/
 * browse surface, not a full register export; summary counts below are
 * computed independently via grouped counts so they stay accurate even
 * once a module's row list is truncated.
 */
class SiteAdministrationOverviewService
{
    private const ROW_LIMIT = 100;

    public function __construct(private CommercialAggregationService $aggregation) {}

    public function build(User $user): array
    {
        $projectIds = $this->aggregation->scopedProjectIds($user);

        $projectsById = Project::whereIn('id', $projectIds)->get(['id', 'name', 'organization_id'])->keyBy('id');

        $rfis         = Rfi::whereIn('project_id', $projectIds)->latest('raised_date')->limit(self::ROW_LIMIT)->get();
        $instructions = SiteInstruction::whereIn('project_id', $projectIds)->latest('issued_date')->limit(self::ROW_LIMIT)->get();
        $diaries      = SiteDiary::whereIn('project_id', $projectIds)->latest('diary_date')->limit(self::ROW_LIMIT)->get();
        $meetings     = MeetingMinutes::whereIn('project_id', $projectIds)->latest('meeting_date')->limit(self::ROW_LIMIT)->get();
        $eots         = EotRequest::whereIn('project_id', $projectIds)->latest('notice_date')->limit(self::ROW_LIMIT)->get();

        return [
            'summary' => [
                'rfis'              => $this->countsByStatus(Rfi::class, $projectIds, ['open', 'pending_response', 'responded', 'closed', 'draft']),
                'site_instructions' => $this->countsByStatus(SiteInstruction::class, $projectIds, ['draft', 'issued']),
                'site_diaries'      => $this->countsByStatus(SiteDiary::class, $projectIds, ['draft', 'submitted', 'approved']),
                'meetings'          => $this->countsByStatus(MeetingMinutes::class, $projectIds, ['draft', 'issued', 'approved']),
                'eot_requests'      => $this->countsByStatus(EotRequest::class, $projectIds, ['draft', 'submitted', 'under_assessment', 'granted', 'refused']),
            ],
            'rfis'              => $rfis->map(fn (Rfi $r) => $this->mapRfi($r, $projectsById))->filter()->values()->all(),
            'site_instructions' => $instructions->map(fn (SiteInstruction $i) => $this->mapSiteInstruction($i, $projectsById))->filter()->values()->all(),
            'site_diaries'      => $diaries->map(fn (SiteDiary $d) => $this->mapSiteDiary($d, $projectsById))->filter()->values()->all(),
            'meetings'          => $meetings->map(fn (MeetingMinutes $m) => $this->mapMeeting($m, $projectsById))->filter()->values()->all(),
            'eot_requests'      => $eots->map(fn (EotRequest $e) => $this->mapEot($e, $projectsById))->filter()->values()->all(),
            'meta' => [
                'row_limit'    => self::ROW_LIMIT,
                'generated_at' => now()->toIso8601String(),
            ],
        ];
    }

    /** @return array<string,int> */
    private function countsByStatus(string $modelClass, Collection $projectIds, array $statuses): array
    {
        $counts = $modelClass::whereIn('project_id', $projectIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];
        foreach ($statuses as $status) {
            $byStatus[$status] = (int) ($counts[$status] ?? 0);
        }

        return ['total' => (int) $counts->sum()] + $byStatus;
    }

    private function mapRfi(Rfi $rfi, Collection $projectsById): ?array
    {
        $project = $projectsById->get($rfi->project_id);
        if (!$project) {
            return null;
        }

        return [
            'id'           => $rfi->id,
            'module'       => 'rfi',
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'reference'    => "RFI #{$rfi->rfi_number}",
            'title'        => $rfi->subject,
            'status'       => $rfi->status,
            'date'         => $rfi->raised_date?->toDateString(),
            'secondary'    => $rfi->response_due_date ? "Response due {$rfi->response_due_date->toDateString()}" : null,
            'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'rfi', $rfi->id),
        ];
    }

    private function mapSiteInstruction(SiteInstruction $instruction, Collection $projectsById): ?array
    {
        $project = $projectsById->get($instruction->project_id);
        if (!$project) {
            return null;
        }

        return [
            'id'           => $instruction->id,
            'module'       => 'site_instruction',
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'reference'    => "SI #{$instruction->instruction_number}",
            'title'        => $instruction->title,
            'status'       => $instruction->status,
            'date'         => $instruction->issued_date?->toDateString(),
            'secondary'    => $instruction->issued_to ? "Issued to {$instruction->issued_to}" : null,
            'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'site_instruction', $instruction->id),
        ];
    }

    private function mapSiteDiary(SiteDiary $diary, Collection $projectsById): ?array
    {
        $project = $projectsById->get($diary->project_id);
        if (!$project) {
            return null;
        }

        return [
            'id'           => $diary->id,
            'module'       => 'site_diary',
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'reference'    => 'Site Diary',
            'title'        => $diary->diary_date?->toDateString(),
            'status'       => $diary->status,
            'date'         => $diary->diary_date?->toDateString(),
            'secondary'    => $diary->workers_on_site !== null ? "{$diary->workers_on_site} workers on site" : null,
            'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'site_diary', $diary->id),
        ];
    }

    private function mapMeeting(MeetingMinutes $meeting, Collection $projectsById): ?array
    {
        $project = $projectsById->get($meeting->project_id);
        if (!$project) {
            return null;
        }

        return [
            'id'           => $meeting->id,
            'module'       => 'meeting',
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'reference'    => "Meeting #{$meeting->meeting_number}",
            'title'        => $meeting->title,
            'status'       => $meeting->status,
            'date'         => $meeting->meeting_date?->toDateString(),
            'secondary'    => $meeting->location,
            'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'meeting', $meeting->id),
        ];
    }

    private function mapEot(EotRequest $eot, Collection $projectsById): ?array
    {
        $project = $projectsById->get($eot->project_id);
        if (!$project) {
            return null;
        }

        return [
            'id'           => $eot->id,
            'module'       => 'eot_request',
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'reference'    => "EOT #{$eot->eot_number}",
            'title'        => $eot->title,
            'status'       => $eot->status,
            'date'         => $eot->notice_date?->toDateString(),
            'secondary'    => $eot->days_claimed ? "{$eot->days_claimed} days claimed" : null,
            'action_url'   => WorkspaceNavigationResolver::actionUrl($project->id, 'eot_request', $eot->id, $eot->trade_package_id),
        ];
    }
}
