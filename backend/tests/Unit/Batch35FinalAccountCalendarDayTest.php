<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Models\FinalAccount;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\OperationalIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

/**
 * Batch 3.5: FinalAccount::isReviewOverdue()/isCloseOutOverdue() and
 * OperationalIntelligenceService::normalizeFinalAccount() now compute
 * calendar-day deadlines (not exact elapsed-hour durations) from the
 * organisation's own local calendar date — see config/suresign.php's
 * "days allowed" framing and the Batch 3.5 report.
 */
class Batch35FinalAccountCalendarDayTest extends TestCase
{
    use RefreshDatabase;

    private static int $orgSeq = 0;

    private function makeOrgProjectContract(string $timezone): array
    {
        $n = ++static::$orgSeq;
        $org = Organization::create(['name' => "Org {$n}", 'slug' => "org-{$n}", 'timezone' => $timezone]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $project = Project::create([
            'organization_id' => $org->id, 'created_by' => $user->id, 'name' => 'Project', 'status' => 'active',
        ]);
        $contract = Contract::create([
            'project_id' => $project->id, 'organization_id' => $org->id, 'created_by' => $user->id,
            'title' => 'Contract', 'type' => 'main_contract', 'status' => 'active',
        ]);

        return [$org, $project, $contract];
    }

    private function makeFinalAccount(Organization $org, Project $project, Contract $contract, array $attrs): FinalAccount
    {
        return FinalAccount::create(array_merge([
            'organization_id' => $org->id,
            'project_id'      => $project->id,
            'contract_id'     => $contract->id,
            'reference'       => 'FA-001',
        ], $attrs));
    }

    // ── FinalAccount::isReviewOverdue() — calendar-day, not elapsed-hours ──

    public function test_review_is_not_overdue_on_the_sla_deadline_day_itself(): void
    {
        [$org, $project, $contract] = $this->makeOrgProjectContract('Asia/Manila');

        // reviewed_at = 2026-07-01 10:00 UTC = 18:00 Manila. SLA = 14 days.
        // Deadline calendar day (Manila) = 2026-07-15. "Now" is later that
        // same deadline day — under a naive addDays(14)->isPast() this
        // would already be past 336 hours and read as overdue; under the
        // correct calendar-day rule the 15th is still on time.
        $fa = $this->makeFinalAccount($org, $project, $contract, [
            'status' => FinalAccount::STATUS_UNDER_REVIEW,
            'reviewed_at' => '2026-07-01 10:00:00',
        ]);

        Date::setTestNow('2026-07-15 10:00:00'); // 18:00 on the 15th in Manila — the deadline day itself.

        $this->assertFalse($fa->isReviewOverdue(), 'The deadline calendar day itself must still be on time.');

        Date::setTestNow();
    }

    public function test_review_is_overdue_the_day_after_the_sla_deadline(): void
    {
        [$org, $project, $contract] = $this->makeOrgProjectContract('Asia/Manila');

        $fa = $this->makeFinalAccount($org, $project, $contract, [
            'status' => FinalAccount::STATUS_UNDER_REVIEW,
            'reviewed_at' => '2026-07-01 10:00:00', // 18:00 Manila -> deadline day = 2026-07-15
        ]);

        Date::setTestNow('2026-07-15 17:00:00'); // 01:00 on the 16th in Manila — one day past the deadline day.

        $this->assertTrue($fa->isReviewOverdue());

        Date::setTestNow();
    }

    public function test_review_overdue_uses_the_reviewed_at_instants_own_local_calendar_day(): void
    {
        // reviewed_at near midnight UTC lands on different calendar days in
        // different organisation timezones — the SLA deadline must be
        // computed from each organisation's own local day, not UTC's.
        [$manilaOrg, $manilaProject, $manilaContract] = $this->makeOrgProjectContract('Asia/Manila');
        [$nyOrg, $nyProject, $nyContract] = $this->makeOrgProjectContract('America/New_York');

        // 23:30 UTC on 2026-07-01 is 2026-07-02 07:30 in Manila (UTC+8),
        // but still 2026-07-01 19:30 in New York (UTC-4, EDT).
        $reviewedAt = '2026-07-01 23:30:00';

        $manilaFa = $this->makeFinalAccount($manilaOrg, $manilaProject, $manilaContract, [
            'status' => FinalAccount::STATUS_UNDER_REVIEW, 'reviewed_at' => $reviewedAt,
        ]);
        $nyFa = $this->makeFinalAccount($nyOrg, $nyProject, $nyContract, [
            'status' => FinalAccount::STATUS_UNDER_REVIEW, 'reviewed_at' => $reviewedAt,
        ]);

        // Manila's deadline day = 07-02 + 14 = 07-16. New York's deadline
        // day = 07-01 + 14 = 07-15 — a full day earlier, purely because the
        // same UTC instant fell on different local calendar days.
        //
        // 2026-07-16 06:00 UTC = 2026-07-16 14:00 in Manila (still its own
        // deadline day, the 16th — not yet overdue) but 2026-07-16 02:00 in
        // New York (UTC-4, EDT) — one full day past its deadline (the 15th).
        Date::setTestNow('2026-07-16 06:00:00');

        $this->assertFalse($manilaFa->isReviewOverdue(), "Manila's deadline is the 16th — today is the 16th, still on time.");
        $this->assertTrue($nyFa->isReviewOverdue(), "New York's deadline was the 15th — today is the 16th, one day late.");

        Date::setTestNow();
    }

    // ── OperationalIntelligenceService::normalizeFinalAccount() ─────────────

    public function test_dispute_window_date_only_item_never_shifts_via_timezone_conversion(): void
    {
        [$org, $project, $contract] = $this->makeOrgProjectContract('America/New_York');

        $fa = $this->makeFinalAccount($org, $project, $contract, [
            'status' => FinalAccount::STATUS_FINAL_CERTIFICATE_ISSUED,
            'final_certificate_issued_at' => '2026-06-01 12:00:00',
            'dispute_window_expires_at'   => '2026-07-16', // DATE-only
        ]);

        Date::setTestNow('2026-07-16 10:00:00');

        $items = app(OperationalIntelligenceService::class)->getItemsForProject($project->id);
        $item  = $items->firstWhere('meta.final_account_id', $fa->id);
        $disputeItems = $items->filter(fn($i) => $i['source_field'] === 'dispute_window_expiry');

        $this->assertCount(1, $disputeItems);
        $disputeItem = $disputeItems->first();
        $this->assertSame('2026-07-16', $disputeItem['event_date']->toDateString(), 'DATE-only field must not shift.');
        $this->assertSame('due_today', $disputeItem['status']);

        Date::setTestNow();
    }

    public function test_certificate_issued_datetime_item_uses_organisations_local_calendar_day(): void
    {
        [$org, $project, $contract] = $this->makeOrgProjectContract('Asia/Manila');

        // 23:30 UTC on the 16th is already 07:30 on the 17th in Manila.
        $fa = $this->makeFinalAccount($org, $project, $contract, [
            'status' => FinalAccount::STATUS_FINAL_CERTIFICATE_ISSUED,
            'final_certificate_issued_at' => '2026-07-16 23:30:00',
        ]);

        Date::setTestNow('2026-07-17 01:00:00'); // 09:00 on the 17th in Manila — same local day as issuance.

        $items = app(OperationalIntelligenceService::class)->getItemsForProject($project->id);
        $issuedItem = $items->firstWhere('source_field', 'certificate_issued_closeout_ready');

        $this->assertNotNull($issuedItem);
        $this->assertSame('2026-07-17', $issuedItem['event_date']->toDateString(), 'Must resolve to Manila\'s local calendar day (the 17th), not UTC\'s (the 16th).');
        $this->assertSame('due_today', $issuedItem['status']);

        Date::setTestNow();
    }
}
