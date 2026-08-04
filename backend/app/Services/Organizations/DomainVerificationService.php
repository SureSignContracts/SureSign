<?php

namespace App\Services\Organizations;

use App\Models\Organization;
use App\Models\OrganizationDomain;
use App\Support\Organizations\BrandingCacheInvalidator;
use App\Support\Organizations\DomainStatus;
use Illuminate\Support\Str;

/**
 * Organisation URL Branding, Phase 2 — Bring Your Own Domain verification.
 *
 * Follows established industry practice (Vercel/Netlify/Shopify's own
 * approach) rather than inventing a proprietary scheme: a TXT record at a
 * dedicated, namespaced label proves ownership (never trusts the customer's
 * own claim alone), and a CNAME record proves the customer has actually
 * pointed traffic at SureSign. Both are required before a domain can ever
 * become `active`.
 *
 * Verification is MANUAL/ON-DEMAND only this phase — mirrors
 * `StripeReconciliationService`'s own "deliberately not scheduled"
 * precedent in this codebase. No cron/queue automatically re-checks a
 * pending domain; a Super Admin action (`POST .../domains/{domain}/verify`)
 * or the `domains:verify-pending` Artisan command triggers a real DNS
 * lookup. See that command's own docblock for why automatic scheduling
 * was deliberately not built this phase.
 *
 * Builds NO automatic SSL/certificate provisioning and makes no claim that
 * one exists — `verify()` only ever moves a domain to `verified`, never
 * `active`. Moving `verified` → `active` is a separate, explicit Super
 * Admin action (`activate()`), the documented point at which an operator
 * has confirmed the real production origin/certificate coverage is ready
 * (Cloudflare/Dokploy configuration — see internal-docs/super-admin/
 * organisation-url-branding.md) — this service has no way to know that
 * itself.
 */
class DomainVerificationService
{
    public function __construct(
        private readonly DnsRecordLookup $dns = new DnsRecordLookup(),
    ) {
    }

    /**
     * Format/reserved/uniqueness validation lives in
     * StoreOrganizationDomainRequest — this method assumes $hostname has
     * already passed that and is normalised (lowercase, trimmed).
     */
    public function initiate(Organization $organization, string $hostname): OrganizationDomain
    {
        return $organization->domains()->create([
            'hostname' => $hostname,
            'status' => DomainStatus::PENDING,
            'verification_token' => Str::random(40),
            'verification_method' => 'txt',
        ]);
    }

    /**
     * Performs a real DNS lookup — never fails the caller (mirrors
     * AiCreditSimulator's own "a single failure can never break the
     * caller's flow" contract). Returns true only when BOTH the TXT
     * ownership record and the CNAME routing record are found and correct;
     * always records what was actually found in `last_check_result`,
     * never a guess.
     */
    public function verify(OrganizationDomain $domain): bool
    {
        $txtOk = false;
        $cnameOk = false;
        $result = [];

        try {
            $txtOk = $this->checkTxtRecord($domain);
            $result[] = $txtOk ? 'txt_ok' : 'txt_missing_or_mismatched';
        } catch (\Throwable $e) {
            $result[] = 'txt_lookup_failed';
        }

        try {
            $cnameOk = $this->checkCnameRecord($domain);
            $result[] = $cnameOk ? 'cname_ok' : 'cname_missing_or_mismatched';
        } catch (\Throwable $e) {
            $result[] = 'cname_lookup_failed';
        }

        $verified = $txtOk && $cnameOk;

        $domain->update([
            'status' => $verified ? DomainStatus::VERIFIED : DomainStatus::AWAITING_DNS,
            'last_checked_at' => now(),
            'last_check_result' => implode(',', $result),
            'verified_at' => $verified ? ($domain->verified_at ?? now()) : $domain->verified_at,
        ]);

        return $verified;
    }

    private function checkTxtRecord(OrganizationDomain $domain): bool
    {
        $prefix = config('organisation_branding.verification_txt_prefix');
        $host = "{$prefix}.{$domain->hostname}";
        $records = $this->dns->txt($host);
        $expected = "suresign-domain-verify={$domain->verification_token}";

        foreach ($records as $record) {
            if (($record['txt'] ?? null) === $expected) {
                return true;
            }
        }

        return false;
    }

    private function checkCnameRecord(OrganizationDomain $domain): bool
    {
        $target = config('organisation_branding.cname_target');
        if (! $target) {
            return false;
        }

        $records = $this->dns->cname($domain->hostname);
        $target = strtolower(rtrim($target, '.'));

        foreach ($records as $record) {
            if (strtolower(rtrim($record['target'] ?? '', '.')) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * Explicit Super Admin action — see this class's own docblock for why
     * this is never automatic.
     */
    public function activate(OrganizationDomain $domain): void
    {
        $domain->update(['status' => DomainStatus::ACTIVE, 'activated_at' => now()]);
        BrandingCacheInvalidator::forgetForOrganization($domain->organization, $domain->hostname);
    }

    public function disable(OrganizationDomain $domain): void
    {
        $domain->update(['status' => DomainStatus::DISABLED, 'disabled_at' => now()]);
        BrandingCacheInvalidator::forgetForOrganization($domain->organization, $domain->hostname);
    }

    /** Reverses disable() — back to `verified` (never straight to `active`; re-activation is its own explicit step). */
    public function reactivate(OrganizationDomain $domain): void
    {
        $domain->update(['status' => DomainStatus::VERIFIED]);
        BrandingCacheInvalidator::forgetForOrganization($domain->organization, $domain->hostname);
    }

    public function remove(OrganizationDomain $domain): void
    {
        $domain->update(['status' => DomainStatus::REMOVED, 'removed_at' => now()]);
        BrandingCacheInvalidator::forgetForOrganization($domain->organization, $domain->hostname);
    }
}
