<?php

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\DelayEvent;
use App\Models\Document;
use App\Models\EotRequest;
use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\Rfi;
use App\Models\SiteDiary;
use App\Models\TradePackage;
use App\Models\User;
use App\Models\Variation;
use Carbon\Carbon;
use Database\Seeders\Demo\Data\AldermereStory as Story;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Illuminate\Database\Seeder;

/**
 * Aldermere Distribution Centre — Phase 1: the "operationally difficult"
 * project in the approved demo portfolio (Phase 4). Deliberately carries
 * live, unresolved problems — an overdue payment, two RFIs past their
 * response window, an open escalating risk, a disputed variation, and an
 * EOT decision that's overdue — but every one of them is visibly logged,
 * notified, or escalated through the platform (most recent meeting minutes
 * explicitly discuss recovery measures), so the project reads as under
 * pressure and professionally managed, never chaotic or abandoned.
 *
 * Consolidated into one seeder class — see DemoColdfieldSeeder's class
 * comment for the rationale (same scale as Coldfield/Priory Court, well
 * under Riverside Wharf's module-family split).
 */
class DemoAldermereSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $megan = User::where('email', 'megan.fairweather@haldengroveconstruction.com')->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();
        $sarah = User::where('email', 'sarah.blythe@haldengroveconstruction.com')->firstOrFail();
        $james = User::where('email', 'james.ridley@haldengroveconstruction.com')->firstOrFail();

        $project = $this->seedProject($organization, $megan);
        $contract = $this->seedContract($project, $daniel);
        $this->seedTradePackages($project, $daniel);
        $this->seedProgramme($project, $contract);
        $this->seedRisks($project, $contract);
        $this->seedVariations($project, $contract, $daniel);
        $this->seedPaymentApplications($project, $contract, $daniel, $megan);
        $this->seedDelayEventAndEot($project, $contract, $sarah, $daniel);
        $this->seedRfis($project, $sarah);
        $this->seedMeetings($project, $megan);
        $this->seedSiteDiaries($project, $sarah);
        $this->seedDocuments($project, $contract, $james);

        $this->command?->info("✓ Demo project: {$project->name} (id {$project->id}) — under pressure, professionally managed.");
    }

    private function seedProject(Organization $organization, User $megan): Project
    {
        $project = Project::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => Story::PROJECT['code']],
            array_merge(Story::PROJECT, ['created_by' => $megan->id])
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
            DemoActivityLogger::log($project, $megan, 'project.created', "Project created: {$project->name}", Story::PROJECT['start_date']);
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
                    'award_date' => $data['award_date'],
                    'commencement_date' => $data['commencement_date'],
                    'completion_date' => $data['completion_date'],
                    'created_by' => $daniel->id,
                ]
            );

            if ($tradePackage->wasRecentlyCreated) {
                $tradePackage->createStandardFolders();
                if ($data['award_date']) {
                    DemoActivityLogger::log($project, $daniel, 'trade_package.awarded', "Trade package awarded: {$data['name']}", $data['award_date'], null, $tradePackage);
                }
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
                    'forecast_date' => $data['forecast_date'],
                    'actual_date' => $data['actual_date'],
                    'status' => $data['status'],
                    'responsible_party' => 'contractor',
                    'sort_order' => $index + 1,
                ]
            );
        }
    }

    private function seedRisks(Project $project, Contract $contract): void
    {
        foreach (Story::RISKS as $data) {
            $tradePackageId = null;
            $contractId = $contract->id;

            if (! empty($data['trade_package_code'])) {
                $tradePackageId = TradePackage::where('project_id', $project->id)
                    ->where('package_code', $data['trade_package_code'])
                    ->value('id');
                $contractId = null;
            }

            ContractRisk::updateOrCreate(
                ['organization_id' => $project->organization_id, 'title' => $data['title']],
                [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'contract_id' => $contractId,
                    'trade_package_id' => $tradePackageId,
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'severity' => $data['severity'],
                    'probability' => $data['probability'],
                    'category' => $data['category'],
                    'urgency' => $data['urgency'],
                    'mitigation' => $data['mitigation'],
                    'status' => $data['status'],
                    'is_ai_generated' => false,
                ]
            );
        }
    }

    private function seedVariations(Project $project, Contract $contract, User $daniel): void
    {
        foreach (Story::VARIATIONS as $data) {
            $variation = Variation::updateOrCreate(
                ['project_id' => $project->id, 'variation_number' => $data['variation_number']],
                [
                    'project_id' => $project->id,
                    'contract_id' => $contract->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $daniel->id,
                    'variation_number' => $data['variation_number'],
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'status' => $data['status'],
                    'quoted_amount' => $data['quoted_amount'],
                    'agreed_amount' => $data['agreed_amount'],
                    'variation_date' => $data['variation_date'],
                    'instructed_at' => $data['instruction_date'],
                    'instructed_by' => $daniel->id,
                ]
            );

            $variation->instruction_date = $data['instruction_date'];
            $variation->description = $data['description'];
            $variation->save();

            if ($variation->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $daniel, 'variation.quoted', "Variation {$data['variation_number']}: {$data['title']}", $data['instruction_date'], null, $variation);
            }
        }
    }

    private function seedPaymentApplications(Project $project, Contract $contract, User $daniel, User $megan): void
    {
        $previousNet = 0.0;

        foreach (Story::PAYMENT_APPLICATIONS as $data) {
            $applicationDate = Carbon::parse($data['application_date']);
            $retention = round($data['gross_valuation'] * ($contract->retention_percentage / 100), 2);
            $netValuation = round($data['gross_valuation'] - $retention, 2);
            $amountDue = round($netValuation - $previousNet, 2);
            $isPaid = $data['status'] === 'paid';

            $application = PaymentApplication::updateOrCreate(
                ['contract_id' => $contract->id, 'application_number' => $data['application_number']],
                [
                    'contract_id' => $contract->id,
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $daniel->id,
                    'application_number' => $data['application_number'],
                    'reference' => "PA-{$data['application_number']}",
                    'application_date' => $data['application_date'],
                    'valuation_period_start' => $data['valuation_period_start'],
                    'valuation_period_end' => $data['valuation_period_end'],
                    'due_date' => $applicationDate->copy()->addDays($contract->due_date_offset_days)->toDateString(),
                    'final_date_for_payment' => $applicationDate->copy()->addDays($contract->final_date_offset_days)->toDateString(),
                    'payment_notice_deadline' => $applicationDate->copy()->addDays($contract->payment_notice_offset_days)->toDateString(),
                    'pay_less_notice_deadline' => $applicationDate->copy()->addDays($contract->pay_less_notice_offset_days)->toDateString(),
                    'gross_valuation' => $data['gross_valuation'],
                    'less_retention' => $retention,
                    'less_previous_payments' => $previousNet,
                    'previous_certified_value' => $previousNet,
                    'amount_due' => $amountDue,
                    'certified_amount' => $data['certified_amount'],
                    'certified_date' => $applicationDate->copy()->addDays($contract->due_date_offset_days)->toDateString(),
                    'certified_at' => $applicationDate->copy()->addDays($contract->due_date_offset_days),
                    'certified_by' => $daniel->id,
                    'payment_date' => $isPaid ? $applicationDate->copy()->addDays($contract->final_date_offset_days)->toDateString() : null,
                    'paid_amount' => $isPaid ? $data['certified_amount'] : null,
                    'paid_at' => $isPaid ? $applicationDate->copy()->addDays($contract->final_date_offset_days) : null,
                    'status' => $data['status'],
                    'notes' => $data['notes'],
                    'submitted_at' => $applicationDate,
                    'submitted_by' => $daniel->id,
                ]
            );

            $previousNet = $data['certified_amount'];

            if ($application->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $daniel, 'payment_application.submitted', "Payment Application {$data['application_number']} submitted", $data['application_date'], $data['notes'], $application);
                if ($isPaid) {
                    DemoActivityLogger::log($project, $megan, 'payment_application.paid', "Payment Application {$data['application_number']} paid", $application->payment_date, null, $application);
                } else {
                    DemoActivityLogger::log($project, $daniel, 'payment_application.certified', "Payment Application {$data['application_number']} certified", $application->certified_date, 'Certified but payment now overdue — final date for payment has passed with no Pay Less Notice issued.', $application);
                }
            }
        }
    }

    private function seedDelayEventAndEot(Project $project, Contract $contract, User $sarah, User $daniel): void
    {
        $delayData = Story::DELAY_EVENT;

        $delayEvent = DelayEvent::updateOrCreate(
            ['project_id' => $project->id, 'title' => $delayData['title']],
            [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'created_by' => $sarah->id,
                'event_number' => 1,
                'title' => $delayData['title'],
                'description' => $delayData['description'],
                'cause_category' => $delayData['cause_category'],
                'date_occurred' => $delayData['date_occurred'],
                'date_notified' => $delayData['date_notified'],
                'notified_by' => $delayData['notified_by'],
                'estimated_delay_days' => $delayData['estimated_delay_days'],
                'status' => $delayData['status'],
            ]
        );

        if ($delayEvent->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $sarah, 'delay_event.logged', "Delay event logged: {$delayData['title']}", $delayData['date_notified'], null, $delayEvent);
        }

        $eotData = Story::EOT_REQUEST;

        $eotRequest = EotRequest::updateOrCreate(
            ['project_id' => $project->id, 'eot_number' => $eotData['eot_number']],
            [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'contract_id' => $contract->id,
                'delay_event_id' => $delayEvent->id,
                'created_by' => $daniel->id,
                'eot_number' => $eotData['eot_number'],
                'title' => $eotData['title'],
                'notice_date' => $eotData['notice_date'],
                'grounds' => $eotData['grounds'],
                'days_claimed' => $eotData['days_claimed'],
                'status' => $eotData['status'],
            ]
        );

        if ($eotRequest->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $daniel, 'eot_request.submitted', "EOT request submitted: {$eotData['title']}", $eotData['notice_date'], 'Decision still pending — now significantly overdue relative to a typical review turnaround.', $eotRequest);
        }
    }

    private function seedRfis(Project $project, User $sarah): void
    {
        foreach (Story::RFIS as $data) {
            $rfi = Rfi::updateOrCreate(
                ['project_id' => $project->id, 'rfi_number' => $data['rfi_number']],
                array_merge($data, [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $sarah->id,
                ])
            );

            if ($rfi->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $sarah, 'rfi.raised', "RFI {$data['rfi_number']} raised: {$data['subject']}", $data['raised_date'], null, $rfi);
            }
        }
    }

    private function seedMeetings(Project $project, User $megan): void
    {
        foreach (Story::MEETINGS as $data) {
            $isRecoveryMeeting = $data['meeting_number'] === 6;

            MeetingMinutes::updateOrCreate(
                ['project_id' => $project->id, 'meeting_number' => $data['meeting_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $megan->id,
                    'meeting_number' => $data['meeting_number'],
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'meeting_date' => $data['meeting_date'],
                    'location' => 'Aldermere Distribution Park site office',
                    'minutes' => $isRecoveryMeeting ? Story::RECOVERY_MEETING_MINUTES : null,
                    'status' => 'issued',
                ]
            );
        }
    }

    private function seedSiteDiaries(Project $project, User $sarah): void
    {
        foreach (Story::SITE_DIARIES as $data) {
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

    private function seedDocuments(Project $project, Contract $contract, User $james): void
    {
        $documents = [
            ['title' => 'Aldermere Distribution Centre — Phase 1: Main Contract (Executed)', 'type' => 'contract', 'category' => 'Contracts', 'reference_number' => 'HG-ADC-P1-001', 'status' => 'approved', 'documentable' => $contract],
            ['title' => 'Recovery Programme — Steel Portal Frame Package', 'type' => 'report', 'category' => 'Programme', 'reference_number' => 'RECOVERY-ADC-P1-01', 'status' => 'issued', 'documentable' => null],
            ['title' => 'Formal Notice — Payment Application 7 Overdue', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'NOTICE-ADC-P1-PA7', 'status' => 'issued', 'documentable' => null],
        ];

        foreach ($documents as $data) {
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
                    'documentable_type' => $data['documentable'] ? get_class($data['documentable']) : null,
                    'documentable_id' => $data['documentable']?->id,
                    'ai_generated' => false,
                ]
            );
        }
    }
}
