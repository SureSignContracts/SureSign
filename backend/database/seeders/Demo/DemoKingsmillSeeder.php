<?php

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\Document;
use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\KingsmillStory as Story;
use Illuminate\Database\Seeder;

/**
 * Kingsmill Logistics Hub: the "recently awarded" project in the approved
 * demo portfolio (Phase 4) — the very start of a project's life in
 * SureSign. Deliberately minimal: project, a draft (unsigned) contract, a
 * single award/negotiation meeting, two documents, and a one-person
 * project team. No trade packages, programme, payment applications,
 * risks, RFIs, or appointments — none of that is genuine yet, and
 * inventing any of it purely to avoid an empty module would be exactly
 * the "filler data" the blueprint warns against.
 *
 * Consolidated into one seeder class — see DemoColdfieldSeeder's class
 * comment for the rationale (here taken to its logical minimum).
 */
class DemoKingsmillSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $priya = User::where('email', 'priya.chandra@haldengroveconstruction.com')->firstOrFail();
        $james = User::where('email', 'james.ridley@haldengroveconstruction.com')->firstOrFail();

        $project = $this->seedProject($organization, $priya);
        $contract = $this->seedContract($project, $priya);
        $this->seedMeetings($project, $priya);
        $this->seedDocuments($project, $contract, $james);

        $this->command?->info("✓ Demo project: {$project->name} (id {$project->id}) — recently awarded, contract unsigned.");
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
            DemoActivityLogger::log($project, $priya, 'project.created', "Project created: {$project->name}", '2026-06-24', 'Tender awarded 2026-06-24; contract drafted and awaiting signature.');
        }

        return $project;
    }

    private function seedContract(Project $project, User $priya): Contract
    {
        $contract = Contract::updateOrCreate(
            ['project_id' => $project->id, 'reference_number' => Story::CONTRACT['reference_number']],
            array_merge(Story::CONTRACT, [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'created_by' => $priya->id,
            ])
        );

        if ($contract->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $priya, 'contract.drafted', 'Contract drafted, awaiting signature', '2026-07-10', null, $contract);
        }

        return $contract;
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
                    'location' => 'Halden Grove head office, Birmingham',
                    'status' => 'issued',
                ]
            );

            if ($meeting->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $priya, 'meeting.held', $data['title'], $data['meeting_date']);
            }
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
