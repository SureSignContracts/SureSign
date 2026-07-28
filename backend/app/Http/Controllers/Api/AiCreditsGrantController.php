<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ManageAiCreditsRequest;
use App\Http\Requests\UpdateAiCreditOperatingModeRequest;
use App\Models\ActivityLog;
use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Services\AI\AiCreditBalanceService;
use App\Services\AI\AiCreditLedgerService;
use App\Support\AI\AiCreditOperatingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Phase G4C.3H — the first write-capable AI Credits endpoint. Deliberately
 * a SEPARATE controller from AiCreditsOperationsController, which stays
 * read-only exactly as documented in its own docblock. `role:Super Admin`
 * ONLY (routes/api.php) — Admin keeps read-only access via
 * AiCreditsOperationsController and must never reach these mutation
 * endpoints, mirroring OrganizationSubscriptionAssignmentController's
 * identical G4B.2 precedent exactly.
 *
 * Every mutation delegates to AiCreditLedgerService — the sole
 * authoritative writer of ai_credit_ledger_entries. This controller only
 * resolves/validates input, generates a fresh idempotency key (a genuine
 * new admin-initiated event each time, never a retry of a prior one — see
 * AiCreditLedgerService's own idempotency design, built for reservation-
 * lifecycle retries, not ad-hoc admin actions), and shapes the response.
 * Never mutates a ledger row directly, never bypasses the service.
 */
class AiCreditsGrantController extends Controller
{
    public function __construct(
        private readonly AiCreditLedgerService $ledger,
        private readonly AiCreditBalanceService $balance,
    ) {
    }

    public function grant(ManageAiCreditsRequest $request, Organization $organization): JsonResponse
    {
        return $this->apply($request, $organization, 'grant', 'ai_credits.granted', fn (float $amount, string $reason, string $key) =>
            $this->ledger->grant($organization->id, $amount, $reason, $key, $request->user()->id));
    }

    public function adjustCredit(ManageAiCreditsRequest $request, Organization $organization): JsonResponse
    {
        return $this->apply($request, $organization, 'adjustment_credit', 'ai_credits.adjusted_credit', fn (float $amount, string $reason, string $key) =>
            $this->ledger->adjustCredit($organization->id, $amount, $reason, $key, $request->user()->id));
    }

    public function adjustDebit(ManageAiCreditsRequest $request, Organization $organization): JsonResponse
    {
        return $this->apply($request, $organization, 'adjustment_debit', 'ai_credits.adjusted_debit', fn (float $amount, string $reason, string $key) =>
            $this->ledger->adjustDebit($organization->id, $amount, $reason, $key, $request->user()->id));
    }

    public function expire(ManageAiCreditsRequest $request, Organization $organization): JsonResponse
    {
        return $this->apply($request, $organization, 'expiry', 'ai_credits.expired', fn (float $amount, string $reason, string $key) =>
            $this->ledger->expire($organization->id, $amount, $reason, $key, $request->user()->id));
    }

    /**
     * PUT /admin/ai-credits/operating-mode — the Super-Admin-only control
     * for the single AI Credit operating mode (App\Support\AI\
     * AiCreditOperatingMode — disabled/shadow/enforced; see that class's
     * docblock for what each mode does). Not organisation-scoped — this is
     * a single, global, platform-wide setting
     * (suresign_settings.ai_credit_operating_mode), same singleton
     * SuresignSetting::instance() every other platform-wide setting uses.
     * Switching to ENFORCED means a real customer can be blocked from
     * running AI analysis the moment their (still only shadow-validated)
     * credit balance is insufficient; switching to DISABLED stops the
     * accounting lifecycle entirely — see CLAUDE.md's AI Workflow Context
     * section and the G4C.3 Readiness Gate before changing this in
     * production.
     */
    public function updateOperatingMode(UpdateAiCreditOperatingModeRequest $request): JsonResponse
    {
        $mode = $request->validated('mode');
        $reason = $request->validated('reason');

        $settings = SuresignSetting::instance();
        $previousMode = AiCreditOperatingMode::current();
        $settings->update(['ai_credit_operating_mode' => $mode]);

        ActivityLog::record(
            'ai_credits.operating_mode_changed',
            "AI Credit operating mode changed from \"{$previousMode}\" to \"{$mode}\": {$reason}",
            $request->user(),
            null,
            [
                'previous_mode' => $previousMode,
                'new_mode' => $mode,
                'reason' => $reason,
                'changed_by' => $request->user()->id,
                'changed_at' => now()->toIso8601String(),
            ],
        );

        return response()->json(['operating_mode' => $mode]);
    }

    private function apply(ManageAiCreditsRequest $request, Organization $organization, string $transactionType, string $activityAction, \Closure $action): JsonResponse
    {
        $amount = (float) $request->validated('amount');
        $reason = $request->validated('reason');
        $idempotencyKey = $transactionType . ':manual:' . Str::uuid()->toString();

        $entry = $action($amount, $reason, $idempotencyKey);

        ActivityLog::record(
            $activityAction,
            ucfirst(str_replace('_', ' ', $transactionType)) . " of {$amount} AI credits for \"{$organization->name}\": {$reason}",
            $request->user(),
            $organization,
            ['amount' => $amount, 'reason' => $reason, 'ledger_entry_id' => $entry->id],
            null,
            $organization->id,
        );

        return response()->json([
            'entry' => [
                'id' => $entry->id,
                'transaction_type' => $entry->transaction_type,
                'amount' => (float) $entry->amount,
                'reason' => $entry->reason,
                'created_at' => $entry->created_at,
            ],
            'balance' => $this->balance->balanceFor($organization->id),
        ], 201);
    }
}
