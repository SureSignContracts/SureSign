<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\User;
use App\Services\NotificationEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Final cleanup: NotificationEngineService is now the sole owner of in-app
 * payment-deadline notifications. SendDeadlineReminders (daily scheduled
 * command) previously also created an in-app notification with no
 * source_type/source_id — meaning no idempotency — for the same four
 * PaymentApplication deadline fields NotificationEngineService already
 * tracks (due_date, payment_notice_deadline, pay_less_notice_deadline,
 * final_date_for_payment). That call has been removed; the command is now
 * email-only. This proves the engine still owns in-app generation and that
 * running the legacy command no longer creates a duplicate.
 */
class NotificationDeadlineOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndClient(string $label): array
    {
        static $n = 0;
        $n++;

        $org  = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        return compact('org', 'user');
    }

    private function makeProject(Organization $org, User $user): Project
    {
        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Project for {$org->name}", 'status' => 'active',
        ]);
    }

    private function makeContract(Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id' => $project->id, 'organization_id' => $project->organization_id,
            'created_by' => $user->id, 'title' => 'Main Contract', 'type' => 'main_contract',
            'status' => 'active', 'retention_percentage' => 5,
        ]);
    }

    public function test_notification_engine_still_generates_in_app_deadline_notifications(): void
    {
        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);

        PaymentApplication::create([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $project->organization_id, 'created_by' => $a['user']->id,
            'application_number' => 1, 'status' => 'submitted',
            'application_date' => now()->toDateString(), 'gross_valuation' => 10000, 'amount_due' => 9500,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        app(NotificationEngineService::class)->generateForProject($project->id);

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $a['user']->id, 'source_type' => 'payment_application', 'source_field' => 'due_date',
        ]);
    }

    public function test_legacy_command_no_longer_creates_an_in_app_notification(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);

        PaymentApplication::create([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $project->organization_id, 'created_by' => $a['user']->id,
            'application_number' => 1, 'status' => 'submitted',
            'application_date' => now()->toDateString(), 'gross_valuation' => 10000, 'amount_due' => 9500,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->artisan('suresign:send-deadline-reminders')->assertExitCode(0);

        $this->assertDatabaseMissing('suresign_notifications', ['type' => 'payment_deadline_approaching']);
    }

    public function test_running_both_paths_does_not_produce_duplicate_in_app_notifications(): void
    {
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);

        $a        = $this->makeOrgAndClient('a');
        $project  = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($project, $a['user']);

        PaymentApplication::create([
            'project_id' => $project->id, 'contract_id' => $contract->id,
            'organization_id' => $project->organization_id, 'created_by' => $a['user']->id,
            'application_number' => 1, 'status' => 'submitted',
            'application_date' => now()->toDateString(), 'gross_valuation' => 10000, 'amount_due' => 9500,
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        app(NotificationEngineService::class)->generateForProject($project->id);
        $this->artisan('suresign:send-deadline-reminders')->assertExitCode(0);
        app(NotificationEngineService::class)->generateForProject($project->id);

        $this->assertEquals(
            1,
            SuresignNotification::where('user_id', $a['user']->id)
                ->where('source_type', 'payment_application')->where('source_field', 'due_date')->count()
        );
    }
}
