<?php

namespace Tests\Feature\Billing;

use App\Models\ActivityLog;
use App\Models\BillingCheckoutSession;
use App\Models\Organization;
use App\Models\PricingPlan;
use App\Models\User;
use App\Services\Billing\CheckoutSessionLifecycleService;
use App\Services\Billing\Exceptions\CheckoutSessionLifecycleConflictException;
use App\Support\Billing\CheckoutSessionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutSessionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private CheckoutSessionLifecycleService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(CheckoutSessionLifecycleService::class);
    }

    private function makeSession(array $overrides = []): BillingCheckoutSession
    {
        $org = Organization::create(['name' => 'Acme', 'slug' => 'acme-' . random_int(1, 10000000), 'timezone' => 'Europe/London']);
        $plan = PricingPlan::create(['code' => 'p' . random_int(1, 10000000), 'slug' => 's' . random_int(1, 10000000), 'name' => 'Plan', 'currency' => 'GBP']);
        $user = User::factory()->create(['organization_id' => $org->id]);

        return BillingCheckoutSession::create(array_merge([
            'organization_id' => $org->id,
            'pricing_plan_id' => $plan->id,
            'initiated_by_user_id' => $user->id,
            'provider' => 'stripe',
            'provider_checkout_session_id' => 'cs_' . random_int(1, 10000000),
            'internal_reference' => 'CHK-' . random_int(1, 10000000),
            'status' => CheckoutSessionStatus::OPEN,
            'billing_interval' => 'monthly',
            'currency' => 'GBP',
            'amount' => 2999,
            'success_url' => '/success',
            'cancel_url' => '/cancel',
        ], $overrides));
    }

    public function test_marks_an_open_session_completed(): void
    {
        $session = $this->makeSession();

        $updated = $this->service->markCompleted($session, CarbonImmutable::now(), 'evt_1');

        $this->assertSame(CheckoutSessionStatus::COMPLETED, $updated->status);
        $this->assertNotNull($updated->completed_at);
    }

    public function test_marking_completed_twice_is_idempotent(): void
    {
        $session = $this->makeSession();
        $occurredAt = CarbonImmutable::now();

        $this->service->markCompleted($session, $occurredAt, 'evt_1');
        $updated = $this->service->markCompleted($session, $occurredAt, 'evt_1_retry');

        $this->assertSame(CheckoutSessionStatus::COMPLETED, $updated->status);
        $this->assertSame(1, ActivityLog::where('action', 'billing.checkout.completed')->count());
    }

    public function test_marks_an_open_session_expired(): void
    {
        $session = $this->makeSession();

        $updated = $this->service->markExpired($session, 'evt_2');

        $this->assertSame(CheckoutSessionStatus::EXPIRED, $updated->status);
    }

    public function test_marking_expired_twice_is_idempotent(): void
    {
        $session = $this->makeSession();

        $this->service->markExpired($session, 'evt_2');
        $updated = $this->service->markExpired($session, 'evt_2_retry');

        $this->assertSame(CheckoutSessionStatus::EXPIRED, $updated->status);
        $this->assertSame(1, ActivityLog::where('action', 'billing.checkout.expired')->count());
    }

    public function test_cannot_mark_an_expired_session_completed(): void
    {
        $session = $this->makeSession(['status' => CheckoutSessionStatus::EXPIRED]);

        $this->expectException(CheckoutSessionLifecycleConflictException::class);
        $this->service->markCompleted($session, CarbonImmutable::now(), 'evt_3');
    }

    public function test_cannot_mark_a_completed_session_expired(): void
    {
        $session = $this->makeSession(['status' => CheckoutSessionStatus::COMPLETED, 'completed_at' => now()]);

        $this->expectException(CheckoutSessionLifecycleConflictException::class);
        $this->service->markExpired($session, 'evt_4');
    }
}
