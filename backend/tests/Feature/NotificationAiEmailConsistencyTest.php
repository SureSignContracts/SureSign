<?php

namespace Tests\Feature;

use App\Jobs\AnalyseContractWithAiJob;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SuresignNotification;
use App\Models\SuresignSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Final cleanup: AI analysis emails ('ai_analysis.completed'/'ai_analysis.failed')
 * previously had no toggle entry in the settings UI at all (the completed
 * email call existed but could never be turned on) and there was no email on
 * failure whatsoever. Both now follow the same notification_settings gate as
 * every other configurable email event, and the in-app notification is
 * unaffected either way.
 */
class NotificationAiEmailConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtures(): array
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $project  = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return compact('org', 'user', 'project', 'contract');
    }

    private function enableBrevo(array $enabledEvents): void
    {
        SuresignSetting::instance()->update([
            'brevo_api_key' => 'fake-brevo-key', 'notification_settings' => $enabledEvents,
        ]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);
    }

    public function test_ai_analysis_failed_email_sends_when_the_event_is_enabled(): void
    {
        $this->enableBrevo(['ai_analysis.failed']);
        ['user' => $user, 'contract' => $contract] = $this->makeFixtures();

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id, 'created_by' => $user->id, 'status' => 'pending',
        ]);

        // fileUploadId 0 never exists -> the job's own curated RuntimeException
        // ('Contract file not found.') fires, taking the failure branch.
        (new AnalyseContractWithAiJob($analysis->id, 0, $user->id))->handle(app(\App\Services\AI\ContractAnalysisService::class));

        Http::assertSentCount(1);
        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $user->id, 'source_type' => 'contract_ai_analysis', 'source_field' => 'failed',
            'action_url' => "/app/projects/{$contract->project_id}/contracts",
        ]);
    }

    public function test_ai_analysis_failed_email_is_suppressed_when_disabled_but_in_app_still_fires(): void
    {
        $this->enableBrevo([]); // brevo configured but no events opted in
        ['user' => $user, 'contract' => $contract] = $this->makeFixtures();

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id, 'created_by' => $user->id, 'status' => 'pending',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, 0, $user->id))->handle(app(\App\Services\AI\ContractAnalysisService::class));

        Http::assertNothingSent();
        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $user->id, 'source_type' => 'contract_ai_analysis', 'source_field' => 'failed',
        ]);
    }
}
