<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role): User
    {
        static $n = 0;
        $n++;

        $org  = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    public function test_super_admin_can_access_pricing_management(): void
    {
        Sanctum::actingAs($this->makeUser('Super Admin'));

        $this->getJson('/api/admin/pricing/plans')->assertOk();
    }

    public function test_admin_can_access_pricing_management(): void
    {
        // Phase G2 — widened from Super-Admin-only to 'Super Admin|Admin' per
        // the approved Phase G0 decision: both are platform-wide roles
        // (organization_id = null), so this carries no customer-org exposure risk.
        Sanctum::actingAs($this->makeUser('Admin'));

        $this->getJson('/api/admin/pricing/plans')->assertOk();
    }

    public function test_client_is_forbidden(): void
    {
        Sanctum::actingAs($this->makeUser('Client'));

        $this->getJson('/api/admin/pricing/plans')->assertForbidden();
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/admin/pricing/plans')->assertUnauthorized();
    }

    public function test_public_endpoint_requires_no_auth(): void
    {
        $this->getJson('/api/pricing')->assertOk();
    }
}
