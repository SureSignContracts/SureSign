<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\SuresignNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Batch 0 of the notification system overhaul: NotificationService::sendToOrganization()
 * replaces the old behaviour where every NotificationService::send() call site only
 * notified the acting user. Covers the approved recipient/actor policy:
 *   - active, non-banned Client-role users of the organisation receive it;
 *   - other orgs, inactive users, banned users, and Admin/Super Admin do not (by default);
 *   - the actor is excluded for synchronous events, included when includeActor is set;
 *   - repeated calls with the same source_type/source_id/source_field don't duplicate.
 */
class NotificationOrganizationFanOutTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Organization $org, string $role = 'Client', array $overrides = []): User
    {
        $user = User::factory()->create(array_merge(['organization_id' => $org->id], $overrides));
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));

        return $user;
    }

    private function makeOrg(string $label): Organization
    {
        static $n = 0;
        $n++;

        return Organization::create(['name' => "{$label} Org {$n}", 'slug' => "org-{$label}-{$n}"]);
    }

    public function test_active_client_users_in_the_organisation_receive_the_notification(): void
    {
        $org    = $this->makeOrg('a');
        $client = $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $client->id,
            'type'    => 'test_event',
        ]);
    }

    public function test_users_in_another_organisation_do_not_receive_it(): void
    {
        $orgA = $this->makeOrg('a');
        $orgB = $this->makeOrg('b');
        $this->makeUser($orgA, 'Client');
        $otherOrgUser = $this->makeUser($orgB, 'Client');

        NotificationService::sendToOrganization($orgA, 'test_event', 'Title', 'Message');

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $otherOrgUser->id]);
    }

    public function test_inactive_users_are_excluded(): void
    {
        $org      = $this->makeOrg('a');
        $inactive = $this->makeUser($org, 'Client', ['is_active' => false]);

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message');

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $inactive->id]);
    }

    public function test_banned_users_are_excluded(): void
    {
        $org    = $this->makeOrg('a');
        $banned = $this->makeUser($org, 'Client', ['banned_at' => now()]);

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message');

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $banned->id]);
    }

    public function test_admin_and_super_admin_are_excluded_by_default(): void
    {
        $org        = $this->makeOrg('a');
        $admin      = $this->makeUser($org, 'Admin');
        $superAdmin = $this->makeUser($org, 'Super Admin');

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message');

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $admin->id]);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $superAdmin->id]);
    }

    public function test_actor_is_excluded_by_default_for_synchronous_events(): void
    {
        $org    = $this->makeOrg('a');
        $actor  = $this->makeUser($org, 'Client');
        $other  = $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message', [], [], $actor);

        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $actor->id]);
        $this->assertDatabaseHas('suresign_notifications', ['user_id' => $other->id]);
    }

    public function test_actor_is_included_when_includeactor_is_true_for_async_events(): void
    {
        $org   = $this->makeOrg('a');
        $actor = $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message', [], [], $actor, includeActor: true);

        $this->assertDatabaseHas('suresign_notifications', ['user_id' => $actor->id]);
    }

    public function test_recipients_are_deduplicated(): void
    {
        $org  = $this->makeOrg('a');
        $user = $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message');

        $this->assertEquals(
            1,
            SuresignNotification::where('user_id', $user->id)->where('type', 'test_event')->count()
        );
    }

    public function test_repeated_calls_with_the_same_source_do_not_duplicate(): void
    {
        $org  = $this->makeOrg('a');
        $user = $this->makeUser($org, 'Client');
        $meta = ['source_type' => 'variation', 'source_id' => 42, 'source_field' => 'variation_submitted'];

        NotificationService::sendToOrganization($org, 'variation_submitted', 'Title', 'Message', [], $meta);
        NotificationService::sendToOrganization($org, 'variation_submitted', 'Title', 'Message', [], $meta);

        $this->assertEquals(
            1,
            SuresignNotification::where('user_id', $user->id)->where('type', 'variation_submitted')->count()
        );
    }

    public function test_a_later_event_with_a_different_source_id_still_creates_a_new_notification(): void
    {
        $org  = $this->makeOrg('a');
        $user = $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization($org, 'variation_submitted', 'Title', 'Message', [], [
            'source_type' => 'variation', 'source_id' => 1, 'source_field' => 'variation_submitted',
        ]);
        NotificationService::sendToOrganization($org, 'variation_submitted', 'Title', 'Message', [], [
            'source_type' => 'variation', 'source_id' => 2, 'source_field' => 'variation_submitted',
        ]);

        $this->assertEquals(
            2,
            SuresignNotification::where('user_id', $user->id)->where('type', 'variation_submitted')->count()
        );
    }

    /**
     * Batch 1 pre-check: a single Variation record moves through up to seven
     * distinct notification types (submitted/instructed/quoted/assessed/
     * approved/rejected/resubmitted), all sharing the same source_id (the
     * variation's own id). Each transition's type doubles as its source_field
     * (see VariationController::notify()), so none of the seven can suppress
     * another even though they share source_type + source_id.
     */
    public function test_all_seven_variation_transition_types_notify_independently_for_the_same_variation(): void
    {
        $org  = $this->makeOrg('a');
        $user = $this->makeUser($org, 'Client');

        $transitions = [
            'variation_submitted', 'variation_instructed', 'variation_quoted',
            'variation_assessed', 'variation_approved', 'variation_rejected', 'variation_resubmitted',
        ];

        foreach ($transitions as $type) {
            NotificationService::sendToOrganization($org, $type, 'Title', 'Message', [], [
                'source_type' => 'variation', 'source_id' => 99, 'source_field' => $type,
            ]);
        }

        foreach ($transitions as $type) {
            $this->assertEquals(
                1,
                SuresignNotification::where('user_id', $user->id)->where('type', $type)
                    ->where('source_type', 'variation')->where('source_id', 99)->count(),
                "Transition '{$type}' did not notify — it may have been suppressed by another transition's row."
            );
        }
    }

    /**
     * Batch 1 pre-check: AI completed and failed outcomes for the same
     * analysis id share source_type + source_id but use distinct source_field
     * ('completed' vs 'failed') and distinct notification types, so a
     * completed run followed by a later failed retry (or vice versa) is
     * never suppressed by the earlier outcome.
     */
    public function test_ai_completed_and_failed_outcomes_do_not_suppress_each_other(): void
    {
        $org  = $this->makeOrg('a');
        $user = $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization($org, 'ai_analysis_completed', 'Completed', 'Message', [], [
            'source_type' => 'contract_ai_analysis', 'source_id' => 7, 'source_field' => 'completed',
        ]);
        NotificationService::sendToOrganization($org, 'ai_analysis_completed', 'Failed', 'Message', [], [
            'source_type' => 'contract_ai_analysis', 'source_id' => 7, 'source_field' => 'failed',
        ]);

        $this->assertEquals(
            2,
            SuresignNotification::where('user_id', $user->id)
                ->where('type', 'ai_analysis_completed')
                ->where('source_type', 'contract_ai_analysis')->where('source_id', 7)->count()
        );
    }

    public function test_personal_notification_via_send_still_reaches_only_its_target(): void
    {
        $org    = $this->makeOrg('a');
        $target = $this->makeUser($org, 'Client');
        $other  = $this->makeUser($org, 'Client');

        NotificationService::send($target, 'personal_event', 'Title', 'Message');

        $this->assertDatabaseHas('suresign_notifications', ['user_id' => $target->id, 'type' => 'personal_event']);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $other->id, 'type' => 'personal_event']);
    }

    public function test_recipient_filter_can_narrow_or_widen_the_default_set(): void
    {
        $org   = $this->makeOrg('a');
        $admin = $this->makeUser($org, 'Admin');
        $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization(
            $org, 'platform_event', 'Title', 'Message', [], [],
            null, false,
            fn ($query) => $query->orWhereHas('roles', fn ($q) => $q->where('name', 'Admin')),
        );

        $this->assertDatabaseHas('suresign_notifications', ['user_id' => $admin->id, 'type' => 'platform_event']);
    }

    public function test_priority_category_and_action_url_persist_correctly(): void
    {
        $org  = $this->makeOrg('a');
        $user = $this->makeUser($org, 'Client');

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message', [], [
            'priority'   => SuresignNotification::PRIORITY_WARNING,
            'category'   => SuresignNotification::CATEGORY_VARIATION,
            'action_url' => '/app/projects/1/commercial?tab=commercial',
        ]);

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id'    => $user->id,
            'priority'   => SuresignNotification::PRIORITY_WARNING,
            'category'   => SuresignNotification::CATEGORY_VARIATION,
            'action_url' => '/app/projects/1/commercial?tab=commercial',
        ]);
    }
}
