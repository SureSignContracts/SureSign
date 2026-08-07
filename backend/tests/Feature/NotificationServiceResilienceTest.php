<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Error Messaging & Recovery UX, Batch 5 — confirmed structural finding:
 * NotificationService::sendToOrganization() is called, unguarded, by many
 * controllers (ProgrammeMilestoneController, DelayEventController,
 * EotRequestController, LossAndExpenseClaimController,
 * MeetingMinutesController, QaReportController, RfiController,
 * SiteDiaryController) immediately after their own primary record already
 * committed. Before this fix, any exception here would propagate all the
 * way up and turn an already-successful save into an apparent total
 * failure (500) for the customer — asymmetric with its usual sibling call,
 * EmailNotificationService::send(), which already never throws.
 */
class NotificationServiceResilienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_to_organization_never_throws_when_a_real_exception_occurs_downstream(): void
    {
        Log::spy();

        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        User::factory()->create(['organization_id' => $org->id, 'is_active' => true])
            ->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        // A real, genuinely-thrown exception from actual user-supplied code
        // (the documented $recipientFilter callable parameter) — not a
        // framework mock — proving the try/catch actually catches a live
        // \Throwable, not merely a hypothetical one.
        NotificationService::sendToOrganization(
            $org,
            'test_event',
            'Title',
            'Message',
            [],
            [],
            null,
            false,
            function () {
                throw new \RuntimeException('Simulated downstream failure');
            }
        );

        // No exception escaped (the test reaching this line at all is the
        // primary assertion) — and the failure was still logged, not
        // silently dropped.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains($message, 'test_event') && str_contains($message, 'Simulated downstream failure'))
            ->once();

        $this->addToAssertionCount(1);
    }

    public function test_send_to_organization_still_delivers_notifications_on_the_normal_path(): void
    {
        $org = Organization::create(['name' => 'Org', 'slug' => 'org-' . uniqid()]);
        $client = User::factory()->create(['organization_id' => $org->id, 'is_active' => true]);
        $client->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Client', 'guard_name' => 'web']));

        NotificationService::sendToOrganization($org, 'test_event', 'Title', 'Message');

        $this->assertDatabaseHas('suresign_notifications', [
            'user_id' => $client->id,
            'type'    => 'test_event',
            'title'   => 'Title',
        ]);
    }
}
