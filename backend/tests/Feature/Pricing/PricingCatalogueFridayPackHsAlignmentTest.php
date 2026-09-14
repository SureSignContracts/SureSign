<?php

namespace Tests\Feature\Pricing;

use App\Models\PricingFeature;
use App\Models\PricingFeatureSection;
use App\Models\PricingPlan;
use App\Models\PricingPlanFeature;
use Database\Seeders\PricingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Marketing, Pricing & Documentation Refresh — Batch 1 (Pricing Alignment).
 *
 * Regression guard for the public pricing feature catalogue matching the
 * real Feature::FRIDAY_PACKS commercial entitlement (Essential=false,
 * Professional/Enterprise=true — see
 * 2026_09_04_000002_add_friday_packs_entitlement_to_pricing_plans.php) and
 * the confirmed-unsupported "Assistant"/"Memory" AI Assistant chat rows
 * being removed from public plan features.
 */
class PricingCatalogueFridayPackHsAlignmentTest extends TestCase
{
    use RefreshDatabase;

    private function statusFor(string $featureName, string $planCode): ?string
    {
        $plan = PricingPlan::where('code', $planCode)->firstOrFail();
        $feature = PricingFeature::where('name', $featureName)->firstOrFail();

        return PricingPlanFeature::where('plan_id', $plan->id)
            ->where('feature_id', $feature->id)
            ->value('status');
    }

    public function test_seeder_includes_weekly_friday_packs_matching_the_real_entitlement(): void
    {
        (new PricingSeeder())->run();

        $this->assertSame('not_included', $this->statusFor('Weekly Friday Packs', 'essential'));
        $this->assertSame('included', $this->statusFor('Weekly Friday Packs', 'professional'));
        $this->assertSame('included', $this->statusFor('Weekly Friday Packs', 'enterprise'));
    }

    public function test_seeder_includes_health_and_safety_records_on_every_plan(): void
    {
        (new PricingSeeder())->run();

        foreach (['essential', 'professional', 'enterprise'] as $code) {
            $this->assertSame('included', $this->statusFor('Health & Safety records', $code));
        }
    }

    public function test_seeder_does_not_reintroduce_assistant_or_memory(): void
    {
        (new PricingSeeder())->run();

        $this->assertNull(PricingFeature::where('name', 'Assistant')->first());
        $this->assertNull(PricingFeature::where('name', 'Memory')->first());
    }

    public function test_seeder_preserves_valid_ai_features(): void
    {
        (new PricingSeeder())->run();

        $this->assertNotNull(PricingFeature::where('name', 'Contract AI analysis')->first());
        $this->assertNotNull(PricingFeature::where('name', 'Prompt library')->first());
    }

    public function test_migration_removes_live_assistant_and_memory_rows_without_touching_others(): void
    {
        (new PricingSeeder())->run();

        // Simulate the exact drift the audit found: an operator adding
        // "Assistant"/"Memory" via Pricing Management on top of an
        // already-seeded catalogue.
        $aiSection = PricingFeatureSection::where('name', 'AI')->firstOrFail();
        $assistant = PricingFeature::create(['section_id' => $aiSection->id, 'name' => 'Assistant', 'order' => 99]);
        $memory = PricingFeature::create(['section_id' => $aiSection->id, 'name' => 'Memory', 'order' => 100]);
        foreach (PricingPlan::all() as $plan) {
            PricingPlanFeature::create(['plan_id' => $plan->id, 'feature_id' => $assistant->id, 'status' => 'included']);
            PricingPlanFeature::create(['plan_id' => $plan->id, 'feature_id' => $memory->id, 'status' => 'included']);
        }

        $this->runMigration('2026_09_08_000001_align_pricing_catalogue_with_friday_pack_and_hs.php');

        $this->assertNull(PricingFeature::where('name', 'Assistant')->first());
        $this->assertNull(PricingFeature::where('name', 'Memory')->first());
        $this->assertDatabaseMissing('pricing_plan_features', ['feature_id' => $assistant->id]);
        $this->assertDatabaseMissing('pricing_plan_features', ['feature_id' => $memory->id]);

        // Unrelated existing rows are untouched.
        $this->assertNotNull(PricingFeature::where('name', 'Contract AI analysis')->first());
        $this->assertNotNull(PricingFeature::where('name', 'Payment applications')->first());
    }

    public function test_migration_is_idempotent_and_never_duplicates_rows(): void
    {
        (new PricingSeeder())->run();

        // Simulate the real target scenario: an already-provisioned
        // environment seeded before this batch, so the two rows don't
        // exist yet and the migration must actually insert them — then
        // prove a second run never duplicates that insert.
        PricingFeature::where('name', 'Weekly Friday Packs')->delete();
        PricingFeature::where('name', 'Health & Safety records')->delete();

        $this->runMigration('2026_09_08_000001_align_pricing_catalogue_with_friday_pack_and_hs.php');
        $this->assertSame('not_included', $this->statusFor('Weekly Friday Packs', 'essential'));
        $this->assertSame('included', $this->statusFor('Weekly Friday Packs', 'professional'));
        $this->assertSame('included', $this->statusFor('Health & Safety records', 'essential'));

        $this->runMigration('2026_09_08_000001_align_pricing_catalogue_with_friday_pack_and_hs.php');

        $this->assertSame(1, PricingFeature::where('name', 'Weekly Friday Packs')->count());
        $this->assertSame(1, PricingFeature::where('name', 'Health & Safety records')->count());
    }

    public function test_migration_never_reintroduces_assistant_or_memory_once_removed(): void
    {
        (new PricingSeeder())->run();

        $this->runMigration('2026_09_08_000001_align_pricing_catalogue_with_friday_pack_and_hs.php');

        $this->assertNull(PricingFeature::where('name', 'Assistant')->first());
        $this->assertNull(PricingFeature::where('name', 'Memory')->first());
    }

    /**
     * Runs a single migration file's up() directly against the test
     * database — mirrors how this repo's other targeted migration tests
     * exercise one migration in isolation without a full migrate:fresh.
     */
    private function runMigration(string $filename): void
    {
        $path = database_path("migrations/{$filename}");
        $migration = require $path;
        $migration->up();
    }
}
