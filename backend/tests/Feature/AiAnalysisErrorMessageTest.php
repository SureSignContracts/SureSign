<?php

namespace Tests\Feature;

use App\Jobs\AnalyseContractWithAiJob;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the M7 fix in AnalyseContractWithAiJob: the catch-all previously
 * persisted $e->getMessage() verbatim to ContractAiAnalysis.error_message
 * (a column returned as-is by GET /contracts/{contract}/ai-analysis) for
 * EVERY failure type. Now only RuntimeException (this AI pipeline's own
 * convention for an already-curated, safe message) is preserved verbatim;
 * anything else is genericised before being persisted or shown in-app.
 */
class AiAnalysisErrorMessageTest extends TestCase
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

    public function test_curated_business_exception_message_is_preserved_verbatim(): void
    {
        ['user' => $user, 'contract' => $contract] = $this->makeFixtures();

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id, 'created_by' => $user->id, 'status' => 'pending',
        ]);

        // fileUploadId 0 never exists -> the job's own curated RuntimeException
        // ('Contract file not found.') fires -- this is the app's own,
        // already-safe business message and must reach the client unchanged.
        (new AnalyseContractWithAiJob($analysis->id, 0, $user->id))->handle(app(\App\Services\AI\ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertSame('Contract file not found.', $analysis->error_message);
    }

    public function test_unexpected_failure_is_genericised_before_being_persisted(): void
    {
        if (!class_exists(\PhpOffice\PhpWord\IOFactory::class)) {
            $this->markTestSkipped('phpoffice/phpword is not installed in this environment.');
        }

        Storage::fake('local');
        Log::spy();

        ['user' => $user, 'contract' => $contract, 'project' => $project] = $this->makeFixtures();

        // A .docx file that exists but is not a valid zip/OOXML document --
        // PhpWord's IOFactory::load() throws a real (non-RuntimeException)
        // exception for this, exercising the "genuinely unexpected" branch.
        Storage::disk('local')->put('contracts/corrupt.docx', 'not a real docx file');
        $upload = FileUpload::create([
            'project_id' => $project->id, 'organization_id' => $contract->organization_id,
            'uploaded_by' => $user->id, 'original_name' => 'corrupt.docx', 'stored_name' => 'corrupt.docx',
            'file_path' => 'contracts/corrupt.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'file_size' => 20,
        ]);

        $analysis = ContractAiAnalysis::create([
            'contract_id' => $contract->id, 'organization_id' => $contract->organization_id,
            'project_id' => $contract->project_id, 'created_by' => $user->id, 'status' => 'pending',
        ]);

        (new AnalyseContractWithAiJob($analysis->id, $upload->id, $user->id))
            ->handle(app(\App\Services\AI\ContractAnalysisService::class));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertSame('The AI analysis could not be completed.', $analysis->error_message);
        $this->assertStringNotContainsString('Zip', $analysis->error_message);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) =>
                $message === 'AnalyseContractWithAiJob failed'
                && $context['analysis_id'] === $analysis->id
                && $context['contract_id'] === $contract->id
                && isset($context['exception'])
            )
            ->once();
    }
}
