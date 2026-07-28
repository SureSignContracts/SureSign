<?php

namespace App\Console\Commands\Demo;

use App\Support\Demo\DemoClock;
use App\Support\Demo\DemoStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only internal-consistency checker for the demo environment. Never
 * writes data — every check below is a SELECT against the isolated `demo`
 * connection. Complements `demo:status` (which reports *what exists*) by
 * reporting *whether what exists makes sense* — see
 * internal-docs/demo-environment/index.md.
 *
 * Exits non-zero if any ERROR-level issue is found (suitable for a CI-style
 * gate later); WARNING-level issues are reported but don't fail the run.
 */
class DemoValidate extends Command
{
    protected $signature = 'demo:validate';

    protected $description = 'Validate internal consistency of the isolated SureSign demo environment (read-only, never writes)';

    private $db;

    private array $errors = [];
    private array $warnings = [];

    public function handle(): int
    {
        $connectionName = config('demo.connection', 'demo');
        $this->db = DB::connection($connectionName);
        DemoStorage::isolate();

        $this->line('<fg=cyan>SureSign Demo Environment Validation</>');
        $this->line('');

        $daysSinceAnchor = DemoClock::daysSinceAnchor();
        if ($daysSinceAnchor > 30) {
            $this->line("<fg=yellow>Anchor date is {$daysSinceAnchor} days in the past (anchor: " . DemoClock::anchorDate()->toDateString() . ", real today: " . now()->toDateString() . ").</>");
            $this->line('<fg=yellow>Business signals below are still evaluated against the anchor, so they remain accurate to the story — but consider re-seeding with a rolled-forward anchor before further live demoing.</>');
            $this->line('');
        }

        $this->checkDuplicateEntities();
        $this->checkOrphanedRelationships();
        $this->checkChronology();
        $this->checkProgrammeDates();
        $this->checkCommercialChains();
        $this->checkGeneratedDocuments();
        $this->checkPortfolioConsistency();

        $this->line('');
        if ($this->errors) {
            $this->line('<fg=red>Errors (' . count($this->errors) . ')</>');
            foreach ($this->errors as $error) {
                $this->line("  <fg=red>✗</> {$error}");
            }
        }

        if ($this->warnings) {
            $this->line('');
            $this->line('<fg=yellow>Warnings (' . count($this->warnings) . ')</>');
            foreach ($this->warnings as $warning) {
                $this->line("  <fg=yellow>!</> {$warning}");
            }
        }

        if (! $this->errors && ! $this->warnings) {
            $this->line('<fg=green>No issues found — the demo environment is internally consistent.</>');
        }

        $this->line('');
        $this->reportBusinessSignals();

        $this->line('');
        $this->line(sprintf('%d error(s), %d warning(s).', count($this->errors), count($this->warnings)));

        return $this->errors ? self::FAILURE : self::SUCCESS;
    }

    private function flagError(string $message): void
    {
        $this->errors[] = $message;
    }

    private function flagWarning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /** Duplicate demo entities — the seeders should never produce these. */
    private function checkDuplicateEntities(): void
    {
        $orgCount = $this->db->table('organizations')->count();
        if ($orgCount > 1) {
            $this->flagError("{$orgCount} organisations exist — the demo environment expects exactly one (Halden Grove).");
        }

        $duplicateProjectCodes = $this->db->table('projects')
            ->select('code')->whereNotNull('code')->groupBy('code')
            ->havingRaw('count(*) > 1')->pluck('code');
        foreach ($duplicateProjectCodes as $code) {
            $this->flagError("Duplicate project code '{$code}'.");
        }

        $duplicateContractRefs = $this->db->table('contracts')
            ->select('project_id', 'reference_number')->whereNotNull('reference_number')
            ->groupBy('project_id', 'reference_number')->havingRaw('count(*) > 1')->get();
        foreach ($duplicateContractRefs as $row) {
            $this->flagError("Duplicate contract reference '{$row->reference_number}' on project {$row->project_id}.");
        }

        $duplicatePackageCodes = $this->db->table('trade_packages')
            ->select('project_id', 'package_code')->whereNotNull('package_code')
            ->groupBy('project_id', 'package_code')->havingRaw('count(*) > 1')->get();
        foreach ($duplicatePackageCodes as $row) {
            $this->flagError("Duplicate trade package code '{$row->package_code}' on project {$row->project_id}.");
        }

        $duplicateApplicationNumbers = $this->db->table('payment_applications')
            ->select('contract_id', 'application_number')
            ->groupBy('contract_id', 'application_number')->havingRaw('count(*) > 1')->get();
        foreach ($duplicateApplicationNumbers as $row) {
            $this->flagError("Duplicate payment application number {$row->application_number} on contract {$row->contract_id}.");
        }

        $duplicateVariationNumbers = $this->db->table('variations')
            ->select('project_id', 'variation_number')
            ->groupBy('project_id', 'variation_number')->havingRaw('count(*) > 1')->get();
        foreach ($duplicateVariationNumbers as $row) {
            $this->flagError("Duplicate variation number {$row->variation_number} on project {$row->project_id}.");
        }
    }

    /** Foreign keys that point nowhere, and records that belong to neither side of an "exactly one of" pair. */
    private function checkOrphanedRelationships(): void
    {
        $projectIds = $this->db->table('projects')->pluck('id')->all();

        foreach (['contracts', 'trade_packages', 'contract_risks', 'variations', 'payment_applications', 'rfis', 'meeting_minutes', 'documents', 'appointments'] as $table) {
            if (! $this->db->getSchemaBuilder()->hasColumn($table, 'project_id')) {
                continue;
            }
            $orphaned = $this->db->table($table)
                ->whereNotNull('project_id')
                ->whereNotIn('project_id', $projectIds)
                ->count();
            if ($orphaned > 0) {
                $this->flagError("{$orphaned} row(s) in '{$table}' reference a project_id that doesn't exist.");
            }
        }

        // contract_risks must belong to exactly one of {contract, trade package}.
        $risksWithBoth = $this->db->table('contract_risks')->whereNotNull('contract_id')->whereNotNull('trade_package_id')->count();
        $risksWithNeither = $this->db->table('contract_risks')->whereNull('contract_id')->whereNull('trade_package_id')->count();
        if ($risksWithBoth > 0) {
            $this->flagError("{$risksWithBoth} risk(s) have both contract_id and trade_package_id set — should be exactly one.");
        }
        if ($risksWithNeither > 0) {
            $this->flagError("{$risksWithNeither} risk(s) have neither contract_id nor trade_package_id set — should be exactly one.");
        }

        // delay_events / eot_requests / loss_and_expense_claims chain integrity.
        $delayEventIds = $this->db->table('delay_events')->pluck('id')->all();
        $eotRequestIds = $this->db->table('eot_requests')->pluck('id')->all();

        $danglingEotDelayLinks = $this->db->table('eot_requests')
            ->whereNotNull('delay_event_id')->whereNotIn('delay_event_id', $delayEventIds ?: [0])->count();
        if ($danglingEotDelayLinks > 0) {
            $this->flagError("{$danglingEotDelayLinks} EOT request(s) reference a delay_event_id that doesn't exist.");
        }

        $danglingLeDelayLinks = $this->db->table('loss_and_expense_claims')
            ->whereNotNull('delay_event_id')->whereNotIn('delay_event_id', $delayEventIds ?: [0])->count();
        $danglingLeEotLinks = $this->db->table('loss_and_expense_claims')
            ->whereNotNull('eot_request_id')->whereNotIn('eot_request_id', $eotRequestIds ?: [0])->count();
        if ($danglingLeDelayLinks > 0) {
            $this->flagError("{$danglingLeDelayLinks} Loss & Expense claim(s) reference a delay_event_id that doesn't exist.");
        }
        if ($danglingLeEotLinks > 0) {
            $this->flagError("{$danglingLeEotLinks} Loss & Expense claim(s) reference an eot_request_id that doesn't exist.");
        }

        // payment_application_variations must point at real rows on both sides.
        $paymentApplicationIds = $this->db->table('payment_applications')->pluck('id')->all();
        $variationIds = $this->db->table('variations')->pluck('id')->all();
        $danglingPav = $this->db->table('payment_application_variations')
            ->whereNotIn('payment_application_id', $paymentApplicationIds ?: [0])
            ->orWhereNotIn('variation_id', $variationIds ?: [0])
            ->count();
        if ($danglingPav > 0) {
            $this->flagError("{$danglingPav} payment_application_variations row(s) reference a missing payment application or variation.");
        }

        // A variation flagged as included must actually have a link row, and vice versa.
        $includedVariations = $this->db->table('variations')->whereNotNull('included_in_pa_id')->pluck('id', 'included_in_pa_id');
        foreach ($includedVariations as $paymentApplicationId => $variationId) {
            $linked = $this->db->table('payment_application_variations')
                ->where('payment_application_id', $paymentApplicationId)->where('variation_id', $variationId)->exists();
            if (! $linked) {
                $this->flagError("Variation {$variationId} has included_in_pa_id={$paymentApplicationId} but no matching payment_application_variations row.");
            }
        }
    }

    /** Dates that should be in order but aren't. */
    private function checkChronology(): void
    {
        $projects = $this->db->table('projects')->get();
        foreach ($projects as $project) {
            if ($project->start_date && $project->end_date && $project->start_date > $project->end_date) {
                $this->flagError("Project '{$project->name}': start_date ({$project->start_date}) is after end_date ({$project->end_date}).");
            }
            if ($project->practical_completion_date && $project->start_date && $project->practical_completion_date < $project->start_date) {
                $this->flagError("Project '{$project->name}': practical_completion_date is before start_date.");
            }
        }

        $contracts = $this->db->table('contracts')->get();
        foreach ($contracts as $contract) {
            if ($contract->commencement_date && $contract->completion_date && $contract->commencement_date > $contract->completion_date) {
                $this->flagError("Contract '{$contract->title}': commencement_date is after completion_date.");
            }
            if ($contract->execution_date && $contract->commencement_date && $contract->execution_date > $contract->commencement_date) {
                $this->flagWarning("Contract '{$contract->title}': executed after commencement — unusual but not necessarily wrong.");
            }
        }

        $tradePackages = $this->db->table('trade_packages')->get();
        foreach ($tradePackages as $package) {
            if ($package->award_date && $package->commencement_date && $package->award_date > $package->commencement_date) {
                $this->flagError("Trade package '{$package->name}' (project {$package->project_id}): award_date is after commencement_date.");
            }
            if ($package->commencement_date && $package->completion_date && $package->status === 'completed' && $package->commencement_date > $package->completion_date) {
                $this->flagError("Trade package '{$package->name}' (project {$package->project_id}): commencement_date is after completion_date.");
            }
        }

        $applications = $this->db->table('payment_applications')->get();
        foreach ($applications as $application) {
            if ($application->due_date && $application->application_date > $application->due_date) {
                $this->flagError("Payment Application {$application->application_number} (contract {$application->contract_id}): application_date is after due_date.");
            }
            if ($application->due_date && $application->final_date_for_payment && $application->due_date > $application->final_date_for_payment) {
                $this->flagError("Payment Application {$application->application_number} (contract {$application->contract_id}): due_date is after final_date_for_payment.");
            }
            if ($application->status === 'paid' && ! $application->paid_amount) {
                $this->flagError("Payment Application {$application->application_number} (contract {$application->contract_id}): status is 'paid' but paid_amount is empty.");
            }
        }

        $rfis = $this->db->table('rfis')->get();
        foreach ($rfis as $rfi) {
            if ($rfi->raised_date && $rfi->response_due_date && $rfi->raised_date > $rfi->response_due_date) {
                $this->flagError("RFI {$rfi->rfi_number} (project {$rfi->project_id}): raised_date is after response_due_date.");
            }
            if ($rfi->responded_at && $rfi->raised_date && $rfi->responded_at < $rfi->raised_date) {
                $this->flagError("RFI {$rfi->rfi_number} (project {$rfi->project_id}): responded_at is before raised_date.");
            }
        }
    }

    /** Programme milestones whose status and dates disagree with each other. */
    private function checkProgrammeDates(): void
    {
        $milestones = $this->db->table('contract_programme_milestones')->get();
        foreach ($milestones as $milestone) {
            if ($milestone->status === 'complete' && ! $milestone->actual_date) {
                $this->flagError("Milestone '{$milestone->name}' (project {$milestone->project_id}): status is 'complete' but actual_date is empty.");
            }
            if ($milestone->status === 'not_started' && $milestone->actual_date) {
                $this->flagError("Milestone '{$milestone->name}' (project {$milestone->project_id}): status is 'not_started' but actual_date is set.");
            }
            if ($milestone->actual_date && $milestone->planned_date && $milestone->actual_date < '1900-01-01') {
                $this->flagError("Milestone '{$milestone->name}' (project {$milestone->project_id}): actual_date looks invalid.");
            }
        }
    }

    /** Commercial records whose state contradicts their own linked records. */
    private function checkCommercialChains(): void
    {
        $finalAccounts = $this->db->table('final_accounts')->get();
        $lockedStatuses = ['agreed', 'signed', 'final_certificate_issued', 'commercially_closed'];

        foreach ($finalAccounts as $finalAccount) {
            $isLocked = in_array($finalAccount->status, $lockedStatuses, true);

            if ($isLocked && $finalAccount->original_contract_sum === null) {
                $this->flagError("Final Account '{$finalAccount->reference}' (project {$finalAccount->project_id}): status '{$finalAccount->status}' is locked but original_contract_sum is empty.");
            }
            if (! $isLocked && $finalAccount->status !== 'draft' && $finalAccount->original_contract_sum !== null) {
                $this->flagWarning("Final Account '{$finalAccount->reference}' (project {$finalAccount->project_id}): status '{$finalAccount->status}' but snapshot columns are already populated — check this is intentional.");
            }

            if ($isLocked) {
                $releasedSum = $this->db->table('retention_releases')->where('project_id', $finalAccount->project_id)->sum('release_amount');
                if ($finalAccount->retention_released !== null && abs($releasedSum - (float) $finalAccount->retention_released) > 0.01) {
                    $this->flagError("Final Account '{$finalAccount->reference}' (project {$finalAccount->project_id}): retention_released ({$finalAccount->retention_released}) doesn't match the sum of retention_releases rows ({$releasedSum}).");
                }
            }
        }

        // Adjudication cases closed/archived should have every step completed.
        $closedCases = $this->db->table('adjudication_cases')->whereIn('status', ['closed', 'archived'])->get();
        foreach ($closedCases as $case) {
            $incompleteSteps = $this->db->table('adjudication_steps')
                ->where('adjudication_case_id', $case->id)->where('status', '!=', 'completed')->count();
            if ($incompleteSteps > 0) {
                $this->flagError("Adjudication case '{$case->case_number}' is '{$case->status}' but has {$incompleteSteps} incomplete step(s).");
            }
        }
    }

    /** Documents that claim to be a real generated file must actually exist on disk. */
    private function checkGeneratedDocuments(): void
    {
        $generatedDocuments = $this->db->table('documents')
            ->where('file_path', 'like', '%/generated/%')
            ->get();

        $missing = 0;
        foreach ($generatedDocuments as $document) {
            if (! Storage::disk('local')->exists($document->file_path)) {
                $missing++;
                $this->flagError("Document '{$document->title}' (id {$document->id}) claims a generated file at '{$document->file_path}' but it doesn't exist on disk.");
            }
        }

        $placeholderCount = $this->db->table('documents')
            ->where('file_path', 'not like', '%/generated/%')
            ->count();
        if ($placeholderCount > 0) {
            $this->line("  ({$placeholderCount} document row(s) have a placeholder file_path with no real binary — expected, see internal-docs/demo-environment/index.md.)");
        }

        if ($generatedDocuments->count() > 0 && $missing === 0) {
            $this->line("  ({$generatedDocuments->count()} real generated document(s) all found on disk.)");
        }
    }

    /**
     * Portfolio-level checks that only make sense once there's more than
     * one project: every child record's organization_id must agree with
     * its own project's organization_id (a copy-paste seeder bug would
     * silently attach one project's data to the wrong organisation), and a
     * project whose contract is still unsigned (`draft`, no execution_date)
     * must not have any trade packages or payment applications — those can
     * only be genuine once a contract is actually in force.
     */
    private function checkPortfolioConsistency(): void
    {
        $projectOrganizations = $this->db->table('projects')->pluck('organization_id', 'id');

        foreach (['contracts', 'trade_packages', 'payment_applications', 'contract_risks', 'documents', 'appointments'] as $table) {
            if (! $this->db->getSchemaBuilder()->hasColumn($table, 'organization_id')
                || ! $this->db->getSchemaBuilder()->hasColumn($table, 'project_id')) {
                continue;
            }

            $rows = $this->db->table($table)->select('id', 'project_id', 'organization_id')
                ->whereNotNull('project_id')->whereNotNull('organization_id')->get();

            foreach ($rows as $row) {
                $expected = $projectOrganizations[$row->project_id] ?? null;
                if ($expected !== null && (int) $row->organization_id !== (int) $expected) {
                    $this->flagError("'{$table}' row {$row->id} has organization_id {$row->organization_id}, but its project ({$row->project_id}) belongs to organization {$expected}.");
                }
            }
        }

        $unsignedContracts = $this->db->table('contracts')
            ->where('status', 'draft')->whereNull('execution_date')->get(['id', 'project_id', 'title']);

        foreach ($unsignedContracts as $contract) {
            $tradePackageCount = $this->db->table('trade_packages')->where('project_id', $contract->project_id)->count();
            $paymentApplicationCount = $this->db->table('payment_applications')->where('project_id', $contract->project_id)->count();

            if ($tradePackageCount > 0) {
                $this->flagError("Contract '{$contract->title}' is unsigned (draft, no execution_date) but its project already has {$tradePackageCount} trade package(s) — procurement shouldn't exist before the contract is in force.");
            }
            if ($paymentApplicationCount > 0) {
                $this->flagError("Contract '{$contract->title}' is unsigned (draft, no execution_date) but its project already has {$paymentApplicationCount} payment application(s).");
            }
        }
    }

    /**
     * Not pass/fail — a plain-English summary of which currently-seeded
     * business conditions across the whole portfolio genuinely warrant a
     * user's attention right now, evaluated against the environment's own
     * anchor date (DemoClock::anchorDate() — "today" as far as the story
     * is concerned), not real wall-clock time. This is deliberate: every
     * Story class was authored against that fixed point, so a screenshot
     * or a demo viewed at any real point in time should report the same
     * signals — Aldermere's overdue payment is *always* overdue relative
     * to the story, not just until enough real days pass. This is the
     * "does the demo environment's overall business state make sense"
     * audit: it doesn't know which project is supposed to be under
     * pressure, it just reports what the authored data actually says. If
     * Aldermere Distribution Centre ever stops showing up here, something
     * has gone wrong with its story or its seeding.
     */
    private function reportBusinessSignals(): void
    {
        $today = DemoClock::anchorDate()->toDateString();
        $signals = [];

        $overdueApplications = $this->db->table('payment_applications as pa')
            ->join('projects as p', 'p.id', '=', 'pa.project_id')
            ->where('pa.final_date_for_payment', '<', $today)
            ->whereNotIn('pa.status', ['paid', 'withdrawn'])
            ->whereNotExists(function ($query) {
                $query->select('id')->from('pay_less_notices')
                    ->whereColumn('pay_less_notices.payment_application_id', 'pa.id');
            })
            ->get(['p.name as project_name', 'pa.application_number', 'pa.final_date_for_payment']);

        foreach ($overdueApplications as $row) {
            $signals[] = "{$row->project_name}: Payment Application {$row->application_number} is overdue (final date for payment {$row->final_date_for_payment}, no Pay Less Notice issued).";
        }

        $overdueRfis = $this->db->table('rfis as r')
            ->join('projects as p', 'p.id', '=', 'r.project_id')
            ->whereIn('r.status', ['open', 'pending_response'])
            ->where('r.response_due_date', '<', $today)
            ->get(['p.name as project_name', 'r.rfi_number', 'r.response_due_date']);

        foreach ($overdueRfis as $row) {
            $signals[] = "{$row->project_name}: RFI {$row->rfi_number} is past its response window (due {$row->response_due_date}).";
        }

        $urgentOpenRisks = $this->db->table('contract_risks as cr')
            ->join('projects as p', 'p.id', '=', 'cr.project_id')
            ->where('cr.status', 'open')->where('cr.urgency', 'act_now')
            ->get(['p.name as project_name', 'cr.title']);

        foreach ($urgentOpenRisks as $row) {
            $signals[] = "{$row->project_name}: open risk requiring immediate action — \"{$row->title}\".";
        }

        $overdueEots = $this->db->table('eot_requests as e')
            ->join('projects as p', 'p.id', '=', 'e.project_id')
            ->where('e.status', 'under_review')
            ->where('e.notice_date', '<', DemoClock::anchorDate()->subWeeks(6)->toDateString())
            ->get(['p.name as project_name', 'e.eot_number', 'e.notice_date']);

        foreach ($overdueEots as $row) {
            $signals[] = "{$row->project_name}: EOT request {$row->eot_number} has been under review for over 6 weeks (submitted {$row->notice_date}) with no decision.";
        }

        $this->line('<fg=cyan>Business signals (informational — not errors)</>');
        if (! $signals) {
            $this->line('  None — nothing across the portfolio currently warrants attention.');

            return;
        }

        foreach ($signals as $signal) {
            $this->line("  <fg=magenta>●</> {$signal}");
        }
    }
}
