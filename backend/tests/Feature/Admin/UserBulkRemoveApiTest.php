<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bulk Remove — UserController::bulkRemove(). Covers: successful batch
 * (soft-delete) removal, partial-success honesty (a bad id in the batch
 * never aborts the rest — see the Error Handling Standard), the
 * can't-remove-yourself / can't-remove-the-last-Super-Admin safety checks
 * (re-checked per row, same as destroy()), the 100-id cap, and authorization.
 */
class UserBulkRemoveApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_bulk_remove_soft_deletes_each_user(): void
    {
        $this->actingAsSuperAdmin();

        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $bob   = User::factory()->create(['email' => 'bob@example.com']);

        $response = $this->postJson('/api/users/bulk-remove', [
            'ids' => [$alice->id, $bob->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data.removed');
        $response->assertJsonCount(0, 'data.failed');

        $this->assertSoftDeleted('users', ['id' => $alice->id]);
        $this->assertSoftDeleted('users', ['id' => $bob->id]);
    }

    public function test_bulk_remove_reports_per_row_failures_without_aborting_the_rest(): void
    {
        $admin = $this->actingAsSuperAdmin();

        $alice = User::factory()->create(['email' => 'alice@example.com']);
        $missingId = $alice->id + 999;

        $response = $this->postJson('/api/users/bulk-remove', [
            // A real id, the caller's own id (can't remove self), and a
            // non-existent id — none of these should stop the real id from
            // being removed.
            'ids' => [$alice->id, $admin->id, $missingId],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.removed');
        $response->assertJsonCount(2, 'data.failed');

        $this->assertSoftDeleted('users', ['id' => $alice->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_bulk_remove_refuses_to_remove_the_last_active_super_admin(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $other = User::factory()->create(['email' => 'other@example.com']);

        // superAdmin is the only active Super Admin — including their own id
        // (via a second Super Admin acting on someone else's behalf isn't
        // possible here, so this exercises the same guard destroy() has).
        $response = $this->postJson('/api/users/bulk-remove', [
            'ids' => [$other->id, $superAdmin->id],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data.removed');
        $response->assertJsonCount(1, 'data.failed');

        $this->assertSoftDeleted('users', ['id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $superAdmin->id, 'deleted_at' => null]);
    }

    public function test_bulk_remove_rejects_more_than_one_hundred_ids(): void
    {
        $this->actingAsSuperAdmin();

        $ids = range(1, 101);

        $response = $this->postJson('/api/users/bulk-remove', [
            'ids' => $ids,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    /**
     * Two User Removal Modes — bulk removal deliberately still only offers
     * "Remove User" (Option A) semantics; no bulk "Remove & Detach" exists.
     * A batch containing an org-attached Client must preserve
     * organization_id exactly as it always has, and passing a
     * detach-shaped parameter alongside `ids` must have no effect (this
     * endpoint's request validation doesn't recognise it at all).
     */
    public function test_bulk_remove_preserves_organization_id_and_has_no_detach_option(): void
    {
        $this->actingAsSuperAdmin();

        $org = \App\Models\Organization::create(['name' => 'Bulk Org', 'slug' => 'bulk-org-' . uniqid()]);
        $client = User::factory()->create(['organization_id' => $org->id, 'email' => 'bulk-preserve@example.com']);
        $client->assignRole(Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        $response = $this->postJson('/api/users/bulk-remove', [
            'ids' => [$client->id],
            // Not a recognised field for this endpoint — proves it's
            // silently ignored, not a hidden detach switch.
            'confirm_last_client' => true,
        ]);

        $response->assertStatus(200);
        $this->assertSoftDeleted('users', ['id' => $client->id]);
        $this->assertSame($org->id, User::withTrashed()->find($client->id)->organization_id);
    }

    public function test_bulk_remove_is_forbidden_for_non_super_admins(): void
    {
        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $target = User::factory()->create();

        $response = $this->postJson('/api/users/bulk-remove', [
            'ids' => [$target->id],
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }
}
