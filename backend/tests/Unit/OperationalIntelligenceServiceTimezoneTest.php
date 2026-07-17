<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\ContractDeadline;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\OperationalIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Batch 4: OperationalIntelligenceService feeds Calendar, Dashboard, and
 * Notifications — its days_from_today/status computation must use each
 * item's own organisation timezone, not the server's UTC calendar day.
 */
class OperationalIntelligenceServiceTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_deadline_status_is_due_today_for_a_manila_organisation_past_utc_midnight(): void
    {
        $org  = Organization::create(['name' => 'Manila Org', 'slug' => 'manila-org', 'timezone' => 'Asia/Manila']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project', 'status' => 'active',
        ]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);

        $deadline = ContractDeadline::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'name' => 'Deadline', 'category' => 'notice',
        ]);
        $deadline->resolved_date = '2026-07-17';
        $deadline->save();

        // 23:30 UTC on the 16th is already 07:30 on the 17th in Manila (UTC+8).
        Date::setTestNow('2026-07-16 23:30:00');

        $items = app(OperationalIntelligenceService::class)->getItemsForProject($project->id);
        $item  = $items->firstWhere('source_id', $deadline->id);

        $this->assertNotNull($item);
        $this->assertSame(0, $item['days_from_today']);
        $this->assertSame('due_today', $item['status']);

        Date::setTestNow();
    }
}
