<?php

namespace Database\Seeders\Demo;

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
use App\Models\Rfi;
use App\Models\Snag;
use App\Models\TradePackage;
use App\Models\User;
use App\Models\Variation;
use App\Services\ExcelGenerationService;
use Carbon\Carbon;
use Database\Seeders\Demo\Data\ColdfieldStory as Story;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * Coldfield Retail Park — Unit 4 Fit-Out: the "near completion" project in
 * the approved demo portfolio (Phase 3). Practical Completion has just
 * been achieved; retention is still pending release and the Final Account
 * is still in draft — this is the deliberate midpoint between Riverside
 * Wharf (mid-project) and Priory Court Apartments (fully closed).
 *
 * Deliberately consolidated into one seeder class (rather than Phase 2's
 * one-class-per-module-family split) — this project's dataset is a fifth
 * the size of Riverside Wharf's and the module-family split would have
 * produced eight mostly-trivial files for no real maintainability gain.
 * Riverside Wharf's split remains the pattern to follow for a project of
 * comparable size and commercial complexity.
 */
class DemoColdfieldSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $tom = User::where('email', 'tom.aldridge@haldengroveconstruction.com')->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();
        $sarah = User::where('email', 'sarah.blythe@haldengroveconstruction.com')->firstOrFail();
        $james = User::where('email', 'james.ridley@haldengroveconstruction.com')->firstOrFail();

        $project = $this->seedProject($organization, $tom);
        $contract = $this->seedContract($project, $daniel);
        $this->seedTradePackages($project, $daniel);
        $this->seedProgramme($project, $contract);
        $this->seedRisks($project, $contract);
        $variations = $this->seedVariations($project, $contract, $daniel);
        $this->seedPaymentApplications($project, $contract, $daniel, $tom, $variations);
        $this->seedFinalAccount($project, $contract);
        $this->seedRfis($project, $sarah);
        $this->seedMeetings($project, $tom);
        $this->seedSnags($project, $sarah);
        $this->seedQaReports($project, $sarah);
        $this->seedCloseout($project, $daniel);
        $this->seedDocuments($project, $contract, $james);

        $this->command?->info("✓ Demo project: {$project->name} (id {$project->id}) — near completion.");
    }

    private function seedProject(Organization $organization, User $tom): Project
    {
        $project = Project::updateOrCreate(
            ['organization_id' => $organization->id, 'code' => Story::PROJECT['code']],
            array_merge(Story::PROJECT, ['created_by' => $tom->id])
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
            DemoActivityLogger::log($project, $tom, 'project.created', "Project created: {$project->name}", Story::PROJECT['start_date']);
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
                DemoActivityLogger::log($project, $daniel, 'trade_package.completed', "Trade package completed: {$data['name']}", $data['completion_date'], null, $tradePackage);
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
                    'forecast_date' => $data['actual_date'] ?? $data['planned_date'],
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
            ContractRisk::updateOrCreate(
                ['organization_id' => $project->organization_id, 'title' => $data['title']],
                [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'contract_id' => $contract->id,
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

    private function seedPaymentApplications(Project $project, Contract $contract, User $daniel, User $tom, array $variations): void
    {
        $previousNet = 0.0;

        foreach (Story::PAYMENT_APPLICATIONS as $data) {
            $applicationDate = Carbon::parse($data['application_date']);
            $retention = round($data['gross_valuation'] * ($contract->retention_percentage / 100), 2);
            $netValuation = round($data['gross_valuation'] - $retention, 2);
            $amountDue = round($netValuation - $previousNet, 2);
            $isPaid = $data['status'] === 'paid';
            $isCertified = in_array($data['status'], ['paid', 'certified'], true);

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
                    'certified_amount' => $isCertified ? $data['certified_amount'] : null,
                    'certified_date' => $isCertified ? $applicationDate->copy()->addDays($contract->due_date_offset_days)->toDateString() : null,
                    'certified_at' => $isCertified ? $applicationDate->copy()->addDays($contract->due_date_offset_days) : null,
                    'certified_by' => $isCertified ? $daniel->id : null,
                    'payment_date' => $isPaid ? $applicationDate->copy()->addDays($contract->final_date_offset_days)->toDateString() : null,
                    'paid_amount' => $isPaid ? $data['certified_amount'] : null,
                    'paid_at' => $isPaid ? $applicationDate->copy()->addDays($contract->final_date_offset_days) : null,
                    'status' => $data['status'],
                    'notes' => $data['notes'],
                    'submitted_at' => $applicationDate,
                    'submitted_by' => $daniel->id,
                ]
            );

            if ($isCertified) {
                $previousNet = $data['certified_amount'];
            }

            if ($application->wasRecentlyCreated) {
                DemoActivityLogger::log($project, $daniel, 'payment_application.submitted', "Payment Application {$data['application_number']} submitted", $data['application_date'], $data['notes'], $application);
                if ($isPaid) {
                    DemoActivityLogger::log($project, $tom, 'payment_application.paid', "Payment Application {$data['application_number']} paid", $application->payment_date, null, $application);
                }
            }

            // Variation 1 included in Application 3, Variation 2 in Application 5.
            if ($data['application_number'] === 3 && isset($variations[1])) {
                $this->linkVariation($application, $variations[1]);
            }
            if ($data['application_number'] === 5 && isset($variations[2])) {
                $this->linkVariation($application, $variations[2]);
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

    /**
     * Draft Final Account — deliberately does NOT set the snapshot columns
     * (original_contract_sum etc.), since those only lock in once the
     * account is agreed (see FinalAccount::isSnapshotted()). The items
     * below are the live worksheet a draft account genuinely has.
     */
    private function seedFinalAccount(Project $project, Contract $contract): FinalAccount
    {
        $finalAccount = FinalAccount::updateOrCreate(
            ['project_id' => $project->id, 'contract_id' => $contract->id],
            [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'is_trade_package' => false,
                'reference' => 'FA-CRP-U4',
                'status' => 'draft',
                'notes' => 'Draft final account, pending completion of close-out items and '
                    . 'confirmation of O&M manual submission before agreement.',
            ]
        );

        $items = [
            ['category' => 'contract_sum', 'description' => 'Original contract sum', 'amount' => Story::CONTRACT['contract_sum'], 'sort_order' => 1],
            ['category' => 'approved_variation', 'description' => 'Variation 1 — Relocated service riser', 'amount' => 13600.00, 'sort_order' => 2],
            ['category' => 'approved_variation', 'description' => 'Variation 2 — Upgraded shopfront glazing specification', 'amount' => 21200.00, 'sort_order' => 3],
        ];

        foreach ($items as $item) {
            FinalAccountItem::updateOrCreate(
                ['final_account_id' => $finalAccount->id, 'description' => $item['description']],
                array_merge($item, ['final_account_id' => $finalAccount->id])
            );
        }

        return $finalAccount;
    }

    private function seedRfis(Project $project, User $sarah): void
    {
        foreach (Story::RFIS as $data) {
            Rfi::updateOrCreate(
                ['project_id' => $project->id, 'rfi_number' => $data['rfi_number']],
                array_merge($data, [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $sarah->id,
                ])
            );
        }
    }

    private function seedMeetings(Project $project, User $tom): void
    {
        foreach (Story::MEETINGS as $data) {
            MeetingMinutes::updateOrCreate(
                ['project_id' => $project->id, 'meeting_number' => $data['meeting_number']],
                [
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'created_by' => $tom->id,
                    'meeting_number' => $data['meeting_number'],
                    'title' => $data['title'],
                    'type' => $data['type'],
                    'meeting_date' => $data['meeting_date'],
                    'location' => 'Unit 4, Coldfield Retail Park',
                    'status' => 'issued',
                ]
            );
        }
    }

    private function seedSnags(Project $project, User $sarah): void
    {
        foreach (Story::SNAGS as $index => $data) {
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
        foreach (Story::QA_REPORTS as $data) {
            QaReport::updateOrCreate(
                ['project_id' => $project->id, 'report_number' => $data['report_number']],
                array_merge($data, [
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'created_by' => $sarah->id,
                    'inspected_by' => $sarah->id,
                    'follow_up_required' => false,
                ])
            );
        }
    }

    private function seedCloseout(Project $project, User $daniel): void
    {
        $closeout = Closeout::updateOrCreate(
            ['project_id' => $project->id],
            [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'created_by' => $daniel->id,
                'title' => 'Coldfield Retail Park — Unit 4: Project Closeout',
                'status' => 'in_progress',
                'notes' => 'Practical Completion achieved 2026-07-15. O&M manuals and as-built '
                    . 'drawings outstanding — final retention moiety withheld until complete.',
            ]
        );

        foreach (Story::CLOSEOUT_ITEMS as $index => $data) {
            CloseoutItem::updateOrCreate(
                ['closeout_id' => $closeout->id, 'title' => $data['title']],
                array_merge($data, [
                    'closeout_id' => $closeout->id,
                    'sort_order' => $index + 1,
                    'completed_at' => in_array($data['status'], ['completed', 'approved'], true) ? $data['due_date'] : null,
                ])
            );
        }
    }

    private function seedDocuments(Project $project, Contract $contract, User $james): void
    {
        $documents = [
            ['title' => 'Coldfield Retail Park — Unit 4: Main Contract (Executed)', 'type' => 'contract', 'category' => 'Contracts', 'reference_number' => 'HG-CRP-U4-001', 'status' => 'approved', 'documentable' => $contract],
            ['title' => 'Practical Completion Certificate — Unit 4', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'PCC-CRP-U4', 'status' => 'issued', 'documentable' => null],
            ['title' => 'Snag List — Pre-Handover', 'type' => 'report', 'category' => 'Commercial Documents', 'reference_number' => 'SNAG-CRP-U4-01', 'status' => 'issued', 'documentable' => null],
            ['title' => 'Draft Final Account — Unit 4', 'type' => 'other', 'category' => 'Commercial Documents', 'reference_number' => 'FA-CRP-U4', 'status' => 'draft', 'documentable' => null],
            ['title' => 'Close-Out Checklist — Unit 4', 'type' => 'report', 'category' => 'Commercial Documents', 'reference_number' => 'CLOSEOUT-CRP-U4', 'status' => 'draft', 'documentable' => null],
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

        // Final (sixth) application, generated the real way per the same
        // convention established in Phase 2 — see DemoDocumentSeeder.
        $finalApplication = PaymentApplication::where('contract_id', $contract->id)->where('application_number', 6)->first();
        if ($finalApplication) {
            $alreadyGenerated = Document::where('documentable_type', PaymentApplication::class)
                ->where('documentable_id', $finalApplication->id)
                ->exists();

            if (! $alreadyGenerated) {
                try {
                    ExcelGenerationService::generatePaymentApplicationWorkbook($finalApplication, $james);
                } catch (Throwable $e) {
                    $this->command?->warn("  Could not generate Coldfield final application workbook: {$e->getMessage()}");
                }
            }
        }
    }
}
