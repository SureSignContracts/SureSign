<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\User;
use App\Services\OperationalIntelligenceService;
use App\Services\ProjectHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 3.5, Section 4: end-to-end proof that a single stored DATE-only
 * deadline (payment_notice_deadline) is classified consistently — and
 * correctly per-organisation — across every consumer: the source module,
 * OperationalIntelligenceService, the Calendar API, and Project Health.
 *
 * Two organisations, same UTC instant, different local calendar days:
 * Asia/Manila (UTC+8) and America/New_York (UTC-4, EDT in July).
 */
class Batch35EndToEndCalendarTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgProjectContractUser(string $timezone): array
    {
        static $n = 0;
        $n++;

        $org  = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => $timezone]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project', 'status' => 'active',
        ]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);

        return [$org, $project, $contract, $user];
    }

    public function test_the_same_stored_deadline_is_classified_consistently_across_every_consumer_per_organisation(): void
    {
        [$manilaOrg, $manilaProject, $manilaContract, $manilaUser] = $this->makeOrgProjectContractUser('Asia/Manila');
        [$nyOrg, $nyProject, $nyContract, $nyUser] = $this->makeOrgProjectContractUser('America/New_York');

        // Same stored DATE-only deadline for both organisations. Using
        // due_date here (not payment_notice_deadline) — see the note below
        // on a pre-existing, unrelated gap discovered while writing this
        // test: CalendarController's project-level feed never surfaces
        // payment_notice_deadline/pay_less_notice_deadline items at all
        // (only the trade-package-scoped feed does). due_date is the field
        // its direct query section actually covers.
        $deadline = '2026-07-17';

        $manilaApp = PaymentApplication::create([
            'project_id' => $manilaProject->id, 'organization_id' => $manilaOrg->id, 'contract_id' => $manilaContract->id,
            'created_by' => $manilaUser->id, 'application_number' => 1, 'gross_valuation' => 1000, 'amount_due' => 1000,
            'status' => 'submitted', 'application_date' => '2026-07-01',
            'due_date' => $deadline, 'payment_notice_deadline' => $deadline,
        ]);
        $nyApp = PaymentApplication::create([
            'project_id' => $nyProject->id, 'organization_id' => $nyOrg->id, 'contract_id' => $nyContract->id,
            'created_by' => $nyUser->id, 'application_number' => 1, 'gross_valuation' => 1000, 'amount_due' => 1000,
            'status' => 'submitted', 'application_date' => '2026-07-01',
            'due_date' => $deadline, 'payment_notice_deadline' => $deadline,
        ]);

        // 23:00 UTC on the 16th: already 07:00 on the 17th in Manila
        // (UTC+8) — the deadline is due TODAY. Still 19:00 on the 16th in
        // New York (UTC-4, EDT) — the deadline is still one day away.
        Date::setTestNow('2026-07-16 23:00:00');

        // 1. Source module — the stored value itself never shifts.
        $this->assertSame($deadline, $manilaApp->fresh()->due_date->toDateString());
        $this->assertSame($deadline, $nyApp->fresh()->due_date->toDateString());

        // 2. OperationalIntelligenceService.
        $intelligence = app(OperationalIntelligenceService::class);

        $manilaItem = $intelligence->getItemsForProject($manilaProject->id)
            ->firstWhere('source_field', 'due_date');
        $nyItem = $intelligence->getItemsForProject($nyProject->id)
            ->firstWhere('source_field', 'due_date');

        $this->assertSame('due_today', $manilaItem['status'], 'Manila: already the 17th locally — due today.');
        $this->assertSame(0, $manilaItem['days_from_today']);
        $this->assertSame('upcoming', $nyItem['status'], 'New York: still the 16th locally — due tomorrow.');
        $this->assertSame(1, $nyItem['days_from_today']);

        // 3. Calendar API (GET /projects/{project}/calendar-events).
        Sanctum::actingAs($manilaUser);
        $manilaCalendar = $this->getJson("/api/projects/{$manilaProject->id}/calendar-events")->json('data');
        $manilaEvent = collect($manilaCalendar)->first(fn($e) => str_contains($e['id'] ?? '', "payapp-{$manilaApp->id}-due"));

        Sanctum::actingAs($nyUser);
        $nyCalendar = $this->getJson("/api/projects/{$nyProject->id}/calendar-events")->json('data');
        $nyEvent = collect($nyCalendar)->first(fn($e) => str_contains($e['id'] ?? '', "payapp-{$nyApp->id}-due"));

        $this->assertNotNull($manilaEvent);
        $this->assertNotNull($nyEvent);
        $this->assertSame('due_today', $manilaEvent['status']);
        $this->assertSame('upcoming', $nyEvent['status']);

        // 4. Project Health (overdue deductions) — flip the deadline to
        // "past" for one org only by re-running one day later, proving the
        // health score also reads the organisation's own calendar day.
        Date::setTestNow('2026-07-17 23:00:00'); // 07:00 on the 18th in Manila; 19:00 on the 17th in New York.

        $manilaHealth = app(ProjectHealthService::class)->getHealth($manilaProject->id);
        $nyHealth     = app(ProjectHealthService::class)->getHealth($nyProject->id);

        $this->assertSame(1, $manilaHealth['counts']['overdue_payment_notices'], 'Manila is now on the 18th — the 17th deadline has passed.');
        $this->assertSame(0, $nyHealth['counts']['overdue_payment_notices'], 'New York is still on the 17th — the deadline day itself, not yet overdue.');

        Date::setTestNow();
    }
}
