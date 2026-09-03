<?php

namespace App\Services\FridayPack;

use App\Models\Contract;
use App\Models\Project;

/**
 * Friday Pack Realignment, R1D — Report Information. Resolves the party/
 * contract-identity fields that need real investigation before a value is
 * trusted; the caller (FridayPackSnapshotService) supplies everything else
 * (project name/code, site address, period dates, generated_by/at, report
 * number — all trivially available from Project/FridayPack directly).
 *
 * **Every method here fails to `null` rather than guesses** — see the R1D
 * checkpoint's own audit: no field resolved by this class has a single,
 * always-reliable source in the existing data model. A `null` result is
 * presented to the customer as "Not recorded," never a fabricated value.
 */
class FridayPackReportInformationService
{
    /**
     * The full set of party/identity fields this class resolves, ready to
     * be merged with the week-date/prepared-by/report-number fields the
     * caller (FridayPackSnapshotService) already has directly from
     * Project/FridayPack. `site_address` is Project's own site location
     * (Phase F/2 fields — the same ones geocoded for the map pin), never
     * an Organisation registered address.
     */
    public function projectAndPartyFields(Project $project): array
    {
        return [
            'project_name' => $project->name,
            'project_code' => $project->code,
            'site_address' => [
                'address'  => $project->address,
                'city'     => $project->city,
                'state'    => $project->state,
                'postcode' => $project->postcode,
                'country'  => $project->country,
            ],
            'principal_contractor'         => $this->principalContractor($project),
            'reporting_organisation_name'  => $project->organization?->name,
            'organization_role'            => $project->organization_role,
            'sub_contract_order_no'        => $this->subContractOrderNo($project),
            'scope_of_works'               => $this->scopeOfWorks($project),
        ];
    }

    /**
     * Principal Contractor — R1D checkpoint: use `Contract::$principal_contractor`
     * only from a Contract source whose relevance is unambiguous, never
     * inferred from `Organization::$name`/`Contract::$party_name`/
     * `TradePackage`. "Unambiguous" is defined here as: exactly one
     * eligible Contract for this Project. Eligibility mirrors the existing
     * `FridayPackSnapshotService::projectIdentity()`/`ProjectContractSetupSyncService`
     * precedent of treating a `type = main_contract` Contract as the
     * Project's own governing contract (Principal Contractor, a CDM 2015
     * term, is a property of the primary/main contract relationship, not
     * a subcontract/consultant-appointment/supplier-agreement) — never a
     * new eligibility concept invented for this field alone. `deleted_at`
     * exclusion is Eloquent's own default soft-delete scope (Contract uses
     * SoftDeletes) — no explicit query needed. `status != 'terminated'`
     * excludes the one status value that clearly signals the relationship
     * no longer applies; `draft`/`active`/`expired`/`complete` all still
     * count (no existing convention filters more narrowly than this for
     * "the project's own contract").
     *
     * Zero eligible Contracts, more than one, or the single eligible
     * Contract's own `principal_contractor` being null — all resolve to
     * `null`. Never a first()/latest()/highest-ID tie-breaker.
     */
    public function principalContractor(Project $project): ?string
    {
        $eligible = $this->eligibleContracts($project, 'main_contract');

        if ($eligible->count() !== 1) {
            return null;
        }

        return $eligible->first()->principal_contractor ?: null;
    }

    /**
     * Sub-Contract Order No. — R1D checkpoint's own explicit rule: use
     * `Contract::$reference_number` ONLY when exactly one eligible
     * `type = subcontract` Contract exists for this Project with a
     * non-empty `reference_number`. `TradePackage::$package_reference` is
     * deliberately never used (checkpoint explicitly rejected it — a
     * Project may have several TradePackages, so there is no single
     * canonical one). Zero eligible Contracts, more than one, or a
     * missing/empty reference on the single eligible Contract — all
     * resolve to `null`.
     */
    public function subContractOrderNo(Project $project): ?string
    {
        $eligible = $this->eligibleContracts($project, 'subcontract')
            ->filter(fn (Contract $c) => filled($c->reference_number));

        if ($eligible->count() !== 1) {
            return null;
        }

        return $eligible->first()->reference_number;
    }

    /**
     * Scope of Works — R1D checkpoint's own decision: the audit found
     * three plausible free-text candidates (`Project::$description`,
     * `Contract::$notes`, `TradePackage::$description`), none of them
     * purpose-built or provably authoritative for this meaning. Rather
     * than silently picking one, R1D deliberately leaves this `null`
     * ("Not recorded") — no Friday-Pack-only override field is introduced
     * this phase; a future Project/Contract configuration-level decision
     * may resolve this properly.
     */
    public function scopeOfWorks(Project $project): ?string
    {
        return null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Contract>
     */
    private function eligibleContracts(Project $project, string $type)
    {
        return $project->contracts()
            ->where('type', $type)
            ->where('status', '!=', 'terminated')
            ->get();
    }
}
