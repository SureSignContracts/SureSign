<?php

namespace App\Services\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCustomer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\Exceptions\BillingCustomerReconciliationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

/**
 * Owns the relationship between a SureSign Organisation and a provider
 * Customer. SureSign owns organisation identity, display name, primary
 * billing relationship, and internal commercial metadata; the provider
 * owns only its own Customer representation (and, separately, whatever
 * payment-method/tax data it manages that this service never touches). A
 * provider Customer must never create or define a SureSign Organisation —
 * this service only ever reads FROM an Organization, never the reverse.
 */
class BillingCustomerService
{
    public function __construct(
        private readonly BillingProviderInterface $provider,
        private readonly BillingProviderManager $providerManager,
    ) {
    }

    /**
     * The organisation's billing customer mapping for the given provider
     * (defaults to the configured provider) in the CURRENT Stripe mode —
     * or null if none exists yet. Read-only, never calls the provider.
     */
    public function findForOrganization(Organization $organization, ?string $provider = null): ?BillingCustomer
    {
        return BillingCustomer::query()
            ->where('organization_id', $organization->id)
            ->where('provider', $provider ?? $this->providerManager->configuredProvider())
            ->forLivemode($this->provider->isLivemode())
            ->first();
    }

    /**
     * Returns the organisation's existing billing customer mapping, or
     * creates one — idempotent and safe under concurrent calls for the
     * same organisation. A distributed lock (matching the pattern
     * SendDeadlineReminders already uses for "no row exists yet to lock")
     * serializes the check-then-create sequence across processes/hosts;
     * the DB's own unique constraint is the final backstop if two
     * processes ever race past the lock anyway.
     */
    public function getOrCreate(Organization $organization, User $actor): BillingCustomer
    {
        $provider = $this->providerManager->configuredProvider();
        $livemode = $this->provider->isLivemode();

        $lock = Cache::lock("billing-customer:{$organization->id}:{$provider}:" . ($livemode ? 'live' : 'test'), 10);

        return $lock->block(5, function () use ($organization, $provider, $livemode, $actor) {
            $existing = BillingCustomer::query()
                ->where('organization_id', $organization->id)
                ->where('provider', $provider)
                ->forLivemode($livemode)
                ->first();

            if ($existing) {
                return $existing;
            }

            $customer = $this->provider->createCustomer([
                'email' => $organization->email,
                'name' => $organization->name,
                'organization_id' => $organization->id,
                'metadata' => $this->reconciliationMetadata($organization),
            ]);

            if (empty($customer['id'])) {
                throw new BillingCustomerReconciliationException('Provider response was missing required identifier "id".');
            }

            try {
                $billingCustomer = BillingCustomer::create([
                    'organization_id' => $organization->id,
                    'provider' => $provider,
                    'provider_customer_id' => $customer['id'],
                    'livemode' => $livemode,
                    'billing_email' => $organization->email,
                    'billing_name' => $organization->name,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another process/host created it between our check and our
                // insert despite the lock — treat exactly like the existing
                // mapping was found in the first place.
                return BillingCustomer::query()
                    ->where('organization_id', $organization->id)
                    ->where('provider', $provider)
                    ->forLivemode($livemode)
                    ->firstOrFail();
            }

            $this->logChange('billing_customer.created', "Created billing customer for \"{$organization->name}\"", $actor, $billingCustomer);

            return $billingCustomer;
        });
    }

    /**
     * Pushes only the organisation fields SureSign actually owns and has a
     * non-null value for (display name, billing email) to the provider,
     * and only when they've actually changed — never overwrites a provider
     * field with null just because SureSign's own value is empty, and
     * never touches anything provider-managed (payment methods, tax
     * settings) this service doesn't own. A no-op call (nothing changed)
     * never reaches the provider.
     */
    public function syncOrganizationDetails(Organization $organization, User $actor): BillingCustomer
    {
        $billingCustomer = $this->findForOrganization($organization)
            ?? throw new BillingCustomerReconciliationException(
                "No billing customer exists for organisation {$organization->id} to synchronize."
            );

        $this->assertLivemodeMatches($billingCustomer);

        $changes = [];

        if ($organization->name !== null && $organization->name !== $billingCustomer->billing_name) {
            $changes['name'] = $organization->name;
        }

        if ($organization->email !== null && $organization->email !== $billingCustomer->billing_email) {
            $changes['email'] = $organization->email;
        }

        if (empty($changes)) {
            return $billingCustomer;
        }

        $this->provider->updateCustomer($billingCustomer->provider_customer_id, $changes);

        $billingCustomer->update(array_filter([
            'billing_name' => $changes['name'] ?? null,
            'billing_email' => $changes['email'] ?? null,
        ], fn ($value) => $value !== null));

        $this->logChange('billing_customer.synced', "Synchronized billing customer details for \"{$organization->name}\"", $actor, $billingCustomer, $changes);

        return $billingCustomer->fresh();
    }

    /**
     * Confirms the local mapping still resolves to a real, matching
     * provider customer.
     *
     * **Deliberate behaviour reversal (approved), replacing this method's
     * previous "safely auto-replace when no financial history exists"
     * policy**: once a BillingCustomer mapping has ever been established
     * for an organisation, a missing/deleted provider customer ALWAYS
     * throws and requires explicit, deliberate repair — never an
     * automatic replacement, even when no subscription or invoice
     * currently references it. A payment method may have existed against
     * that customer with no subscription yet; an incomplete Checkout may
     * still reference it; the provider's own identity for this
     * organisation should not change silently; and a missing provider
     * object may itself indicate an operational/account-level problem
     * worth a human noticing rather than papering over. The *only* place
     * this service is still allowed to create a provider customer without
     * hesitation is getOrCreate() — and only because, by definition, no
     * local mapping (and therefore nothing this method needs to protect)
     * exists yet in that case.
     *
     * Conflicting provider metadata (the remote customer's own SureSign
     * reconciliation metadata pointing at a different organisation) is,
     * as before, always rejected, never repaired.
     *
     * @throws BillingCustomerReconciliationException
     */
    public function reconcile(BillingCustomer $billingCustomer, User $actor): BillingCustomer
    {
        $this->assertLivemodeMatches($billingCustomer);

        $remote = $this->provider->retrieveCustomer($billingCustomer->provider_customer_id);

        if ($remote === null) {
            throw new BillingCustomerReconciliationException(
                "Billing customer {$billingCustomer->id} (organisation {$billingCustomer->organization_id}) "
                . "no longer resolves to a provider customer ({$billingCustomer->provider_customer_id}) — "
                . 'an established mapping is never automatically replaced; explicit reconciliation is required.'
            );
        }

        return $billingCustomer;
    }

    // ─── Private helpers ────────────────────────────────────────────────────

    private function reconciliationMetadata(Organization $organization): array
    {
        return [
            'suresign_organization_id' => (string) $organization->id,
            'suresign_organization_name' => $organization->name,
            'suresign_environment' => app()->environment(),
            'suresign_source' => 'BillingCustomerService',
        ];
    }

    private function assertLivemodeMatches(BillingCustomer $billingCustomer): void
    {
        if ($billingCustomer->livemode !== $this->provider->isLivemode()) {
            $mappingMode = $billingCustomer->livemode ? 'live' : 'test';
            $currentMode = $this->provider->isLivemode() ? 'live' : 'test';

            throw new BillingCustomerReconciliationException(
                "Billing customer {$billingCustomer->id} was created in {$mappingMode} mode but the current environment is {$currentMode} mode."
            );
        }
    }

    private function logChange(string $action, string $description, User $actor, ?BillingCustomer $subject, array $meta = []): void
    {
        ActivityLog::record(
            action: $action,
            description: $description,
            user: $actor,
            subject: $subject,
            meta: $meta ?: ($subject ? [
                'organization_id' => $subject->organization_id,
                'provider' => $subject->provider,
                'provider_customer_id' => $subject->provider_customer_id,
                'livemode' => $subject->livemode,
            ] : []),
        );
    }
}
