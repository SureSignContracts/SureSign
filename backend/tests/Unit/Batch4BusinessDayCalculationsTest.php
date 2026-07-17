<?php

namespace Tests\Unit;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationDeadline;
use App\Models\CalendarEvent;
use App\Models\Contract;
use App\Models\ContractDeadline;
use App\Models\ContractDeliverable;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Batch 4: verifies the model-level "is this overdue/due today" business-day
 * calculations are now organisation-timezone-aware, not UTC-server-blind —
 * specifically the exact midnight-boundary case the architecture audit
 * flagged: a UTC instant that is one calendar day in one timezone can
 * already be the next calendar day in another.
 */
class Batch4BusinessDayCalculationsTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgSeq = 0;

    private function makeOrg(string $timezone): Organization
    {
        $n = ++static::$orgSeq;

        return Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => $timezone]);
    }

    private function makeUser(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id]);
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id,
            'created_by'      => $user->id,
            'name'            => 'Project',
            'status'          => 'active',
        ]);
    }

    private function makeContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id'      => $project->id,
            'organization_id' => $project->organization_id,
            'created_by'      => $user->id,
            'title'           => 'Contract',
            'type'            => 'main_contract',
            'status'          => 'active',
        ]);
    }

    public function test_contract_deadline_is_due_today_in_manila_but_not_yet_in_new_york(): void
    {
        // Resolved date is a pure calendar DATE — nominally the same "day"
        // everywhere, but "is it today" depends entirely on the
        // organisation's own timezone at a given real-world instant.
        $manilaOrg = $this->makeOrg('Asia/Manila');
        $nyOrg     = $this->makeOrg('America/New_York');

        $manilaUser = $this->makeUser($manilaOrg);
        $nyUser     = $this->makeUser($nyOrg);

        $manilaProject = $this->makeProject($manilaOrg, $manilaUser);
        $nyProject     = $this->makeProject($nyOrg, $nyUser);
        $manilaContract = $this->makeContract($manilaProject, $manilaUser);
        $nyContract     = $this->makeContract($nyProject, $nyUser);

        // 23:30 UTC on the 16th is already 07:30 on the 17th in Manila
        // (UTC+8), but still 19:30 on the 16th in New York (UTC-4, EDT).
        Date::setTestNow('2026-07-16 23:30:00');

        // resolved_date isn't mass-assignable on this model (set directly in
        // production, not via create()) — assign then save.
        $manilaDeadline = ContractDeadline::create([
            'organization_id' => $manilaOrg->id,
            'project_id'      => $manilaProject->id,
            'contract_id'     => $manilaContract->id,
            'name'            => 'Deadline',
            'category'        => 'notice',
        ]);
        $manilaDeadline->resolved_date = '2026-07-17';
        $manilaDeadline->save();

        $nyDeadline = ContractDeadline::create([
            'organization_id' => $nyOrg->id,
            'project_id'      => $nyProject->id,
            'contract_id'     => $nyContract->id,
            'name'            => 'Deadline',
            'category'        => 'notice',
        ]);
        $nyDeadline->resolved_date = '2026-07-17';
        $nyDeadline->save();

        $this->assertTrue($manilaDeadline->isDueToday(), 'Manila is already on the 17th — this should read as due today.');
        $this->assertFalse($nyDeadline->isDueToday(), 'New York is still on the 16th — this should not yet be due today.');
        $this->assertSame(0, $manilaDeadline->daysFromToday());
        $this->assertSame(1, $nyDeadline->daysFromToday());

        Date::setTestNow();
    }

    public function test_calendar_event_overdue_status_respects_organisation_timezone(): void
    {
        $manilaOrg  = $this->makeOrg('Asia/Manila');
        $manilaUser = $this->makeUser($manilaOrg);
        $project    = $this->makeProject($manilaOrg, $manilaUser);

        Date::setTestNow('2026-07-16 23:30:00'); // 07:30 on the 17th in Manila

        $event = CalendarEvent::create([
            'organization_id' => $manilaOrg->id,
            'project_id'      => $project->id,
            'source_type'     => CalendarEvent::SOURCE_CONTRACT,
            'source_id'       => 1,
            'title'           => 'Event',
            'event_date'      => '2026-07-16', // yesterday, from Manila's perspective
        ]);

        $this->assertTrue($event->isOverdue(), 'Already the 17th in Manila — the 16th is now in the past.');
        $this->assertFalse($event->isDueToday());

        Date::setTestNow();
    }

    public function test_contract_deliverable_daysfromtoday_is_organisation_aware(): void
    {
        $nyOrg    = $this->makeOrg('America/New_York');
        $nyUser   = $this->makeUser($nyOrg);
        $nyProject = $this->makeProject($nyOrg, $nyUser);
        $contract = $this->makeContract($nyProject, $nyUser);

        Date::setTestNow('2026-07-16 23:30:00'); // still the 16th in New York (EDT)

        // resolved_date isn't mass-assignable on this model (set directly in
        // production, not via create()) — assign then save.
        $deliverable = ContractDeliverable::create([
            'organization_id' => $nyOrg->id,
            'project_id'      => $nyProject->id,
            'contract_id'     => $contract->id,
            'name'            => 'Deliverable',
            'category'        => 'design',
        ]);
        $deliverable->resolved_date = '2026-07-16';
        $deliverable->save();

        $this->assertSame(0, $deliverable->daysFromToday(), 'Still the 16th in New York — should be due today, not overdue.');
        $this->assertFalse($deliverable->isOverdue());

        Date::setTestNow();
    }

    public function test_adjudication_deadline_computed_status_uses_organisation_today(): void
    {
        $manilaOrg  = $this->makeOrg('Asia/Manila');
        $manilaUser = $this->makeUser($manilaOrg);
        $project    = $this->makeProject($manilaOrg, $manilaUser);

        $case = AdjudicationCase::create([
            'organization_id'  => $manilaOrg->id,
            'project_id'       => $project->id,
            'created_by'       => $manilaUser->id,
            'case_number'      => 'ADJ-001',
            'title'            => 'Case',
            'dispute_type'      => 'payment',
            'claimant_name'    => 'Claimant',
            'respondent_name'  => 'Respondent',
        ]);

        Date::setTestNow('2026-07-16 23:30:00'); // 07:30 on the 17th in Manila

        $deadline = AdjudicationDeadline::create([
            'organization_id'      => $manilaOrg->id,
            'project_id'           => $project->id,
            'adjudication_case_id' => $case->id,
            'title'                => 'Referral',
            'deadline_type'        => 'referral',
            'due_date'             => '2026-07-16', // yesterday in Manila
        ]);

        $this->assertSame('overdue', $deadline->computedStatus());

        Date::setTestNow();
    }
}
