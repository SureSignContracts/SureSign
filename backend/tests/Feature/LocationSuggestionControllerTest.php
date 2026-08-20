<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Global Address UX V3 — GET /location-suggestions/cities. Every test uses
 * Http::fake() for the real bound GeoapifyLocationSuggestionProvider
 * (mirrors this codebase's own "never a hand-written provider stand-in"
 * testing convention) — none ever calls the real Geoapify API.
 */
class LocationSuggestionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $org = Organization::create(['name' => 'City Suggestion Org', 'slug' => 'city-suggestion-org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson('/api/location-suggestions/cities?query=Calap');

        $response->assertStatus(401);
    }

    public function test_valid_query_returns_normalized_suggestions(): void
    {
        $this->actingUser();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [
            ['city' => 'Calapan', 'state' => 'Oriental Mindoro', 'country' => 'Philippines', 'result_type' => 'city'],
        ]])]);

        $response = $this->getJson('/api/location-suggestions/cities?' . http_build_query([
            'query' => 'Calap', 'country_code' => 'PH',
        ]));

        $response->assertOk();
        $response->assertJson(['data' => [
            ['name' => 'Calapan', 'region' => 'Oriental Mindoro', 'country' => 'Philippines'],
        ]]);
    }

    /**
     * V3 closeout: `region` is not part of this endpoint's contract at
     * all — submitting it must never be a validation error (it's simply
     * an unrecognised field, silently ignored by Laravel's validator
     * since it isn't listed in the rules), and it must have zero effect
     * on the Geoapify request actually sent.
     */
    public function test_submitting_region_is_not_a_validation_error_and_has_no_effect(): void
    {
        $this->actingUser();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => []])]);

        $response = $this->getJson('/api/location-suggestions/cities?' . http_build_query([
            'query' => 'Calap', 'country_code' => 'PH', 'region' => 'Oriental Mindoro',
        ]));

        $response->assertOk();
        Http::assertSent(fn ($request) => $request['text'] === 'Calap' && !str_contains(json_encode($request->data()), 'Mindoro'));
    }

    public function test_missing_query_is_a_validation_error(): void
    {
        $this->actingUser();

        $response = $this->getJson('/api/location-suggestions/cities');

        $response->assertStatus(422);
    }

    public function test_invalid_country_code_shape_is_a_validation_error(): void
    {
        $this->actingUser();

        $response = $this->getJson('/api/location-suggestions/cities?query=Calap&country_code=PHL');

        $response->assertStatus(422);
    }

    public function test_below_minimum_length_query_returns_empty_without_calling_provider(): void
    {
        $this->actingUser();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake();

        $response = $this->getJson('/api/location-suggestions/cities?query=C');

        $response->assertOk();
        $response->assertJson(['data' => []]);
        Http::assertNothingSent();
    }

    public function test_provider_failure_returns_empty_data_not_a_server_error(): void
    {
        $this->actingUser();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response([], 500)]);

        $response = $this->getJson('/api/location-suggestions/cities?query=Calap');

        $response->assertOk();
        $response->assertJson(['data' => []]);
    }

    public function test_api_key_is_never_present_in_the_response_body(): void
    {
        $this->actingUser();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [
            ['city' => 'Calapan', 'result_type' => 'city'],
        ]])]);

        $response = $this->getJson('/api/location-suggestions/cities?query=Calap');

        $response->assertOk();
        $this->assertStringNotContainsString('secret-key', $response->getContent());
    }

    public function test_endpoint_does_not_mutate_any_database_record(): void
    {
        $user = $this->actingUser();
        config(['services.geoapify.api_key' => 'secret-key']);
        Http::fake(['api.geoapify.com/*' => Http::response(['results' => [
            ['city' => 'Calapan', 'result_type' => 'city'],
        ]])]);

        $before = $user->fresh()->updated_at;
        $orgBefore = $user->organization->fresh()->updated_at;

        $this->getJson('/api/location-suggestions/cities?query=Calap')->assertOk();

        $this->assertEquals($before, $user->fresh()->updated_at);
        $this->assertEquals($orgBefore, $user->organization->fresh()->updated_at);
    }
}
