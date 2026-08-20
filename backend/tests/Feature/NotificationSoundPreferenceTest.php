<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notification Sound System — the `users.notification_sound_enabled`
 * preference (PUT /auth/notification-sound). Persistence, validation, and
 * per-user isolation only — actual audio playback is a frontend concern
 * with no backend surface.
 */
class NotificationSoundPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrgAndUser(): array
    {
        $org  = Organization::create(['name' => 'Org', 'slug' => 'org', 'timezone' => 'Europe/London']);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return [$org, $user];
    }

    public function test_notification_sound_defaults_to_enabled_for_a_new_user(): void
    {
        [, $user] = $this->makeOrgAndUser();

        // Eloquent doesn't read back column defaults after INSERT — assert
        // against a re-fetched instance to check what's actually stored.
        $this->assertTrue($user->fresh()->notification_sound_enabled);
    }

    public function test_user_can_disable_their_own_notification_sound_preference(): void
    {
        [, $user] = $this->makeOrgAndUser();
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/notification-sound', ['enabled' => false]);

        $response->assertOk();
        $this->assertFalse($user->fresh()->notification_sound_enabled);
        $this->assertFalse($response->json('notification_sound_enabled'));
    }

    public function test_user_can_re_enable_their_notification_sound_preference(): void
    {
        [, $user] = $this->makeOrgAndUser();
        $user->update(['notification_sound_enabled' => false]);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/auth/notification-sound', ['enabled' => true]);

        $response->assertOk();
        $this->assertTrue($user->fresh()->notification_sound_enabled);
        $this->assertTrue($response->json('notification_sound_enabled'));
    }

    public function test_notification_sound_update_rejects_a_non_boolean_value(): void
    {
        [, $user] = $this->makeOrgAndUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/notification-sound', ['enabled' => 'loud'])
            ->assertStatus(422)->assertJsonValidationErrors('enabled');
    }

    public function test_notification_sound_update_requires_the_enabled_field(): void
    {
        [, $user] = $this->makeOrgAndUser();
        Sanctum::actingAs($user);

        $this->putJson('/api/auth/notification-sound', [])
            ->assertStatus(422)->assertJsonValidationErrors('enabled');
    }

    public function test_a_user_cannot_alter_another_users_notification_sound_preference(): void
    {
        [$org, $userA] = $this->makeOrgAndUser();
        $userB = User::factory()->create(['organization_id' => $org->id]);

        Sanctum::actingAs($userA);
        $this->putJson('/api/auth/notification-sound', ['enabled' => false])->assertOk();

        // The endpoint only ever acts on $request->user() — there is no
        // target-user parameter to manipulate — so User B's own row must be
        // completely unaffected by User A's change.
        $this->assertFalse($userA->fresh()->notification_sound_enabled);
        $this->assertTrue($userB->fresh()->notification_sound_enabled);
    }

    public function test_me_endpoint_exposes_the_notification_sound_preference(): void
    {
        [, $user] = $this->makeOrgAndUser();
        $user->update(['notification_sound_enabled' => false]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertFalse($response->json('notification_sound_enabled'));
    }

    public function test_login_response_exposes_the_notification_sound_preference(): void
    {
        [, $user] = $this->makeOrgAndUser();
        $user->update(['password' => bcrypt('password'), 'notification_sound_enabled' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();
        $this->assertFalse($response->json('user.notification_sound_enabled'));
    }
}
