<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Covers the M7 fix in AiController::generateBrief(): the catch block used
 * to return 'PDF generation failed: ' . $e->getMessage() at 500. This forces
 * a REAL failure (not a mock) by giving the contract a key_dates value that
 * is a string rather than an array/null -- the Blade view's
 * `@foreach($keyDates as ...)` then throws a genuine \TypeError, exercising
 * the exact code path a malformed/unexpected AI extraction result could hit.
 */
class AiControllerBriefDisclosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_rendering_failure_returns_a_generic_message_and_is_logged(): void
    {
        Log::spy();

        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
            // Deliberately malformed to force a genuine \TypeError in the Blade
            // view's `@foreach($keyDates as ...)` -- key_dates is cast 'array'
            // but nothing prevents a string being persisted through it.
            'key_dates' => 'not-an-array',
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'confirmed',
            'confirmed_data_json' => ['contract_summary' => 'Summary'],
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/ai/analyses/{$analysis->id}/generate-brief");

        $response->assertStatus(500)->assertJson(['message' => 'The document could not be generated.']);
        $this->assertStringNotContainsString('TypeError', $response->getContent());
        $this->assertStringNotContainsString('foreach', $response->getContent());
        $this->assertStringNotContainsString('.blade.php', $response->getContent());

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) =>
                $message === 'Contract Intelligence Brief generation failed'
                && $context['user_id'] === $user->id
                && $context['analysis_id'] === $analysis->id
                && $context['contract_id'] === $contract->id
                && isset($context['exception'])
            )
            ->once();
    }
}
