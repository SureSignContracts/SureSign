<?php

namespace App\Console\Commands;

use App\Models\OrganizationDomain;
use App\Services\Organizations\DomainVerificationService;
use App\Support\Organizations\DomainStatus;
use Illuminate\Console\Command;

/**
 * Organisation URL Branding, Phase 2 — manual/on-demand re-check of every
 * domain still awaiting DNS verification. Deliberately NOT scheduled (see
 * routes/console.php and StripeReconciliationService's own identical
 * precedent) — a domain's DNS state only changes when the customer
 * actually edits their own DNS, so a recurring schedule would just produce
 * noisy no-op runs; an operator (or the customer support flow) triggers
 * this when the customer says they've made the change.
 */
class VerifyPendingOrganizationDomains extends Command
{
    protected $signature = 'domains:verify-pending {--dry-run}';

    protected $description = 'Re-run DNS verification for every organisation domain still pending/awaiting DNS.';

    public function handle(DomainVerificationService $service): int
    {
        $domains = OrganizationDomain::whereIn('status', [DomainStatus::PENDING, DomainStatus::AWAITING_DNS])->get();

        if ($domains->isEmpty()) {
            $this->info('No domains awaiting verification.');

            return self::SUCCESS;
        }

        foreach ($domains as $domain) {
            if ($this->option('dry-run')) {
                $this->line("Would check: {$domain->hostname}");

                continue;
            }

            $verified = $service->verify($domain);
            $this->line(($verified ? '[verified] ' : '[still pending] ') . $domain->hostname);
        }

        return self::SUCCESS;
    }
}
