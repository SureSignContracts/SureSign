<?php

namespace Database\Seeders\Demo;

use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\Project;
use App\Models\QaReport;
use App\Models\Rfi;
use App\Models\SiteDiary;
use App\Models\SiteInstruction;
use App\Models\Snag;
use App\Models\User;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates Riverside Wharf's day-to-day site management records: RFIs,
 * site instructions, weekly site diaries, monthly progress meetings,
 * snags, and QA reports.
 */
class DemoSiteManagementSeeder extends Seeder
{
    /** Meeting agenda/minutes content, keyed by meeting_number — kept here
     * rather than in RiversideWharfStory since it's prose tied 1:1 to the
     * meeting titles already defined there. */
    private const MEETING_CONTENT = [
        1 => ['agenda' => "1. Introductions and site set-up\n2. Health & safety induction arrangements\n3. Mobilisation programme\n4. First payment application timetable", 'minutes' => 'Site possession confirmed 20 October. Welfare facilities and hoarding installed. Pennine Groundworks Ltd. site induction scheduled for first week of November.', 'action_items' => ['Confirm groundworks site induction date', 'Issue mobilisation programme to Employer\'s Agent']],
        2 => ['agenda' => "1. Groundworks progress\n2. Weather impact on piling\n3. Health & safety", 'minutes' => 'Groundworks progressing but affected by sustained wet weather since 1 December — standing water preventing safe piling on several days. Delay event to be logged and notified.', 'action_items' => ['Log delay event and notify EOT within contractual timescale', 'Monitor rainfall records for supporting evidence']],
        3 => ['agenda' => "1. Substructure completion\n2. Concrete frame mobilisation\n3. EOT status", 'minutes' => 'Substructure works complete. Concrete frame subcontractor mobilising for 19 January start. EOT request submitted 15 December, awaiting Employer\'s Agent decision.', 'action_items' => ['Chase Employer\'s Agent for EOT decision', 'Confirm frame pour sequence with structural engineer']],
        4 => ['agenda' => "1. Frame progress\n2. Podium slab reinforcement query (RFI 1)\n3. Steel package procurement", 'minutes' => 'Frame progressing to programme following RFI 1 response on podium construction joints. Structural steel package tender returns under review — fabrication lead time risk identified.', 'action_items' => ['Award structural steel package early to absorb fabrication lead time', 'Close out RFI 1 in the register']],
        5 => ['agenda' => "1. Frame and steel coordination\n2. Variation 1 (podium slab reinforcement)\n3. Programme review", 'minutes' => 'Variation 1 agreed following the supplementary ground investigation findings — included in Payment Application 3. Steel package awarded ahead of programme.', 'action_items' => ['Confirm Variation 1 inclusion in next payment application', 'Steel fabrication drawings to be fast-tracked']],
        6 => ['agenda' => "1. Frame completion\n2. Structural steel mobilisation\n3. Acoustic insulation query", 'minutes' => 'Concrete frame complete, ahead of programme. Structural steel mobilised to site. Building Control raised a query on party wall acoustic insulation — Variation 2 being priced.', 'action_items' => ['Price and agree Variation 2', 'Confirm structural steel erection sequence']],
        7 => ['agenda' => "1. Structural steel completion\n2. Envelope trades mobilisation\n3. Variation 2 agreement", 'minutes' => 'Structural steel complete. Variation 2 (acoustic insulation) agreed and included in Payment Application 5. Brickwork and roofing subcontractors mobilising.', 'action_items' => ['Confirm brickwork and roofing mobilisation dates', 'Building control sign-off on steel connections']],
        8 => ['agenda' => "1. Brickwork and roofing progress\n2. Drainage fall levels query (RFI 3)\n3. Facade package award", 'minutes' => 'Brickwork and roofing progressing well. RFI 3 (podium deck drainage falls) responded — falls to be regraded within the roofing package allowance. Facade & Cladding package awarded.', 'action_items' => ['Confirm facade mobilisation date', 'Roofing subcontractor to action RFI 3 response']],
        9 => ['agenda' => "1. Facade mobilisation\n2. Payment Application 6 and Pay Less Notice\n3. Steel connection clash query (RFI 4)\n4. Variation 3 (balustrade specification)", 'minutes' => 'Facade & Cladding subcontractor mobilised to site. Employer\'s Agent has issued a Pay Less Notice against Payment Application 6, disputing the facade mobilisation valuation — site inspection found fixing works had not yet commenced. Contractor to provide further evidence of site set-up costs incurred. RFI 4 (steel/services clash at grid E/12) remains open. Variation 3 instructed to proceed pending formal agreement.', 'action_items' => ['Provide supporting evidence for facade mobilisation valuation to Employer\'s Agent', 'Resolve RFI 4 steel connection clash', 'Agree Variation 3 balustrade valuation']],
    ];

    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $project = Project::where('organization_id', $organization->id)
            ->where('code', RiversideWharfStory::PROJECT['code'])
            ->firstOrFail();
        $sarah = User::where('email', 'sarah.blythe@haldengroveconstruction.com')->firstOrFail();
        $megan = User::where('email', 'megan.fairweather@haldengroveconstruction.com')->firstOrFail();

        $this->seedRfis($project, $sarah);
        $this->seedSiteInstructions($project, $megan);
        $this->seedSiteDiaries($project, $sarah);
        $this->seedMeetings($project, $megan);
        $this->seedSnags($project, $sarah);
        $this->seedQaReports($project, $sarah);

        $this->command?->info('✓ Demo site management: RFIs, site instructions, diaries, meetings, snags, QA reports ready.');
    }

    private function seedRfis(Project $project, User $sarah): void
    {
        foreach (RiversideWharfStory::RFIS as $data) {
            $rfi = Rfi::updateOrCreate(
                ['project_id' => $project->id, 'rfi_number' => $data['rfi_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $sarah->id,
                    'rfi_number' => $data['rfi_number'],
                    'subject' => $data['subject'],
                    'description' => $data['description'],
                    'priority' => $data['priority'],
                    'status' => $data['status'],
                    'raised_date' => $data['raised_date'],
                    'response_due_date' => $data['response_due_date'],
                    'responded_at' => $data['responded_at'],
                    'response' => $data['response'],
                ]
            );

            if ($rfi->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $sarah, 'rfi.raised', "RFI {$data['rfi_number']} raised: {$data['subject']}", $data['raised_date'], null, $rfi);
                if ($data['responded_at']) {
                    DemoActivityLogger::log($project, $sarah, 'rfi.responded', "RFI {$data['rfi_number']} responded", $data['responded_at'], null, $rfi);
                }
            }
        }
    }

    private function seedSiteInstructions(Project $project, User $megan): void
    {
        foreach (RiversideWharfStory::SITE_INSTRUCTIONS as $data) {
            $instruction = SiteInstruction::updateOrCreate(
                ['project_id' => $project->id, 'instruction_number' => $data['instruction_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $megan->id,
                    'instruction_number' => $data['instruction_number'],
                    'title' => $data['title'],
                    'issued_date' => $data['issued_date'],
                    'description' => $data['description'],
                    'status' => $data['status'],
                    'issued_to' => $data['issued_to'],
                ]
            );

            if ($instruction->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $megan, 'site_instruction.issued', "Site Instruction {$data['instruction_number']}: {$data['title']}", $data['issued_date'], null, $instruction);
            }
        }
    }

    private function seedSiteDiaries(Project $project, User $sarah): void
    {
        foreach (RiversideWharfStory::SITE_DIARIES as $data) {
            SiteDiary::updateOrCreate(
                ['project_id' => $project->id, 'diary_date' => $data['diary_date']],
                array_merge($data, [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $sarah->id,
                    'status' => 'approved',
                ])
            );
        }
    }

    private function seedMeetings(Project $project, User $megan): void
    {
        foreach (RiversideWharfStory::MEETINGS as $data) {
            $content = self::MEETING_CONTENT[$data['meeting_number']];

            $attributes = [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'created_by' => $megan->id,
                'meeting_number' => $data['meeting_number'],
                'title' => $data['title'],
                'type' => 'progress',
                'location' => 'Riverside Wharf site office',
                'agenda' => $content['agenda'],
                'minutes' => $content['minutes'],
                'action_items' => $content['action_items'],
                'status' => 'issued',
            ];

            if ($data['timed']) {
                $attributes['starts_at'] = $data['starts_at'];
                $attributes['ends_at'] = $data['ends_at'];
                $attributes['scheduled_timezone'] = 'Europe/London';
            } else {
                $attributes['meeting_date'] = $data['meeting_date'];
            }

            $meeting = MeetingMinutes::updateOrCreate(
                ['project_id' => $project->id, 'meeting_number' => $data['meeting_number']],
                $attributes
            );

            if ($meeting->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $megan, 'meeting.held', $data['title'], $data['meeting_date'], null, $meeting);
            }
        }
    }

    private function seedSnags(Project $project, User $sarah): void
    {
        foreach (RiversideWharfStory::SNAGS as $index => $data) {
            Snag::updateOrCreate(
                ['project_id' => $project->id, 'title' => $data['title']],
                array_merge($data, [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'created_by' => $sarah->id,
                    'snag_number' => $index + 1,
                    'closed_at' => $data['status'] === 'closed' ? $data['due_date'] : null,
                ])
            );
        }
    }

    private function seedQaReports(Project $project, User $sarah): void
    {
        foreach (RiversideWharfStory::QA_REPORTS as $index => $data) {
            QaReport::updateOrCreate(
                ['project_id' => $project->id, 'report_number' => $data['report_number']],
                array_merge($data, [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'created_by' => $sarah->id,
                    'inspected_by' => $sarah->id,
                    'follow_up_required' => $data['status'] === 'failed',
                ])
            );
        }
    }
}
