<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Batch 2 API surface: organisations.timezone (required) and users.timezone
 * (nullable override). Persistence and validation only — no rendering/
 * business-logic changes are covered here (that's Batch 4/5).
 */
class TimezoneSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $orgTimezone = 'Europe/London'): array
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => $orgTimezone]);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return [$org, $user];
    }

    // ── PUT /organization — organisation timezone ───────────────────────────

    public function test_organization_update_accepts_a_valid_iana_timezone(): void
    {
        [$org, $user] = $this->makeOrgAndUser();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/organization', ['timezone' => 'Asia/Manila']);

        $response->assertOk();
        $this->assertSame('Asia/Manila', $org->fresh()->timezone);
    }

    public function test_organization_update_rejects_a_raw_utc_offset(): void
    {
        [, $user] = $this->makeOrgAndUser();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/organization', ['timezone' => 'UTC+8']);

        $response->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    public function test_organization_update_rejects_empty_timezone_but_allows_omitting_it(): void
    {
        [$org, $user] = $this->makeOrgAndUser('Europe/London');
        Sanctum::actingAs($user);

        // Present but blank — must fail, timezone is a required column.
        $this->putJson('/api/organization', ['timezone' => ''])
            ->assertStatus(422)->assertJsonValidationErrors('timezone');

        // Omitted entirely — must succeed and leave the existing value untouched.
        $this->putJson('/api/organization', ['city' => 'London'])->assertOk();
        $this->assertSame('Europe/London', $org->fresh()->timezone);
    }

    public function test_organization_created_without_a_timezone_defaults_to_europe_london(): void
    {
        $org = Organization::create(['name' => 'Default Org', 'slug' => 'default-org']);

        // Eloquent doesn't read back column defaults after INSERT — assert
        // against a re-fetched instance to check what's actually stored.
        $this->assertSame('Europe/London', $org->fresh()->timezone);
    }

    // ── PUT /auth/timezone — user override ──────────────────────────────────

    public function test_user_can_set_an_explicit_timezone_override(): void
    {
        [, $user] = $this->makeOrgAndUser('Europe/London');
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/timezone', ['timezone' => 'America/New_York']);

        $response->assertOk();
        $this->assertSame('America/New_York', $user->fresh()->timezone);
        $this->assertSame('America/New_York', $response->json('effective_timezone'));
    }

    public function test_user_can_clear_their_override_to_inherit_the_organisation_timezone_again(): void
    {
        [, $user] = $this->makeOrgAndUser('Europe/London');
        $user->update(['timezone' => 'America/New_York']);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/timezone', ['timezone' => null]);

        $response->assertOk();
        $this->assertNull($user->fresh()->timezone);
        $this->assertSame('Europe/London', $response->json('effective_timezone'));
    }

    public function test_user_timezone_override_rejects_a_raw_offset(): void
    {
        [, $user] = $this->makeOrgAndUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/timezone', ['timezone' => 'GMT+1'])
            ->assertStatus(422)->assertJsonValidationErrors('timezone');
    }

    // ── /auth/me exposes timezone data cleanly ──────────────────────────────

    public function test_me_endpoint_exposes_user_and_organisation_timezone_and_the_effective_value(): void
    {
        [$org, $user] = $this->makeOrgAndUser('Asia/Manila');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertNull($response->json('timezone'));
        $this->assertSame('Asia/Manila', $response->json('effective_timezone'));
        $this->assertSame('Asia/Manila', $response->json('organization.timezone'));

        $user->update(['timezone' => 'Australia/Sydney']);
        Sanctum::actingAs($user->fresh());

        $response = $this->getJson('/api/auth/me');
        $this->assertSame('Australia/Sydney', $response->json('timezone'));
        $this->assertSame('Australia/Sydney', $response->json('effective_timezone'));
    }
}
