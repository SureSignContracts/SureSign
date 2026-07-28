<?php

namespace Database\Seeders\Demo;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates a small, deliberately varied set of Appointments for Halden
 * Grove — covering completed, confirmed, cancelled, and rescheduled states
 * (per the approved blueprint's Appointments module coverage). Appointment
 * types are the generic, project-agnostic set seeded by the platform's own
 * \Database\Seeders\AppointmentTypeSeeder (called from DemoEnvironmentSeeder
 * alongside this one) — appointments are Halden Grove's bookings against
 * SureSign, not a construction-site "meeting" (see meeting_minutes for
 * that), so `assigned_user_id` (a SureSign staff member) is intentionally
 * left null here — no SureSign staff user exists in the isolated demo
 * database, and an appointment awaiting internal assignment is itself a
 * realistic state.
 */
class DemoAppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $project = Project::where('organization_id', $organization->id)
            ->where('code', RiversideWharfStory::PROJECT['code'])
            ->firstOrFail();

        $priya = User::where('email', 'priya.chandra@haldengroveconstruction.com')->firstOrFail();
        $james = User::where('email', 'james.ridley@haldengroveconstruction.com')->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();
        $megan = User::where('email', 'megan.fairweather@haldengroveconstruction.com')->firstOrFail();

        $onboarding = AppointmentType::where('slug', 'customer-onboarding')->first();
        $training = AppointmentType::where('slug', 'training-session')->first();
        $accountReview = AppointmentType::where('slug', 'account-review')->first();
        $support = AppointmentType::where('slug', 'support-consultation')->first();

        if (! $onboarding || ! $training || ! $accountReview || ! $support) {
            $this->command?->warn('  Skipping demo appointments — appointment types not yet seeded.');

            return;
        }

        $appointments = [
            [
                'reference' => 'APT-DEMO-001',
                'appointment_type_id' => $onboarding->id,
                'attendee' => $priya,
                'starts_at' => '2025-10-13 10:00:00',
                'ends_at' => '2025-10-13 11:00:00',
                'status' => 'completed',
                'completion_notes' => 'Organisation onboarded — branding, users, and first project (Riverside Wharf) walked through.',
            ],
            [
                'reference' => 'APT-DEMO-002',
                'appointment_type_id' => $training->id,
                'attendee' => $james,
                'starts_at' => '2025-11-10 14:00:00',
                'ends_at' => '2025-11-10 15:00:00',
                'status' => 'completed',
                'completion_notes' => 'Document management and folder sync training for the document control team.',
            ],
            [
                'reference' => 'APT-DEMO-003',
                'appointment_type_id' => $accountReview->id,
                'attendee' => $daniel,
                'starts_at' => '2026-04-21 11:00:00',
                'ends_at' => '2026-04-21 11:30:00',
                'status' => 'completed',
                'completion_notes' => 'Quarterly account review — commercial module usage and upcoming feature interest discussed.',
            ],
            [
                'reference' => 'APT-DEMO-004',
                'appointment_type_id' => $accountReview->id,
                'attendee' => $megan,
                'starts_at' => '2026-06-02 11:00:00',
                'ends_at' => '2026-06-02 11:30:00',
                'status' => 'cancelled',
                'cancellation_reason' => 'Rescheduling required due to a clash with a Riverside Wharf site visit — not yet rebooked.',
                'cancelled_at' => '2026-05-29 09:15:00',
            ],
            [
                'reference' => 'APT-DEMO-005',
                'appointment_type_id' => $support->id,
                'attendee' => $daniel,
                'starts_at' => '2026-08-04 09:30:00',
                'ends_at' => '2026-08-04 10:00:00',
                'status' => 'confirmed',
                'schedule_version' => 1,
                'reschedule_reason' => 'Original slot (2026-07-28) moved at Daniel\'s request due to the Payment Application 6 dispute taking priority that week.',
            ],
            [
                'reference' => 'APT-DEMO-006',
                'appointment_type_id' => $accountReview->id,
                'attendee' => $priya,
                'starts_at' => '2026-08-19 15:00:00',
                'ends_at' => '2026-08-19 15:30:00',
                'status' => 'confirmed',
            ],
        ];

        foreach ($appointments as $data) {
            /** @var User $attendee */
            $attendee = $data['attendee'];

            Appointment::updateOrCreate(
                ['reference' => $data['reference']],
                [
                    'reference' => $data['reference'],
                    'appointment_type_id' => $data['appointment_type_id'],
                    'organization_id' => $organization->id,
                    'linked_user_id' => $attendee->id,
                    'company_name' => DemoCompanyProfile::ORGANIZATION['name'],
                    'project_id' => $project->id,
                    'attendee_name' => $attendee->name,
                    'attendee_email' => $attendee->email,
                    'attendee_job_title' => $attendee->job_title,
                    'attendee_company' => DemoCompanyProfile::ORGANIZATION['name'],
                    'attendee_timezone' => 'Europe/London',
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                    'booking_timezone' => 'Europe/London',
                    'status' => $data['status'],
                    'booking_source' => 'admin_created',
                    'meeting_method' => 'teams',
                    'completion_notes' => $data['completion_notes'] ?? null,
                    'cancellation_reason' => $data['cancellation_reason'] ?? null,
                    'cancelled_at' => $data['cancelled_at'] ?? null,
                    'completed_at' => $data['status'] === 'completed' ? $data['ends_at'] : null,
                    'reschedule_reason' => $data['reschedule_reason'] ?? null,
                    'schedule_version' => $data['schedule_version'] ?? 0,
                ]
            );
        }

        $this->command?->info('✓ Demo appointments: ' . count($appointments) . ' appointments ready (completed, confirmed, cancelled, rescheduled).');
    }
}
