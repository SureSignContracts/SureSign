<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingReorderTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($user);
    }

    private function makePlans(int $count): array
    {
        $plans = [];
        for ($i = 1; $i <= $count; $i++) {
            $plans[] = PricingPlan::create(['code' => "plan-{$i}", 'slug' => "plan-{$i}", 'name' => "Plan {$i}", 'order' => $i]);
        }

        return $plans;
    }

    public function test_valid_full_permutation_reorders_successfully(): void
    {
        $this->actingAsSuperAdmin();
        [$a, $b, $c] = $this->makePlans(3);

        $this->putJson('/api/admin/pricing/plans/reorder', ['order' => [$c->id, $a->id, $b->id]])
            ->assertOk();

        $this->assertEquals(0, $c->fresh()->order);
        $this->assertEquals(1, $a->fresh()->order);
        $this->assertEquals(2, $b->fresh()->order);
    }

    public function test_partial_order_list_is_rejected_and_leaves_order_unchanged(): void
    {
        $this->actingAsSuperAdmin();
        [$a, $b, $c] = $this->makePlans(3);

        $this->putJson('/api/admin/pricing/plans/reorder', ['order' => [$a->id, $b->id]])
            ->assertUnprocessable();

        $this->assertEquals(1, $a->fresh()->order);
        $this->assertEquals(2, $b->fresh()->order);
        $this->assertEquals(3, $c->fresh()->order);
    }

    public function test_duplicate_ids_are_rejected(): void
    {
        $this->actingAsSuperAdmin();
        [$a, $b] = $this->makePlans(2);

        $this->putJson('/api/admin/pricing/plans/reorder', ['order' => [$a->id, $a->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order.0');
    }

    public function test_foreign_id_not_belonging_to_entity_is_rejected(): void
    {
        $this->actingAsSuperAdmin();
        [$a, $b] = $this->makePlans(2);

        $this->putJson('/api/admin/pricing/plans/reorder', ['order' => [$a->id, $b->id, 999999]])
            ->assertUnprocessable();

        $this->assertEquals(1, $a->fresh()->order);
        $this->assertEquals(2, $b->fresh()->order);
    }
}
