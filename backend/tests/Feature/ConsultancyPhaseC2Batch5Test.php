<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ConsultancyService;
use App\Models\ConsultationEnquiry;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Consultancy — Phase C2, Batch 5 (Project Linkage). See
 * internal-docs/commercial/suresign-consultancy-phase-c2-specification-v1.md §16.
 */
class ConsultancyPhaseC2Batch5Test extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(string $role): array
    {
        static $n = 0;
        $n++;
        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return [$org, $user];
    }

    private function makeService(array $overrides = []): ConsultancyService
    {
        static $n = 0;
        $n++;

        return app(ConsultancyCatalogueService::class)->create(array_merge([
            'code'                             => "batch5-service-{$n}",
            'display_name'                     => "Batch 5 Service {$n}",
            'enabled'                          => true,
            'publicly_bookable'                => true,
            'available_to_existing_customers'  => true,
            'price_minor_units'                => 4000,
            'currency'                         => 'GBP',
            'duration_minutes'                 => 30,
            'requires_confirmation'            => false,
            'assignment_mode'                  => 'manual',
        ], $overrides));
    }

    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }
        return $date->toDateString();
    }

    private function makeProject(Organization $org, array $overrides = []): Project
    {
        static $n = 0;
        $n++;
        $creator = User::factory()->create(['organization_id' => $org->id]);

        return Project::create(array_merge([
            'organization_id' => $org->id,
            'created_by'      => $creator->id,
            'name'            => "Project {$n}",
            'code'            => "PRJ-{$n}",
            'status'          => 'active',
        ], $overrides));
    }

    private function makeClient(Organization $org, string $name = 'Acme Client'): Client
    {
        return Client::create(['organization_id' => $org->id, 'name' => $name]);
    }

    /** Books a consultation for $org's own Client, assigned to $assignedAdmin, returns the fresh ConsultationEnquiry. */
    private function makeAssignedConsultation(Organization $org, User $assignedAdmin, int $weekday): ConsultationEnquiry
    {
        \App\Models\AppointmentAvailability::create([
            'user_id' => $assignedAdmin->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY, 'weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '17:00', 'is_active' => true,
        ]);
        // Consultancy Live Booking Upgrade, Stage 1 — the consultant is a
        // platform-wide setting, not a per-service field.
        \App\Models\SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $assignedAdmin->id]);
        $service = $this->makeService();
        $client = User::factory()->create(['organization_id' => $org->id]);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));
        $date = $this->nextDateForWeekday($weekday);

        $booking = $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A query',
            'description'        => 'A description.',
        ])->assertStatus(201)->json();

        return ConsultationEnquiry::where('appointment_id', $booking['id'])->firstOrFail();
    }

    // ── Authorization ────────────────────────────────────────────────────────

    public function test_super_admin_can_link_a_project(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 0);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])
            ->assertStatus(200)
            ->assertJsonPath('project.id', $project->id);
    }

    public function test_assigned_admin_can_link_a_project(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 1);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);

        $this->actingAs($assignedAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])
            ->assertStatus(200)
            ->assertJsonPath('project.id', $project->id);
    }

    public function test_unassigned_admin_receives_403_on_link_and_unlink(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $unassignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 2);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);

        $this->actingAs($unassignedAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])
            ->assertStatus(403);
        $this->actingAs($unassignedAdmin)
            ->deleteJson("/api/admin/consultancy/consultations/{$appointment->id}/project")
            ->assertStatus(403);
    }

    public function test_client_receives_403_on_link_and_unlink(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 3);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);

        $this->actingAs($client)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])
            ->assertStatus(403);
    }

    public function test_unassigned_admin_never_learns_whether_the_project_is_invalid_because_authorization_runs_first(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $unassignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 4);
        $appointment = $enquiry->appointment;

        // A nonexistent project id — if authorization ran second, this
        // would 422; since it runs first, an unassigned Admin gets a plain
        // 403 regardless of the project_id's validity.
        $response = $this->actingAs($unassignedAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => 999999]);
        $response->assertStatus(403);
    }

    // ── Organisation validation ──────────────────────────────────────────────

    public function test_same_organisation_link_succeeds(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 5);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);

        $this->actingAs($assignedAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])
            ->assertStatus(200);
    }

    public function test_cross_organisation_link_is_rejected_with_422_even_for_super_admin(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 6);
        $appointment = $enquiry->appointment;

        [$otherOrg] = $this->makeOrgAndUser('Client');
        $foreignProject = $this->makeProject($otherOrg);

        $response = $this->actingAs($superAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $foreignProject->id]);

        $response->assertStatus(422)->assertJsonFragment(['message' => 'This project belongs to a different organisation.']);
        $this->assertNull($appointment->fresh()->project_id);
    }

    public function test_soft_deleted_project_is_rejected(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 0);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);
        $project->delete();

        $response = $this->actingAs($assignedAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id]);
        $response->assertStatus(422);
    }

    // ── Relationship: link / change / unlink / idempotency ──────────────────

    public function test_change_link_replaces_the_existing_project_and_logs_both_identifiers(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 1);
        $appointment = $enquiry->appointment;
        $projectA = $this->makeProject($appointment->organization, ['name' => 'Project A', 'code' => 'PA']);
        $projectB = $this->makeProject($appointment->organization, ['name' => 'Project B', 'code' => 'PB']);

        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $projectA->id])->assertStatus(200);
        $response = $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $projectB->id]);

        $response->assertStatus(200)->assertJsonPath('project.id', $projectB->id);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'consultation.project_changed',
        ]);
        $log = \App\Models\ActivityLog::where('action', 'consultation.project_changed')->firstOrFail();
        $this->assertSame($projectA->id, $log->metadata['previous_project_id']);
        $this->assertSame('PA', $log->metadata['previous_project_code']);
        $this->assertSame($projectB->id, $log->metadata['new_project_id']);
        $this->assertSame('PB', $log->metadata['new_project_code']);
    }

    public function test_unlink_removes_the_relationship_and_logs_it(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 2);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])->assertStatus(200);

        $response = $this->actingAs($assignedAdmin)->deleteJson("/api/admin/consultancy/consultations/{$appointment->id}/project");

        $response->assertStatus(200)->assertJsonPath('project', null);
        $this->assertNull($appointment->fresh()->project_id);
        $this->assertDatabaseHas('activity_logs', ['action' => 'consultation.project_unlinked']);
    }

    public function test_relinking_the_same_project_is_an_idempotent_no_op(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 3);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])->assertStatus(200);
        $updatedAtAfterFirstLink = $appointment->fresh()->updated_at;
        $activityCountAfterFirstLink = \App\Models\ActivityLog::count();

        $response = $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id]);

        $response->assertStatus(200)->assertJsonPath('project.id', $project->id);
        $this->assertSame($activityCountAfterFirstLink, \App\Models\ActivityLog::count(), 'Re-linking the same project must not create an activity event.');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'consultation.project_changed']);
        $this->assertEquals($updatedAtAfterFirstLink, $appointment->fresh()->updated_at);
    }

    public function test_unlinking_when_nothing_is_linked_is_a_safe_no_op(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 4);
        $appointment = $enquiry->appointment;

        $response = $this->actingAs($assignedAdmin)->deleteJson("/api/admin/consultancy/consultations/{$appointment->id}/project");

        $response->assertStatus(200)->assertJsonPath('project', null);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'consultation.project_unlinked']);
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    public function test_operator_presenter_exposes_only_approved_project_fields(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 5);
        $appointment = $enquiry->appointment;
        $client = $this->makeClient($appointment->organization, 'Acme Client Co');
        $project = $this->makeProject($appointment->organization, ['client_id' => $client->id]);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])->assertStatus(200);

        $response = $this->actingAs($assignedAdmin)->getJson("/api/admin/consultancy/consultations/{$appointment->id}");

        $projectJson = $response->json('project');
        $this->assertEqualsCanonicalizing(['id', 'name', 'code', 'status', 'client', 'organization'], array_keys($projectJson));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($projectJson['client']));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($projectJson['organization']));
        $this->assertSame('Acme Client Co', $projectJson['client']['name']);
        // Never a raw Project field like contract_value, retention_percentage, address, etc.
        $this->assertArrayNotHasKey('contract_value', $projectJson);
        $this->assertArrayNotHasKey('address', $projectJson);
    }

    public function test_project_side_endpoint_exposes_only_approved_consultancy_fields(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 6);
        $appointment = $enquiry->appointment;
        $enquiry->update(['internal_notes' => 'Should never appear here.']);
        $project = $this->makeProject($appointment->organization);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])->assertStatus(200);

        $response = $this->actingAs($assignedAdmin)->getJson("/api/admin/consultancy/projects/{$project->id}/consultations");

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertEqualsCanonicalizing(
            ['id', 'reference', 'consultancy_service', 'engagement_status', 'appointment_status', 'assigned_consultant', 'created_at', 'starts_at', 'permissions'],
            array_keys($row),
        );
        $this->assertStringNotContainsString('Should never appear here.', $response->getContent());
        $this->assertArrayNotHasKey('internal_notes', $row);
        $this->assertArrayNotHasKey('activity', $row);
        $this->assertArrayNotHasKey('attendee_email', $row);
    }

    public function test_client_can_view_their_own_linked_project_consultations_summary(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 0);
        $appointment = $enquiry->appointment;
        $project = $this->makeProject($appointment->organization);
        $this->actingAs($assignedAdmin)->putJson("/api/admin/consultancy/consultations/{$appointment->id}/project", ['project_id' => $project->id])->assertStatus(200);

        // The project's own detail endpoint (existing ProjectController;
        // untouched by this batch) remains reachable by the org's own Client.
        $orgClient = User::where('organization_id', $appointment->organization_id)->whereHas('roles', fn ($q) => $q->where('name', 'Client'))->first();
        $this->actingAs($orgClient)->getJson("/api/projects/{$project->id}")->assertStatus(200);
    }

    // ── Project search extension ─────────────────────────────────────────────

    public function test_project_search_matches_name_code_and_client_name(): void
    {
        [$org, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $client = $this->makeClient($org, 'Findable Client Ltd');
        $byName = $this->makeProject($org, ['name' => 'Findable Tower', 'code' => 'X1']);
        $byCode = $this->makeProject($org, ['name' => 'Other Project', 'code' => 'FINDCODE']);
        $byClient = $this->makeProject($org, ['name' => 'Unrelated', 'code' => 'X2', 'client_id' => $client->id]);
        $this->makeProject($org, ['name' => 'Totally Different', 'code' => 'X3']);

        $byNameResp = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$org->id}&search=Findable+Tower");
        $this->assertSame([$byName->id], collect($byNameResp->json('data'))->pluck('id')->all());

        $byCodeResp = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$org->id}&search=FINDCODE");
        $this->assertSame([$byCode->id], collect($byCodeResp->json('data'))->pluck('id')->all());

        $byClientResp = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$org->id}&search=Findable+Client");
        $this->assertSame([$byClient->id], collect($byClientResp->json('data'))->pluck('id')->all());
    }

    public function test_project_search_no_match_returns_empty(): void
    {
        [$org, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $this->makeProject($org);

        $response = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$org->id}&search=NoSuchThingExists");
        $this->assertCount(0, $response->json('data'));
    }

    public function test_project_search_composes_with_status_filter(): void
    {
        [$org, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $active = $this->makeProject($org, ['name' => 'Shared Name', 'status' => 'active']);
        $completed = $this->makeProject($org, ['name' => 'Shared Name', 'status' => 'completed']);

        $response = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$org->id}&search=Shared+Name&status=active");
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($completed->id));
    }

    public function test_project_search_composes_with_organisation_filter(): void
    {
        [$orgA, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [$orgB] = $this->makeOrgAndUser('Client');
        $inOrgA = $this->makeProject($orgA, ['name' => 'Shared Search Term']);
        $this->makeProject($orgB, ['name' => 'Shared Search Term']);

        $response = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$orgA->id}&search=Shared+Search+Term");
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertSame([$inOrgA->id], $ids->all());
    }

    public function test_project_search_supports_pagination(): void
    {
        [$org, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        for ($i = 0; $i < 3; $i++) {
            $this->makeProject($org, ['name' => 'Paginated Project']);
        }

        $response = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$org->id}&search=Paginated&per_page=2");
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(3, $response->json('total'));
    }

    public function test_admin_can_search_across_permitted_organisations(): void
    {
        [$orgA, $admin] = $this->makeOrgAndUser('Admin');
        [$orgB] = $this->makeOrgAndUser('Client');
        $this->makeProject($orgA, ['name' => 'Cross Org Match']);
        $projectB = $this->makeProject($orgB, ['name' => 'Cross Org Match']);

        $response = $this->actingAs($admin)->getJson("/api/projects?organization_id={$orgB->id}&search=Cross+Org+Match");
        $this->assertSame([$projectB->id], collect($response->json('data'))->pluck('id')->all());
    }

    public function test_client_cannot_discover_projects_outside_their_own_organisation_via_search(): void
    {
        [$orgA, $clientA] = $this->makeOrgAndUser('Client');
        [$orgB] = $this->makeOrgAndUser('Client');
        $this->makeProject($orgA, ['name' => 'Visible To Client A']);
        $this->makeProject($orgB, ['name' => 'Visible To Client A']); // same searchable name, different org

        // A Client cannot pass organization_id at all (ignored for non-staff);
        // scoping to their own org happens regardless of any search term.
        $response = $this->actingAs($clientA)->getJson("/api/projects?organization_id={$orgB->id}&search=Visible+To+Client+A");
        $projects = collect($response->json('data'));
        $this->assertTrue($projects->every(fn ($p) => $p['organization_id'] === $orgA->id));
    }

    public function test_soft_deleted_client_is_excluded_from_search_matches(): void
    {
        [$org, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $client = $this->makeClient($org, 'Soon Deleted Client');
        $project = $this->makeProject($org, ['name' => 'Some Project', 'client_id' => $client->id]);
        $client->delete();

        $response = $this->actingAs($superAdmin)->getJson("/api/projects?organization_id={$org->id}&search=Soon+Deleted+Client");
        $this->assertCount(0, $response->json('data'));
    }

    // ── Regression ───────────────────────────────────────────────────────────

    public function test_existing_batch_4_write_actions_still_work_after_project_linkage_added(): void
    {
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        $enquiry = $this->makeAssignedConsultation($assignedAdmin->organization, $assignedAdmin, 1);
        $appointment = $enquiry->appointment;

        $this->actingAs($assignedAdmin)
            ->putJson("/api/admin/consultancy/consultations/{$appointment->id}/notes", ['internal_notes' => 'Still works.'])
            ->assertStatus(200);
    }

    public function test_existing_project_detail_endpoint_still_works(): void
    {
        [$org, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        $project = $this->makeProject($org);

        $this->actingAs($superAdmin)->getJson("/api/projects/{$project->id}")->assertStatus(200);
    }
}
