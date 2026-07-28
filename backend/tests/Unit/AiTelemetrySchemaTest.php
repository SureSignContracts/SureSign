<?php

namespace Tests\Unit;

use App\Models\ContractAiAnalysis;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Contract;
use App\Models\User;
use App\Support\AI\AiTelemetryImmutableException;
use App\Support\AI\AiTelemetrySchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase G4C.2D — telemetry_schema_version versions the STRUCTURE of
 * collected telemetry, distinct from AiInputNormalizer::VERSION and
 * candidate_policy_version. Confirms: new rows stamp the current version,
 * legacy (pre-migration) rows stay null rather than being guessed, and the
 * field is protected by AiTelemetryIntegrityGuard once terminal — same
 * discipline as every other execution-telemetry column.
 */
class AiTelemetrySchemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysis(array $overrides = []): ContractAiAnalysis
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return ContractAiAnalysis::create(array_merge([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'workflow' => 'contract_analysis',
        ], $overrides));
    }

    public function test_current_version_constant_is_a_positive_integer(): void
    {
        $this->assertIsInt(AiTelemetrySchema::CURRENT_VERSION);
        $this->assertGreaterThan(0, AiTelemetrySchema::CURRENT_VERSION);
    }

    public function test_analysis_created_with_current_version_persists_and_casts_to_integer(): void
    {
        $analysis = $this->makeAnalysis(['telemetry_schema_version' => AiTelemetrySchema::CURRENT_VERSION]);

        $this->assertSame(AiTelemetrySchema::CURRENT_VERSION, $analysis->fresh()->telemetry_schema_version);
    }

    public function test_analysis_created_without_a_version_stays_null_never_guessed(): void
    {
        $analysis = $this->makeAnalysis();

        $this->assertNull($analysis->fresh()->telemetry_schema_version);
    }

    public function test_telemetry_schema_version_is_immutable_once_terminal(): void
    {
        $analysis = $this->makeAnalysis(['telemetry_schema_version' => AiTelemetrySchema::CURRENT_VERSION, 'status' => 'completed']);

        $this->expectException(AiTelemetryImmutableException::class);

        $analysis->update(['telemetry_schema_version' => 99]);
    }
}
