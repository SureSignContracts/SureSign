<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\User;
use App\Support\Entitlements\EntitlementCategory;
use App\Support\Entitlements\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase G2, Stages 3-5 — the entitlement editor API. Confirms the payload
 * is generated dynamically from Feature::ALL (no hardcoded feature list),
 * that updates persist correctly for every EntitlementValue shape, and that
 * server-side validation rejects unknown keys, dormant keys, duplicates,
 * missing rows, and type/state mismatches.
 */
class PricingPlanEntitlementEditorTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $role = 'Super Admin'): User
    {
        static $n = 0;
        $n++;

        $org  = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makePlan(): PricingPlan
    {
        $plan = PricingPlan::create(['code' => 'starter', 'slug' => 'starter', 'name' => 'Starter']);
        app(\App\Services\Entitlements\PlanEntitlementRepository::class)->initializeDefaultsForPlan($plan);

        return $plan;
    }

    private function nonDormantKeys(): array
    {
        return array_values(array_filter(Feature::ALL, fn (string $k) => !Feature::isDormant($k)));
    }

    public function test_editor_payload_includes_every_feature_key_including_reserved(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $response = $this->getJson("/api/admin/pricing/plans/{$plan->id}/entitlements")->assertOk();
        $keys = collect($response->json('data.entitlements'))->pluck('feature_key')->all();

        // Stage X — reserved/dormant keys ARE included (read-only, clearly marked), unlike
        // the editable PUT set, which still excludes them entirely.
        $this->assertEqualsCanonicalizing(Feature::ALL, $keys);
        $this->assertContains(Feature::MAX_USERS, $keys);
        $this->assertContains(Feature::MAX_ORGANISATIONS, $keys);
    }

    public function test_editor_payload_reflects_feature_registry_metadata(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $response = $this->getJson("/api/admin/pricing/plans/{$plan->id}/entitlements")->assertOk();
        $row = collect($response->json('data.entitlements'))->firstWhere('feature_key', Feature::MAX_ACTIVE_PROJECTS);

        $this->assertSame('Active Projects', $row['display_name']);
        $this->assertSame(Feature::description(Feature::MAX_ACTIVE_PROJECTS), $row['description']);
        $this->assertSame('usage', $row['category']);
        $this->assertSame('integer', $row['value_type']);
        $this->assertSame('projects', $row['unit']);
        $this->assertFalse($row['is_reserved']);
    }

    public function test_editor_payload_marks_reserved_keys_and_never_lets_them_be_edited(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $response = $this->getJson("/api/admin/pricing/plans/{$plan->id}/entitlements")->assertOk();
        $row = collect($response->json('data.entitlements'))->firstWhere('feature_key', Feature::MAX_USERS);

        $this->assertTrue($row['is_reserved']);
        $this->assertFalse($row['is_applicable']);
        $this->assertFalse($row['is_unlimited']);
        $this->assertNull($row['value']);
        $this->assertFalse($row['customer_visible']);
        $this->assertFalse($row['currently_sold']);

        // Reserved rows never get a pricing_plan_entitlements row, even after other edits.
        $this->assertDatabaseMissing('pricing_plan_entitlements', [
            'pricing_plan_id' => $plan->id,
            'feature_key' => Feature::MAX_USERS,
        ]);
    }

    public function test_editor_payload_includes_dynamically_generated_category_metadata(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $response = $this->getJson("/api/admin/pricing/plans/{$plan->id}/entitlements")->assertOk();
        $categories = collect($response->json('data.categories'));

        $this->assertEqualsCanonicalizing(EntitlementCategory::ALL, $categories->pluck('key')->all());

        $reserved = $categories->firstWhere('key', EntitlementCategory::RESERVED);
        $this->assertSame(EntitlementCategory::label(EntitlementCategory::RESERVED), $reserved['label']);
        $this->assertSame(EntitlementCategory::description(EntitlementCategory::RESERVED), $reserved['description']);
    }

    public function test_update_persists_boolean_integer_and_unlimited_rows(): void
    {
        Sanctum::actingAs($this->makeAdmin('Admin')); // widened role — Admin can manage too
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(function (string $key) {
            if (Feature::isFeatureFlag($key)) {
                return ['feature_key' => $key, 'is_applicable' => true, 'is_unlimited' => false, 'value' => true];
            }
            if ($key === Feature::STORAGE_GB) {
                return ['feature_key' => $key, 'is_applicable' => true, 'is_unlimited' => true, 'value' => null];
            }

            return ['feature_key' => $key, 'is_applicable' => true, 'is_unlimited' => false, 'value' => 42];
        })->values()->all();

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])->assertOk();

        $this->assertDatabaseHas('pricing_plan_entitlements', [
            'pricing_plan_id' => $plan->id,
            'feature_key' => Feature::MAX_ACTIVE_PROJECTS,
            'value' => json_encode(42),
        ]);

        $storageRow = PricingPlanEntitlement::where('pricing_plan_id', $plan->id)
            ->where('feature_key', Feature::STORAGE_GB)->first();
        $this->assertTrue($storageRow->is_unlimited);
        $this->assertNull($storageRow->value);
    }

    public function test_not_applicable_row_clears_value_and_unlimited(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => $key !== Feature::API_ACCESS,
            'is_unlimited' => false,
            'value' => $key === Feature::API_ACCESS ? null : (Feature::isFeatureFlag($key) ? false : 1),
        ])->values()->all();

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])->assertOk();

        $this->assertDatabaseHas('pricing_plan_entitlements', [
            'pricing_plan_id' => $plan->id,
            'feature_key' => Feature::API_ACCESS,
            'is_applicable' => false,
            'is_unlimited' => false,
            'value' => null,
        ]);
    }

    public function test_rejects_unknown_feature_key(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => Feature::isFeatureFlag($key) ? false : 1,
        ])->values()->all();
        $rows[0]['feature_key'] = 'not_a_real_feature';

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])
            ->assertStatus(422);
    }

    public function test_rejects_dormant_feature_key(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => Feature::isFeatureFlag($key) ? false : 1,
        ])->values()->all();
        $rows[0]['feature_key'] = Feature::MAX_USERS;

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])
            ->assertStatus(422);
    }

    public function test_rejects_duplicate_feature_key(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => Feature::isFeatureFlag($key) ? false : 1,
        ])->values()->all();
        $rows[1]['feature_key'] = $rows[0]['feature_key'];

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])
            ->assertStatus(422);
    }

    public function test_rejects_incomplete_set_missing_a_key(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => Feature::isFeatureFlag($key) ? false : 1,
        ])->values()->all();
        array_pop($rows);

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])
            ->assertStatus(422);
    }

    public function test_rejects_value_type_mismatch(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => Feature::isFeatureFlag($key) ? false : 1,
        ])->values()->all();
        $rows[0]['value'] = 'not-a-boolean-or-integer'; // first non-dormant key is an integer usage entitlement

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])
            ->assertStatus(422);
    }

    public function test_rejects_unlimited_row_with_a_finite_value(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => true,
            'is_unlimited' => $key === Feature::MAX_ACTIVE_PROJECTS,
            'value' => $key === Feature::MAX_ACTIVE_PROJECTS ? 10 : (Feature::isFeatureFlag($key) ? false : 1),
        ])->values()->all();

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])
            ->assertStatus(422);
    }

    public function test_client_cannot_access_entitlement_editor(): void
    {
        Sanctum::actingAs($this->makeAdmin('Client'));
        $plan = $this->makePlan();

        $this->getJson("/api/admin/pricing/plans/{$plan->id}/entitlements")->assertForbidden();
    }

    public function test_editing_entitlements_never_writes_a_snapshot_row(): void
    {
        Sanctum::actingAs($this->makeAdmin());
        $plan = $this->makePlan();

        $rows = collect($this->nonDormantKeys())->map(fn (string $key) => [
            'feature_key' => $key,
            'is_applicable' => true,
            'is_unlimited' => false,
            'value' => Feature::isFeatureFlag($key) ? false : 999,
        ])->values()->all();

        $this->putJson("/api/admin/pricing/plans/{$plan->id}/entitlements", ['entitlements' => $rows])->assertOk();

        $this->assertDatabaseCount('billing_entitlement_snapshots', 0);
    }
}
