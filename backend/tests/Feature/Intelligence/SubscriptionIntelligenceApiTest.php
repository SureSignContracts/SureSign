<?php

namespace Tests\Feature\Intelligence;

use App\Models\ActivityLog;
use App\Models\Contract;
use App\Models\ContractAiAnalysis;
use App\Models\Document;
use App\Models\FileUpload;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Billing\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phase G3 — the Subscription Intelligence Centre's single read-only
 * endpoint (`GET /billing/intelligence`). Covers usage computation from
 * real authoritative rows (projects/AI analyses/storage), the trial card
 * appearing/disappearing with access mode, health/recommendation
 * generation, the timeline reusing existing ActivityLog rows, and
 * organisation-scoped tenant isolation.
 */
class SubscriptionIntelligenceApiTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name): Organization
    {
        return Organization::create([
            'name' => $name,
            'slug' => strtolower($name) . '-' . random_int(1, 10000000),
            'timezone' => 'Europe/London',
        ]);
    }

    private function plan(string $code): PricingPlan
    {
        return PricingPlan::create(['code' => $code, 'slug' => $code, 'name' => ucfirst($code), 'status' => 'active']);
    }

    private function activeSubscription(Organization $org, PricingPlan $plan): Subscription
    {
        return Subscription::create([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'provider' => 'stripe',
            'livemode' => false,
            'internal_reference' => 'SUB-INTEL-' . $org->id,
            'status' => SubscriptionStatus::ACTIVE,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'unit_amount' => 79900,
            'quantity' => 1,
            'subtotal_amount' => 79900,
            'tax_amount' => 0,
            'total_amount' => 79900,
            'activated_at' => now()->subDays(10),
            'plan_code_snapshot' => $plan->code,
            'plan_name_snapshot' => $plan->name,
        ]);
    }

    public function test_no_subscription_still_returns_a_usable_payload(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/billing/intelligence')->assertOk();

        $this->assertNull($response->json('data.subscription'));
        $this->assertNull($response->json('data.trial'));
        $this->assertSame('unknown', $response->json('data.health.overall'));
        $this->assertIsArray($response->json('data.usage'));
        $this->assertNotEmpty($response->json('data.usage'));
    }

    public function test_usage_is_generated_dynamically_for_every_non_dormant_usage_key(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->activeSubscription($org, $this->plan('professional'));
        Sanctum::actingAs($user);

        $usage = $this->getJson('/api/billing/intelligence')->assertOk()->json('data.usage');
        $keys = collect($usage)->pluck('feature_key')->all();

        $this->assertEqualsCanonicalizing(
            ['max_active_projects', 'ai_analyses_per_month', 'storage_gb'],
            $keys,
        );
    }

    public function test_active_project_count_only_includes_non_terminal_statuses(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->activeSubscription($org, $this->plan('professional'));

        Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'A', 'status' => 'active']);
        Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'B', 'status' => 'on_hold']);
        Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'C', 'status' => 'completed']);
        Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'D', 'status' => 'cancelled']);

        Sanctum::actingAs($user);
        $usage = $this->getJson('/api/billing/intelligence')->assertOk()->json('data.usage');
        $projects = collect($usage)->firstWhere('feature_key', 'max_active_projects');

        $this->assertEquals(2, $projects['used']);
    }

    public function test_ai_analyses_count_excludes_pending_and_cancelled_and_respects_calendar_month(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->activeSubscription($org, $this->plan('professional'));
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P', 'status' => 'active']);
        $contract = Contract::create(['organization_id' => $org->id, 'project_id' => $project->id, 'created_by' => $user->id, 'title' => 'C1', 'type' => 'main_contract']);

        $make = fn (string $status, $createdAt) => tap(ContractAiAnalysis::create([
            'organization_id' => $org->id, 'project_id' => $project->id, 'contract_id' => $contract->id,
            'created_by' => $user->id, 'status' => $status,
        ]))->forceFill(['created_at' => $createdAt])->save();

        $make('completed', now());
        $make('failed', now());
        $make('pending', now());
        $make('cancelled', now());
        $make('completed', now()->subMonthsNoOverflow(2));

        Sanctum::actingAs($user);
        $usage = $this->getJson('/api/billing/intelligence')->assertOk()->json('data.usage');
        $ai = collect($usage)->firstWhere('feature_key', 'ai_analyses_per_month');

        $this->assertEquals(2, $ai['used']);
    }

    public function test_storage_sums_file_size_across_documents_and_file_uploads(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $this->activeSubscription($org, $this->plan('professional'));
        $project = Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'P', 'status' => 'active']);

        Document::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Doc', 'type' => 'other', 'file_size' => 500_000_000,
        ]);
        FileUpload::create([
            'organization_id' => $org->id, 'uploaded_by' => $user->id,
            'original_name' => 'a.pdf', 'stored_name' => 'a.pdf', 'file_path' => 'a.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 500_000_000,
        ]);

        Sanctum::actingAs($user);
        $storage = $this->getJson('/api/billing/intelligence')->assertOk()->json('data.storage');

        $this->assertEquals(1.0, $storage['used']); // 1,000,000,000 bytes = 1.0 GB
    }

    public function test_trial_card_appears_only_while_access_mode_is_trial(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('professional');

        $subscription = Subscription::create([
            'organization_id' => $org->id, 'pricing_plan_id' => $plan->id, 'provider' => 'stripe', 'livemode' => false,
            'internal_reference' => 'SUB-TRIAL-1', 'status' => SubscriptionStatus::TRIALING, 'billing_interval' => 'monthly',
            'currency' => 'GBP', 'unit_amount' => 79900, 'quantity' => 1, 'subtotal_amount' => 79900, 'tax_amount' => 0,
            'total_amount' => 79900, 'starts_at' => now()->subDays(4), 'trial_ends_at' => now()->addDays(3),
        ]);

        Sanctum::actingAs($user);
        $trial = $this->getJson('/api/billing/intelligence')->assertOk()->json('data.trial');

        $this->assertNotNull($trial);
        $this->assertTrue($trial['is_active']);
        $this->assertSame(3, $trial['days_remaining']);

        // Convert: status moves to active — the trial card must disappear automatically.
        $subscription->update(['status' => SubscriptionStatus::ACTIVE]);
        $trialAfterConversion = $this->getJson('/api/billing/intelligence')->assertOk()->json('data.trial');
        $this->assertNull($trialAfterConversion);
    }

    public function test_near_limit_usage_produces_a_warning_health_status_and_a_recommendation(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $plan = $this->plan('professional');
        $subscription = $this->activeSubscription($org, $plan);

        \App\Models\PricingPlanEntitlement::create([
            'pricing_plan_id' => $plan->id, 'feature_key' => 'max_active_projects',
            'is_applicable' => true, 'is_unlimited' => false, 'value' => 5,
        ]);

        // FeatureGate resolves a modern (non-legacy) subscription from its
        // frozen entitlement snapshot, not live plan defaults — creating a
        // real snapshot here exercises the actual, unchanged resolution
        // path rather than assuming a live fallback that only applies to
        // legacy pre-snapshot subscriptions.
        app(\App\Services\Entitlements\EntitlementSnapshotService::class)
            ->snapshotForActivation($subscription, \Carbon\CarbonImmutable::now());

        for ($i = 0; $i < 5; $i++) {
            Project::create(['organization_id' => $org->id, 'created_by' => $user->id, 'name' => "P{$i}", 'status' => 'active']);
        }

        Sanctum::actingAs($user);
        $data = $this->getJson('/api/billing/intelligence')->assertOk()->json('data');

        $projectsUsage = collect($data['usage'])->firstWhere('feature_key', 'max_active_projects');
        $this->assertSame('exceeded', $projectsUsage['status']);
        $this->assertSame('exceeded', $data['health']['overall']);
        $this->assertNotEmpty(collect($data['recommendations'])->where('key', 'upgrade.max_active_projects'));
    }

    public function test_timeline_reuses_existing_activity_log_rows(): void
    {
        $org = $this->org('Acme');
        $user = User::factory()->create(['organization_id' => $org->id]);
        $subscription = $this->activeSubscription($org, $this->plan('professional'));

        ActivityLog::record(
            action: 'subscription.activated',
            description: 'Activated subscription',
            user: $user,
            subject: $subscription,
            organizationId: $org->id,
        );

        Sanctum::actingAs($user);
        $timeline = $this->getJson('/api/billing/intelligence')->assertOk()->json('data.timeline');

        $this->assertNotEmpty($timeline);
        $this->assertSame('subscription.activated', $timeline[0]['action']);
    }

    public function test_organisation_never_sees_another_organisations_usage_or_timeline(): void
    {
        $orgA = $this->org('Acme');
        $orgB = $this->org('Globex');
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $plan = $this->plan('professional');
        $this->activeSubscription($orgA, $plan);
        $subB = $this->activeSubscription($orgB, $plan);

        Project::create(['organization_id' => $orgB->id, 'created_by' => $userA->id, 'name' => 'Other org project', 'status' => 'active']);
        ActivityLog::record(action: 'subscription.activated', description: 'Org B activated', subject: $subB, organizationId: $orgB->id);

        Sanctum::actingAs($userA);
        $data = $this->getJson('/api/billing/intelligence')->assertOk()->json('data');

        $projectsUsage = collect($data['usage'])->firstWhere('feature_key', 'max_active_projects');
        $this->assertEquals(0, $projectsUsage['used']);
        $this->assertEmpty($data['timeline']);
    }

    public function test_guest_is_unauthorized(): void
    {
        $this->getJson('/api/billing/intelligence')->assertUnauthorized();
    }
}
