<?php

namespace Tests\Feature\Pricing;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PricingValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        Sanctum::actingAs($user);
    }

    private function basePlanPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'starter',
            'slug' => 'starter',
            'name' => 'Starter',
        ], $overrides);
    }

    public function test_invalid_badge_color_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['badge_color' => 'hotpink']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('badge_color');
    }

    public function test_invalid_background_style_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['background_style' => 'rainbow']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('background_style');
    }

    public function test_invalid_icon_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['icon' => 'not-a-real-icon']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('icon');
    }

    public function test_javascript_url_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['cta_url' => 'javascript:alert(1)']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cta_url');
    }

    public function test_data_url_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['cta_url' => 'data:text/html,<script>1</script>']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cta_url');
    }

    public function test_relative_url_is_accepted(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['cta_url' => '/book/demo']))
            ->assertCreated();
    }

    public function test_https_url_is_accepted(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['cta_url' => 'https://example.com/demo']))
            ->assertCreated();
    }

    public function test_plain_http_url_is_rejected(): void
    {
        $this->actingAsSuperAdmin();

        $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload(['cta_url' => 'http://example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cta_url');
    }

    public function test_code_cannot_be_changed_via_update(): void
    {
        $this->actingAsSuperAdmin();

        $create = $this->postJson('/api/admin/pricing/plans', $this->basePlanPayload())->assertCreated();
        $planId = $create->json('data.id');

        $this->putJson("/api/admin/pricing/plans/{$planId}", ['code' => 'renamed-code', 'name' => 'Still Starter'])
            ->assertOk();

        $this->assertDatabaseHas('pricing_plans', ['id' => $planId, 'code' => 'starter']);
        $this->assertDatabaseMissing('pricing_plans', ['code' => 'renamed-code']);
    }
}
