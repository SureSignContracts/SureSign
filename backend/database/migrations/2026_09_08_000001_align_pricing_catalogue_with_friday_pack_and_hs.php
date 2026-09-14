<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Marketing, Pricing & Documentation Refresh — Batch 1 (Pricing Alignment).
 *
 * The public pricing feature catalogue (`pricing_features`/
 * `pricing_plan_features`) had drifted from `PricingSeeder` via ordinary
 * Super Admin Pricing Management edits (that's the intended, authoritative
 * write path for this catalogue — see `PricingManagementService::
 * createFeature()`/`deleteFeatureSection()` — the seeder is bootstrap-only
 * and is never re-run against an already-provisioned environment). This
 * migration is the correct mechanism to bring an *already-provisioned*
 * environment's live catalogue in line with a genuine product decision,
 * mirroring `2026_08_05_000001_update_pricing_plan_vat_exclusive_suffix.php`
 * and `2026_09_04_000002_add_friday_packs_entitlement_to_pricing_plans.php`
 * exactly — resolve everything by name/code (never a hardcoded id), and
 * guard every write so an operator's own subsequent Pricing Management
 * customisation is never silently clobbered by a later re-run.
 *
 * Three changes, each independently gated:
 *
 * 1. Remove the live "Assistant"/"Memory" rows from the AI section. Both
 *    describe a general AI Assistant chat capability with conversation
 *    memory — confirmed non-functional (`AiController` has no
 *    conversation/summarize/draft-document methods; see CLAUDE.md's AI
 *    Workflow Context). These were never part of `PricingSeeder` — they
 *    were added directly via Pricing Management at some point — so there
 *    is no seeder counterpart to also change for this half.
 * 2. Add "Weekly Friday Packs" to Core Platform (not Commercial) — Friday
 *    Packs are weekly project administration/reporting, not a
 *    money-lifecycle capability the way Variations/Retention/Final
 *    Accounts are; Core Platform already holds the platform's other
 *    broad administration capabilities (Projects & contracts, Document
 *    management, Payment applications). Essential = not_included,
 *    Professional/Enterprise = included, matching the real
 *    `Feature::FRIDAY_PACKS` entitlement exactly.
 * 3. Add "Health & Safety records" to Core Platform likewise — a single
 *    broad row (Toolbox Talks, Site Inductions, Incidents, H&S
 *    Inspections, Plant & Equipment, Statutory Inspections are all
 *    genuinely all-plan today; a single row avoids inventing six-way
 *    differentiation that doesn't exist). included on every plan.
 *
 * Idempotent throughout: every insert is guarded by a name-existence
 * check, so re-running this migration (or running it after an operator
 * has since edited/removed one of these rows via Pricing Management)
 * never duplicates or resurrects anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->removeUnsupportedAiRows();
        $this->addCorePlatformRow('Weekly Friday Packs', [
            'essential' => 'not_included',
            'professional' => 'included',
            'enterprise' => 'included',
        ]);
        $this->addCorePlatformRow('Health & Safety records', [
            'essential' => 'included',
            'professional' => 'included',
            'enterprise' => 'included',
        ]);
    }

    public function down(): void
    {
        // Deliberately does not restore "Assistant"/"Memory" — removing a
        // confirmed-unsupported public claim is not something a rollback
        // should ever reintroduce. Only undoes the two additive rows this
        // migration made, and only if they still look exactly as this
        // migration left them (an operator may have since edited them via
        // Pricing Management — never overwrite that).
        foreach (['Weekly Friday Packs', 'Health & Safety records'] as $name) {
            $featureId = DB::table('pricing_features')
                ->join('pricing_feature_sections', 'pricing_features.section_id', '=', 'pricing_feature_sections.id')
                ->where('pricing_feature_sections.name', 'Core Platform')
                ->where('pricing_features.name', $name)
                ->value('pricing_features.id');

            if ($featureId !== null) {
                DB::table('pricing_features')->where('id', $featureId)->delete();
            }
        }
    }

    private function removeUnsupportedAiRows(): void
    {
        $aiSectionId = DB::table('pricing_feature_sections')->where('name', 'AI')->value('id');

        if ($aiSectionId === null) {
            return;
        }

        DB::table('pricing_features')
            ->where('section_id', $aiSectionId)
            ->whereIn('name', ['Assistant', 'Memory'])
            ->delete(); // cascades to pricing_plan_features
    }

    private function addCorePlatformRow(string $name, array $statusByPlanCode): void
    {
        $sectionId = DB::table('pricing_feature_sections')->where('name', 'Core Platform')->value('id');

        if ($sectionId === null) {
            return;
        }

        $alreadyExists = DB::table('pricing_features')
            ->where('section_id', $sectionId)
            ->where('name', $name)
            ->exists();

        if ($alreadyExists) {
            return;
        }

        $now = now();
        $nextOrder = (int) DB::table('pricing_features')->where('section_id', $sectionId)->max('order') + 1;

        $featureId = DB::table('pricing_features')->insertGetId([
            'section_id' => $sectionId,
            'name' => $name,
            'order' => $nextOrder,
            'is_visible' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($statusByPlanCode as $planCode => $status) {
            $planId = DB::table('pricing_plans')->where('code', $planCode)->value('id');

            if ($planId === null) {
                continue;
            }

            DB::table('pricing_plan_features')->insert([
                'plan_id' => $planId,
                'feature_id' => $featureId,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
