<?php

namespace Tests\Feature\Pricing;

use App\Models\PricingFaq;
use App\Models\PricingFeature;
use App\Models\PricingFeatureSection;
use App\Models\PricingIncludedItem;
use App\Models\PricingPlan;
use App\Models\PricingPlanFeature;
use App\Models\PricingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingPublicEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeActivePlan(array $overrides = []): PricingPlan
    {
        static $n = 0;
        $n++;

        return PricingPlan::create(array_merge([
            'code' => "plan-{$n}", 'slug' => "plan-{$n}", 'name' => "Plan {$n}",
            'status' => 'active', 'published_at' => now(), 'is_visible' => true,
        ], $overrides));
    }

    public function test_only_active_visible_published_plans_are_returned(): void
    {
        $this->makeActivePlan(['name' => 'Live Plan']);
        $this->makeActivePlan(['name' => 'Draft Plan', 'status' => 'draft', 'published_at' => null]);
        $this->makeActivePlan(['name' => 'Archived Plan', 'status' => 'archived']);
        $this->makeActivePlan(['name' => 'Hidden Plan', 'is_visible' => false]);
        $this->makeActivePlan(['name' => 'Future Plan', 'published_at' => now()->addDay()]);

        $response = $this->getJson('/api/pricing');

        $response->assertOk();
        $names = collect($response->json('data.plans'))->pluck('name');
        $this->assertEquals(['Live Plan'], $names->all());
    }

    public function test_internal_fields_are_never_exposed(): void
    {
        $this->makeActivePlan(['code' => 'secret-code']);

        $response = $this->getJson('/api/pricing');

        $json = $response->getContent();
        $this->assertStringNotContainsString('secret-code', $json);
        $this->assertStringNotContainsString('"code"', $json);
        $this->assertStringNotContainsString('"status"', $json);
        $this->assertStringNotContainsString('"published_at"', $json);
        $this->assertStringNotContainsString('"deleted_at"', $json);
    }

    public function test_admin_managed_plan_and_page_fields_are_returned_unchanged(): void
    {
        $settings = PricingSetting::instance();
        $settings->update([
            'published' => true,
            'hero_title' => 'Configured pricing',
            'hero_subtitle' => 'Configured subtitle',
            'section_title' => 'Configured plans',
            'monthly_billing_enabled' => true,
            'annual_billing_enabled' => true,
            'discount_label' => 'Configured saving',
            'everything_included_title' => 'Configured inclusions',
            'everything_included_subtitle' => 'Configured inclusion detail',
            'final_cta_title' => 'Configured CTA',
            'final_cta_subtitle' => 'Configured CTA detail',
            'primary_cta_text' => 'Configured primary',
            'primary_cta_url' => '/configured-primary',
            'secondary_cta_text' => 'Configured secondary',
            'secondary_cta_url' => '/configured-secondary',
        ]);

        $plan = $this->makeActivePlan([
            'name' => 'Configured plan',
            'monthly_price' => 37.50,
            'annual_price' => 382.50,
            'currency' => 'GBP',
            'price_prefix' => 'From',
            'price_suffix' => 'excluding VAT',
            'description' => 'Configured description',
            'summary' => 'Configured audience',
            'cta_text' => 'Configured plan CTA',
            'cta_url' => '/configured-plan',
            'is_popular' => true,
            'badge_text' => 'Configured badge',
            'custom_label' => 'Configured custom label',
        ]);

        $section = PricingFeatureSection::create(['name' => 'Configured section', 'is_visible' => true]);
        $feature = PricingFeature::create([
            'section_id' => $section->id,
            'name' => 'Configured feature',
            'is_visible' => true,
        ]);
        PricingPlanFeature::create([
            'plan_id' => $plan->id,
            'feature_id' => $feature->id,
            'status' => 'limited',
            'value_text' => 'Configured limit',
        ]);
        PricingIncludedItem::create(['text' => 'Configured included item', 'is_visible' => true]);
        PricingFaq::create([
            'question' => 'Configured question?',
            'answer' => 'Configured answer.',
            'is_enabled' => true,
        ]);

        $response = $this->getJson('/api/pricing')->assertOk();

        $response
            ->assertJsonPath('data.settings.hero_title', 'Configured pricing')
            ->assertJsonPath('data.settings.hero_subtitle', 'Configured subtitle')
            ->assertJsonPath('data.settings.section_title', 'Configured plans')
            ->assertJsonPath('data.settings.discount_label', 'Configured saving')
            ->assertJsonPath('data.settings.everything_included_title', 'Configured inclusions')
            ->assertJsonPath('data.settings.final_cta_title', 'Configured CTA')
            ->assertJsonPath('data.settings.primary_cta_text', 'Configured primary')
            ->assertJsonPath('data.settings.primary_cta_url', '/configured-primary')
            ->assertJsonPath('data.settings.secondary_cta_text', 'Configured secondary')
            ->assertJsonPath('data.plans.0.name', 'Configured plan')
            ->assertJsonPath('data.plans.0.monthly_price', '37.50')
            ->assertJsonPath('data.plans.0.annual_price', '382.50')
            ->assertJsonPath('data.plans.0.description', 'Configured description')
            ->assertJsonPath('data.plans.0.summary', 'Configured audience')
            ->assertJsonPath('data.plans.0.is_popular', true)
            ->assertJsonPath('data.plans.0.badge_text', 'Configured badge')
            ->assertJsonPath("data.plans.0.features.{$feature->id}.status", 'limited')
            ->assertJsonPath("data.plans.0.features.{$feature->id}.value_text", 'Configured limit')
            ->assertJsonPath('data.included_items.0.text', 'Configured included item')
            ->assertJsonPath('data.faqs.0.question', 'Configured question?')
            ->assertJsonPath('data.faqs.0.answer', 'Configured answer.');
    }

    public function test_only_visible_feature_sections_features_and_enabled_faqs_returned(): void
    {
        $visibleSection = PricingFeatureSection::create(['name' => 'Visible Section', 'is_visible' => true]);
        PricingFeatureSection::create(['name' => 'Hidden Section', 'is_visible' => false]);
        PricingFeature::create(['section_id' => $visibleSection->id, 'name' => 'Visible Feature', 'is_visible' => true]);
        PricingFeature::create(['section_id' => $visibleSection->id, 'name' => 'Hidden Feature', 'is_visible' => false]);

        PricingIncludedItem::create(['text' => 'Visible Item', 'is_visible' => true]);
        PricingIncludedItem::create(['text' => 'Hidden Item', 'is_visible' => false]);

        PricingFaq::create(['question' => 'Enabled Q', 'answer' => 'A', 'is_enabled' => true]);
        PricingFaq::create(['question' => 'Disabled Q', 'answer' => 'A', 'is_enabled' => false]);

        $response = $this->getJson('/api/pricing');

        $sections = collect($response->json('data.feature_sections'));
        $this->assertEquals(['Visible Section'], $sections->pluck('name')->all());
        $this->assertEquals(['Visible Feature'], collect($sections->first()['features'])->pluck('name')->all());

        $this->assertEquals(['Visible Item'], collect($response->json('data.included_items'))->pluck('text')->all());
        $this->assertEquals(['Enabled Q'], collect($response->json('data.faqs'))->pluck('question')->all());
    }

    public function test_empty_state_when_nothing_published(): void
    {
        PricingSetting::instance();

        $response = $this->getJson('/api/pricing');

        $response->assertOk();
        $this->assertEquals([], $response->json('data.plans'));
    }
}
