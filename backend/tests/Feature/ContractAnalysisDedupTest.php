<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\ContractAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Confirms the pre-existing document_hash dedup in ContractAnalysisService::
 * analyse() still functions after the M6 rate-limiter change — this test
 * doesn't touch the rate limiter at all, it calls the service directly so it
 * never needs a real Claude API call (identical text short-circuits before
 * the provider is ever constructed).
 */
class ContractAnalysisDedupTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_document_text_reuses_the_cached_completed_analysis_without_calling_the_provider(): void
    {
        Storage::fake('local');

        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        $text = 'Identical contract text used for both analyses.';
        $hash = hash('sha256', $text);

        // A prior, already-completed analysis with the same document_hash + model.
        ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'completed', 'model' => 'claude-sonnet-5',
            'document_hash' => $hash, 'raw_response_json' => ['contract_summary' => 'Cached result'],
        ]);

        Storage::disk('local')->put('contracts/new.txt', $text);
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'new.txt', 'stored_name' => 'new.txt', 'file_path' => 'contracts/new.txt',
            'mime_type' => 'text/plain', 'file_size' => strlen($text),
        ]);

        $newAnalysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $org->id, 'project_id' => $project->id,
            'created_by' => $user->id, 'status' => 'pending', 'model' => 'claude-sonnet-5',
        ]);

        $result = (new ContractAnalysisService())->analyse($newAnalysis, $upload);

        $this->assertSame('Cached result', $result['data']['contract_summary']);
        $this->assertSame(0, $result['tokens_input']);
        $this->assertSame(0, $result['tokens_output']);
    }
}
