<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dashboard Command Center — Project Location & Map Foundation.
 *
 * Covers only the validation behaviour introduced for
 * `projects.latitude`/`projects.longitude`: nullable, numeric range,
 * required-as-a-pair, and that 0,0 is never used as an empty-input
 * default. Project Map payload scoping is covered separately in
 * OrganisationDashboardActionCentreTest.
 */
class ProjectLocationCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $org = Organization::create(['name' => 'Coord Org', 'slug' => 'coord-org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_project_can_be_created_without_coordinates(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'No Coordinates Project']);

        $response->assertStatus(201);
        $this->assertNull($response->json('latitude'));
        $this->assertNull($response->json('longitude'));
    }

    public function test_project_can_be_created_with_a_valid_coordinate_pair(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Mapped Project', 'latitude' => 51.5074, 'longitude' => -0.1278,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(51.5074, (float) $response->json('latitude'));
        $this->assertEquals(-0.1278, (float) $response->json('longitude'));
    }

    public function test_latitude_out_of_range_is_rejected(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Bad Lat', 'latitude' => 91, 'longitude' => 0,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('latitude');
    }

    public function test_longitude_out_of_range_is_rejected(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Bad Lng', 'latitude' => 0, 'longitude' => 181,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_latitude_without_longitude_is_rejected(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Half Coordinates', 'latitude' => 51.5074,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_longitude_without_latitude_is_rejected(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Half Coordinates', 'longitude' => -0.1278,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('latitude');
    }

    public function test_zero_zero_is_a_legitimate_coordinate_not_treated_as_empty(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', [
            'name' => 'Equator Prime Meridian', 'latitude' => 0, 'longitude' => 0,
        ]);

        $response->assertStatus(201);
        $this->assertEquals(0.0, (float) $response->json('latitude'));
        $this->assertEquals(0.0, (float) $response->json('longitude'));
    }

    public function test_empty_country_does_not_override_the_database_default(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Blank Country', 'country' => '']);

        $response->assertStatus(201);
        $this->assertEquals('AU', $response->json('country'));
    }

    public function test_an_explicit_country_is_saved_worldwide(): void
    {
        $this->actingUser();

        $response = $this->postJson('/api/projects', ['name' => 'Worldwide Project', 'country' => 'NZ']);

        $response->assertStatus(201);
        $this->assertEquals('NZ', $response->json('country'));
    }

    public function test_existing_project_coordinates_can_be_updated(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Editable Project',
        ]);

        $response = $this->putJson("/api/projects/{$project->id}", [
            'latitude' => 40.7128, 'longitude' => -74.0060,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(40.7128, (float) $response->json('latitude'));
        $this->assertEquals(-74.006, (float) $response->json('longitude'));
    }

    public function test_cross_tenant_update_is_rejected(): void
    {
        $ownerOrg = Organization::create(['name' => 'Owner Org', 'slug' => 'owner-org', 'timezone' => 'Europe/London']);
        $owner = User::factory()->create(['organization_id' => $ownerOrg->id]);
        $project = Project::create(['organization_id' => $ownerOrg->id, 'created_by' => $owner->id, 'name' => 'Owner Project']);

        $intruder = $this->actingUser(); // a different organisation

        $response = $this->putJson("/api/projects/{$project->id}", ['latitude' => 1, 'longitude' => 1]);

        $response->assertStatus(403);
        $this->assertNull($project->fresh()->latitude);
    }

    public function test_both_coordinates_can_be_cleared_via_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Mapped Project',
            'latitude' => 51.5, 'longitude' => -0.1,
        ]);

        $response = $this->putJson("/api/projects/{$project->id}", ['latitude' => null, 'longitude' => null]);

        $response->assertStatus(200);
        $this->assertNull($response->json('latitude'));
        $this->assertNull($response->json('longitude'));
    }

    public function test_update_rejects_a_single_coordinate(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Half Update']);

        $response = $this->putJson("/api/projects/{$project->id}", ['latitude' => 51.5]);

        $response->assertStatus(422)->assertJsonValidationErrors('longitude');
    }

    public function test_update_rejects_out_of_range_coordinates(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Bad Update']);

        $response = $this->putJson("/api/projects/{$project->id}", ['latitude' => 95, 'longitude' => 0]);

        $response->assertStatus(422)->assertJsonValidationErrors('latitude');
    }

    public function test_update_accepts_zero_zero(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Zero Update']);

        $response = $this->putJson("/api/projects/{$project->id}", ['latitude' => 0, 'longitude' => 0]);

        $response->assertStatus(200);
        $this->assertEquals(0.0, (float) $response->json('latitude'));
        $this->assertEquals(0.0, (float) $response->json('longitude'));
    }

    public function test_omitted_fields_are_left_unchanged_on_update(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'organization_id' => $user->organization_id, 'created_by' => $user->id, 'name' => 'Untouched Fields',
            'city' => 'London', 'latitude' => 51.5, 'longitude' => -0.1,
        ]);

        // Only the name changes — city/coordinates must survive untouched,
        // matching update()'s existing partial-update contract (see the
        // identical `currency` precedent already in this controller).
        $response = $this->putJson("/api/projects/{$project->id}", ['name' => 'Renamed Only']);

        $response->assertStatus(200);
        $this->assertEquals('London', $response->json('city'));
        $this->assertEquals(51.5, (float) $response->json('latitude'));
        $this->assertEquals(-0.1, (float) $response->json('longitude'));
    }
}
