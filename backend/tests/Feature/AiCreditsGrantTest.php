<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AiCreditLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use App\Services\AI\AiCreditLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G4C.3H — the Super-Admin-only AI Credits grant/adjustment/expiry
 * endpoints (POST /admin/ai-credits/organizations/{id}/{grant,adjust-credit,
 * adjust-debit,expire}). Confirms: role isolation (Admin denied, matching
 * the G4B.2 subscription-assignment precedent), every mutation creates a
 * real immutable ledger entry via the existing service (never a raw
 * insert), validation (reason/confirmation/amount required), an audit log
 * entry is recorded, and the returned balance reflects the change
 * immediately.
 */
class AiCreditsGrantTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $org = Organization::create(['name' => 'Platform', 'slug' => 'platform-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        return $user;
    }

    private function admin(): User
    {
        $org = Organization::create(['name' => 'Platform2', 'slug' => 'platform2-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        return $user;
    }

    private function targetOrg(): Organization
    {
        return Organization::create(['name' => 'Target Org', 'slug' => 'target-org-' . uniqid()]);
    }

    public function test_super_admin_can_grant_credits(): void
    {
        $org = $this->targetOrg();
        Sanctum::actingAs($this->superAdmin());

        $response = $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 50,
            'reason' => 'Manual goodwill grant for launch testing',
            'confirmed' => true,
        ])->assertCreated();

        $response->assertJsonPath('entry.transaction_type', 'grant');
        $response->assertJsonPath('entry.amount', 50);
        $response->assertJsonPath('balance.available', 50);
        $response->assertJsonPath('balance.issued', 50);

        $this->assertSame(1, AiCreditLedgerEntry::where('organization_id', $org->id)->where('transaction_type', 'grant')->count());
    }

    public function test_admin_role_is_denied_on_every_mutation(): void
    {
        $org = $this->targetOrg();
        Sanctum::actingAs($this->admin());

        $payload = ['amount' => 10, 'reason' => 'Should not be permitted here', 'confirmed' => true];

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", $payload)->assertForbidden();
        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/adjust-credit", $payload)->assertForbidden();
        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/adjust-debit", $payload)->assertForbidden();
        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/expire", $payload)->assertForbidden();

        $this->assertSame(0, AiCreditLedgerEntry::where('organization_id', $org->id)->count());
    }

    public function test_client_role_is_denied(): void
    {
        $org = $this->targetOrg();
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($client);

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 10, 'reason' => 'Should not be permitted here', 'confirmed' => true,
        ])->assertForbidden();
    }

    public function test_reason_is_required_with_a_minimum_length(): void
    {
        $org = $this->targetOrg();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 10, 'reason' => 'short', 'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_confirmation_is_required(): void
    {
        $org = $this->targetOrg();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 10, 'reason' => 'A perfectly valid reason for this credit',
        ])->assertStatus(422)->assertJsonValidationErrors('confirmed');
    }

    public function test_non_positive_amount_is_rejected(): void
    {
        $org = $this->targetOrg();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 0, 'reason' => 'A perfectly valid reason for this credit', 'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_adjust_debit_and_expire_decrease_available_without_a_prior_reservation(): void
    {
        $org = $this->targetOrg();
        $superAdmin = $this->superAdmin();
        app(AiCreditLedgerService::class)->grant($org->id, 100, 'Initial grant', 'grant-' . uniqid(), $superAdmin->id);

        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/adjust-debit", [
            'amount' => 10, 'reason' => 'Correcting an earlier over-grant', 'confirmed' => true,
        ])->assertCreated()->assertJsonPath('balance.available', 90);

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/expire", [
            'amount' => 5, 'reason' => 'End-of-cycle expiry for testing', 'confirmed' => true,
        ])->assertCreated()->assertJsonPath('balance.available', 85);
    }

    public function test_adjust_credit_increases_available(): void
    {
        $org = $this->targetOrg();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/adjust-credit", [
            'amount' => 25, 'reason' => 'Compensating for a support incident', 'confirmed' => true,
        ])->assertCreated()->assertJsonPath('balance.available', 25);
    }

    public function test_mutation_creates_an_activity_log_entry_scoped_to_the_target_organisation(): void
    {
        $org = $this->targetOrg();
        $superAdmin = $this->superAdmin();
        Sanctum::actingAs($superAdmin);

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 15, 'reason' => 'Verifying audit trail is recorded', 'confirmed' => true,
        ])->assertCreated();

        $log = ActivityLog::where('action', 'ai_credits.granted')->first();
        $this->assertNotNull($log);
        $this->assertSame($org->id, $log->organization_id);
        $this->assertSame($superAdmin->id, $log->user_id);
    }

    public function test_each_mutation_creates_a_distinct_idempotency_key(): void
    {
        $org = $this->targetOrg();
        Sanctum::actingAs($this->superAdmin());

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 10, 'reason' => 'First grant of the day for this org', 'confirmed' => true,
        ])->assertCreated();

        $this->postJson("/api/admin/ai-credits/organizations/{$org->id}/grant", [
            'amount' => 10, 'reason' => 'Second, separate grant for this org', 'confirmed' => true,
        ])->assertCreated();

        $this->assertSame(2, AiCreditLedgerEntry::where('organization_id', $org->id)->where('transaction_type', 'grant')->count());
    }
}
