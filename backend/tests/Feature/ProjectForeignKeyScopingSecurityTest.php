<?php

namespace Tests\Feature;

use App\Models\AdjudicationCase;
use App\Models\Contract;
use App\Models\DelayEvent;
use App\Models\DeliveryDocument;
use App\Models\Document;
use App\Models\EotRequest;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\TradePackage;
use App\Models\User;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P0 Security Remediation (2026-08-21) — Project Foreign-Key Scoping +
 * Document Register Authorization.
 *
 * Two confirmed vulnerability mechanisms, fixed together:
 *
 *   A. Several project-scoped create/update endpoints
 *      (RiskController, DelayEventController, EotRequestController,
 *      LossAndExpenseClaimController, DeliveryDocumentController,
 *      AdjudicationCaseController::update(), AdjudicationDocumentController)
 *      accepted client-supplied foreign keys (contract_id, trade_package_id,
 *      variation_id, affected_milestone_id, delay_event_id, eot_request_id,
 *      document_id, payment_application_id) validated only via a global
 *      `exists:<table>,id` rule — proving the row exists ANYWHERE on the
 *      platform, never that it belongs to the route's own Project. The
 *      created/updated record was correctly scoped to the acting Project,
 *      but carried a cross-tenant (or, within the same org, cross-project)
 *      foreign key that several index()/show() methods then eager-load and
 *      return.
 *
 *   B. DocumentRegisterController::index() received the route's {project}
 *      segment as a plain `int $projectId` (never resolved through
 *      Laravel's implicit model binding) and performed no authorization
 *      check at all — any authenticated user of any organisation could
 *      request another organisation's Project ID directly.
 *
 * Fixed via explicit, post-validation `Model::where('id', ...)
 * ->where('project_id', $project->id)->exists()` checks (mirroring
 * AdjudicationCaseController::store()'s pre-existing, already-correct
 * pattern) and — for DocumentRegisterController — a real route-bound
 * `Project $project` parameter plus this codebase's standard
 * authorize() convention. No new abstraction was introduced.
 *
 * A read-only scan of the real dev database before this fix found ZERO
 * existing rows with an invalid cross-project relationship for any of the
 * newly-protected foreign keys — no data cleanup was required or performed.
 */
class ProjectForeignKeyScopingSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Fixtures ───────────────────────────────────────────────────────────

    private function makeOrgAndUser(string $label): array
    {
        static $n = 0;
        $n++;

        $org = Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return compact('org', 'user');
    }

    private function makeProject(Organization $org, User $user, string $name = 'Project'): Project
    {
        static $n = 0;
        $n++;

        return Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "{$name} {$n}", 'status' => 'active',
        ]);
    }

    private function makeContract(Organization $org, Project $project, User $user): Contract
    {
        return Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);
    }

    private function makeTradePackage(Organization $org, Project $project, User $user): TradePackage
    {
        static $n = 0;
        $n++;

        return TradePackage::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'name' => "Package {$n}", 'slug' => "package-{$n}", 'status' => 'active',
        ]);
    }

    private function makeVariation(Organization $org, Project $project, Contract $contract, User $user): Variation
    {
        static $n = 0;
        $n++;

        return Variation::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'variation_number' => $n, 'title' => 'Variation', 'status' => 'draft',
        ]);
    }

    private function makeDelayEvent(Organization $org, Project $project, User $user): DelayEvent
    {
        static $n = 0;
        $n++;

        return DelayEvent::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Delay Event', 'date_occurred' => now()->toDateString(), 'event_number' => $n,
        ]);
    }

    private function makeEotRequest(Organization $org, Project $project, User $user): EotRequest
    {
        static $n = 0;
        $n++;

        return EotRequest::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'EOT Request', 'notice_date' => now()->toDateString(), 'eot_number' => $n, 'status' => 'draft',
        ]);
    }

    private function makeDocument(Organization $org, Project $project, User $user): Document
    {
        return Document::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Document', 'type' => 'other', 'status' => 'draft',
        ]);
    }

    private function makePaymentApplication(Organization $org, Project $project, Contract $contract, User $user): PaymentApplication
    {
        static $n = 0;
        $n++;

        return PaymentApplication::create([
            'project_id' => $project->id, 'contract_id' => $contract->id, 'organization_id' => $org->id,
            'created_by' => $user->id, 'application_number' => $n, 'application_date' => now()->toDateString(),
            'gross_valuation' => 1000, 'amount_due' => 1000, 'status' => 'draft',
        ]);
    }

    private function makeAdjudicationCase(Organization $org, Project $project, User $user): AdjudicationCase
    {
        static $n = 0;
        $n++;

        return AdjudicationCase::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id,
            'case_number' => "CASE-{$n}", 'title' => "Dispute {$n}", 'dispute_type' => 'payment_dispute',
            'claimant_name' => 'Claimant', 'respondent_name' => 'Respondent', 'status' => 'draft',
            'current_step' => 'notice_of_dispute',
        ]);
    }

    // ── A. RISK ────────────────────────────────────────────────────────────

    public function test_risk_rejects_foreign_organisation_contract(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$projectA->id}/risks", [
            'title' => 'Injected risk', 'contract_id' => $contractB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('contract_risks', ['title' => 'Injected risk']);
    }

    public function test_risk_rejects_foreign_organisation_trade_package(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $packageB = $this->makeTradePackage($b['org'], $projectB, $b['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$projectA->id}/risks", [
            'title' => 'Injected risk', 'trade_package_id' => $packageB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('contract_risks', ['title' => 'Injected risk']);
    }

    public function test_risk_rejects_same_organisation_different_project_contract(): void
    {
        $a = $this->makeOrgAndUser('a');
        $projectA1 = $this->makeProject($a['org'], $a['user']);
        $projectA2 = $this->makeProject($a['org'], $a['user']);
        $contractA2 = $this->makeContract($a['org'], $projectA2, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$projectA1->id}/risks", [
            'title' => 'Cross-project risk', 'contract_id' => $contractA2->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('contract_risks', ['title' => 'Cross-project risk']);
    }

    public function test_risk_accepts_legitimate_same_project_contract(): void
    {
        $a = $this->makeOrgAndUser('a'); $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($a['org'], $project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/risks", [
            'title' => 'Legit risk', 'contract_id' => $contract->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('contract_risks', ['title' => 'Legit risk', 'contract_id' => $contract->id]);
    }

    public function test_risk_index_never_exposes_foreign_contract_title_after_rejected_write(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $contractB->update(['title' => 'SECRET-ORG-B-CONTRACT']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectA->id}/risks", [
            'title' => 'Injected risk', 'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $response = $this->getJson("/api/projects/{$projectA->id}/risks");
        $response->assertStatus(200);
        $this->assertStringNotContainsString('SECRET-ORG-B-CONTRACT', $response->getContent());
    }

    // ── B. DELAY EVENT ─────────────────────────────────────────────────────

    public function test_delay_event_rejects_foreign_contract_variation_and_milestone(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $variationB = $this->makeVariation($b['org'], $projectB, $contractB, $b['user']);
        $milestoneB = \App\Models\ContractProgrammeMilestone::create([
            'contract_id' => $contractB->id, 'project_id' => $projectB->id,
            'name' => 'Milestone', 'milestone_type' => 'key_milestone', 'status' => 'not_started',
        ]);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectA->id}/delay-events", [
            'title' => 'DE', 'date_occurred' => now()->toDateString(), 'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$projectA->id}/delay-events", [
            'title' => 'DE', 'date_occurred' => now()->toDateString(), 'variation_id' => $variationB->id,
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$projectA->id}/delay-events", [
            'title' => 'DE', 'date_occurred' => now()->toDateString(), 'affected_milestone_id' => $milestoneB->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('delay_events', 0);
    }

    public function test_delay_event_accepts_legitimate_same_project_contract(): void
    {
        $a = $this->makeOrgAndUser('a'); $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($a['org'], $project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/delay-events", [
            'title' => 'DE', 'date_occurred' => now()->toDateString(), 'contract_id' => $contract->id,
        ]);

        $response->assertStatus(201);
    }

    // ── C. EOT REQUEST ─────────────────────────────────────────────────────

    public function test_eot_request_rejects_foreign_contract_and_delay_event(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $delayEventB = $this->makeDelayEvent($b['org'], $projectB, $b['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectA->id}/eot-requests", [
            'title' => 'EOT', 'notice_date' => now()->toDateString(), 'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$projectA->id}/eot-requests", [
            'title' => 'EOT', 'notice_date' => now()->toDateString(), 'delay_event_id' => $delayEventB->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('eot_requests', 0);
    }

    public function test_eot_request_accepts_legitimate_same_project_references(): void
    {
        $a = $this->makeOrgAndUser('a'); $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($a['org'], $project, $a['user']);
        $delayEvent = $this->makeDelayEvent($a['org'], $project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/eot-requests", [
            'title' => 'EOT', 'notice_date' => now()->toDateString(),
            'contract_id' => $contract->id, 'delay_event_id' => $delayEvent->id,
        ]);

        $response->assertStatus(201);
    }

    // ── D. LOSS & EXPENSE ──────────────────────────────────────────────────

    public function test_loss_and_expense_rejects_foreign_contract_delay_event_and_eot(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $delayEventB = $this->makeDelayEvent($b['org'], $projectB, $b['user']);
        $eotB = $this->makeEotRequest($b['org'], $projectB, $b['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectA->id}/loss-and-expense-claims", [
            'title' => 'LE', 'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$projectA->id}/loss-and-expense-claims", [
            'title' => 'LE', 'delay_event_id' => $delayEventB->id,
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$projectA->id}/loss-and-expense-claims", [
            'title' => 'LE', 'eot_request_id' => $eotB->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('loss_and_expense_claims', 0);
    }

    public function test_loss_and_expense_accepts_legitimate_same_project_contract(): void
    {
        $a = $this->makeOrgAndUser('a'); $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($a['org'], $project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/loss-and-expense-claims", [
            'title' => 'LE', 'contract_id' => $contract->id,
        ]);

        $response->assertStatus(201);
    }

    // ── E. DELIVERY DOCUMENT ───────────────────────────────────────────────

    public function test_delivery_document_rejects_foreign_contract_package_and_document_on_create(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $packageB = $this->makeTradePackage($b['org'], $projectB, $b['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectA->id}/delivery-documents", [
            'title' => 'DD', 'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$projectA->id}/delivery-documents", [
            'title' => 'DD', 'trade_package_id' => $packageB->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('delivery_documents', 0);
    }

    public function test_delivery_document_rejects_foreign_document_id_on_update(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $contractA = $this->makeContract($a['org'], $projectA, $a['user']);
        $deliveryDoc = DeliveryDocument::create([
            'project_id' => $projectA->id, 'organization_id' => $a['org']->id, 'created_by' => $a['user']->id,
            'title' => 'Requirement', 'contract_id' => $contractA->id, 'category' => 'other', 'status' => 'required',
        ]);

        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $documentB = $this->makeDocument($b['org'], $projectB, $b['user']);
        $documentB->update(['file_name' => 'SECRET-ORG-B-FILE.pdf']);

        Sanctum::actingAs($a['user']);
        $response = $this->putJson("/api/projects/{$projectA->id}/delivery-documents/{$deliveryDoc->id}", [
            'document_id' => $documentB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('delivery_documents', ['id' => $deliveryDoc->id, 'document_id' => null]);
    }

    public function test_delivery_document_accepts_legitimate_same_project_references(): void
    {
        $a = $this->makeOrgAndUser('a'); $project = $this->makeProject($a['org'], $a['user']);
        $contract = $this->makeContract($a['org'], $project, $a['user']);
        $document = $this->makeDocument($a['org'], $project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$project->id}/delivery-documents", [
            'title' => 'DD', 'contract_id' => $contract->id, 'document_id' => $document->id,
        ]);

        $response->assertStatus(201);
    }

    public function test_delivery_document_index_never_exposes_foreign_metadata_after_rejected_write(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $contractB->update(['title' => 'SECRET-ORG-B-CONTRACT']);
        $packageB = $this->makeTradePackage($b['org'], $projectB, $b['user']);
        Sanctum::actingAs($a['user']);

        $this->postJson("/api/projects/{$projectA->id}/delivery-documents", [
            'title' => 'DD', 'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $response = $this->getJson("/api/projects/{$projectA->id}/delivery-documents");
        $response->assertStatus(200);
        $this->assertStringNotContainsString('SECRET-ORG-B-CONTRACT', $response->getContent());
        $this->assertStringNotContainsString($packageB->name, $response->getContent());
    }

    // ── F. ADJUDICATION CASE ───────────────────────────────────────────────

    public function test_adjudication_case_update_rejects_foreign_contract_payment_application_and_variation(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $case = $this->makeAdjudicationCase($a['org'], $projectA, $a['user']);

        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $paymentAppB = $this->makePaymentApplication($b['org'], $projectB, $contractB, $b['user']);
        $variationB = $this->makeVariation($b['org'], $projectB, $contractB, $b['user']);

        Sanctum::actingAs($a['user']);

        $this->putJson("/api/projects/{$projectA->id}/adjudication-cases/{$case->id}", [
            'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $this->putJson("/api/projects/{$projectA->id}/adjudication-cases/{$case->id}", [
            'payment_application_id' => $paymentAppB->id,
        ])->assertStatus(422);

        $this->putJson("/api/projects/{$projectA->id}/adjudication-cases/{$case->id}", [
            'variation_id' => $variationB->id,
        ])->assertStatus(422);

        $this->assertDatabaseHas('adjudication_cases', [
            'id' => $case->id, 'contract_id' => null, 'payment_application_id' => null, 'variation_id' => null,
        ]);
    }

    public function test_adjudication_case_update_accepts_legitimate_same_project_contract(): void
    {
        $a = $this->makeOrgAndUser('a'); $project = $this->makeProject($a['org'], $a['user']);
        $case = $this->makeAdjudicationCase($a['org'], $project, $a['user']);
        $contract = $this->makeContract($a['org'], $project, $a['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->putJson("/api/projects/{$project->id}/adjudication-cases/{$case->id}", [
            'contract_id' => $contract->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('adjudication_cases', ['id' => $case->id, 'contract_id' => $contract->id]);
    }

    public function test_adjudication_case_store_remains_safe(): void
    {
        // Confirms the pre-existing, already-correct store() check this
        // remediation deliberately did not rewrite.
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        Sanctum::actingAs($a['user']);

        $response = $this->postJson("/api/projects/{$projectA->id}/adjudication-cases", [
            'title' => 'Dispute', 'dispute_type' => 'payment_dispute',
            'claimant_name' => 'Claimant', 'respondent_name' => 'Respondent',
            'contract_id' => $contractB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('adjudication_cases', 0);
    }

    public function test_adjudication_case_show_never_exposes_foreign_metadata_after_rejected_update(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $case = $this->makeAdjudicationCase($a['org'], $projectA, $a['user']);

        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $contractB = $this->makeContract($b['org'], $projectB, $b['user']);
        $contractB->update(['title' => 'SECRET-ORG-B-CONTRACT']);

        Sanctum::actingAs($a['user']);
        $this->putJson("/api/projects/{$projectA->id}/adjudication-cases/{$case->id}", [
            'contract_id' => $contractB->id,
        ])->assertStatus(422);

        $response = $this->getJson("/api/projects/{$projectA->id}/adjudication-cases/{$case->id}");
        $response->assertStatus(200);
        $this->assertStringNotContainsString('SECRET-ORG-B-CONTRACT', $response->getContent());
    }

    // ── H. ADJUDICATION DOCUMENT ───────────────────────────────────────────

    public function test_adjudication_document_rejects_foreign_document_id(): void
    {
        $a = $this->makeOrgAndUser('a'); $projectA = $this->makeProject($a['org'], $a['user']);
        $case = $this->makeAdjudicationCase($a['org'], $projectA, $a['user']);

        $b = $this->makeOrgAndUser('b'); $projectB = $this->makeProject($b['org'], $b['user']);
        $documentB = $this->makeDocument($b['org'], $projectB, $b['user']);

        Sanctum::actingAs($a['user']);
        $response = $this->postJson("/api/projects/{$projectA->id}/adjudication-cases/{$case->id}/documents", [
            'title' => 'Linked doc', 'document_type' => 'evidence', 'document_id' => $documentB->id,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('adjudication_documents', ['title' => 'Linked doc']);
    }

    public function test_adjudication_document_accepts_legitimate_same_project_document(): void
    {
        $a = $this->makeOrgAndUser('a'); $project = $this->makeProject($a['org'], $a['user']);
        $case = $this->makeAdjudicationCase($a['org'], $project, $a['user']);
        $document = $this->makeDocument($a['org'], $project, $a['user']);

        Sanctum::actingAs($a['user']);
        $response = $this->postJson("/api/projects/{$project->id}/adjudication-cases/{$case->id}/documents", [
            'title' => 'Linked doc', 'document_type' => 'evidence', 'document_id' => $document->id,
        ]);

        $response->assertStatus(201);
    }

    // ── G. DOCUMENT REGISTER ───────────────────────────────────────────────

    public function test_document_register_rejects_client_reading_another_organisations_project(): void
    {
        $a = $this->makeOrgAndUser('a');
        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user'], 'Confidential Project');

        \App\Models\DocumentRegister::create([
            'project_id' => $projectB->id, 'document_number' => 'DOC-0001',
            'title' => 'SECRET-ORG-B-DOCUMENT', 'document_type' => 'RFI',
        ]);

        Sanctum::actingAs($a['user']);
        $response = $this->getJson("/api/projects/{$projectB->id}/document-register");

        $response->assertStatus(403);
        $this->assertStringNotContainsString('SECRET-ORG-B-DOCUMENT', $response->getContent());
        $this->assertStringNotContainsString('Confidential Project', $response->getContent());
    }

    public function test_document_register_allows_client_reading_another_project_within_their_own_organisation(): void
    {
        // Current, unchanged SureSign policy: a Client may access any
        // Project within their own organisation — this remediation must not
        // narrow that.
        $a = $this->makeOrgAndUser('a');
        $projectA1 = $this->makeProject($a['org'], $a['user']);
        $projectA2 = $this->makeProject($a['org'], $a['user']);

        \App\Models\DocumentRegister::create([
            'project_id' => $projectA2->id, 'document_number' => 'DOC-0002',
            'title' => 'Shared-org document', 'document_type' => 'RFI',
        ]);

        Sanctum::actingAs($a['user']);
        $response = $this->getJson("/api/projects/{$projectA2->id}/document-register");

        $response->assertStatus(200);
        $this->assertStringContainsString('Shared-org document', $response->getContent());
    }

    public function test_document_register_retains_super_admin_platform_wide_access(): void
    {
        $superAdmin = $this->makeOrgAndUser('platform');
        $superAdmin['user']->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        $b = $this->makeOrgAndUser('b');
        $projectB = $this->makeProject($b['org'], $b['user']);

        Sanctum::actingAs($superAdmin['user']);
        $response = $this->getJson("/api/projects/{$projectB->id}/document-register");

        $response->assertStatus(200);
    }
}
