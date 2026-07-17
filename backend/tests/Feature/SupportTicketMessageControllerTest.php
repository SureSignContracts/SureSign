<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SuresignNotification;
use App\Models\SuresignSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketMessageControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'Client', ?Organization $org = null): User
    {
        $org  = $org ?? Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $user = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makeTicket(User $owner, string $status = 'waiting_for_support'): SupportTicket
    {
        return SupportTicket::create([
            'organization_id' => $owner->organization_id,
            'user_id'         => $owner->id,
            'reference'       => 'SUP-TEST-' . uniqid(),
            'subject'         => 'Test ticket',
            'category'        => 'other',
            'priority'        => 'normal',
            'message'         => 'Original message',
            'status'          => $status,
        ]);
    }

    private function enableBrevo(): void
    {
        SuresignSetting::instance()->update(['brevo_api_key' => 'fake-brevo-key', 'support_email' => 'support@suresign.example']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'test'], 201)]);
    }

    // ── Client reply ─────────────────────────────────────────────────────────

    public function test_client_can_reply_to_own_open_ticket(): void
    {
        $this->enableBrevo();
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner, 'waiting_for_you');
        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Still broken for me.']);

        $response->assertStatus(201)->assertJsonFragment(['sender_type' => 'customer', 'body' => 'Still broken for me.']);
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id, 'user_id' => $owner->id, 'sender_type' => 'customer', 'visibility' => 'public',
        ]);
    }

    public function test_client_cannot_reply_to_another_users_ticket(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $stranger = $this->makeUser('Client', $owner->organization); // same org, different user
        Sanctum::actingAs($stranger);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Snooping.'])->assertStatus(403);
    }

    public function test_client_cannot_access_another_organizations_ticket(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $outsider = $this->makeUser(); // different org entirely
        Sanctum::actingAs($outsider);

        $this->getJson("/api/support-tickets/{$ticket->id}/messages")->assertStatus(403);
        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'x'])->assertStatus(403);
    }

    public function test_client_cannot_create_an_internal_note(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        Sanctum::actingAs($owner);

        // A Client request has no 'visibility' field at all — sending one
        // anyway must not be honored (visibility is resolved server-side
        // from role, not the request body).
        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Trying to sneak a note in', 'visibility' => 'internal'])
            ->assertStatus(201);

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id, 'sender_type' => 'customer', 'visibility' => 'public',
        ]);
    }

    public function test_client_cannot_reply_to_a_closed_ticket(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner, 'closed');
        Sanctum::actingAs($owner);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Please help again'])
            ->assertStatus(422);
    }

    // ── Admin reply / internal notes ─────────────────────────────────────────

    public function test_admin_can_reply(): void
    {
        $this->enableBrevo();
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'We are looking into it.'])
            ->assertStatus(201)
            ->assertJsonFragment(['sender_type' => 'support', 'visibility' => 'public']);
    }

    public function test_admin_can_create_an_internal_note(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Escalate to engineering.', 'visibility' => 'internal'])
            ->assertStatus(201)
            ->assertJsonFragment(['visibility' => 'internal']);

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id, 'sender_type' => 'support', 'visibility' => 'internal',
        ]);
    }

    public function test_internal_notes_are_excluded_from_the_client_thread(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');

        Sanctum::actingAs($admin);
        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Public reply', 'visibility' => 'public']);
        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Internal-only note', 'visibility' => 'internal']);

        Sanctum::actingAs($owner);
        $thread = $this->getJson("/api/support-tickets/{$ticket->id}/messages")->assertStatus(200)->json('data');

        $bodies = collect($thread)->pluck('body');
        $this->assertTrue($bodies->contains('Public reply'));
        $this->assertFalse($bodies->contains('Internal-only note'));
    }

    public function test_internal_notes_are_visible_to_admin(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Internal-only note', 'visibility' => 'internal']);
        $thread = $this->getJson("/api/support-tickets/{$ticket->id}/messages")->assertStatus(200)->json('data');

        $this->assertTrue(collect($thread)->pluck('body')->contains('Internal-only note'));
    }

    // ── Status workflow ──────────────────────────────────────────────────────

    public function test_support_reply_changes_status_to_waiting_for_you(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner, 'waiting_for_support');
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Reply'])->assertStatus(201);

        $this->assertSame('waiting_for_you', $ticket->fresh()->status);
    }

    public function test_internal_note_does_not_change_status(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner, 'waiting_for_support');
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'note', 'visibility' => 'internal'])->assertStatus(201);

        $this->assertSame('waiting_for_support', $ticket->fresh()->status);
    }

    public function test_customer_reply_changes_status_to_waiting_for_support(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner, 'waiting_for_you');
        Sanctum::actingAs($owner);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Thanks, still broken'])->assertStatus(201);

        $this->assertSame('waiting_for_support', $ticket->fresh()->status);
    }

    public function test_customer_reply_to_resolved_ticket_reopens_automatically(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner, 'resolved');
        Sanctum::actingAs($owner);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Actually still an issue'])->assertStatus(201);

        $this->assertSame('waiting_for_support', $ticket->fresh()->status);
    }

    // ── Notifications ────────────────────────────────────────────────────────

    public function test_support_reply_notifies_only_the_ticket_owner(): void
    {
        $this->enableBrevo();
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Reply'])->assertStatus(201);

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $owner->id, 'type' => 'support_ticket_reply', 'action_url' => "/app/help/support/{$ticket->id}",
        ]);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $admin->id, 'type' => 'support_ticket_reply']);
    }

    public function test_internal_note_never_notifies_the_customer(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');
        Sanctum::actingAs($admin);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'note', 'visibility' => 'internal'])->assertStatus(201);

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $owner->id, 'type' => 'support_ticket_reply']);
    }

    public function test_customer_reply_notifies_platform_operators_only(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');
        $superAdmin = $this->makeUser('Super Admin');
        $unrelatedClient = $this->makeUser('Client', $owner->organization);
        Sanctum::actingAs($owner);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Any update?'])->assertStatus(201);

        $this->assertDatabaseHas('suresign_notifications', ['user_id' => $admin->id, 'type' => 'support_ticket_customer_reply']);
        $this->assertDatabaseHas('suresign_notifications', ['user_id' => $superAdmin->id, 'type' => 'support_ticket_customer_reply']);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $unrelatedClient->id, 'type' => 'support_ticket_customer_reply']);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $owner->id, 'type' => 'support_ticket_customer_reply']);
    }

    // ── Rate limiting ────────────────────────────────────────────────────────

    public function test_reply_rate_limiting_blocks_excessive_replies(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        Sanctum::actingAs($owner);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => "Reply {$i}"])->assertStatus(201);
        }

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'One too many'])->assertStatus(429);
    }

    // ── Pagination / thread integrity ────────────────────────────────────────

    public function test_thread_is_returned_in_chronological_order(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');

        Sanctum::actingAs($owner);
        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'First']);
        Sanctum::actingAs($admin);
        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => 'Second']);

        Sanctum::actingAs($owner);
        $thread = $this->getJson("/api/support-tickets/{$ticket->id}/messages")->assertStatus(200)->json('data');

        $this->assertSame('First', $thread[0]['body']);
        $this->assertSame('Second', $thread[1]['body']);
    }

    public function test_message_body_length_is_bounded(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        Sanctum::actingAs($owner);

        $this->postJson("/api/support-tickets/{$ticket->id}/messages", ['body' => str_repeat('a', 5001)])
            ->assertStatus(422);
    }

    // ── Read markers (unread indicators) ─────────────────────────────────────

    public function test_viewing_thread_marks_it_read_for_the_correct_side(): void
    {
        $owner = $this->makeUser();
        $ticket = $this->makeTicket($owner);
        $admin = $this->makeUser('Admin');

        Sanctum::actingAs($admin);
        $this->getJson("/api/support-tickets/{$ticket->id}/messages")->assertStatus(200);
        $this->assertNotNull($ticket->fresh()->support_last_read_at);
        $this->assertNull($ticket->fresh()->client_last_read_at);

        Sanctum::actingAs($owner);
        $this->getJson("/api/support-tickets/{$ticket->id}/messages")->assertStatus(200);
        $this->assertNotNull($ticket->fresh()->client_last_read_at);
    }
}
