<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\SuresignNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers AuthController::notifyPlatformOperatorsOfInvitedUserFirstLogin() —
 * every Super Admin/Admin gets a personal in-app notification the first
 * (and only the first) time an admin-invited user actually logs in.
 * Deliberately never fires for a self-registered/onboarded account.
 */
class InvitedUserFirstLoginNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuperAdmin(string $email = 'super-admin@example.com'): User
    {
        $admin = User::factory()->create(['email' => $email]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']));

        return $admin;
    }

    private function makeAdmin(string $email = 'admin@example.com'): User
    {
        $admin = User::factory()->create(['email' => $email]);
        $admin->assignRole(Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']));

        return $admin;
    }

    /** Mirrors the exact 'user.invited' ActivityLog UserController::inviteOneUser() records. */
    private function markAsInvited(User $user): void
    {
        ActivityLog::record('user.invited', "Invited {$user->email} to SureSign as Client", null, $user, ['role' => 'Client']);
    }

    public function test_every_super_admin_and_admin_is_notified_on_an_invited_users_first_login(): void
    {
        $superAdmin = $this->makeSuperAdmin();
        $admin = $this->makeAdmin();
        $invitee = User::factory()->create(['email' => 'invitee@example.com', 'password' => bcrypt('MyPassphrase1!'), 'last_login_at' => null]);
        $this->markAsInvited($invitee);

        $this->postJson('/api/auth/login', [
            'email' => 'invitee@example.com',
            'password' => 'MyPassphrase1!',
        ])->assertStatus(200);

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $superAdmin->id,
            'type' => 'invited_user_first_login',
        ]);
        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $admin->id,
            'type' => 'invited_user_first_login',
        ]);
    }

    public function test_no_notification_on_a_second_login_from_the_same_invited_user(): void
    {
        $this->makeSuperAdmin();
        $invitee = User::factory()->create(['email' => 'repeatlogin@example.com', 'password' => bcrypt('MyPassphrase1!'), 'last_login_at' => null]);
        $this->markAsInvited($invitee);

        $this->postJson('/api/auth/login', ['email' => 'repeatlogin@example.com', 'password' => 'MyPassphrase1!'])->assertStatus(200);
        SuresignNotification::query()->delete();

        $this->postJson('/api/auth/login', ['email' => 'repeatlogin@example.com', 'password' => 'MyPassphrase1!'])->assertStatus(200);

        $this->assertSame(0, SuresignNotification::where('type', 'invited_user_first_login')->count());
    }

    public function test_no_notification_for_a_self_registered_users_first_login(): void
    {
        $this->makeSuperAdmin();
        // Never invited — no 'user.invited' ActivityLog entry for this user.
        $selfRegistered = User::factory()->create(['email' => 'organic@example.com', 'password' => bcrypt('MyPassphrase1!'), 'last_login_at' => null]);

        $this->postJson('/api/auth/login', ['email' => 'organic@example.com', 'password' => 'MyPassphrase1!'])->assertStatus(200);

        $this->assertSame(0, SuresignNotification::where('type', 'invited_user_first_login')->count());
    }

    public function test_no_notification_when_no_platform_operator_exists_yet(): void
    {
        // A fresh database with no Super Admin/Admin seeded — login must not
        // throw (RoleDoesNotExist etc.) just because there's no one to notify.
        $invitee = User::factory()->create(['email' => 'lonely@example.com', 'password' => bcrypt('MyPassphrase1!'), 'last_login_at' => null]);
        $this->markAsInvited($invitee);

        $this->postJson('/api/auth/login', ['email' => 'lonely@example.com', 'password' => 'MyPassphrase1!'])
            ->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }
}
