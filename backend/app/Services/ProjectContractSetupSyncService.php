<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Project;
use App\Services\Geocoding\ProjectGeocodingService;
use App\Support\Projects\ProjectContractSuggestionKeys;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase E — Contract-Assisted Project Setup: the one-way, deterministic
 * bridge from an already-CONFIRMED Contract to a small, whitelisted set of
 * Project-summary fields. Deliberately narrow and deliberately downstream
 * of the authoritative Contract confirmation pipeline — this service:
 *
 *   - NEVER calls the AI provider (no analysis exists here that hasn't
 *     already run; every value this service reads was already extracted,
 *     reviewed, and confirmed before this service is ever invoked);
 *   - NEVER calls `ContractIntelligenceSyncService` (that service owns
 *     Contract-side sync exclusively; this service only ever reads from
 *     `Contract`/`ContractAiAnalysis`, never writes to either);
 *   - NEVER mutates `Contract`, `ContractAiAnalysis`, or
 *     `confirmed_data_json` — Phase E is one-way, Contract → Project,
 *     never the reverse;
 *   - NEVER creates/links `App\Models\Client`, never touches
 *     `Project::$client_id`;
 *   - NEVER derives `Project::$organization_role` from `Contract::$type`
 *     or `contract_overview.is_subcontract` alone — see
 *     `roleSuggestion()`'s own docblock for the identity-led rule this
 *     enforces instead;
 *   - NEVER trusts a value the frontend proposes — `apply()` always
 *     recomputes suggestions itself from the confirmed source before
 *     writing anything, so a caller can only submit which *keys* to
 *     apply, never raw values;
 *   - NEVER derives Project Location from `latitude`/`longitude` itself, and
 *     the AI provider NEVER generates or looks up a coordinate value —
 *     `PROJECT_LOCATION`'s textual fields (`address`/`city`/`state`/
 *     `postcode`/`country`) always come from confirmed data alone.
 *     Coordinates ARE now (Phase 2) written, but only ever as the direct,
 *     validated output of `ProjectGeocodingService`'s deterministic
 *     Geoapify lookup — never invented, never AI-derived, never guessed;
 *   - **Stale-coordinate safety.** Whenever applying `PROJECT_LOCATION`
 *     genuinely changes the Project's textual location (i.e. it wasn't
 *     already matching), `Project::$latitude`/`$longitude` are always
 *     recomputed in the same update: replaced with a fresh, validated
 *     Geoapify match if one is found, or cleared to null otherwise — never
 *     left pointing at the OLD address's site either way. When the applied
 *     location already matches the Project's existing one, textual fields
 *     and (if already valid) coordinates are left untouched, UNLESS the
 *     map pin is specifically missing/incomplete, in which case an
 *     explicit geocode-only action is available (Part 20/21 — see
 *     `projectLocationSuggestion()`'s own docblock for `map_pin_status`);
 *   - **Provider-failure atomicity.** A `GeocodingProviderException` (auth,
 *     timeout, rate limit, 5xx, malformed response — never "no reliable
 *     match," which is a normal successful outcome) is thrown from inside
 *     `apply()`'s selection loop, before any `Project` field has been
 *     written and before the transaction opens — it propagates straight out
 *     of `apply()` uncaught, so if `project_location` was selected
 *     alongside any other suggestion in the same request, NONE of them
 *     persist. `ProjectContractSetupController` is responsible for
 *     catching it and returning a safe, generic error.
 *
 * Reads prefer structured `Contract` columns where — and only where —
 * they reliably distinguish "genuinely confirmed" from "never touched":
 * `contract_sum`, `commencement_date`, `completion_date`, and
 * `form_of_contract` are all genuinely nullable at the database level with
 * no default, so a non-null value there really did come from a real
 * confirm. `Contract::$currency` (DB default `'AUD'`, NOT NULL) and
 * `Contract::$retention_percentage`/`$retention_cap_percentage` (DB
 * default `0`, NOT NULL) do NOT have that property — every Contract
 * created without an explicit value already has a real, non-null,
 * non-empty value in these two columns the moment it's created, and
 * `ContractIntelligenceSyncService`'s own `$should()` guard
 * (`empty($current)`) then treats that default as "already set,"
 * permanently blocking the real confirmed value from ever being written
 * there outside an explicit `force_overwrite`. So for currency and
 * retention specifically, this service reads `confirmed_data_json`
 * directly instead of the Contract column — confirmed by direct
 * inspection of the contracts table migration and
 * `ContractIntelligenceSyncService::applyV2Fields()`/`applyV1Fields()`,
 * not assumed. `confirmed_data_json` is also the sole source for Project
 * Organization Role identity matching, where no single structured Contract
 * column reliably represents "which named party is the Main Contractor"
 * vs "which is the Subcontractor" (see `roleSuggestion()`).
 */
class ProjectContractSetupSyncService
{
    public function __construct(
        private readonly ProjectGeocodingService $geocoding,
    ) {
    }

    /**
     * Build the current suggestion set for one confirmed Contract analysis.
     * Purely a read — never writes anything. Only entries with an actual
     * confirmed source are returned; nothing "unavailable" is included.
     */
    public function suggestions(Project $project, Contract $contract, ContractAiAnalysis $analysis): array
    {
        $rows = [];

        if ($row = $this->moneySuggestion($project, $contract, $analysis)) {
            $rows[] = $row;
        }
        if ($row = $this->dateSuggestion($project, $contract, 'start_date', 'commencement_date', 'Commencement Date')) {
            $rows[] = $row;
        }
        if ($row = $this->dateSuggestion($project, $contract, 'end_date', 'completion_date', 'Completion Date')) {
            $rows[] = $row;
        }
        if ($row = $this->contractTypeSuggestion($project, $contract)) {
            $rows[] = $row;
        }
        if ($row = $this->retentionSuggestion($project, $contract, $analysis)) {
            $rows[] = $row;
        }
        if ($row = $this->roleSuggestion($project, $contract, $analysis)) {
            $rows[] = $row;
        }
        if ($row = $this->projectLocationSuggestion($project, $contract, $analysis)) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Apply exactly the selected, currently-available, non-already-matching
     * suggestion keys. Recomputes every value server-side from the
     * confirmed source — `$selectedKeys` is a set of keys only, never raw
     * values. Runs inside one transaction so a multi-field selection is
     * applied atomically (see the date-consistency check below, which can
     * still reject the whole apply after date fields are otherwise valid).
     *
     * @return array{project: Project, applied: string[], project_location_result: ?array{textual_location_applied: bool, map_position: string}}
     * @throws \App\Services\Geocoding\GeocodingProviderException on a
     *   geocoding provider/system failure while project_location is being
     *   processed — propagates uncaught; see class docblock's
     *   provider-failure atomicity note. Never thrown for "no reliable
     *   match," which is a normal, successful outcome.
     */
    public function apply(Project $project, Contract $contract, ContractAiAnalysis $analysis, array $selectedKeys): array
    {
        $selectedKeys = array_values(array_unique(array_filter(
            $selectedKeys,
            fn ($k) => is_string($k) && ProjectContractSuggestionKeys::isValid($k)
        )));

        $available = collect($this->suggestions($project, $contract, $analysis))
            ->keyBy('key');

        $updates = [];
        $applied = [];
        $projectLocationResult = null;

        foreach ($selectedKeys as $key) {
            $row = $available->get($key);
            if (!$row) {
                continue;
            }

            $isProjectLocation = $key === ProjectContractSuggestionKeys::PROJECT_LOCATION;

            // Not currently available, or already matches — nothing to do.
            // Silently skipped rather than an error: re-selecting a row
            // that has since started matching (e.g. the user already
            // edited the Project manually) is not a failure. PROJECT_LOCATION
            // is the one exception (Part 20/21): matching text with a
            // missing/incomplete map pin still has real work to do (a
            // geocode-only action), so it's only skipped once BOTH the
            // text matches AND the pin is already valid.
            if ($isProjectLocation) {
                if ($row['already_matches'] && ($row['map_pin_status'] ?? null) === 'set') {
                    continue;
                }
            } elseif ($row['already_matches']) {
                continue;
            }

            switch ($key) {
                case ProjectContractSuggestionKeys::CONTRACT_VALUE_CURRENCY:
                    $updates['contract_value'] = $row['suggested']['value'];
                    $updates['currency']       = $row['suggested']['currency'];
                    break;
                case ProjectContractSuggestionKeys::START_DATE:
                    $updates['start_date'] = $row['suggested']['value'];
                    break;
                case ProjectContractSuggestionKeys::END_DATE:
                    $updates['end_date'] = $row['suggested']['value'];
                    break;
                case ProjectContractSuggestionKeys::CONTRACT_TYPE:
                    $updates['contract_type'] = $row['suggested']['value'];
                    break;
                case ProjectContractSuggestionKeys::RETENTION_PERCENTAGE:
                    $updates['retention_percentage'] = $row['suggested']['value'];
                    break;
                case ProjectContractSuggestionKeys::ORGANIZATION_ROLE:
                    $updates['organization_role'] = $row['suggested']['value'];
                    break;
                case ProjectContractSuggestionKeys::PROJECT_LOCATION:
                    // Wholesale replace, not a per-field merge — the user
                    // reviewed and applied the confirmed location exactly as
                    // displayed (Current vs Suggested), so every component
                    // is set to precisely what was shown, including null for
                    // any component the confirmed Contract didn't state.
                    // Mixing old and new components with no visible rule for
                    // which wins would be more confusing than predictable.
                    // Skipped entirely when text already matches (Part
                    // 20/21's geocode-only action) — the textual fields are
                    // never touched in that case.
                    $textAlreadyMatches = $row['already_matches'];
                    $location = $row['suggested']['location'] ?? [];
                    $textChanged = false;

                    if (!$textAlreadyMatches) {
                        $updates['address']  = $location['address']  ?? null;
                        $updates['city']     = $location['city']     ?? null;
                        $updates['state']    = $location['state']    ?? null;
                        $updates['postcode'] = $location['postcode'] ?? null;
                        $updates['country']  = $location['country']  ?? null;
                        $textChanged = true;
                    }

                    // Geocode OUTSIDE any DB transaction (Part 13) — a
                    // GeocodingProviderException here propagates straight
                    // out of apply() uncaught, before $project->update() has
                    // ever been called and before DB::transaction() below is
                    // ever reached, so nothing selected in this request
                    // persists (see class docblock's provider-failure
                    // atomicity note). ProjectContractSetupController is
                    // responsible for catching it and returning a safe error.
                    $outcome = $this->geocoding->resolve($location);

                    if ($outcome->isMatched()) {
                        $updates['latitude']  = $outcome->latitude;
                        $updates['longitude'] = $outcome->longitude;
                        $projectLocationResult = ['textual_location_applied' => $textChanged, 'map_position' => 'updated'];
                    } elseif ($textChanged) {
                        // Stale-coordinate safety: the text is changing
                        // regardless of geocoding, so any old coordinates
                        // must never survive attached to the new address —
                        // cleared even though no fresh reliable match was
                        // found (Part 15).
                        $updates['latitude']  = null;
                        $updates['longitude'] = null;
                        $projectLocationResult = ['textual_location_applied' => true, 'map_position' => 'not_found'];
                    } else {
                        // Geocode-only action (Part 20/21) with no reliable
                        // match — the Project doesn't actually change at all
                        // (coordinates were already missing/null and stay
                        // that way), so this is deliberately NOT marked
                        // applied and creates no activity entry (Part 21:
                        // "do not create a misleading successful
                        // field-change activity if no actual Project data
                        // changed") — only the result is reported so the
                        // frontend can still show the warning.
                        $projectLocationResult = ['textual_location_applied' => false, 'map_position' => 'not_found'];
                        continue 2;
                    }
                    break;
                default:
                    continue 2; // unrecognised key — never mark as applied
            }

            $applied[] = $key;
        }

        if (empty($updates)) {
            return ['project' => $project, 'applied' => [], 'project_location_result' => $projectLocationResult];
        }

        // Date-consistency check (Part 10) — considers the resulting state,
        // not just the two selected dates in isolation: a selected start
        // date must never end up later than whichever end date will be in
        // effect (either a selected one, or the Project's own existing one
        // if end_date wasn't selected this time), and vice versa.
        $resultingStart = $updates['start_date'] ?? $project->start_date?->toDateString();
        $resultingEnd   = $updates['end_date']   ?? $project->end_date?->toDateString();
        if ($resultingStart && $resultingEnd && Carbon::parse($resultingEnd)->lt(Carbon::parse($resultingStart))) {
            throw new ProjectContractSuggestionValidationException(
                'The completion date suggested from this Contract would be earlier than the resulting start date. Nothing was applied.'
            );
        }

        DB::transaction(function () use ($project, $updates) {
            $project->update($updates);
        });

        return ['project' => $project->refresh(), 'applied' => $applied, 'project_location_result' => $projectLocationResult];
    }

    // ── Individual suggestion builders ──────────────────────────────────────

    private function moneySuggestion(Project $project, Contract $contract, ContractAiAnalysis $analysis): ?array
    {
        if ($contract->contract_sum === null) {
            return null;
        }
        // Part 7 — a confirmed sum with no trustworthy confirmed currency is
        // never suggested; ambiguous money has no safe Project meaning.
        // Deliberately NOT read from Contract::$currency — see this class's
        // own docblock for why that column defaults to 'AUD' and therefore
        // can't reliably distinguish "genuinely confirmed" from "never
        // touched." The shape check mirrors ProjectController's own
        // `currency` rule (exactly 3 letters) rather than inventing a
        // stricter one.
        $currency = $this->confirmedCurrency($analysis);
        if (!$currency || !preg_match('/^[A-Za-z]{3}$/', $currency)) {
            return null;
        }
        $currency = strtoupper($currency);

        $currentValue    = $project->contract_value !== null ? (float) $project->contract_value : null;
        $currentCurrency = $project->resolved_currency ?: null;
        $suggestedValue  = (float) $contract->contract_sum;

        $matches = $currentValue !== null
            && abs($currentValue - $suggestedValue) < 0.005
            && $currentCurrency === $currency;

        return [
            'key'   => ProjectContractSuggestionKeys::CONTRACT_VALUE_CURRENCY,
            'label' => 'Contract Value & Currency',
            'current'   => ['value' => $currentValue, 'currency' => $currentCurrency],
            'suggested' => ['value' => $suggestedValue, 'currency' => $currency],
            'already_matches'  => $matches,
            'default_selected' => !$matches && $currentValue === null,
        ];
    }

    private function dateSuggestion(Project $project, Contract $contract, string $projectField, string $contractField, string $label): ?array
    {
        $suggestedDate = $contract->{$contractField};
        if (!$suggestedDate) {
            return null;
        }
        $suggestedStr = $suggestedDate->toDateString();
        $currentDate  = $project->{$projectField};
        $currentStr   = $currentDate?->toDateString();

        $matches = $currentStr === $suggestedStr;

        return [
            'key'   => $projectField, // 'start_date' / 'end_date' — already-valid whitelist keys
            'label' => $label,
            'current'   => ['value' => $currentStr],
            'suggested' => ['value' => $suggestedStr],
            'already_matches'  => $matches,
            'default_selected' => !$matches && $currentStr === null,
        ];
    }

    private function contractTypeSuggestion(Project $project, Contract $contract): ?array
    {
        // Project.contract_type is the form/standard classification (JCT,
        // NEC4, ...) — sourced from Contract.form_of_contract, NEVER from
        // Contract.type (main_contract/subcontract/consultant_appointment/
        // supplier_agreement, a different concept entirely — see this
        // service's class docblock and Part 5's locked rule).
        $suggested = $contract->form_of_contract ? trim($contract->form_of_contract) : null;
        if (!$suggested) {
            return null;
        }
        $suggested = mb_substr($suggested, 0, 100); // matches Project.contract_type's own max:100 rule
        $current   = $project->contract_type ? trim($project->contract_type) : null;
        $matches   = $current !== null && strcasecmp($current, $suggested) === 0;

        return [
            'key'   => ProjectContractSuggestionKeys::CONTRACT_TYPE,
            'label' => 'Contract Form',
            'current'   => ['value' => $current],
            'suggested' => ['value' => $suggested],
            'already_matches'  => $matches,
            'default_selected' => !$matches && $current === null,
        ];
    }

    private function retentionSuggestion(Project $project, Contract $contract, ContractAiAnalysis $analysis): ?array
    {
        // Deliberately NOT read from Contract::$retention_percentage — see
        // this class's own docblock: that column defaults to 0 (NOT NULL),
        // so it can't distinguish "confirmed as 0%" from "never touched."
        $raw = $this->confirmedRetentionPercent($analysis);
        if ($raw === null || !is_numeric($raw)) {
            return null;
        }
        $suggested = (float) $raw;
        if ($suggested < 0 || $suggested > 100) {
            return null;
        }
        $current = $project->retention_percentage !== null ? (float) $project->retention_percentage : null;
        $matches = $current !== null && abs($current - $suggested) < 0.005;

        return [
            'key'   => ProjectContractSuggestionKeys::RETENTION_PERCENTAGE,
            'label' => 'Retention %',
            'current'   => ['value' => $current],
            'suggested' => ['value' => $suggested],
            'already_matches'  => $matches,
            'default_selected' => !$matches && $current === null,
        ];
    }

    /**
     * Identity-led Project Organization Role suggestion — the ONLY role
     * logic in this service, and deliberately the most conservative
     * builder here.
     *
     * Locked rules (see Phase E's own architecture review):
     *   - never shown at all once Project.organization_role is already set
     *     (an explicit prior user decision is never second-guessed here);
     *   - never derived from Contract::$type or
     *     contract_overview.is_subcontract alone — a Main Contractor can
     *     legitimately upload a downstream Subcontract, and vice versa;
     *   - only derived from an exact-normalized match between the
     *     Organization's own name and a *specifically named* confirmed
     *     party — `parties.main_contractor`/`parties.employer`/
     *     `parties.subcontractor` in the v2 analysis schema. No consultant
     *     suggestion exists because that schema has several distinct
     *     consultant-type roles (architect/QS/project_manager/structural/
     *     MEP) and no single authoritative "the consultant" field;
     *   - if the Organization's name matches more than one of those three
     *     candidate parties (or matches none), no suggestion is returned —
     *     ambiguity always resolves to silence, never a guess.
     *
     * `confirmed_data_json` (not any structured Contract column) is the
     * source here deliberately: `Contract::$party_name` conflates
     * main_contractor/subcontractor into one ambiguous column, so it is
     * not a reliable identity source for this specific purpose, even
     * though it's a real synced field for other display purposes.
     */
    private function roleSuggestion(Project $project, Contract $contract, ContractAiAnalysis $analysis): ?array
    {
        if ($project->organization_role !== null) {
            return null;
        }

        $orgName = $this->normalizeName($project->organization?->name ?? '');
        if ($orgName === '') {
            return null;
        }

        $confirmed = $analysis->confirmed_data_json;
        $parties   = is_array($confirmed) ? ($confirmed['parties'] ?? null) : null;
        if (!is_array($parties)) {
            return null; // v1 schema, or no party structure at all — no reliable identity
        }

        $candidates = [];

        $mainName = $this->normalizeName($parties['main_contractor']['name'] ?? '');
        if ($mainName !== '' && $mainName === $orgName) {
            $candidates['main_contractor'] = [
                'label'  => 'Main / General Contractor',
                'reason' => 'Your organization name matches the Main Contractor named in the confirmed Contract.',
            ];
        }

        // Contract::$employer_name is the structured, already-synced column
        // for this one party — reliable and unambiguous on its own, unlike
        // party_name — so it's used here in preference to re-reading the
        // raw JSON a second time for the same fact.
        $employerName = $this->normalizeName($contract->employer_name ?? ($parties['employer']['name'] ?? ''));
        if ($employerName !== '' && $employerName === $orgName) {
            $candidates['employer'] = [
                'label'  => 'Employer / Owner',
                'reason' => 'Your organization name matches the Employer named in the confirmed Contract.',
            ];
        }

        // A dedicated, specifically-named subcontractor party — NOT the
        // generic is_subcontract boolean, which is never used for role
        // inference anywhere in this service.
        $subName = $this->normalizeName($parties['subcontractor']['name'] ?? '');
        if ($subName !== '' && $subName === $orgName) {
            $candidates['subcontractor'] = [
                'label'  => 'Subcontractor / Specialist Contractor',
                'reason' => 'Your organization name matches the Subcontractor named in the confirmed Contract.',
            ];
        }

        if (count($candidates) !== 1) {
            return null; // none, or genuinely ambiguous — never guess
        }

        $roleValue = array_key_first($candidates);
        $candidate = $candidates[$roleValue];

        return [
            'key'   => ProjectContractSuggestionKeys::ORGANIZATION_ROLE,
            'label' => 'Your Role on this Project',
            'current'   => ['value' => null],
            'suggested' => ['value' => $roleValue, 'label' => $candidate['label'], 'reason' => $candidate['reason']],
            'already_matches'  => false,
            'default_selected' => false, // a role decision is never preselected, even when blank
        ];
    }

    /**
     * Project/Site Location — one grouped suggestion, never five separate
     * keys (see ProjectContractSuggestionKeys' own docblock). Read
     * exclusively from confirmed_data_json's own
     * `contract_overview.project_location` — the schema field the AI
     * extraction prompt keeps structurally distinct from every party's own
     * address (`parties.*.address`), so a company registered office can
     * never surface here by construction, not by extra filtering logic
     * added on top. A v1 (pre-v2.0) confirmed analysis has no equivalent
     * field at all — no suggestion, never a guess.
     *
     * Supports a partial confirmed address (e.g. city + country only, no
     * street/postcode) — at least one non-blank component is enough to
     * suggest something useful; a completely empty project_location
     * produces no suggestion at all, never an empty one.
     *
     * `map_pin_status` (`'set'`/`'missing'`) and `map_pin_action_required`
     * (Part 20/21's "matching text, missing pin" edge case) — a Project
     * whose textual location already matches the confirmed Contract but
     * has no valid map position would otherwise be permanently stuck: the
     * normal `already_matches` short-circuit means nothing to apply, so
     * this workflow could never be used to add the missing pin. Deliberately
     * still `project_location` — no second persisted suggestion key exists
     * for this. `default_selected` is true for exactly this case (a
     * geocode-only action that can only add a pin, never touches already-
     * correct text, so it's safe to default-select) even though `matches`
     * is true; false in every other already-matching case, matching the
     * existing behaviour for every other suggestion type.
     *
     * A coordinate pair is only ever "set" when BOTH latitude and longitude
     * are present and pass the same range validation
     * `GeoapifyGeocodingProvider` applies to a fresh result (Part 22) — a
     * partial pair (one set, one null) is always "missing," never treated
     * as a valid existing pin.
     */
    private function projectLocationSuggestion(Project $project, Contract $contract, ContractAiAnalysis $analysis): ?array
    {
        $data = is_array($analysis->confirmed_data_json) ? $analysis->confirmed_data_json : [];
        $location = $data['contract_overview']['project_location'] ?? null;
        if (!is_array($location)) {
            return null;
        }

        $suggested = [
            'address'  => $this->normalizeLocationComponent($location['address_line'] ?? null),
            'city'     => $this->normalizeLocationComponent($location['city'] ?? null),
            'state'    => $this->normalizeLocationComponent($location['region'] ?? null),
            'postcode' => $this->normalizeLocationComponent($location['postal_code'] ?? null),
            'country'  => $this->normalizeLocationComponent($location['country'] ?? null),
        ];

        if (array_filter($suggested, fn ($v) => $v !== null) === []) {
            return null; // the Contract never named a project/site location at all
        }

        $current = [
            'address'  => $this->normalizeLocationComponent($project->address),
            'city'     => $this->normalizeLocationComponent($project->city),
            'state'    => $this->normalizeLocationComponent($project->state),
            'postcode' => $this->normalizeLocationComponent($project->postcode),
            'country'  => $this->normalizeLocationComponent($project->country),
        ];
        // Project::$country used to have a DB-level NOT NULL default
        // ('AU') — fixed schema-only in
        // 2026_08_17_000002_fix_projects_country_default.php, WITHOUT
        // backfilling existing rows, because `country` is a free-text
        // field (not a fixed dropdown) and there is no provable way to
        // distinguish a row that only ever got 'AU' from the old default
        // from one where a user genuinely typed "AU". Precisely because
        // that ambiguity isn't provable, it is never reinterpreted here
        // either — an existing 'AU' (or any other non-blank value) always
        // counts as real, already-present location data, exactly like any
        // other stored value. A Project is "blank" only when every one of
        // these five fields is actually null after normalization; nothing
        // is special-cased.
        $currentIsBlank = array_filter($current, fn ($v) => $v !== null) === [];

        $matches = true;
        foreach ($suggested as $field => $value) {
            if (!$this->locationComponentsMatch($current[$field], $value)) {
                $matches = false;
                break;
            }
        }

        $mapPinSet = $this->hasValidCoordinatePair($project);
        // Part 20/21: matching text + missing pin gets its own actionable,
        // default-selected outcome; every other already-matching case (pin
        // already set) stays exactly as before — nothing to do, never
        // preselected.
        $mapPinActionRequired = $matches && !$mapPinSet;
        $defaultSelected = $matches ? $mapPinActionRequired : ($currentIsBlank);

        return [
            'key'   => ProjectContractSuggestionKeys::PROJECT_LOCATION,
            'label' => 'Project Location',
            'current'   => ['value' => $this->locationSummary($current), 'lines' => $this->locationLines($current)],
            // 'location' carries the structured components apply() reads
            // directly — never re-derived from 'lines' (a flattened,
            // order-dependent display array with no reliable way back to
            // named fields). Not raw AI JSON — the same normalized values
            // already computed above, just not stripped before the
            // response goes out, exactly like organization_role's own
            // extra 'label'/'reason' fields on 'suggested'.
            'suggested' => ['value' => $this->locationSummary($suggested), 'lines' => $this->locationLines($suggested), 'location' => $suggested],
            'already_matches'  => $matches,
            'default_selected' => $defaultSelected,
            'map_pin_status' => $mapPinSet ? 'set' : 'missing',
            'map_pin_action_required' => $mapPinActionRequired,
        ];
    }

    /**
     * A coordinate pair counts as a genuinely valid existing pin only when
     * BOTH latitude and longitude are present and within range — mirrors
     * `GeoapifyGeocodingProvider`'s own validation so a Project can never
     * be considered to have a valid pin that a fresh geocode would itself
     * have rejected. A partial pair (Part 22) is always "missing."
     */
    private function hasValidCoordinatePair(Project $project): bool
    {
        return $this->isValidLatitude($project->latitude) && $this->isValidLongitude($project->longitude);
    }

    private function isValidLatitude(mixed $value): bool
    {
        return $value !== null && is_numeric($value) && (float) $value >= -90 && (float) $value <= 90;
    }

    private function isValidLongitude(mixed $value): bool
    {
        return $value !== null && is_numeric($value) && (float) $value >= -180 && (float) $value <= 180;
    }

    /**
     * Trim + collapse whitespace only — matches Part 7's explicitly
     * limited comparison-normalization rule (safe presentation differences
     * only, never fuzzy address matching). Returns null for an empty
     * result so "" and null are always treated identically downstream.
     */
    private function normalizeLocationComponent(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $normalized = preg_replace('/\s+/', ' ', trim($value)) ?? '';
        return $normalized === '' ? null : $normalized;
    }

    /**
     * Both null counts as a match (an unset component on both sides is not
     * a difference); exactly one null is always a mismatch; otherwise an
     * exact case-insensitive comparison — never fuzzy matching, per Part 7.
     */
    private function locationComponentsMatch(?string $a, ?string $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        return strcasecmp($a, $b) === 0;
    }

    /** Display order matches the Project field mapping: address, city, state/region, postcode, country. */
    private function locationLines(array $location): array
    {
        return array_values(array_filter([
            $location['address'] ?? null,
            $location['city'] ?? null,
            $location['state'] ?? null,
            $location['postcode'] ?? null,
            $location['country'] ?? null,
        ], fn ($v) => $v !== null));
    }

    private function locationSummary(array $location): ?string
    {
        $lines = $this->locationLines($location);
        return $lines === [] ? null : implode(', ', $lines);
    }

    /**
     * Conservative, deterministic normalization only — trim, case-fold,
     * collapse whitespace. Deliberately does NOT do fuzzy/substring
     * matching or strip legal-suffix punctuation ("Ltd" vs "Limited" is a
     * genuine mismatch here, not a false negative) — see the class
     * docblock above and Phase E's own locked normalization rules.
     */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($name)) ?? '');
    }

    /**
     * Reads the confirmed currency directly from confirmed_data_json,
     * covering both the v2 (`contract_overview.currency`/
     * `commercial.currency`) and v1 (`extracted_fields.currency`) schema
     * shapes — see this class's docblock for why Contract::$currency
     * itself is not used for this one check.
     */
    private function confirmedCurrency(ContractAiAnalysis $analysis): ?string
    {
        $data = is_array($analysis->confirmed_data_json) ? $analysis->confirmed_data_json : [];
        if (isset($data['contract_overview'])) {
            return $data['contract_overview']['currency'] ?? $data['commercial']['currency'] ?? null;
        }
        $fields = $data['extracted_fields'] ?? $data;
        return $fields['currency'] ?? null;
    }

    /**
     * Reads the confirmed retention percentage directly from
     * confirmed_data_json — see this class's docblock for why
     * Contract::$retention_percentage itself is not used for this check.
     */
    private function confirmedRetentionPercent(ContractAiAnalysis $analysis): mixed
    {
        $data = is_array($analysis->confirmed_data_json) ? $analysis->confirmed_data_json : [];
        if (isset($data['contract_overview'])) {
            return $data['commercial']['retention_percent'] ?? null;
        }
        $fields = $data['extracted_fields'] ?? $data;
        return $fields['retention_percent'] ?? $fields['retention_percentage'] ?? null;
    }
}
