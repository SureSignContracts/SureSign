<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\FinalAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * FinalAccountController::agree() catches \RuntimeException and returns its
 * message verbatim at 422 -- this is a deliberate, curated workflow-state
 * message (not a raw exception leak) and must remain unchanged by the M7
 * disclosure cleanup. This confirms FinalAccountService::snapshotAgreement()
 * still throws the same safe, specific text.
 */
class FinalAccountBusinessMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_agreeing_a_final_account_not_under_review_throws_a_specific_safe_message(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P']);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'type' => 'main_contract', 'title' => 'C',
        ]);

        $finalAccount = FinalAccount::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'is_trade_package' => false, 'status' => FinalAccount::STATUS_DRAFT,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Cannot agree a Final Account in status 'draft'. Must be under_review.");

        (new FinalAccountService())->snapshotAgreement($finalAccount, $user->id);
    }
}
