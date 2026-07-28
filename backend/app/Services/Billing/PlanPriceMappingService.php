<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\PricingPlan;
use App\Models\PricingPlanProviderPrice;
use App\Models\User;
use App\Services\Billing\Exceptions\PlanPriceMappingException;
use App\Support\Billing\Money;
use Illuminate\Support\Facades\DB;

/**
 * Maps a SureSign Pricing Plan to a provider Product and one or more
 * provider Prices — one per billing interval/currency. SureSign owns the
 * plan's commercial definition (name, description, decimal display price);
 * the provider Product/Price exist solely so a Checkout Session (a later
 * checkpoint) has something to sell, and their metadata supports
 * reconciliation only — never the reverse.
 *
 * Stripe Prices are immutable for amount/currency (a real Stripe API
 * constraint, not a SureSign convention): every commercial price change
 * creates a NEW provider Price and supersedes the old local mapping
 * in-place, never mutating an existing Price's amount. The superseded
 * mapping row is kept, not deleted — an existing subscription may still
 * reference its provider_price_id by ID indefinitely (the historical-
 * pricing-protection guarantee from the Phase 0 architecture report).
 */
class PlanPriceMappingService
{
    private const SUPPORTED_INTERVALS = ['monthly', 'annual'];

    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly BillingProviderManager $providerManager,
    ) {
    }

    /**
     * The mapping a new checkout session should use for this plan/interval/
     * currency right now, in the currently-configured Stripe mode — or null
     * if the plan has never been synced for this combination. Never calls
     * the provider; reads local state only.
     */
    public function resolveActivePrice(PricingPlan $plan, string $billingInterval, string $currency): ?PricingPlanProviderPrice
    {
        $this->assertSupportedInterval($billingInterval);
        $currency = $this->normalizeCurrency($currency);

        $matches = PricingPlanProviderPrice::query()
            ->where('pricing_plan_id', $plan->id)
            ->where('provider', $this->providerManager->configuredProvider())
            ->where('billing_interval', $billingInterval)
            ->where('currency', $currency)
            ->active()
            ->forLivemode($this->provider->isLivemode())
            ->latest('id')
            ->get();

        // No unique DB constraint enforces "at most one active mapping per
        // plan/interval/currency/mode" (only (provider, provider_price_id)
        // is unique) — syncPlanPrice()'s own supersede-before-create flow
        // never produces this, but a manual/out-of-band row could. Fail
        // loudly rather than silently picking the most recent one, which
        // would be an unauditable, non-deterministic Checkout input.
        if ($matches->count() > 1) {
            throw new PlanPriceMappingException(
                "Multiple active provider price mappings exist for plan {$plan->id} ({$billingInterval}, {$currency}) — resolve the duplicate manually before Checkout can proceed."
            );
        }

        return $matches->first();
    }

    /**
     * Ensures a plan/interval/currency has a valid, active provider Price
     * mapping reflecting the given major-unit amount (e.g. "29.99") —
     * creating the provider Product/Price the first time, reusing the
     * existing mapping unchanged when nothing commercially relevant has
     * changed, or superseding it with a brand-new immutable provider Price
     * when the amount has changed. Safe to call repeatedly with the same
     * amount: only the very first call (or a genuine amount change) ever
     * reaches the provider.
     *
     * @throws PlanPriceMappingException
     */
    public function syncPlanPrice(
        PricingPlan $plan,
        string $billingInterval,
        string $currency,
        string|int|float $majorUnitAmount,
        User $actor,
    ): PricingPlanProviderPrice {
        $this->assertPlanIsSyncable($plan);
        $this->assertSupportedInterval($billingInterval);
        $currency = $this->normalizeCurrency($currency);

        $unitAmount = Money::toMinorUnits($majorUnitAmount, $currency);
        $livemode = $this->provider->isLivemode();
        $provider = $this->providerManager->configuredProvider();

        $existing = PricingPlanProviderPrice::query()
            ->where('pricing_plan_id', $plan->id)
            ->where('provider', $provider)
            ->where('billing_interval', $billingInterval)
            ->where('currency', $currency)
            ->active()
            ->forLivemode($livemode)
            ->latest('id')
            ->first();

        // Idempotent reuse: identical commercial terms, no provider call at all.
        if ($existing && $existing->unit_amount === $unitAmount) {
            return $existing;
        }

        return DB::transaction(function () use ($plan, $billingInterval, $currency, $unitAmount, $livemode, $provider, $existing, $actor) {
            $productId = $this->resolveOrCreateProductId($plan, $provider, $livemode);

            $price = $this->provider->createPrice([
                'product_id' => $productId,
                'unit_amount' => $unitAmount,
                'currency' => strtolower($currency),
                'recurring_interval' => $billingInterval === 'annual' ? 'year' : 'month',
                'metadata' => $this->reconciliationMetadata($plan),
                'idempotency_key' => "plan-price:{$plan->id}:{$billingInterval}:{$currency}:{$unitAmount}:{$livemode}",
            ]);

            $this->assertProviderResponseHasIdentifier($price, 'id');

            if ($existing) {
                $this->supersede($existing, $actor);
            }

            $mapping = PricingPlanProviderPrice::create([
                'pricing_plan_id' => $plan->id,
                'provider' => $provider,
                'billing_interval' => $billingInterval,
                'currency' => $currency,
                'provider_product_id' => $productId,
                'provider_price_id' => $price['id'],
                'livemode' => $livemode,
                'unit_amount' => $unitAmount,
                'is_active' => true,
                'effective_from' => now(),
            ]);

            $this->logChange(
                $existing ? 'plan_price_mapping.superseded' : 'plan_price_mapping.created',
                "Synced provider price for \"{$plan->name}\" ({$billingInterval}, {$currency})",
                $actor,
                $mapping,
            );

            return $mapping;
        });
    }

    /**
     * Explicitly retires a mapping without creating a replacement — e.g.
     * when a plan/interval/currency combination is being discontinued, not
     * repriced. The provider Price is deactivated (hidden from future
     * Checkout Sessions), never deleted — any subscription still
     * referencing it by ID is unaffected.
     */
    public function deactivateMapping(PricingPlanProviderPrice $mapping, User $actor): void
    {
        $this->assertLivemodeMatchesCurrentEnvironment($mapping);

        $this->supersede($mapping, $actor);
    }

    /**
     * On-demand diagnostic: confirms a local mapping's amount/currency/
     * product still agree with what the provider actually has stored,
     * without ever repairing a mismatch silently. Not called automatically
     * during normal sync — sync's idempotent-reuse path is deliberately
     * network-free; this is for an explicit Super Admin "verify" action
     * (a later checkpoint's UI concern, not built here).
     *
     * @throws PlanPriceMappingException
     */
    public function reconcileMapping(PricingPlanProviderPrice $mapping): void
    {
        $this->assertLivemodeMatchesCurrentEnvironment($mapping);

        $remote = $this->provider->retrievePrice($mapping->provider_price_id);

        if (!$remote) {
            throw new PlanPriceMappingException(
                "Provider price {$mapping->provider_price_id} for plan {$mapping->pricing_plan_id} no longer exists."
            );
        }

        if (
            $remote['unit_amount'] !== $mapping->unit_amount
            || strtoupper($remote['currency']) !== $mapping->currency
            || $remote['product_id'] !== $mapping->provider_product_id
        ) {
            throw new PlanPriceMappingException(
                "Provider price {$mapping->provider_price_id} no longer matches its local mapping for plan {$mapping->pricing_plan_id} — reconcile manually."
            );
        }
    }

    /**
     * Guards against a mapping resolved for one plan being applied against
     * another — e.g. a caller holding a raw provider_price_id and assuming
     * it belongs to a specific plan.
     *
     * @throws PlanPriceMappingException
     */
    public function assertMappingBelongsToPlan(PricingPlanProviderPrice $mapping, PricingPlan $plan): void
    {
        if ($mapping->pricing_plan_id !== $plan->id) {
            throw new PlanPriceMappingException(
                "Provider price mapping {$mapping->id} belongs to plan {$mapping->pricing_plan_id}, not plan {$plan->id}."
            );
        }
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function resolveOrCreateProductId(PricingPlan $plan, string $provider, bool $livemode): string
    {
        $existingProductId = PricingPlanProviderPrice::query()
            ->where('pricing_plan_id', $plan->id)
            ->where('provider', $provider)
            ->forLivemode($livemode)
            ->whereNotNull('provider_product_id')
            ->latest('id')
            ->value('provider_product_id');

        if ($existingProductId) {
            return $existingProductId;
        }

        $product = $this->provider->createProduct([
            'name' => $plan->name,
            'metadata' => $this->reconciliationMetadata($plan),
        ]);

        $this->assertProviderResponseHasIdentifier($product, 'id');

        return $product['id'];
    }

    /**
     * Stable reconciliation metadata only — never the authoritative plan
     * name/description (SureSign owns that; see class docblock).
     */
    private function reconciliationMetadata(PricingPlan $plan): array
    {
        return [
            'suresign_pricing_plan_id' => (string) $plan->id,
            'suresign_plan_code' => $plan->code,
            'suresign_source' => 'PlanPriceMappingService',
        ];
    }

    private function supersede(PricingPlanProviderPrice $mapping, User $actor): void
    {
        $this->provider->deactivatePrice($mapping->provider_price_id);

        $mapping->update([
            'is_active' => false,
            'effective_until' => now(),
        ]);

        $this->logChange(
            'plan_price_mapping.deactivated',
            "Deactivated provider price mapping for plan {$mapping->pricing_plan_id} ({$mapping->billing_interval}, {$mapping->currency})",
            $actor,
            $mapping,
        );
    }

    private function assertPlanIsSyncable(PricingPlan $plan): void
    {
        if ($plan->status === 'archived') {
            throw new PlanPriceMappingException("Pricing plan \"{$plan->name}\" is archived and cannot be synced to a provider price.");
        }
    }

    private function assertSupportedInterval(string $billingInterval): void
    {
        if (!in_array($billingInterval, self::SUPPORTED_INTERVALS, true)) {
            throw new PlanPriceMappingException("Unsupported billing interval: {$billingInterval}");
        }
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (strlen($currency) !== 3) {
            throw new PlanPriceMappingException("Invalid currency code: {$currency}");
        }

        return $currency;
    }

    private function assertLivemodeMatchesCurrentEnvironment(PricingPlanProviderPrice $mapping): void
    {
        if ($mapping->livemode !== $this->provider->isLivemode()) {
            $mappingMode = $mapping->livemode ? 'live' : 'test';
            $currentMode = $this->provider->isLivemode() ? 'live' : 'test';

            throw new PlanPriceMappingException(
                "Provider price mapping {$mapping->id} was created in {$mappingMode} mode but the current environment is {$currentMode} mode."
            );
        }
    }

    private function assertProviderResponseHasIdentifier(array $response, string $key): void
    {
        if (empty($response[$key])) {
            throw new PlanPriceMappingException("Provider response was missing required identifier \"{$key}\".");
        }
    }

    private function logChange(string $action, string $description, User $actor, PricingPlanProviderPrice $mapping): void
    {
        ActivityLog::record(
            action: $action,
            description: $description,
            user: $actor,
            subject: $mapping,
            meta: [
                'pricing_plan_id' => $mapping->pricing_plan_id,
                'provider' => $mapping->provider,
                'provider_price_id' => $mapping->provider_price_id,
                'billing_interval' => $mapping->billing_interval,
                'currency' => $mapping->currency,
                'unit_amount' => $mapping->unit_amount,
                'livemode' => $mapping->livemode,
            ],
        );
    }
}
