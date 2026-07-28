<?php

namespace Database\Seeders\Demo;

use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\ContractProgrammeMilestone;
use App\Models\Document;
use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\TradePackage;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\ElmsworthStory as Story;
use Illuminate\Database\Seeder;

/**
 * Elmsworth Care Home Extension: the "pre-construction" project in the
 * approved demo portfolio (Phase 4). Contract executed, site commencement
 * three weeks away, trade packages still in procurement, programme holds
 * only planned dates, and the Contract AI Analysis is deliberately left
 * mid-flight ('processing', unconfirmed) — see the Story class comment.
 * No payment applications, risks, RFIs, or site diaries exist because
 * nothing is genuinely due yet: this is a deliberate omission, not a gap.
 *
 * Consolidated into one seeder class — see DemoColdfieldSeeder's class
 * comment for the rationale.
 */
class DemoElmsworthSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $priya = User::where('email', 'priya.chandra@haldengroveconstruction.com')->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();
        $james = User::where('email', 'james.ridley@haldengroveconstruction.com')->firstOrFail();

        $project = $this->seedProject($organization, $priya);
        $contract = $this->seedContract($project, $daniel);
        $this->seedTradePackages($project, $daniel);
        $this->seedProgramme($project, $contract);
        $this->seedMeetings($project, $priya);
        $this->seedAppointments($project, $organization);
        $this->seedDocuments($project, $contract, $james);

        $this->command?->info("✓ Demo project: {$project->name} (id {$project->id}) — pre-construction.");
    }

    private function seedProject(Organization $organization, User $priya): Project
    {
        $project = Project::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => Story::PROJECT['code']],
            array_merge(Story::PROJECT, ['created_by' => $priya->id])
        );

        foreach (Story::PROJECT_TEAM as $member) {
            $user = User::where('email', $member['email'])->first();
            if ($user) {
                $project->users()->syncWithoutDetaching([$user->id => ['role' => $member['role']]]);
            }
        }

        foreach (Story::PROJECT_CONTACTS as $contact) {
            ProjectContact::updateOrCreate(
                ['project_id' => $project->id, 'name' => $contact['name'], 'company' => $contact['company']],
                array_merge(['project_id' => $project->id], $contact)
            );
        }

        if ($project->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $priya, 'project.created', "Project created: {$project->name}", '2026-06-16');
        }

        return $project;
    }

    private function seedContract(Project $project, User $daniel): Contract
    {
        $contract = Contract::updateOrCreate(
            ['project_id' => $project->id, 'reference_number' => Story::CONTRACT['reference_number']],
            array_merge(Story::CONTRACT, [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'created_by' => $daniel->id,
            ])
        );

        if ($contract->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $daniel, 'contract.executed', 'Main contract executed', Story::CONTRACT['execution_date'], null, $contract);
        }

        $analysis = ContractAiAnalysis::updateOrCreate(
            ['contract_id' => $contract->id],
            array_merge(Story::CONTRACT_AI_ANALYSIS, [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'created_by' => $daniel->id,
            ])
        );

        if ($analysis->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $daniel, 'contract.ai_analysis_started', 'Contract AI analysis started', '2026-07-20', 'Analysis still processing — not yet confirmed.', $contract);
        }

        return $contract;
    }

    private function seedTradePackages(Project $project, User $daniel): void
    {
        foreach (Story::TRADE_PACKAGES as $data) {
            $tradePackage = TradePackage::updateOrCreate(
                ['project_id' => $project->id, 'package_code' => $data['code']],
                [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'name' => $data['name'],
                    'slug' => TradePackage::makeSlug($data['name'], $project->id),
                    'package_code' => $data['code'],
                    'contractor_name' => $data['contractor_name'],
                    'status' => $data['status'],
                    'contract_value' => $data['contract_value'],
                    'retention_percentage' => Story::CONTRACT['retention_percentage'],
                    'payment_frequency' => 'monthly',
                    'created_by' => $daniel->id,
                ]
            );

            if ($tradePackage->wasRecentlyCreated) {
                $tradePackage->createStandardFolders();
            }
        }
    }

    private function seedProgramme(Project $project, Contract $contract): void
    {
        foreach (Story::PROGRAMME_MILESTONES as $index => $data) {
            ContractProgrammeMilestone::updateOrCreate(
                ['contract_id' => $contract->id, 'name' => $data['name']],
                [
                    'contract_id' => $contract->id,
                    'project_id' => $project->id,
                    'name' => $data['name'],
                    'milestone_type' => $data['milestone_type'],
                    'planned_date' => $data['planned_date'],
                    'status' => $data['status'],
                    'responsible_party' => 'contractor',
                    'sort_order' => $index + 1,
                ]
            );
        }
    }

    private function seedMeetings(Project $project, User $priya): void
    {
        foreach (Story::MEETINGS as $data) {
            $meeting = MeetingMinutes::updateOrCreate(
                ['project_id' => $project->id, 'meeting_number' => $data['meeting_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $priya->id,
                    'meeting_number' => $data['meeting_number'],
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'meeting_date' => $data['meeting_date'],
                    'location' => 'Elmsworth Care Home — meeting room',
                    'status' => 'issued',
                ]
            );

            if ($meeting->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $priya, 'meeting.held', $data['title'], $data['meeting_date']);
            }
        }
    }

    private function seedAppointments(Project $project, Organization $organization): void
    {
        foreach (Story::APPOINTMENTS as $data) {
            $type = AppointmentType::where('slug', $data['type_slug'])->first();
            $attendee = User::where('email', $data['attendee_email'])->first();

            if (! $type || ! $attendee) {
                continue;
            }

            Appointment::updateOrCreate(
                ['reference' => $data['reference']],
                [
                    'reference' => $data['reference'],
                    'appointment_type_id' => $type->id,
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
                    'completed_at' => $data['status'] === 'completed' ? $data['ends_at'] : null,
                ]
            );
        }
    }

    private function seedDocuments(Project $project, Contract $contract, User $james): void
    {
        foreach (Story::DOCUMENTS as $data) {
            $documentable = $data['documentable'] === 'contract' ? $contract : null;

            Document::updateOrCreate(
                ['project_id' => $project->id, 'reference_number' => $data['reference_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $james->id,
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'category' => $data['category'],
                    'reference_number' => $data['reference_number'],
                    'status' => $data['status'],
                    'file_name' => str($data['reference_number'])->lower() . '.pdf',
                    'file_path' => "projects/{$project->id}/documents/" . str($data['reference_number'])->lower() . '.pdf',
                    'mime_type' => 'application/pdf',
                    'documentable_type' => $documentable ? get_class($documentable) : null,
                    'documentable_id' => $documentable?->id,
                    'ai_generated' => false,
                ]
            );
        }
    }
}
