<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * One-off, idempotent sync of the Pricing Management content configured
 * locally (plans, comparison matrix, included items, FAQs, entitlements)
 * into an environment that only has PricingSeeder's bare defaults.
 *
 * Every upsert is keyed by a natural identifier (plan `code`, section/
 * feature `name`, entitlement `feature_key`) — never by the local
 * database's auto-increment id — so this is safe to run regardless of
 * what rows/ids already exist in the target environment. Safe to run
 * more than once.
 *
 * Deliberately excludes `pricing_plan_provider_prices` — those are
 * Stripe TEST mode Price mappings from local dev and must never be
 * copied into a LIVE mode environment. Production Stripe Price sync is
 * a separate, deliberate step via PlanPriceMappingService::syncPlanPrice().
 *
 * Run manually: php artisan db:seed --class="Database\Seeders\SyncLocalPricingSnapshotSeeder"
 */
class SyncLocalPricingSnapshotSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('pricing_settings')->updateOrInsert(
            ['id' => 1],
            [
                'hero_title' => 'Simple, transparent pricing',
                'hero_subtitle' => 'Choose the plan that fits how your team runs contracts — no hidden fees, no surprises.',
                'section_title' => 'Plans',
                'monthly_billing_enabled' => 1,
                'annual_billing_enabled' => 1,
                'discount_label' => 'Save 15% billed annually',
                'everything_included_title' => 'Everything Included',
                'everything_included_subtitle' => 'Every plan ships with the fundamentals your team needs to run contracts properly.',
                'final_cta_title' => 'Ready to see SureSign in action?',
                'final_cta_subtitle' => 'Book a demo and we\'ll walk through your contracts, your team, and how SureSign fits.',
                'primary_cta_text' => 'Book a Demo',
                'primary_cta_url' => '/book/demo?src=pricing',
                'primary_cta_new_tab' => 0,
                'secondary_cta_text' => 'Contact Sales',
                'secondary_cta_url' => '/book-a-demo?src=pricing',
                'secondary_cta_new_tab' => 0,
                'published' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $planIds = [];
        DB::table('pricing_plans')->updateOrInsert(
            ['code' => 'essential'],
            [
                'code' => 'essential',
                'slug' => 'essential',
                'name' => 'Essential',
                'order' => 1,
                'monthly_price' => '299.00',
                'annual_price' => '3050.00',
                'currency' => 'GBP',
                'price_prefix' => 'From',
                'price_suffix' => '/month + VAT',
                'description' => 'Everything you need to administer contracts properly, without the overhead.',
                'summary' => 'For small teams running their first few contracts.',
                'cta_text' => 'Get Started',
                'cta_url' => '/book/demo?src=pricing',
                'cta_new_tab' => 0,
                'is_visible' => 1,
                'is_popular' => 0,
                'badge_text' => null,
                'badge_color' => null,
                'accent_color' => 'neutral',
                'background_style' => 'surface',
                'icon' => 'zap',
                'custom_label' => null,
                'status' => 'active',
                'deleted_at' => null,
                'published_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $planIds['essential'] = DB::table('pricing_plans')->where('code', 'essential')->value('id');
        DB::table('pricing_plans')->updateOrInsert(
            ['code' => 'professional'],
            [
                'code' => 'professional',
                'slug' => 'professional',
                'name' => 'Professional',
                'order' => 2,
                'monthly_price' => '799.00',
                'annual_price' => '8150.00',
                'currency' => 'GBP',
                'price_prefix' => 'From',
                'price_suffix' => '/month + VAT',
                'description' => 'Full payment application lifecycle, AI contract analysis, and subcontract package management.',
                'summary' => 'For growing contractors managing multiple live projects.',
                'cta_text' => 'Book a Demo',
                'cta_url' => '/book/demo?src=pricing',
                'cta_new_tab' => 0,
                'is_visible' => 1,
                'is_popular' => 1,
                'badge_text' => 'Most Popular',
                'badge_color' => 'gold',
                'accent_color' => 'gold',
                'background_style' => 'elevated',
                'icon' => 'star',
                'custom_label' => null,
                'status' => 'active',
                'deleted_at' => null,
                'published_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $planIds['professional'] = DB::table('pricing_plans')->where('code', 'professional')->value('id');
        DB::table('pricing_plans')->updateOrInsert(
            ['code' => 'enterprise'],
            [
                'code' => 'enterprise',
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'order' => 3,
                'monthly_price' => null,
                'annual_price' => null,
                'currency' => 'GBP',
                'price_prefix' => 'Custom',
                'price_suffix' => null,
                'description' => 'Dedicated onboarding, custom workflow configuration, and priority support.',
                'summary' => 'For multi-entity contractors with bespoke workflow and compliance needs.',
                'cta_text' => 'Contact Sales',
                'cta_url' => '/book-a-demo?src=pricing',
                'cta_new_tab' => 0,
                'is_visible' => 1,
                'is_popular' => 0,
                'badge_text' => null,
                'badge_color' => null,
                'accent_color' => 'accent',
                'background_style' => 'gradient',
                'icon' => 'building',
                'custom_label' => 'Custom pricing',
                'status' => 'active',
                'deleted_at' => null,
                'published_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $planIds['enterprise'] = DB::table('pricing_plans')->where('code', 'enterprise')->value('id');

        $sectionIds = [];
        DB::table('pricing_feature_sections')->updateOrInsert(
            ['name' => 'Core Platform'],
            [
                'name' => 'Core Platform',
                'order' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $sectionIds['Core Platform'] = DB::table('pricing_feature_sections')->where('name', 'Core Platform')->value('id');
        DB::table('pricing_feature_sections')->updateOrInsert(
            ['name' => 'Commercial'],
            [
                'name' => 'Commercial',
                'order' => 2,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $sectionIds['Commercial'] = DB::table('pricing_feature_sections')->where('name', 'Commercial')->value('id');
        DB::table('pricing_feature_sections')->updateOrInsert(
            ['name' => 'AI'],
            [
                'name' => 'AI',
                'order' => 3,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $sectionIds['AI'] = DB::table('pricing_feature_sections')->where('name', 'AI')->value('id');
        DB::table('pricing_feature_sections')->updateOrInsert(
            ['name' => 'Support'],
            [
                'name' => 'Support',
                'order' => 4,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $sectionIds['Support'] = DB::table('pricing_feature_sections')->where('name', 'Support')->value('id');

        $featureIds = [];
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['AI'], 'name' => 'Assistant'],
            [
                'name' => 'Assistant',
                'order' => 0,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['AI::Assistant'] = DB::table('pricing_features')->where('section_id', $sectionIds['AI'])->where('name', 'Assistant')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Core Platform'], 'name' => 'Storage'],
            [
                'name' => 'Storage',
                'order' => 0,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Core Platform::Storage'] = DB::table('pricing_features')->where('section_id', $sectionIds['Core Platform'])->where('name', 'Storage')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['AI'], 'name' => 'Memory'],
            [
                'name' => 'Memory',
                'order' => 0,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['AI::Memory'] = DB::table('pricing_features')->where('section_id', $sectionIds['AI'])->where('name', 'Memory')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Support'], 'name' => 'Enhanced Support'],
            [
                'name' => 'Enhanced Support',
                'order' => 0,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Support::Enhanced Support'] = DB::table('pricing_features')->where('section_id', $sectionIds['Support'])->where('name', 'Enhanced Support')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Core Platform'], 'name' => 'Projects & contracts'],
            [
                'name' => 'Projects & contracts',
                'order' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Core Platform::Projects & contracts'] = DB::table('pricing_features')->where('section_id', $sectionIds['Core Platform'])->where('name', 'Projects & contracts')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Commercial'], 'name' => 'Variations'],
            [
                'name' => 'Variations',
                'order' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Commercial::Variations'] = DB::table('pricing_features')->where('section_id', $sectionIds['Commercial'])->where('name', 'Variations')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['AI'], 'name' => 'Contract AI analysis'],
            [
                'name' => 'Contract AI analysis',
                'order' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['AI::Contract AI analysis'] = DB::table('pricing_features')->where('section_id', $sectionIds['AI'])->where('name', 'Contract AI analysis')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Support'], 'name' => 'Email support'],
            [
                'name' => 'Email support',
                'order' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Support::Email support'] = DB::table('pricing_features')->where('section_id', $sectionIds['Support'])->where('name', 'Email support')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Core Platform'], 'name' => 'Document management'],
            [
                'name' => 'Document management',
                'order' => 2,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Core Platform::Document management'] = DB::table('pricing_features')->where('section_id', $sectionIds['Core Platform'])->where('name', 'Document management')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Commercial'], 'name' => 'Retention releases'],
            [
                'name' => 'Retention releases',
                'order' => 2,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Commercial::Retention releases'] = DB::table('pricing_features')->where('section_id', $sectionIds['Commercial'])->where('name', 'Retention releases')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Support'], 'name' => 'Priority support'],
            [
                'name' => 'Priority support',
                'order' => 2,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Support::Priority support'] = DB::table('pricing_features')->where('section_id', $sectionIds['Support'])->where('name', 'Priority support')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['AI'], 'name' => 'Prompt library'],
            [
                'name' => 'Prompt library',
                'order' => 2,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['AI::Prompt library'] = DB::table('pricing_features')->where('section_id', $sectionIds['AI'])->where('name', 'Prompt library')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Core Platform'], 'name' => 'Payment applications'],
            [
                'name' => 'Payment applications',
                'order' => 3,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Core Platform::Payment applications'] = DB::table('pricing_features')->where('section_id', $sectionIds['Core Platform'])->where('name', 'Payment applications')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Commercial'], 'name' => 'Final accounts'],
            [
                'name' => 'Final accounts',
                'order' => 3,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Commercial::Final accounts'] = DB::table('pricing_features')->where('section_id', $sectionIds['Commercial'])->where('name', 'Final accounts')->value('id');
        DB::table('pricing_features')->updateOrInsert(
            ['section_id' => $sectionIds['Support'], 'name' => 'Dedicated onboarding'],
            [
                'name' => 'Dedicated onboarding',
                'order' => 3,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        $featureIds['Support::Dedicated onboarding'] = DB::table('pricing_features')->where('section_id', $sectionIds['Support'])->where('name', 'Dedicated onboarding')->value('id');

        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Core Platform::Projects & contracts']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Core Platform::Projects & contracts']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Core Platform::Projects & contracts']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Core Platform::Document management']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Core Platform::Document management']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Core Platform::Document management']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Core Platform::Payment applications']],
            [
                'status' => 'limited',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Core Platform::Payment applications']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Core Platform::Payment applications']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Commercial::Variations']],
            [
                'status' => 'not_included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Commercial::Variations']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Commercial::Variations']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Commercial::Retention releases']],
            [
                'status' => 'not_included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Commercial::Retention releases']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Commercial::Retention releases']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Commercial::Final accounts']],
            [
                'status' => 'not_included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Commercial::Final accounts']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Commercial::Final accounts']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['AI::Contract AI analysis']],
            [
                'status' => 'not_included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['AI::Contract AI analysis']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['AI::Contract AI analysis']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Support::Email support']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Support::Email support']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Support::Email support']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Support::Priority support']],
            [
                'status' => 'not_included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Support::Priority support']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Support::Priority support']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Support::Dedicated onboarding']],
            [
                'status' => 'not_included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Support::Dedicated onboarding']],
            [
                'status' => 'not_included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Support::Dedicated onboarding']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['AI::Assistant']],
            [
                'status' => 'limited',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['AI::Assistant']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['AI::Assistant']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Core Platform::Storage']],
            [
                'status' => 'limited',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Core Platform::Storage']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Core Platform::Storage']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['AI::Memory']],
            [
                'status' => 'limited',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['AI::Memory']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['AI::Memory']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['Support::Enhanced Support']],
            [
                'status' => 'limited',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['Support::Enhanced Support']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['Support::Enhanced Support']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['essential'], 'feature_id' => $featureIds['AI::Prompt library']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['professional'], 'feature_id' => $featureIds['AI::Prompt library']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_features')->updateOrInsert(
            ['plan_id' => $planIds['enterprise'], 'feature_id' => $featureIds['AI::Prompt library']],
            [
                'status' => 'included',
                'value_text' => null,
                'icon_override' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('pricing_included_items')->updateOrInsert(
            ['text' => 'Automation'],
            [
                'text' => 'Automation',
                'icon' => 'check-circle',
                'order' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_included_items')->updateOrInsert(
            ['text' => 'Unlimited projects'],
            [
                'text' => 'Unlimited projects',
                'icon' => 'check-circle',
                'order' => 1,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_included_items')->updateOrInsert(
            ['text' => 'Multiple'],
            [
                'text' => 'Multiple',
                'icon' => 'check-circle',
                'order' => 2,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_included_items')->updateOrInsert(
            ['text' => 'Statutory payment date calculation'],
            [
                'text' => 'Statutory payment date calculation',
                'icon' => 'check-circle',
                'order' => 2,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_included_items')->updateOrInsert(
            ['text' => 'Organisation branding on documents'],
            [
                'text' => 'Organisation branding on documents',
                'icon' => 'check-circle',
                'order' => 3,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_included_items')->updateOrInsert(
            ['text' => 'Role-based access control'],
            [
                'text' => 'Role-based access control',
                'icon' => 'check-circle',
                'order' => 4,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_included_items')->updateOrInsert(
            ['text' => 'Audit trail'],
            [
                'text' => 'Audit trail',
                'icon' => 'check-circle',
                'order' => 5,
                'is_visible' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('pricing_faqs')->updateOrInsert(
            ['question' => 'Can I change plans later?'],
            [
                'question' => 'Can I change plans later?',
                'answer' => 'Yes — upgrade or downgrade at any time; changes take effect on your next billing cycle.',
                'order' => 1,
                'is_enabled' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_faqs')->updateOrInsert(
            ['question' => 'Is there a setup fee?'],
            [
                'question' => 'Is there a setup fee?',
                'answer' => 'No setup fees on Essential or Professional. Enterprise includes a dedicated onboarding package.',
                'order' => 2,
                'is_enabled' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_faqs')->updateOrInsert(
            ['question' => 'Do you offer annual billing?'],
            [
                'question' => 'Do you offer annual billing?',
                'answer' => 'Yes — pay annually and save 15% compared to monthly billing.',
                'order' => 3,
                'is_enabled' => 1,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'max_active_projects'],
            [
                'feature_key' => 'max_active_projects',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '5',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'ai_analyses_per_month'],
            [
                'feature_key' => 'ai_analyses_per_month',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '10',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'storage_gb'],
            [
                'feature_key' => 'storage_gb',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '50',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'custom_branding'],
            [
                'feature_key' => 'custom_branding',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'true',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'advanced_reporting'],
            [
                'feature_key' => 'advanced_reporting',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'false',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'priority_support'],
            [
                'feature_key' => 'priority_support',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'false',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'accounting_exports'],
            [
                'feature_key' => 'accounting_exports',
                'is_applicable' => 0,
                'is_unlimited' => 0,
                'value' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'api_access'],
            [
                'feature_key' => 'api_access',
                'is_applicable' => 0,
                'is_unlimited' => 0,
                'value' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'max_active_projects'],
            [
                'feature_key' => 'max_active_projects',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '25',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'ai_analyses_per_month'],
            [
                'feature_key' => 'ai_analyses_per_month',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '50',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'storage_gb'],
            [
                'feature_key' => 'storage_gb',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '200',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'custom_branding'],
            [
                'feature_key' => 'custom_branding',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'true',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'advanced_reporting'],
            [
                'feature_key' => 'advanced_reporting',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'true',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'priority_support'],
            [
                'feature_key' => 'priority_support',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'true',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'accounting_exports'],
            [
                'feature_key' => 'accounting_exports',
                'is_applicable' => 0,
                'is_unlimited' => 0,
                'value' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'api_access'],
            [
                'feature_key' => 'api_access',
                'is_applicable' => 0,
                'is_unlimited' => 0,
                'value' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'max_active_projects'],
            [
                'feature_key' => 'max_active_projects',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '100',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'ai_analyses_per_month'],
            [
                'feature_key' => 'ai_analyses_per_month',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '200',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'storage_gb'],
            [
                'feature_key' => 'storage_gb',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '500',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'custom_branding'],
            [
                'feature_key' => 'custom_branding',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'true',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'advanced_reporting'],
            [
                'feature_key' => 'advanced_reporting',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'true',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'priority_support'],
            [
                'feature_key' => 'priority_support',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => 'true',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'accounting_exports'],
            [
                'feature_key' => 'accounting_exports',
                'is_applicable' => 0,
                'is_unlimited' => 0,
                'value' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'api_access'],
            [
                'feature_key' => 'api_access',
                'is_applicable' => 0,
                'is_unlimited' => 0,
                'value' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['essential'], 'feature_key' => 'ai_credits_per_month'],
            [
                'feature_key' => 'ai_credits_per_month',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '100',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['professional'], 'feature_key' => 'ai_credits_per_month'],
            [
                'feature_key' => 'ai_credits_per_month',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '1000',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        DB::table('pricing_plan_entitlements')->updateOrInsert(
            ['pricing_plan_id' => $planIds['enterprise'], 'feature_key' => 'ai_credits_per_month'],
            [
                'feature_key' => 'ai_credits_per_month',
                'is_applicable' => 1,
                'is_unlimited' => 0,
                'value' => '0',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
