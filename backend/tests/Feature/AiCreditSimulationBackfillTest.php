<?php

namespace Tests\Feature;

use App\Models\AiCreditSimulationResult;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\AiCreditSimulator;
use App\Services\AI\AiInputNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase G4C.2C-2 — ai:credits:backfill-simulations. Confirms the command
 * never calls the AI provider, never mutates the analysis it backfills,
 * correctly reconstructs normalized input from the original file when
 * still available, and correctly records "unavailable" (never a guess)
 * when it isn't.
 */
class AiCreditSimulationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixtures(): array
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        return compact('org', 'user', 'project', 'contract');
    }

    public function test_backfill_reconstructs_normalized_input_from_the_original_file(): void
    {
        Storage::fake('local');
        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $text = 'Historical contract text that predates AI Credit simulation.';
        Storage::disk('local')->put('contracts/historical.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'historical.txt', 'stored_name' => 'historical.txt',
            'file_path' => 'contracts/historical.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'file_upload_id' => $upload->id, 'document_hash' => hash('sha256', $text),
            'document_char_count' => mb_strlen($text), 'completed_at' => now(),
        ]);

        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'contract_analysis'])
            ->assertExitCode(0);

        $result = AiCreditSimulationResult::query()
            ->where('analysable_id', $analysis->id)
            ->where('candidate_policy_key', 'candidate_a')
            ->firstOrFail();

        $this->assertSame(AiCreditSimulator::STATUS_CALCULATED, $result->simulation_status);
        $this->assertSame(AiInputNormalizer::normalizedCharCount($text), $result->normalized_input_char_count);
        $this->assertSame('backfill', $result->source);
    }

    public function test_backfill_records_unavailable_when_file_upload_is_missing(): void
    {
        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'file_upload_id' => null, 'document_hash' => hash('sha256', 'gone'),
            'document_char_count' => 4, 'completed_at' => now(),
        ]);

        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'contract_analysis'])
            ->assertExitCode(0);

        $result = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->first();

        $this->assertSame(AiCreditSimulator::STATUS_UNAVAILABLE, $result->simulation_status);
        $this->assertNull($result->normalized_input_char_count);
        $this->assertNull($result->hypothetical_credits);
    }

    public function test_backfill_records_unavailable_when_stored_file_hash_no_longer_matches(): void
    {
        Storage::fake('local');
        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        Storage::disk('local')->put('contracts/changed.txt', 'New different content now.');
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'changed.txt', 'stored_name' => 'changed.txt',
            'file_path' => 'contracts/changed.txt', 'mime_type' => 'text/plain', 'file_size' => 10,
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'file_upload_id' => $upload->id, 'document_hash' => hash('sha256', 'the original text, long gone'),
            'document_char_count' => 30, 'completed_at' => now(),
        ]);

        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'contract_analysis'])
            ->assertExitCode(0);

        $result = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->first();

        $this->assertSame(AiCreditSimulator::STATUS_UNAVAILABLE, $result->simulation_status);
    }

    public function test_backfill_never_mutates_the_analysis_row(): void
    {
        Storage::fake('local');
        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $text = 'Immutability check text.';
        Storage::disk('local')->put('contracts/immutable.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'immutable.txt', 'stored_name' => 'immutable.txt',
            'file_path' => 'contracts/immutable.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'file_upload_id' => $upload->id, 'document_hash' => hash('sha256', $text),
            'document_char_count' => mb_strlen($text), 'estimated_cost' => 0.42, 'completed_at' => now(),
        ]);
        $before = $analysis->fresh()->toArray();

        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'contract_analysis'])
            ->assertExitCode(0);

        $this->assertEquals($before, $analysis->fresh()->toArray());
    }

    public function test_dry_run_writes_no_simulation_rows(): void
    {
        Storage::fake('local');
        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $text = 'Dry run check text.';
        Storage::disk('local')->put('contracts/dryrun.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'dryrun.txt', 'stored_name' => 'dryrun.txt',
            'file_path' => 'contracts/dryrun.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'file_upload_id' => $upload->id, 'document_hash' => hash('sha256', $text),
            'document_char_count' => mb_strlen($text), 'completed_at' => now(),
        ]);

        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'contract_analysis', '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(0, AiCreditSimulationResult::query()->count());
    }

    public function test_running_backfill_twice_is_idempotent(): void
    {
        Storage::fake('local');
        ['user' => $user, 'contract' => $contract, 'project' => $project, 'org' => $org] = $this->makeFixtures();

        $text = 'Idempotent backfill check text.';
        Storage::disk('local')->put('contracts/idem.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'idem.txt', 'stored_name' => 'idem.txt',
            'file_path' => 'contracts/idem.txt', 'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'file_upload_id' => $upload->id, 'document_hash' => hash('sha256', $text),
            'document_char_count' => mb_strlen($text), 'completed_at' => now(),
        ]);

        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'contract_analysis'])->assertExitCode(0);
        $countAfterFirst = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->count();

        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'contract_analysis'])->assertExitCode(0);
        $countAfterSecond = AiCreditSimulationResult::query()->where('analysable_id', $analysis->id)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_analysis_id_requires_workflow(): void
    {
        $this->artisan('ai:credits:backfill-simulations', ['--analysis-id' => 1])
            ->assertExitCode(1);
    }

    public function test_unknown_workflow_is_rejected(): void
    {
        $this->artisan('ai:credits:backfill-simulations', ['--workflow' => 'not_a_real_workflow'])
            ->assertExitCode(1);
    }
}
