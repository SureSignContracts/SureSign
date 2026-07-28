<?php

namespace App\Console\Commands;

use App\Services\Billing\BillingPortalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Slice E2 — a manual/on-demand operational check, deliberately NOT
 * scheduled (matching `billing:subscriptions:check-integrity`'s own
 * reasoning: configuration drift is not time-critical the way webhook/
 * lifecycle automation is, and there is nothing to auto-repair here —
 * `BillingPortalService` itself already fails Portal Session creation
 * closed on drift; this command exists purely so an operator can check
 * that report on demand rather than only discovering it via a failed
 * customer request).
 */
class VerifyBillingPortalConfiguration extends Command
{
    protected $signature = 'billing:portal:verify-configuration';

    protected $description = 'Report whether the restricted Stripe Customer Portal configuration currently matches the approved capability policy';

    public function handle(BillingPortalService $portal): int
    {
        $result = $portal->verifyRestrictedConfiguration();

        $this->info("Configuration: {$result['configuration_id']} (" . ($result['reused'] ? 'existing' : 'newly created') . ')');
        $this->line('  payment_method_update: ' . ($result['features']['payment_method_update'] ? 'enabled' : 'disabled'));
        $this->line('  invoice_history: ' . ($result['features']['invoice_history'] ? 'enabled' : 'disabled'));
        $this->line('  customer_update: ' . ($result['features']['customer_update'] ? 'enabled' : 'disabled') . ' (allowed: ' . implode(', ', $result['features']['customer_update_allowed_fields'] ?: ['none']) . ')');
        $this->line('  subscription_cancel: ' . ($result['features']['subscription_cancel'] ? 'ENABLED — UNSAFE' : 'disabled'));
        $this->line('  subscription_update: ' . ($result['features']['subscription_update'] ? 'ENABLED — UNSAFE' : 'disabled'));

        if (!$result['safe']) {
            $this->error('UNSAFE — this configuration does not match the approved restricted capability policy. Portal Session creation will refuse to use it.');
            Log::critical('billing:portal:verify-configuration found an unsafe Portal configuration', $result);

            return self::FAILURE;
        }

        $this->info('Safe — matches the approved restricted capability policy.');

        return self::SUCCESS;
    }
}
