<?php

namespace Tests\Feature;

use App\Models\AppointmentAvailability;
use App\Models\ConsultancyService;
use App\Models\Organization;
use App\Models\SuresignNotification;
use App\Models\SuresignSetting;
use App\Models\User;
use App\Services\Consultancy\ConsultancyCatalogueService;
use App\Support\Consultancy\ConsultancyNewBookingNotificationRecipients;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The operator-facing "new booking" in-app notification — confirmed
 * absent entirely before this (an operator only ever learned about a new
 * booking by manually checking the Consultancy Queue). Configurable via
 * ConsultancyNewBookingNotificationRecipients, per explicit instruction —
 * not hardcoded to one recipient policy.
 */
class ConsultancyNewBookingNotificationTest extends TestCase
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

    private function makeService(): ConsultancyService
    {
        static $n = 0;
        $n++;

        return app(ConsultancyCatalogueService::class)->create([
            'code'                             => "notif-service-{$n}",
            'display_name'                     => "Notification Service {$n}",
            'enabled'                          => true,
            'publicly_bookable'                => true,
            'available_to_existing_customers'  => true,
            'price_minor_units'                => 0,
            'currency'                         => 'GBP',
            'duration_minutes'                 => 30,
            'requires_confirmation'            => false,
            'assignment_mode'                  => 'manual',
        ]);
    }

    private function configureConsultant(User $staff): void
    {
        SuresignSetting::instance()->update(['consultancy_consultant_user_id' => $staff->id]);

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            AppointmentAvailability::create([
                'user_id' => $staff->id, 'context' => \App\Support\Appointments\AvailabilityContext::CONSULTANCY,
                'weekday' => $weekday,
                'start_time' => '00:00', 'end_time' => '23:59', 'is_active' => true,
            ]);
        }
    }

    private function nextDateForWeekday(int $weekday): string
    {
        $date = now()->addDays(3);
        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }
        return $date->toDateString();
    }

    private function bookAsClient(User $client, ConsultancyService $service): array
    {
        $date = $this->nextDateForWeekday(0);

        return $this->actingAs($client)->postJson('/api/consultations', [
            'consultancy_service_code' => $service->code,
            'attendee_name'      => 'Jane Client',
            'attendee_email'     => 'jane@client.example.com',
            'attendee_timezone'  => 'Europe/London',
            'date'               => $date,
            'start_time'         => '10:00',
            'timezone'           => 'Europe/London',
            'title'              => 'A payment notice question',
            'description'        => 'We have a dispute over a pay less notice.',
        ])->assertStatus(201)->json();
    }

    public function test_default_recipient_mode_is_all_admins(): void
    {
        $this->assertSame(ConsultancyNewBookingNotificationRecipients::ALL_ADMINS, ConsultancyNewBookingNotificationRecipients::current());
    }

    public function test_booking_notifies_every_admin_and_super_admin_by_default(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $otherAdmin] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $this->configureConsultant($otherAdmin);

        $booking = $this->bookAsClient($client, $service);

        foreach ([$superAdmin, $otherAdmin] as $recipient) {
            $this->assertDatabaseHas('suresign_notifications', [
                'user_id' => $recipient->id,
                'type'    => 'consultation_booked',
            ]);
        }

        // The Client (customer) never gets an in-app operator notification.
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $client->id, 'type' => 'consultation_booked']);

        $notification = SuresignNotification::where('user_id', $otherAdmin->id)->where('type', 'consultation_booked')->firstOrFail();
        $this->assertSame('consultancy', $notification->category);
        $this->assertSame("/admin/consultancy/queue/{$booking['id']}", $notification->action_url);
    }

    public function test_assigned_consultant_only_mode_notifies_just_the_assigned_staff(): void
    {
        SuresignSetting::instance()->update(['consultancy_new_booking_notification_recipients' => ConsultancyNewBookingNotificationRecipients::ASSIGNED_CONSULTANT]);

        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $assignedAdmin] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $this->configureConsultant($assignedAdmin);

        $this->bookAsClient($client, $service);

        $this->assertDatabaseHas('suresign_notifications', ['user_id' => $assignedAdmin->id, 'type' => 'consultation_booked']);
        $this->assertDatabaseMissing('suresign_notifications', ['user_id' => $superAdmin->id, 'type' => 'consultation_booked']);
    }

    public function test_notification_settings_get_and_put(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');
        [, $admin] = $this->makeOrgAndUser('Admin');

        $this->actingAs($admin)->getJson('/api/admin/consultancy/settings/notifications')
            ->assertStatus(200)
            ->assertJsonPath('recipients', ConsultancyNewBookingNotificationRecipients::ALL_ADMINS);

        // Admin (not Super Admin) may not change it.
        $this->actingAs($admin)->putJson('/api/admin/consultancy/settings/notifications', ['recipients' => 'assigned_consultant'])
            ->assertStatus(403);

        $this->actingAs($superAdmin)->putJson('/api/admin/consultancy/settings/notifications', ['recipients' => 'assigned_consultant'])
            ->assertStatus(200)
            ->assertJsonPath('recipients', 'assigned_consultant');

        $this->assertSame('assigned_consultant', ConsultancyNewBookingNotificationRecipients::current());
    }

    public function test_notification_settings_rejects_invalid_value(): void
    {
        [, $superAdmin] = $this->makeOrgAndUser('Super Admin');

        $this->actingAs($superAdmin)->putJson('/api/admin/consultancy/settings/notifications', ['recipients' => 'literally_everyone'])
            ->assertStatus(422);
    }

    public function test_consultancy_counts_endpoint_reflects_awaiting_consultant(): void
    {
        [, $admin] = $this->makeOrgAndUser('Admin');
        [, $client] = $this->makeOrgAndUser('Client');
        $service = $this->makeService();
        $this->configureConsultant($admin);

        $this->bookAsClient($client, $service);

        $response = $this->actingAs($admin)->getJson('/api/admin/consultancy/counts');

        $response->assertStatus(200)->assertJsonPath('counts.awaiting_consultant', 1);
    }
}
