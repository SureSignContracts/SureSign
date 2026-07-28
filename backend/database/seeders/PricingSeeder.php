<?php

namespace Database\Seeders;

use App\Models\PricingFaq;
use App\Models\PricingFeature;
use App\Models\PricingFeatureSection;
use App\Models\PricingIncludedItem;
use App\Models\PricingPlan;
use App\Models\PricingPlanFeature;
use App\Models\PricingSetting;
use App\Services\Entitlements\PlanEntitlementRepository;
use Illuminate\Database\Seeder;

class PricingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = PricingSetting::instance();
        $settings->fill([
            'hero_title'                    => 'Simple, transparent pricing',
            'hero_subtitle'                 => 'Choose the plan that fits how your team runs contracts — no hidden fees, no surprises.',
            'section_title'                 => 'Plans',
            'monthly_billing_enabled'       => true,
            'annual_billing_enabled'        => true,
            'discount_label'                => 'Save 15% billed annually',
            'everything_included_title'     => 'Everything Included',
            'everything_included_subtitle'  => 'Every plan ships with the fundamentals your team needs to run contracts properly.',
            'final_cta_title'               => 'Ready to see SureSign in action?',
            'final_cta_subtitle'            => 'Book a demo and we\'ll walk through your contracts, your team, and how SureSign fits.',
            'primary_cta_text'              => 'Book a Demo',
            'primary_cta_url'               => '/book/demo?src=pricing',
            'secondary_cta_text'            => 'Contact Sales',
            'secondary_cta_url'             => '/book-a-demo?src=pricing',
            'published'                     => true,
        ])->save();

        $plans = [
            [
                'code' => 'essential', 'slug' => 'essential', 'name' => 'Essential', 'order' => 1,
                'monthly_price' => 299, 'annual_price' => 3050, 'currency' => 'GBP',
                'price_prefix' => 'From', 'price_suffix' => '/month + VAT',
                'summary' => 'For small teams running their first few contracts.',
                'description' => 'Everything you need to administer contracts properly, without the overhead.',
                'cta_text' => 'Get Started', 'cta_url' => '/book/demo?src=pricing', 'is_visible' => true,
                'icon' => 'zap', 'accent_color' => 'neutral', 'background_style' => 'surface',
                'status' => 'active', 'published_at' => now(),
            ],
            [
                'code' => 'professional', 'slug' => 'professional', 'name' => 'Professional', 'order' => 2,
                'monthly_price' => 799, 'annual_price' => 8150, 'currency' => 'GBP',
                'price_prefix' => 'From', 'price_suffix' => '/month + VAT',
                'summary' => 'For growing contractors managing multiple live projects.',
                'description' => 'Full payment application lifecycle, AI contract analysis, and subcontract package management.',
                'cta_text' => 'Book a Demo', 'cta_url' => '/book/demo?src=pricing', 'is_visible' => true, 'is_popular' => true,
                'badge_text' => 'Most Popular', 'badge_color' => 'gold', 'icon' => 'star', 'accent_color' => 'gold', 'background_style' => 'elevated',
                'status' => 'active', 'published_at' => now(),
            ],
            [
                'code' => 'enterprise', 'slug' => 'enterprise', 'name' => 'Enterprise', 'order' => 3,
                'currency' => 'GBP', 'price_prefix' => 'Custom', 'price_suffix' => null,
                'summary' => 'For multi-entity contractors with bespoke workflow and compliance needs.',
                'description' => 'Dedicated onboarding, custom workflow configuration, and priority support.',
                'cta_text' => 'Contact Sales', 'cta_url' => '/book-a-demo?src=pricing', 'is_visible' => true,
                'icon' => 'building', 'accent_color' => 'accent', 'background_style' => 'gradient',
                'custom_label' => 'Custom pricing',
                'status' => 'active', 'published_at' => now(),
            ],
        ];

        $planModels = [];
        foreach ($plans as $data) {
            $planModels[$data['code']] = PricingPlan::updateOrCreate(['code' => $data['code']], $data);
        }

        // Phase G1 — on a genuinely fresh install, this is the first
        // point at which a real `pricing_plans` row exists for
        // "essential"/"professional"/"enterprise" for the migration's own
        // seed step to have attached rows to (seeders always run AFTER
        // migrations). A no-op if entitlement rows already exist for a
        // plan (e.g. the migration's own seed step already found it in a
        // non-fresh environment).
        $planEntitlements = app(PlanEntitlementRepository::class);
        foreach ($planModels as $plan) {
            $planEntitlements->seedExactDefaultsForKnownPlan($plan);
        }

        // Feature name => [starter status, professional status, enterprise status]
        $sections = [
            'Core Platform' => [
                'Projects & contracts'  => ['included', 'included', 'included'],
                'Document management'   => ['included', 'included', 'included'],
                'Payment applications'  => ['limited', 'included', 'included'],
            ],
            'Commercial' => [
                'Variations'          => ['not_included', 'included', 'included'],
                'Retention releases'  => ['not_included', 'included', 'included'],
                'Final accounts'      => ['not_included', 'included', 'included'],
            ],
            'AI' => [
                'Contract AI analysis' => ['not_included', 'included', 'included'],
                'Prompt library'       => ['included', 'included', 'included'],
            ],
            'Support' => [
                'Email support'        => ['included', 'included', 'included'],
                'Priority support'     => ['not_included', 'included', 'included'],
                'Dedicated onboarding' => ['not_included', 'not_included', 'included'],
            ],
        ];

        $sectionOrder = 1;
        foreach ($sections as $sectionName => $features) {
            $section = PricingFeatureSection::updateOrCreate(['name' => $sectionName], ['order' => $sectionOrder++]);

            $featureOrder = 1;
            foreach ($features as $featureName => $statuses) {
                $feature = PricingFeature::updateOrCreate(
                    ['section_id' => $section->id, 'name' => $featureName],
                    ['order' => $featureOrder++]
                );

                foreach (array_values($planModels) as $index => $plan) {
                    PricingPlanFeature::updateOrCreate(
                        ['plan_id' => $plan->id, 'feature_id' => $feature->id],
                        ['status' => $statuses[$index] ?? 'not_included']
                    );
                }
            }
        }

        $includedItems = [
            'Unlimited projects', 'Statutory payment date calculation', 'Organisation branding on documents',
            'Role-based access control', 'Audit trail',
        ];
        foreach ($includedItems as $i => $text) {
            PricingIncludedItem::updateOrCreate(['text' => $text], ['order' => $i + 1, 'icon' => 'check-circle']);
        }

        $faqs = [
            ['question' => 'Can I change plans later?', 'answer' => 'Yes — upgrade or downgrade at any time; changes take effect on your next billing cycle.'],
            ['question' => 'Is there a setup fee?', 'answer' => 'No setup fees on Essential or Professional. Enterprise includes a dedicated onboarding package.'],
            ['question' => 'Do you offer annual billing?', 'answer' => 'Yes — pay annually and save 15% compared to monthly billing.'],
        ];
        foreach ($faqs as $i => $data) {
            PricingFaq::updateOrCreate(['question' => $data['question']], $data + ['order' => $i + 1]);
        }
    }
}
