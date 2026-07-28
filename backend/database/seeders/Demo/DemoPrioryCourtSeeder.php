<?php

namespace Database\Seeders\Demo;

use App\Models\AdjudicationCase;
use App\Models\AdjudicationStep;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Closeout;
use App\Models\CloseoutItem;
use App\Models\Contract;
use App\Models\ContractProgrammeMilestone;
use App\Models\ContractRisk;
use App\Models\Document;
use App\Models\FinalAccount;
use App\Models\FinalAccountItem;
use App\Models\MeetingMinutes;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\PaymentApplicationVariation;
use App\Models\Project;
use App\Models\ProjectContact;
use App\Models\QaReport;
use App\Models\RetentionRelease;
use App\Models\Rfi;
use App\Models\TradePackage;
use App\Models\User;
use App\Models\Variation;
use Carbon\Carbon;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\PrioryCourtStory as Story;
use Illuminate\Database\Seeder;

/**
 * Priory Court Apartments: the "completed" project in the approved demo
 * portfolio (Phase 3) — the reference example for historical reporting.
 * Everything here is deliberately resolved: Practical Completion, the
 * Defects Liability Period, the agreed Final Account, both retention
 * moieties released, and a closed adjudication case that arose (and was
 * resolved) during construction, well before completion.
 *
 * Consolidated into one seeder class — see DemoColdfieldSeeder's class
 * comment for why (same rationale, same project scale).
 */
class DemoPrioryCourtSeeder extends Seeder
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
        $this->seedRisks($project, $contract);
        $variations = $this->seedVariations($project, $contract, $daniel);
        $this->seedPaymentApplications($project, $contract, $daniel, $priya, $variations);
        $this->seedFinalAccountAndRetention($project, $contract, $priya, $daniel);
        $this->seedAdjudicationCase($project, $contract, $daniel);
        $this->seedRfis($project, $daniel);
        $this->seedMeetings($project, $priya);
        $this->seedQaReports($project, $daniel);
        $this->seedCloseout($project, $priya);
        $this->seedAppointments($project, $organization);
        $this->seedDocuments($project, $contract, $james);

        $this->command?->info("✓ Demo project: {$project->name} (id {$project->id}) — completed.");
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
            DemoActivityLogger::log($project, $priya, 'project.created', "Project created: {$project->name}", Story::PROJECT['start_date']);
            DemoActivityLogger::log($project, $priya, 'project.completed', "Project marked completed: {$project->name}", '2026-03-22', 'Defects Liability Period ended and Final Account agreed.');
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
            DemoActivityLogger::log($project, $daniel, 'contract.archived', 'Contract archived following commercial close-out', '2026-03-22', null, $contract);
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
                    'status' => 'completed',
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
                    'forecast_date' => $data['actual_date'],
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

    private function seedVariations(Project $project, Contract $contract, User $daniel): array
    {
        $variations = [];

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
                    'approved_at' => $data['variation_date'],
                    'approved_by' => $daniel->id,
                ]
            );

            $variation->instruction_date = $data['instruction_date'];
            $variation->description = $data['description'];
            $variation->save();

            $variations[$data['variation_number']] = $variation;
        }

        return $variations;
    }

    private function seedPaymentApplications(Project $project, Contract $contract, User $daniel, User $priya, array $variations): void
    {
        $previousNet = 0.0;

        foreach (Story::PAYMENT_APPLICATIONS as $data) {
            $applicationDate = Carbon::parse($data['application_date']);
            $retention = round($data['gross_valuation'] * ($contract->retention_percentage / 100), 2);
            $netValuation = round($data['gross_valuation'] - $retention, 2);
            $amountDue = round($netValuation - $previousNet, 2);

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
                    'payment_date' => $applicationDate->copy()->addDays($contract->final_date_offset_days)->toDateString(),
                    'paid_amount' => $data['certified_amount'],
                    'paid_at' => $applicationDate->copy()->addDays($contract->final_date_offset_days),
                    'status' => 'paid',
                    'notes' => $data['notes'],
                    'submitted_at' => $applicationDate,
                    'submitted_by' => $daniel->id,
                ]
            );

            $previousNet = $data['certified_amount'];

            if ($application->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $daniel, 'payment_application.paid', "Payment Application {$data['application_number']} paid", $application->payment_date, $data['notes'], $application);
            }

            // Variation 1 -> Application 2, Variation 4 -> Application 3 (the
            // adjudicated one), Variation 3 -> Application 4, Variation 2 was
            // agreed between applications 2 and 3 and is included alongside V4.
            $links = [2 => [1], 3 => [4, 2], 4 => [3]];
            foreach ($links[$data['application_number']] ?? [] as $variationNumber) {
                if (isset($variations[$variationNumber])) {
                    $this->linkVariation($application, $variations[$variationNumber]);
                }
            }
        }
    }

    private function linkVariation(PaymentApplication $application, Variation $variation): void
    {
        PaymentApplicationVariation::firstOrCreate(
            ['payment_application_id' => $application->id, 'variation_id' => $variation->id],
            [
                'variation_number_at_inclusion' => (string) $variation->variation_number,
                'title_at_inclusion' => $variation->title,
                'description_at_inclusion' => $variation->description,
                'amount_at_inclusion' => $variation->agreed_amount,
                'status_at_inclusion' => $variation->status,
            ]
        );

        $variation->valuation_status = 'included';
        $variation->included_in_pa_id = $application->id;
        $variation->save();
    }

    private function seedFinalAccountAndRetention(Project $project, Contract $contract, User $priya, User $daniel): void
    {
        $variationsTotal = array_sum(array_column(Story::VARIATIONS, 'agreed_amount'));
        $adjustedSum = Story::CONTRACT['contract_sum'] + $variationsTotal;
        $retentionTotal = round($adjustedSum * ($contract->retention_percentage / 100), 2);

        $finalAccount = FinalAccount::updateOrCreate(
            ['project_id' => $project->id, 'contract_id' => $contract->id],
            [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'is_trade_package' => false,
                'reference' => 'FA-PC-APTS',
                'status' => 'agreed',
                'original_contract_sum' => Story::CONTRACT['contract_sum'],
                'approved_variations_total' => $variationsTotal,
                'loss_and_expense_total' => 0,
                'dayworks_total' => 0,
                'provisional_sum_adjustment' => 0,
                'prime_cost_sum_adjustment' => 0,
                'contra_charges_total' => 0,
                'other_adjustments_total' => 0,
                'certified_to_date' => $adjustedSum,
                'paid_to_date' => $adjustedSum,
                'retention_held' => 0,
                'retention_released' => $retentionTotal,
                'submitted_at' => '2026-03-24 09:00:00',
                'submitted_by' => $daniel->id,
                'reviewed_at' => '2026-03-27 10:00:00',
                'reviewed_by' => $daniel->id,
                'agreed_at' => '2026-04-05 15:00:00',
                'agreed_by' => $priya->id,
                'notes' => 'Final Account agreed with Ellery Marchmont Chartered Surveyors '
                    . 'following the end of the Defects Liability Period. Includes the '
                    . 'adjudicated valuation of Variation 4.',
            ]
        );

        $items = [
            ['category' => 'contract_sum', 'description' => 'Original contract sum', 'amount' => Story::CONTRACT['contract_sum'], 'sort_order' => 1],
        ];
        foreach (Story::VARIATIONS as $index => $variation) {
            $items[] = [
                'category' => 'approved_variation',
                'description' => "Variation {$variation['variation_number']} — {$variation['title']}",
                'amount' => $variation['agreed_amount'],
                'sort_order' => $index + 2,
            ];
        }

        foreach ($items as $item) {
            FinalAccountItem::updateOrCreate(
                ['final_account_id' => $finalAccount->id, 'description' => $item['description']],
                array_merge($item, ['final_account_id' => $finalAccount->id])
            );
        }

        RetentionRelease::updateOrCreate(
            ['project_id' => $project->id, 'moiety' => RetentionRelease::MOIETY_HALF_1],
            [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'contract_id' => $contract->id,
                'created_by' => $daniel->id,
                'release_amount' => 45000.00,
                'release_date' => '2025-12-22',
                'release_reason' => 'First moiety released at Practical Completion.',
                'moiety' => RetentionRelease::MOIETY_HALF_1,
            ]
        );

        RetentionRelease::updateOrCreate(
            ['project_id' => $project->id, 'moiety' => RetentionRelease::MOIETY_HALF_2],
            [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'contract_id' => $contract->id,
                'created_by' => $daniel->id,
                'release_amount' => $retentionTotal - 45000.00,
                'release_date' => '2026-03-22',
                'release_reason' => 'Second moiety released at the end of the Defects Liability Period, following the Certificate of Making Good Defects.',
                'moiety' => RetentionRelease::MOIETY_HALF_2,
            ]
        );

        DemoActivityLogger::log($project, $priya, 'final_account.agreed', 'Final Account agreed', '2026-04-05', null, $finalAccount);
        DemoActivityLogger::log($project, $daniel, 'retention.released', 'Final retention moiety released', '2026-03-22', null);
    }

    private function seedAdjudicationCase(Project $project, Contract $contract, User $daniel): void
    {
        $variation = Variation::where('project_id', $project->id)->where('variation_number', 4)->first();

        $case = AdjudicationCase::updateOrCreate(
            ['case_number' => Story::ADJUDICATION_CASE['case_number']],
            array_merge(Story::ADJUDICATION_CASE, [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'variation_id' => $variation?->id,
                'created_by' => $daniel->id,
                'archived_by' => $daniel->id,
                'archived_at' => '2025-08-29 17:00:00',
            ])
        );

        $stepDates = [
            'notice_of_dispute' => '2025-06-20',
            'notice_of_adjudication' => '2025-06-27',
            'adjudicator_appointment' => '2025-06-30',
            'referral_submission' => '2025-07-04',
            'response_analysis' => '2025-07-18',
            'further_submissions' => '2025-07-25',
            'decision_analysis' => '2025-08-15',
            'enforcement' => '2025-08-29',
        ];

        $index = 0;
        foreach (AdjudicationCase::STEPS as $stepKey => $title) {
            AdjudicationStep::updateOrCreate(
                ['adjudication_case_id' => $case->id, 'step_key' => $stepKey],
                [
                    'adjudication_case_id' => $case->id,
                    'step_key' => $stepKey,
                    'title' => $title,
                    'status' => 'completed',
                    'due_date' => $stepDates[$stepKey],
                    'completed_at' => $stepDates[$stepKey],
                    'completed_by' => $daniel->id,
                    'sort_order' => $index + 1,
                ]
            );
            $index++;
        }

        if ($case->wasRecentlyCreated) {
            DemoActivityLogger::log($project, $daniel, 'adjudication.closed', "Adjudication case closed: {$case->title}", '2025-08-29', null, $case);
        }
    }

    private function seedRfis(Project $project, User $daniel): void
    {
        foreach (Story::RFIS as $data) {
            Rfi::updateOrCreate(
                ['project_id' => $project->id, 'rfi_number' => $data['rfi_number']],
                array_merge($data, [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $daniel->id,
                ])
            );
        }
    }

    private function seedMeetings(Project $project, User $priya): void
    {
        foreach (Story::MEETINGS as $data) {
            MeetingMinutes::updateOrCreate(
                ['project_id' => $project->id, 'meeting_number' => $data['meeting_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $priya->id,
                    'meeting_number' => $data['meeting_number'],
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'meeting_date' => $data['meeting_date'],
                    'location' => 'Priory Court site office',
                    'status' => 'issued',
                ]
            );
        }
    }

    private function seedQaReports(Project $project, User $daniel): void
    {
        foreach (Story::QA_REPORTS as $data) {
            QaReport::updateOrCreate(
                ['project_id' => $project->id, 'report_number' => $data['report_number']],
                array_merge($data, [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'created_by' => $daniel->id,
                    'inspected_by' => $daniel->id,
                    'follow_up_required' => false,
                ])
            );
        }
    }

    private function seedCloseout(Project $project, User $priya): void
    {
        $closeout = Closeout::updateOrCreate(
            ['project_id' => $project->id],
            [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'created_by' => $priya->id,
                'title' => 'Priory Court Apartments: Project Closeout',
                'status' => 'approved',
                'completed_at' => '2026-03-22 17:00:00',
                'notes' => 'All close-out items approved; Certificate of Making Good Defects issued and Final Account agreed.',
            ]
        );

        foreach (Story::CLOSEOUT_ITEMS as $index => $data) {
            CloseoutItem::updateOrCreate(
                ['closeout_id' => $closeout->id, 'title' => $data['title']],
                array_merge($data, [
                    'closeout_id' => $closeout->id,
                    'sort_order' => $index + 1,
                    'completed_at' => $data['due_date'],
                ])
            );
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
                    'status' => 'completed',
                    'booking_source' => 'admin_created',
                    'meeting_method' => 'teams',
                    'completion_notes' => $data['completion_notes'],
                    'completed_at' => $data['ends_at'],
                ]
            );
        }
    }

    private function seedDocuments(Project $project, Contract $contract, User $james): void
    {
        $documents = [
            ['title' => 'Priory Court Apartments: Main Contract (Executed)', 'type' => 'contract', 'category' => 'Contracts', 'reference_number' => 'HG-PC-APTS-001', 'documentable' => $contract],
            ['title' => 'Practical Completion Certificate — Priory Court', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'PCC-PC-APTS', 'documentable' => null],
            ['title' => 'Certificate of Making Good Defects', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'CMGD-PC-APTS', 'documentable' => null],
            ['title' => 'Agreed Final Account — Priory Court Apartments', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'FA-PC-APTS', 'documentable' => null],
            ['title' => 'Adjudicator\'s Decision — Variation 4 Valuation Dispute', 'type' => 'other', 'category' => 'Adjudication', 'reference_number' => 'ADJ-PC-2025-01-DECISION', 'documentable' => null],
            ['title' => 'O&M Manuals — Priory Court Apartments (Full Set)', 'type' => 'other', 'category' => 'Specifications', 'reference_number' => 'OM-PC-APTS', 'documentable' => null],
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
                    // Everything here is historical — archived, not merely "approved".
                    'status' => 'archived',
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
