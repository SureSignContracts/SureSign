<?php

namespace App\Services\Pricing;

use App\Models\ActivityLog;
use App\Models\PricingFaq;
use App\Models\PricingFeature;
use App\Models\PricingFeatureSection;
use App\Models\PricingIncludedItem;
use App\Models\PricingPlan;
use App\Models\PricingPlanEntitlement;
use App\Models\PricingPlanFeature;
use App\Models\PricingSetting;
use App\Models\User;
use App\Services\Entitlements\PlanEntitlementRepository;
use App\Support\Entitlements\EntitlementCategory;
use App\Support\Entitlements\Feature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PricingManagementService
{
    public const CACHE_KEY = 'pricing.public';

    public function __construct(
        private readonly PlanEntitlementRepository $planEntitlements,
    ) {
    }

    // ─── Public payload ─────────────────────────────────────────────────────

    /**
     * Assembles the full, whitelisted public payload — only fields safe to
     * expose to the marketing site. Internal fields (code, status,
     * published_at, deleted_at, id-only pivot bookkeeping) are never
     * serialized here. Cached for an hour; every admin write busts the key.
     */
    public function publicPayload(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function () {
            $settings = PricingSetting::instance();

            $plans = PricingPlan::active()
                ->orderBy('order')
                ->with(['planFeatures'])
                ->get();

            $sections = PricingFeatureSection::where('is_visible', true)
                ->orderBy('order')
                ->with(['features' => fn ($q) => $q->where('is_visible', true)])
                ->get();

            // Flattened to plain arrays/scalars before returning — Cache::remember
            // persists this via Redis' PHP serialize()/unserialize(), which is
            // brittle for nested Eloquent/Support Collection objects (observed
            // producing __PHP_Incomplete_Class_Name on unserialize). Plain
            // arrays round-trip through cache reliably regardless of class
            // autoloading state.
            return json_decode(json_encode([
                'published' => (bool) $settings->published,
                'settings'  => [
                    'hero_title'                    => $settings->hero_title,
                    'hero_subtitle'                 => $settings->hero_subtitle,
                    'section_title'                 => $settings->section_title,
                    'monthly_billing_enabled'       => $settings->monthly_billing_enabled,
                    'annual_billing_enabled'        => $settings->annual_billing_enabled,
                    'discount_label'                => $settings->discount_label,
                    'everything_included_title'     => $settings->everything_included_title,
                    'everything_included_subtitle'  => $settings->everything_included_subtitle,
                    'final_cta_title'                => $settings->final_cta_title,
                    'final_cta_subtitle'             => $settings->final_cta_subtitle,
                    'primary_cta_text'               => $settings->primary_cta_text,
                    'primary_cta_url'                => $settings->primary_cta_url,
                    'primary_cta_new_tab'            => $settings->primary_cta_new_tab,
                    'secondary_cta_text'             => $settings->secondary_cta_text,
                    'secondary_cta_url'              => $settings->secondary_cta_url,
                    'secondary_cta_new_tab'          => $settings->secondary_cta_new_tab,
                ],
                'plans' => $plans->map(function (PricingPlan $plan) {
                    return [
                        'slug'              => $plan->slug,
                        'name'              => $plan->name,
                        'monthly_price'     => $plan->monthly_price,
                        'annual_price'      => $plan->annual_price,
                        'currency'          => $plan->currency,
                        'price_prefix'      => $plan->price_prefix,
                        'price_suffix'      => $plan->price_suffix,
                        'description'       => $plan->description,
                        'summary'           => $plan->summary,
                        'cta_text'          => $plan->cta_text,
                        'cta_url'           => $plan->cta_url,
                        'cta_new_tab'       => $plan->cta_new_tab,
                        'is_popular'        => $plan->is_popular,
                        'badge_text'        => $plan->badge_text,
                        'badge_color'       => $plan->badge_color,
                        'accent_color'      => $plan->accent_color,
                        'background_style'  => $plan->background_style,
                        'icon'              => $plan->icon,
                        'custom_label'      => $plan->custom_label,
                        'features'          => $plan->planFeatures->mapWithKeys(fn (PricingPlanFeature $pf) => [
                            $pf->feature_id => [
                                'status'        => $pf->status,
                                'value_text'    => $pf->value_text,
                                'icon_override' => $pf->icon_override,
                            ],
                        ]),
                    ];
                })->values(),
                'feature_sections' => $sections->map(fn (PricingFeatureSection $section) => [
                    'name'     => $section->name,
                    'features' => $section->features->map(fn (PricingFeature $f) => [
                        'id'   => $f->id,
                        'name' => $f->name,
                    ])->values(),
                ])->values(),
                'included_items' => PricingIncludedItem::where('is_visible', true)
                    ->orderBy('order')
                    ->get(['text', 'icon'])
                    ->values(),
                'faqs' => PricingFaq::where('is_enabled', true)
                    ->orderBy('order')
                    ->get(['question', 'answer'])
                    ->values(),
            ], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        });
    }

    // ─── Cache ──────────────────────────────────────────────────────────────

    public function bustCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ─── Settings ───────────────────────────────────────────────────────────

    public function updateSettings(array $data, User $actor): PricingSetting
    {
        $settings = PricingSetting::instance();
        $before   = $settings->only(array_keys($data));

        $settings->fill($data)->save();

        $this->logChange('pricing_settings.updated', 'Updated pricing global settings', $actor, $settings, $before, $settings->only(array_keys($data)));
        $this->bustCache();

        return $settings->fresh();
    }

    // ─── Plans ──────────────────────────────────────────────────────────────

    public function createPlan(array $data, User $actor): PricingPlan
    {
        $plan = PricingPlan::create($data);

        if (! empty($data['is_popular'])) {
            $this->setPopularPlan($plan, $actor, silent: true);
        }

        // Phase G1, Stage 9 — a brand-new plan must never silently
        // resolve to zero entitlements (see PlanEntitlementRepository's
        // own docblock). A no-op if this plan already has configured rows.
        $this->planEntitlements->initializeDefaultsForPlan($plan);

        $this->logChange('pricing_plan.created', "Created plan \"{$plan->name}\"", $actor, $plan, [], $plan->toArray());
        $this->bustCache();

        return $plan->fresh();
    }

    public function updatePlan(PricingPlan $plan, array $data, User $actor): PricingPlan
    {
        $before = $plan->only(array_keys($data));

        $plan->fill($data)->save();

        if (array_key_exists('is_popular', $data) && $data['is_popular']) {
            $this->setPopularPlan($plan, $actor, silent: true);
        }

        $this->logChange('pricing_plan.updated', "Updated plan \"{$plan->name}\"", $actor, $plan, $before, $plan->only(array_keys($data)));
        $this->bustCache();

        return $plan->fresh();
    }

    public function publishPlan(PricingPlan $plan, User $actor): PricingPlan
    {
        $before = ['status' => $plan->status, 'published_at' => $plan->published_at];

        $plan->status = 'active';
        $plan->published_at ??= now();
        $plan->save();

        $this->logChange('pricing_plan.published', "Published plan \"{$plan->name}\"", $actor, $plan, $before, ['status' => $plan->status, 'published_at' => $plan->published_at]);
        $this->bustCache();

        return $plan->fresh();
    }

    public function archivePlan(PricingPlan $plan, User $actor): PricingPlan
    {
        $before = ['status' => $plan->status];

        $plan->status = 'archived';
        $plan->is_popular = false;
        $plan->save();

        $this->logChange('pricing_plan.archived', "Archived plan \"{$plan->name}\"", $actor, $plan, $before, ['status' => $plan->status]);
        $this->bustCache();

        return $plan->fresh();
    }

    /**
     * Hard-deletes a plan only if it was never published and has no
     * comparison-matrix rows; otherwise archives it instead and returns false
     * so the controller can report why nothing was deleted.
     */
    public function deleteOrArchivePlan(PricingPlan $plan, User $actor): bool
    {
        $everReferenced = $plan->published_at !== null || PricingPlanFeature::where('plan_id', $plan->id)->exists();

        if ($everReferenced) {
            $this->archivePlan($plan, $actor);
            return false;
        }

        $this->logChange('pricing_plan.deleted', "Deleted plan \"{$plan->name}\"", $actor, $plan, $plan->toArray(), []);
        $plan->delete();
        $this->bustCache();

        return true;
    }

    public function reorderPlans(array $orderedIds, User $actor): void
    {
        $this->reorderEntity(PricingPlan::class, $orderedIds);
        $this->logChange('pricing_plan.reordered', 'Reordered plans', $actor, null, [], ['order' => $orderedIds]);
        $this->bustCache();
    }

    // ─── Plan entitlement defaults (Phase G2) ──────────────────────────────

    /**
     * The full, dynamically-generated entitlement editor payload for one
     * plan — EVERY Feature::* key, including reserved/dormant ones (Stage
     * X: shown read-only, clearly marked, so an admin never mistakes
     * "exists in the registry" for "currently sold"), grouped by category
     * metadata rather than hardcoded per feature. `categories` is derived
     * entirely from `EntitlementCategory::ALL`/`label()`/`description()` —
     * a future approved category needs no change here to appear correctly
     * labelled. Never reads PlanEntitlementRepository — that class resolves
     * LIVE FeatureGate lookups (with its temporary hardcoded fallback);
     * this is the admin editor and must show exactly what's configured in
     * the database, defaulting an unconfigured non-dormant key to the same
     * conservative baseline initializeDefaultsForPlan() would write, never
     * a silent live fallback.
     */
    public function entitlementsForPlan(PricingPlan $plan): array
    {
        $rows = PricingPlanEntitlement::query()
            ->where('pricing_plan_id', $plan->id)
            ->get()
            ->keyBy('feature_key');

        $entitlements = [];

        foreach (Feature::ALL as $key) {
            $isReserved = Feature::isDormant($key);

            /** @var PricingPlanEntitlement|null $row */
            $row = $isReserved ? null : $rows->get($key);

            $entitlements[] = [
                'feature_key' => $key,
                'display_name' => Feature::displayName($key),
                'description' => Feature::description($key),
                'category' => Feature::category($key),
                'value_type' => Feature::valueType($key),
                'unit' => Feature::unit($key),
                'enforcement_level' => Feature::enforcementLevel($key),
                'customer_visible' => Feature::isCustomerVisible($key),
                'currently_sold' => Feature::isCurrentlySold($key),
                'overrideable' => Feature::isOverrideable($key),
                'is_reserved' => $isReserved,
                'is_applicable' => $isReserved ? false : ($row?->is_applicable ?? true),
                'is_unlimited' => $isReserved ? false : ($row?->is_unlimited ?? false),
                'value' => $isReserved ? null : ($row !== null ? $row->value : (Feature::isFeatureFlag($key) ? false : 0)),
            ];
        }

        $categories = collect(EntitlementCategory::ALL)->map(fn (string $category) => [
            'key' => $category,
            'label' => EntitlementCategory::label($category),
            'description' => EntitlementCategory::description($category),
        ])->values()->all();

        return ['categories' => $categories, 'entitlements' => $entitlements];
    }

    /**
     * Replaces this plan's entire set of entitlement default rows.
     * `$rows` has already been validated by UpdatePricingPlanEntitlementsRequest
     * to be exactly the non-dormant Feature::ALL set, with no duplicates and
     * type-correct values. Deliberately does NOT bust the public pricing
     * cache — entitlement defaults are never part of the marketing payload —
     * and deliberately never touches billing_entitlement_snapshots: editing
     * a plan's defaults here only changes what a FUTURE activation/upgrade/
     * downgrade snapshot will capture, never an existing subscription's
     * already-frozen snapshot.
     */
    public function updateEntitlements(PricingPlan $plan, array $rows, User $actor): array
    {
        DB::transaction(function () use ($plan, $rows) {
            foreach ($rows as $row) {
                $isApplicable = (bool) $row['is_applicable'];
                $isUnlimited = (bool) $row['is_unlimited'];

                PricingPlanEntitlement::updateOrCreate(
                    ['pricing_plan_id' => $plan->id, 'feature_key' => $row['feature_key']],
                    [
                        'is_applicable' => $isApplicable,
                        'is_unlimited' => $isUnlimited,
                        'value' => $isApplicable && !$isUnlimited ? ($row['value'] ?? null) : null,
                    ]
                );
            }
        });

        $this->logChange(
            'pricing_plan.entitlements_updated',
            "Updated entitlement defaults for plan \"{$plan->name}\"",
            $actor,
            $plan,
            [],
            ['feature_keys' => array_column($rows, 'feature_key')],
        );

        return $this->entitlementsForPlan($plan);
    }

    // ─── Copy plan (Phase G2, Stage 6) ──────────────────────────────────────

    /**
     * Commercial/presentation fields copied verbatim from the source plan.
     * Deliberately excludes: code/slug/name (always supplied fresh by the
     * caller — a copy can never silently share the source's identity),
     * order/status/published_at/is_popular (a copy always starts as an
     * unranked, unpublished, non-popular draft), and anything Stripe-related
     * (no such column exists on PricingPlan itself — pricing_plan_provider_prices
     * is a separate table and a copied plan always starts with zero rows
     * there, requiring its own new Stripe Product/Price mapping).
     */
    private const COPYABLE_COMMERCIAL_FIELDS = [
        'monthly_price', 'annual_price', 'currency', 'price_prefix', 'price_suffix',
        'description', 'summary', 'cta_text', 'cta_url', 'cta_new_tab',
        'badge_text', 'badge_color', 'accent_color', 'background_style', 'icon', 'custom_label',
    ];

    /**
     * Creates a new plan from an existing one — commercial fields and every
     * entitlement default row are duplicated; Stripe mapping, popularity,
     * ordering, and publish state are deliberately never copied (see
     * COPYABLE_COMMERCIAL_FIELDS's docblock).
     */
    public function copyPlan(PricingPlan $source, array $overrides, User $actor): PricingPlan
    {
        $data = array_merge(
            $source->only(self::COPYABLE_COMMERCIAL_FIELDS),
            [
                'code' => $overrides['code'],
                'slug' => $overrides['slug'],
                'name' => $overrides['name'],
                'is_visible' => $overrides['is_visible'] ?? false,
            ],
        );

        $plan = DB::transaction(function () use ($data, $source) {
            $plan = PricingPlan::create($data);

            $sourceRows = PricingPlanEntitlement::where('pricing_plan_id', $source->id)->get();

            if ($sourceRows->isEmpty()) {
                // Source plan has no configured rows to copy (shouldn't happen for
                // any plan created since Phase G1/G2, but never leave the copy with
                // zero entitlement rows) — same conservative baseline a blank plan gets.
                $this->planEntitlements->initializeDefaultsForPlan($plan);

                return $plan;
            }

            $now = now();
            $rows = $sourceRows->map(fn (PricingPlanEntitlement $row) => [
                'pricing_plan_id' => $plan->id,
                'feature_key' => $row->feature_key,
                'is_applicable' => $row->is_applicable,
                'is_unlimited' => $row->is_unlimited,
                'value' => $row->value === null ? null : json_encode($row->value),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            DB::table('pricing_plan_entitlements')->insert($rows);

            return $plan;
        });

        $this->logChange(
            'pricing_plan.copied',
            "Copied plan \"{$source->name}\" to \"{$plan->name}\"",
            $actor,
            $plan,
            [],
            ['source_plan_id' => $source->id, 'source_plan_code' => $source->code],
        );
        $this->bustCache();

        return $plan->fresh();
    }

    private function setPopularPlan(PricingPlan $plan, User $actor, bool $silent = false): void
    {
        DB::transaction(function () use ($plan) {
            PricingPlan::where('id', '!=', $plan->id)->update(['is_popular' => false]);
            if (! $plan->is_popular) {
                $plan->is_popular = true;
                $plan->save();
            }
        });

        if (! $silent) {
            $this->logChange('pricing_plan.popular_changed', "Marked \"{$plan->name}\" as the popular plan", $actor, $plan, [], ['is_popular' => true]);
            $this->bustCache();
        }
    }

    // ─── Feature sections ───────────────────────────────────────────────────

    public function createFeatureSection(array $data, User $actor): PricingFeatureSection
    {
        $section = PricingFeatureSection::create($data);
        $this->logChange('pricing_feature_section.created', "Created feature section \"{$section->name}\"", $actor, $section, [], $section->toArray());
        $this->bustCache();

        return $section;
    }

    public function updateFeatureSection(PricingFeatureSection $section, array $data, User $actor): PricingFeatureSection
    {
        $before = $section->only(array_keys($data));
        $section->fill($data)->save();
        $this->logChange('pricing_feature_section.updated', "Updated feature section \"{$section->name}\"", $actor, $section, $before, $section->only(array_keys($data)));
        $this->bustCache();

        return $section->fresh();
    }

    public function deleteFeatureSection(PricingFeatureSection $section, User $actor): void
    {
        $this->logChange('pricing_feature_section.deleted', "Deleted feature section \"{$section->name}\"", $actor, $section, $section->toArray(), []);
        $section->delete(); // cascades to its features (and their plan_features)
        $this->bustCache();
    }

    public function reorderFeatureSections(array $orderedIds, User $actor): void
    {
        $this->reorderEntity(PricingFeatureSection::class, $orderedIds);
        $this->logChange('pricing_feature_section.reordered', 'Reordered feature sections', $actor, null, [], ['order' => $orderedIds]);
        $this->bustCache();
    }

    // ─── Features ───────────────────────────────────────────────────────────

    /**
     * Creates a feature and seeds a "not_included" comparison row for every
     * active plan, all inside one transaction.
     */
    public function createFeature(array $data, User $actor): PricingFeature
    {
        $feature = DB::transaction(function () use ($data) {
            $feature = PricingFeature::create($data);

            $planIds = PricingPlan::where('status', 'active')->pluck('id');
            foreach ($planIds as $planId) {
                PricingPlanFeature::create([
                    'plan_id'    => $planId,
                    'feature_id' => $feature->id,
                    'status'     => 'not_included',
                ]);
            }

            return $feature;
        });

        $this->logChange('pricing_feature.created', "Created feature \"{$feature->name}\"", $actor, $feature, [], $feature->toArray());
        $this->bustCache();

        return $feature;
    }

    public function updateFeature(PricingFeature $feature, array $data, User $actor): PricingFeature
    {
        $before = $feature->only(array_keys($data));
        $feature->fill($data)->save();
        $this->logChange('pricing_feature.updated', "Updated feature \"{$feature->name}\"", $actor, $feature, $before, $feature->only(array_keys($data)));
        $this->bustCache();

        return $feature->fresh();
    }

    public function deleteFeature(PricingFeature $feature, User $actor): void
    {
        $this->logChange('pricing_feature.deleted', "Deleted feature \"{$feature->name}\"", $actor, $feature, $feature->toArray(), []);
        $feature->delete(); // cascades to its plan_features rows
        $this->bustCache();
    }

    public function reorderFeatures(array $orderedIds, User $actor): void
    {
        $this->reorderEntity(PricingFeature::class, $orderedIds);
        $this->logChange('pricing_feature.reordered', 'Reordered features', $actor, null, [], ['order' => $orderedIds]);
        $this->bustCache();
    }

    // ─── Comparison matrix ──────────────────────────────────────────────────

    /**
     * Applies every cell update inside one transaction — either all land or
     * none do.
     */
    public function updateMatrix(array $updates, User $actor): void
    {
        DB::transaction(function () use ($updates) {
            foreach ($updates as $update) {
                PricingPlanFeature::updateOrCreate(
                    ['plan_id' => $update['plan_id'], 'feature_id' => $update['feature_id']],
                    [
                        'status'        => $update['status'],
                        'value_text'    => $update['value_text'] ?? null,
                        'icon_override' => $update['icon_override'] ?? null,
                    ]
                );
            }
        });

        $this->logChange('pricing_matrix.updated', 'Updated comparison matrix', $actor, null, [], ['cells' => count($updates)]);
        $this->bustCache();
    }

    // ─── FAQs ───────────────────────────────────────────────────────────────

    public function createFaq(array $data, User $actor): PricingFaq
    {
        $faq = PricingFaq::create($data);
        $this->logChange('pricing_faq.created', 'Created FAQ', $actor, $faq, [], $faq->toArray());
        $this->bustCache();

        return $faq;
    }

    public function updateFaq(PricingFaq $faq, array $data, User $actor): PricingFaq
    {
        $before = $faq->only(array_keys($data));
        $faq->fill($data)->save();
        $this->logChange('pricing_faq.updated', 'Updated FAQ', $actor, $faq, $before, $faq->only(array_keys($data)));
        $this->bustCache();

        return $faq->fresh();
    }

    public function deleteFaq(PricingFaq $faq, User $actor): void
    {
        $this->logChange('pricing_faq.deleted', 'Deleted FAQ', $actor, $faq, $faq->toArray(), []);
        $faq->delete();
        $this->bustCache();
    }

    public function reorderFaqs(array $orderedIds, User $actor): void
    {
        $this->reorderEntity(PricingFaq::class, $orderedIds);
        $this->logChange('pricing_faq.reordered', 'Reordered FAQs', $actor, null, [], ['order' => $orderedIds]);
        $this->bustCache();
    }

    // ─── Included items ─────────────────────────────────────────────────────

    public function createIncludedItem(array $data, User $actor): PricingIncludedItem
    {
        $item = PricingIncludedItem::create($data);
        $this->logChange('pricing_included_item.created', "Created included item \"{$item->text}\"", $actor, $item, [], $item->toArray());
        $this->bustCache();

        return $item;
    }

    public function updateIncludedItem(PricingIncludedItem $item, array $data, User $actor): PricingIncludedItem
    {
        $before = $item->only(array_keys($data));
        $item->fill($data)->save();
        $this->logChange('pricing_included_item.updated', "Updated included item \"{$item->text}\"", $actor, $item, $before, $item->only(array_keys($data)));
        $this->bustCache();

        return $item->fresh();
    }

    public function deleteIncludedItem(PricingIncludedItem $item, User $actor): void
    {
        $this->logChange('pricing_included_item.deleted', "Deleted included item \"{$item->text}\"", $actor, $item, $item->toArray(), []);
        $item->delete();
        $this->bustCache();
    }

    public function reorderIncludedItems(array $orderedIds, User $actor): void
    {
        $this->reorderEntity(PricingIncludedItem::class, $orderedIds);
        $this->logChange('pricing_included_item.reordered', 'Reordered included items', $actor, null, [], ['order' => $orderedIds]);
        $this->bustCache();
    }

    // ─── Shared helpers ─────────────────────────────────────────────────────

    /**
     * Sets `order` = array index for each ID, inside one transaction, but
     * only after confirming the given ID list is exactly a permutation of the
     * entity's current full ID set (no partial reorder, no foreign IDs, no
     * duplicates — duplicates are already rejected by ReorderPricingRequest).
     *
     * @param class-string<Model> $modelClass
     */
    private function reorderEntity(string $modelClass, array $orderedIds): void
    {
        $currentIds = $modelClass::query()->pluck('id')->sort()->values()->all();
        $givenIds   = collect($orderedIds)->sort()->values()->all();

        if ($currentIds !== $givenIds) {
            throw ValidationException::withMessages([
                'order' => 'The order list must contain exactly the current set of items, with no additions, omissions, or duplicates.',
            ]);
        }

        DB::transaction(function () use ($modelClass, $orderedIds) {
            foreach ($orderedIds as $index => $id) {
                $modelClass::whereKey($id)->update(['order' => $index]);
            }
        });
    }

    private function logChange(string $action, string $description, User $actor, ?Model $subject, array $before, array $after): void
    {
        ActivityLog::record(
            action: $action,
            description: $description,
            user: $actor,
            subject: $subject,
            meta: ['before' => $before, 'after' => $after],
        );
    }
}
