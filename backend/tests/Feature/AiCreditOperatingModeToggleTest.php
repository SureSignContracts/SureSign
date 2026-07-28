<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Support\AI\AiCreditOperatingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Super-Admin-only AI Credit operating mode control
 * (GET/PUT /admin/ai-credits/operating-mode). Confirms: defaults to SHADOW
 * after migration, Admin can read but not write (mirrors the
 * AiCreditsGrantController precedent), the mode actually flows through to
 * suresign_settings (the single source of truth AiCreditOperatingMode::
 * current() / AiCreditWorkflowLifecycle both read), validation, and an
 * audit log entry with previous/new mode metadata.
 */
class AiCreditOperatingModeToggleTest extends TestCase
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

    public function test_defaults_to_shadow_mode(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->getJson('/api/admin/ai-credits/operating-mode')
            ->assertOk()
            ->assertJsonPath('operating_mode', AiCreditOperatingMode::SHADOW);

        $this->assertSame(AiCreditOperatingMode::SHADOW, SuresignSetting::instance()->fresh()->ai_credit_operating_mode);
    }

    public function test_admin_can_read_but_not_write(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/admin/ai-credits/operating-mode')->assertOk();

        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => AiCreditOperatingMode::ENFORCED, 'reason' => 'Attempting to change as Admin', 'confirmed' => true,
        ])->assertForbidden();

        $this->assertSame(AiCreditOperatingMode::SHADOW, SuresignSetting::instance()->fresh()->ai_credit_operating_mode);
    }

    public function test_super_admin_can_move_through_all_three_modes(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => AiCreditOperatingMode::DISABLED, 'reason' => 'Pausing all credit accounting for maintenance', 'confirmed' => true,
        ])->assertOk()->assertJsonPath('operating_mode', AiCreditOperatingMode::DISABLED);
        $this->assertSame(AiCreditOperatingMode::DISABLED, SuresignSetting::instance()->fresh()->ai_credit_operating_mode);

        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => AiCreditOperatingMode::ENFORCED, 'reason' => 'Enabling for a controlled production trial', 'confirmed' => true,
        ])->assertOk()->assertJsonPath('operating_mode', AiCreditOperatingMode::ENFORCED);
        $this->assertSame(AiCreditOperatingMode::ENFORCED, SuresignSetting::instance()->fresh()->ai_credit_operating_mode);

        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => AiCreditOperatingMode::SHADOW, 'reason' => 'Rolling back after the trial', 'confirmed' => true,
        ])->assertOk()->assertJsonPath('operating_mode', AiCreditOperatingMode::SHADOW);
        $this->assertSame(AiCreditOperatingMode::SHADOW, SuresignSetting::instance()->fresh()->ai_credit_operating_mode);
    }

    public function test_invalid_mode_value_is_rejected(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => 'not_a_real_mode', 'reason' => 'A perfectly valid reason string', 'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('mode');
    }

    public function test_reason_and_confirmation_are_required(): void
    {
        Sanctum::actingAs($this->superAdmin());

        $this->putJson('/api/admin/ai-credits/operating-mode', ['mode' => AiCreditOperatingMode::ENFORCED])
            ->assertStatus(422)->assertJsonValidationErrors(['reason', 'confirmed']);

        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => AiCreditOperatingMode::ENFORCED, 'reason' => 'short', 'confirmed' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_mode_change_writes_an_activity_log_entry_with_previous_and_new_mode(): void
    {
        $superAdmin = $this->superAdmin();
        Sanctum::actingAs($superAdmin);

        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => AiCreditOperatingMode::ENFORCED, 'reason' => 'Verifying audit trail is recorded here', 'confirmed' => true,
        ])->assertOk();

        $log = ActivityLog::where('action', 'ai_credits.operating_mode_changed')->first();
        $this->assertNotNull($log);
        $this->assertSame($superAdmin->id, $log->user_id);
        $this->assertSame(AiCreditOperatingMode::SHADOW, $log->metadata['previous_mode']);
        $this->assertSame(AiCreditOperatingMode::ENFORCED, $log->metadata['new_mode']);
        $this->assertSame('Verifying audit trail is recorded here', $log->metadata['reason']);
        $this->assertSame($superAdmin->id, $log->metadata['changed_by']);
        $this->assertArrayHasKey('changed_at', $log->metadata);
    }

    public function test_client_role_is_denied(): void
    {
        $org = Organization::create(['name' => 'ClientOrg', 'slug' => 'client-org-' . uniqid()]);
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        Sanctum::actingAs($client);

        $this->getJson('/api/admin/ai-credits/operating-mode')->assertForbidden();
        $this->putJson('/api/admin/ai-credits/operating-mode', [
            'mode' => AiCreditOperatingMode::ENFORCED, 'reason' => 'Should not be permitted here', 'confirmed' => true,
        ])->assertForbidden();
    }
}
