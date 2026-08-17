<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendInvitationEmailJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch Invite — UserController::bulkInvite(). Covers: successful batch
 * invitation, partial-success honesty (a bad email in the batch never
 * aborts the rest — see the Error Handling Standard), within-batch
 * deduplication, the 100-email cap, and authorization.
 */
class UserBulkInviteApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['organization_id' => null]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_bulk_invite_creates_a_user_and_sends_an_invitation_for_each_email(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/bulk-invite', [
            'emails' => ['alice@example.com', 'bob@example.com'],
            'role'   => 'Client',
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(2, 'data.invited');
        $response->assertJsonCount(0, 'data.failed');

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'bob@example.com']);

        $alice = User::where('email', 'alice@example.com')->first();
        $this->assertTrue($alice->hasRole('Client'));

        Queue::assertPushed(SendInvitationEmailJob::class, 2);
    }

    public function test_bulk_invite_reports_per_email_failures_without_aborting_the_rest(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();

        // 'existing@example.com' is already a real, non-deleted user — the
        // per-row unique check should fail just that row, not the batch.
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/users/bulk-invite', [
            'emails' => ['new@example.com', 'existing@example.com', 'not-an-email'],
            'role'   => 'Client',
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'data.invited');
        $response->assertJsonCount(2, 'data.failed');

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
        Queue::assertPushed(SendInvitationEmailJob::class, 1);
    }

    public function test_bulk_invite_dedupes_case_insensitive_repeats_within_the_same_batch(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/bulk-invite', [
            'emails' => ['Dup@Example.com', 'dup@example.com', ' dup@example.com '],
            'role'   => 'Client',
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'data.invited');
        $this->assertSame(1, User::where('email', 'dup@example.com')->count());
        Queue::assertPushed(SendInvitationEmailJob::class, 1);
    }

    public function test_bulk_invite_rejects_more_than_one_hundred_emails(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();

        $emails = array_map(fn (int $i) => "user{$i}@example.com", range(1, 101));

        $response = $this->postJson('/api/users/bulk-invite', [
            'emails' => $emails,
            'role'   => 'Client',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['emails']);
        Queue::assertNothingPushed();
    }

    public function test_bulk_invite_rejects_a_disallowed_role(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();

        $response = $this->postJson('/api/users/bulk-invite', [
            'emails' => ['someone@example.com'],
            'role'   => 'Company Admin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_bulk_invite_is_forbidden_for_non_super_admins(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['organization_id' => null]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/users/bulk-invite', [
            'emails' => ['someone@example.com'],
            'role'   => 'Client',
        ]);

        $response->assertStatus(403);
        Queue::assertNothingPushed();
    }
}
