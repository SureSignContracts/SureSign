import { useEffect } from 'react';
import api from '@/lib/api';
import type { AiAnalysisRecord } from '@/store/aiAnalysisStore';

const POLL_INTERVAL_MS = 3000;

/**
 * Phase C — Contract AI Foundation. Polls `GET /ai/analyses/{analysisId}`
 * every 3 seconds until the analysis reaches a terminal status
 * (`completed`/`failed`), then stops. Extracted from two previously
 * duplicated, behaviourally-identical implementations in
 * `app/app/projects/[id]/contracts/page.tsx` (the existing-contract AI
 * Contract Review flow and `AiContractWizard`'s own upload-and-analyse
 * flow) — this hook owns ONLY the polling mechanic. Each caller still
 * decides what to do when a terminal status is reached (via `onResolved`)
 * or when a poll request itself fails (via `onError`), since the two
 * original call sites differ slightly there — `AiContractWizard` also
 * drives its own step state machine, which this hook has no knowledge of.
 *
 * Deliberately NOT used by `AiAnalysisWidget.tsx`'s own separate 4-second
 * background poll — that poll only runs while the widget is minimized and
 * has a different enablement condition (minimized AND still
 * pending/processing), serving same-session background convenience rather
 * than an open review flow. Folding it into this hook would add coupling
 * between two components with different lifecycles for no demonstrated
 * benefit, so it remains its own independent `setInterval` — see Phase C's
 * final report for the full reasoning.
 *
 * @param analysisId  The `ContractAiAnalysis` id to poll, or `null` if none
 *                     exists yet (polling never starts without a real id).
 * @param enabled     Whether polling should currently be active — mirrors
 *                     each original call site's own local `polling` state.
 * @param onResolved  Called once, with the full analysis record, the
 *                     moment its status is `completed` or `failed`.
 * @param onError     Called if a poll request itself throws (network/auth
 *                     failure) — mirrors each original call site's `catch`
 *                     block, which never touched analysis state, only
 *                     stopped polling.
 */
export function useAiAnalysisPolling(
  analysisId: number | null,
  enabled: boolean,
  onResolved: (analysis: AiAnalysisRecord) => void,
  onError?: () => void,
): void {
  useEffect(() => {
    if (!enabled || !analysisId) return;
    const interval = setInterval(async () => {
      try {
        const res = await api.get(`/ai/analyses/${analysisId}`);
        const a = res.data?.data;
        if (a?.status === 'completed' || a?.status === 'failed') {
          onResolved(a);
        }
      } catch {
        onError?.();
      }
    }, POLL_INTERVAL_MS);
    return () => clearInterval(interval);
    // onResolved/onError are recreated every render by design (they close
    // over each caller's latest state setters) — matching the exact
    // dependency behaviour of the two original inline effects, which also
    // only restarted on a real analysisId/enabled change, never on their
    // own callback bodies.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [enabled, analysisId]);
}
