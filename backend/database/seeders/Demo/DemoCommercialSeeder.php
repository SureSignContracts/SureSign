<?php

namespace Database\Seeders\Demo;

use App\Models\Contract;
use App\Models\DelayEvent;
use App\Models\EotRequest;
use App\Models\LossAndExpenseClaim;
use App\Models\Organization;
use App\Models\PaymentApplication;
use App\Models\PaymentApplicationVariation;
use App\Models\PaymentNotice;
use App\Models\PayLessNotice;
use App\Models\Project;
use App\Models\User;
use App\Models\Variation;
use Carbon\Carbon;
use Database\Seeders\Demo\Data\DemoActivityLogger;
use Database\Seeders\Demo\Data\DemoCompanyProfile;
use Database\Seeders\Demo\Data\RiversideWharfStory;
use Illuminate\Database\Seeder;

/**
 * Creates Riverside Wharf's commercial history: variations, the six
 * interim payment applications with statutory dates derived from the
 * contract's offset days, the live payment/pay-less notice dispute on
 * application 6, and the weather-delay -> EOT -> loss & expense chain.
 *
 * Amounts are cumulative-valuation JCT convention (gross_valuation is the
 * cumulative value of work done to date) — each application's incremental
 * amount_due is derived from the difference against the previous
 * application's certified value, not re-authored per row, so the six
 * applications can never drift out of arithmetic agreement with each
 * other.
 */
class DemoCommercialSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', DemoCompanyProfile::ORGANIZATION['slug'])->firstOrFail();
        $project = Project::where('organization_id', $organization->id)
            ->where('code', RiversideWharfStory::PROJECT['code'])
            ->firstOrFail();
        $contract = Contract::where('project_id', $project->id)
            ->where('reference_number', RiversideWharfStory::CONTRACT['reference_number'])
            ->firstOrFail();
        $daniel = User::where('email', 'daniel.okafor@haldengroveconstruction.com')->firstOrFail();
        $priya = User::where('email', 'priya.chandra@haldengroveconstruction.com')->firstOrFail();
        $sarah = User::where('email', 'sarah.blythe@haldengroveconstruction.com')->firstOrFail();

        $variations = $this->seedVariations($project, $contract, $daniel);
        $applications = $this->seedPaymentApplications($project, $contract, $daniel, $priya, $variations);
        $this->seedPaymentAndPayLessNotice($project, $applications[6], $priya);
        $this->seedDelayEotAndLossExpense($project, $contract, $sarah, $daniel);

        $this->command?->info('✓ Demo commercial: variations, 6 payment applications, pay-less notice dispute, delay/EOT/L&E chain ready.');
    }

    private function seedVariations(Project $project, Contract $contract, User $daniel): array
    {
        $variations = [];

        foreach (RiversideWharfStory::VARIATIONS as $data) {
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
                    'programme_impact_days' => $data['programme_impact_days'],
                    'instructed_at' => $data['instruction_date'],
                    'instructed_by' => $daniel->id,
                    'approved_at' => $data['status'] === 'approved' ? $data['variation_date'] : null,
                    'approved_by' => $data['status'] === 'approved' ? $daniel->id : null,
                ]
            );

            // instruction_date/description/notes aren't in Variation's $fillable
            // (see internal-docs/demo-environment/index.md schema notes) — set
            // directly rather than losing them silently through create().
            $variation->instruction_date = $data['instruction_date'];
            $variation->description = $data['description'];
            $variation->save();

            if ($variation->wasRecentlyCreated) {
                DemoActivityLogger::log(
                    $project,
                    $daniel,
                    'variation.' . $data['status'],
                    "Variation {$data['variation_number']}: {$data['title']}",
                    $data['instruction_date'],
                    null,
                    $variation
                );
            }

            $variations[$data['variation_number']] = $variation;
        }

        return $variations;
    }

    private function seedPaymentApplications(Project $project, Contract $contract, User $daniel, User $priya, array $variations): array
    {
        $applications = [];
        $previousNet = 0.0;

        foreach (RiversideWharfStory::PAYMENT_APPLICATIONS as $data) {
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
                    'certified_date' => $data['certified_amount'] !== null ? $applicationDate->copy()->addDays($contract->due_date_offset_days)->toDateString() : null,
                    'certified_at' => $data['certified_amount'] !== null ? $applicationDate->copy()->addDays($contract->due_date_offset_days) : null,
                    'certified_by' => $data['certified_amount'] !== null ? $daniel->id : null,
                    'payment_date' => $data['status'] === 'paid' ? $applicationDate->copy()->addDays($contract->final_date_offset_days)->toDateString() : null,
                    'paid_amount' => $data['status'] === 'paid' ? $data['certified_amount'] : null,
                    'paid_at' => $data['status'] === 'paid' ? $applicationDate->copy()->addDays($contract->final_date_offset_days) : null,
                    'status' => $data['status'],
                    'notes' => $data['notes'],
                    'submitted_at' => $applicationDate,
                    'submitted_by' => $daniel->id,
                ]
            );

            if ($data['certified_amount'] !== null) {
                $previousNet = $data['certified_amount'];
            }

            if ($application->wasRecentlyCreated) {
                DemoActivityLogger::log(
                    $project,
                    $daniel,
                    'payment_application.submitted',
                    "Payment Application {$data['application_number']} submitted",
                    $data['application_date'],
                    $data['notes'],
                    $application
                );

                if ($data['status'] === 'paid') {
                    DemoActivityLogger::log(
                        $project,
                        $priya,
                        'payment_application.paid',
                        "Payment Application {$data['application_number']} paid",
                        $application->payment_date,
                        null,
                        $application
                    );
                }
            }

            $applications[$data['application_number']] = $application;

            // Link Variation 1 into Application 3, Variation 2 into Application 5 —
            // both were described in RiversideWharfStory as "included in" that
            // application's valuation.
            if ($data['application_number'] === 3 && isset($variations[1])) {
                $this->linkVariation($application, $variations[1]);
            }
            if ($data['application_number'] === 5 && isset($variations[2])) {
                $this->linkVariation($application, $variations[2]);
            }
        }

        return $applications;
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

    private function seedPaymentAndPayLessNotice(Project $project, PaymentApplication $application, User $priya): void
    {
        $applicationDate = Carbon::parse($application->application_date);
        $originalAmountDue = (float) $application->amount_due;
        $deduction = RiversideWharfStory::PAY_LESS_NOTICE['amount'];
        $revised = round($originalAmountDue - $deduction, 2);

        $notice = PaymentNotice::firstOrCreate(
            ['payment_application_id' => $application->id],
            [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'created_by' => $priya->id,
                'reference' => "PN-{$application->application_number}",
                'notice_date' => $applicationDate->copy()->addDays(5)->toDateString(),
                'notified_sum' => $originalAmountDue,
                'basis_of_assessment' => "Notified sum per Contractor's Interim Application {$application->application_number}, ahead of the Employer's Agent's Pay Less Notice.",
                'issued_by' => 'Whitfield & Sutton Chartered Surveyors (Employer\'s Agent)',
                'status' => 'issued',
            ]
        );

        $payLess = RiversideWharfStory::PAY_LESS_NOTICE;

        PayLessNotice::firstOrCreate(
            ['payment_application_id' => $application->id],
            [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'created_by' => $priya->id,
                'payment_notice_id' => $notice->id,
                'notice_date' => $payLess['notice_date'],
                'amount' => $payLess['amount'],
                'notified_sum' => $originalAmountDue,
                'reason' => $payLess['reason'],
                'basis_of_difference' => $payLess['basis_of_difference'],
                'reference' => "PLN-{$application->application_number}",
                'status' => 'issued',
                'original_amount_due' => $originalAmountDue,
                'total_deductions' => $deduction,
                'revised_amount_payable' => $revised,
                'issued_by' => $payLess['issued_by'],
            ]
        );

        DemoActivityLogger::log(
            $project,
            $priya,
            'pay_less_notice.issued',
            "Pay Less Notice issued against Payment Application {$application->application_number}",
            $payLess['notice_date'],
            $payLess['reason'],
            $application
        );
    }

    private function seedDelayEotAndLossExpense(Project $project, Contract $contract, User $sarah, User $daniel): void
    {
        $delayData = RiversideWharfStory::DELAY_EVENT;

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
            DemoActivityLogger::log(
                $project,
                $sarah,
                'delay_event.logged',
                "Delay event logged: {$delayData['title']}",
                $delayData['date_notified'],
                $delayData['description'],
                $delayEvent
            );
        }

        $eotData = RiversideWharfStory::EOT_REQUEST;

        $eotRequest = EotRequest::updateOrCreate(
            ['project_id' => $project->id, 'eot_number' => $eotData['eot_number']],
            [
                'project_id' => $project->id,
                'organization_id' => $project->organization_id,
                'contract_id' => $contract->id,
                'trade_package_id' => null,
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
            DemoActivityLogger::log(
                $project,
                $daniel,
                'eot_request.submitted',
                "EOT request submitted: {$eotData['title']}",
                $eotData['notice_date'],
                null,
                $eotRequest
            );
        }

        $lossData = RiversideWharfStory::LOSS_AND_EXPENSE_CLAIM;

        $claim = LossAndExpenseClaim::updateOrCreate(
            ['project_id' => $project->id, 'claim_number' => $lossData['claim_number']],
            [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'contract_id' => $contract->id,
                'trade_package_id' => null,
                'delay_event_id' => $delayEvent->id,
                'eot_request_id' => $eotRequest->id,
                'created_by' => $daniel->id,
                'claim_number' => $lossData['claim_number'],
                'title' => $lossData['title'],
                'description' => $lossData['description'],
                'amount_claimed' => $lossData['amount_claimed'],
                'amount_assessed' => $lossData['amount_assessed'],
                'amount_agreed' => $lossData['amount_agreed'],
                'status' => $lossData['status'],
            ]
        );

        if ($claim->wasRecentlyCreated) {
            DemoActivityLogger::log(
                $project,
                $daniel,
                'loss_and_expense_claim.agreed',
                "Loss & Expense claim agreed: {$lossData['title']}",
                '2026-02-04',
                null,
                $claim
            );
        }
    }
}
