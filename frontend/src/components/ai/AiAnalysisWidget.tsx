'use client';

import { useEffect, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { useAiAnalysisStore } from '@/store/aiAnalysisStore';
import api from '@/lib/api';
import toast, { gooeyToast } from '@/lib/toast';

/**
 * Purely a side-effect component now — renders nothing itself;
 * <GooeyToaster/> (mounted in providers.tsx, which persists across route
 * changes exactly like this component does — both live in the app shell,
 * app/app/layout.tsx) renders the actual UI. Previously this rendered its
 * own hand-built floating card; now it drives a real toast instead:
 *
 * - While the analysis is still running and the panel is minimized
 *   (the user navigated away from the Contracts page mid-analysis), a
 *   single toast is created and then UPDATED IN PLACE on every poll tick
 *   with the analysis's own progress_percent/progress_stage/
 *   progress_message — never re-created, so it doesn't re-morph on every
 *   4s tick.
 * - On reaching a terminal status, that tracking toast is dismissed and
 *   replaced with a fresh, properly branded success/error toast (an
 *   in-place `.update()` can't change fillColor/borderColor once a toast
 *   exists, so the terminal state needs a real new toast to get its own
 *   accent colour) with a "Review"/"View details" action button back to
 *   the Contracts page.
 * - Dismissing the toast (its own close button) clears the store too, so
 *   a stale tracking id is never reused.
 */
export default function AiAnalysisWidget() {
  const { isMinimized, status, contractTitle, projectId, analysisId, updateStatus } = useAiAnalysisStore();
  const router = useRouter();
  const trackingToastId = useRef<string | number | null>(null);

  function goToContract() {
    if (projectId) router.push(`/app/projects/${projectId}/contracts`);
    useAiAnalysisStore.getState().restore();
  }

  function dismissTracking() {
    if (trackingToastId.current !== null) {
      gooeyToast.dismiss(trackingToastId.current);
      trackingToastId.current = null;
    }
  }

  // Poll independently while minimized + still running (same 4s cadence as
  // before), now also reading the progress fields this analysis already
  // records (see AnalyseContractWithAiJob) to keep the tracking toast's
  // description current instead of only its terminal status.
  useEffect(() => {
    if (!isMinimized || !analysisId) return;
    if (status !== 'pending' && status !== 'processing') return;

    let cancelled = false;

    const tick = async () => {
      try {
        const res = await api.get(`/ai/analyses/${analysisId}`);
        const a = res.data?.data;
        if (cancelled || !a) return;

        if (a.status !== status) {
          updateStatus(a.status, a.status === 'completed' ? a : null);
          return; // the status-transition effect below handles the toast change
        }

        if (trackingToastId.current !== null) {
          const description = a.progress_message
            ? (typeof a.progress_percent === 'number' ? `${a.progress_percent}% — ${a.progress_message}` : a.progress_message)
            : (contractTitle || 'Contract');
          gooeyToast.update(trackingToastId.current, { description });
        }
      } catch {
        // Silent — user can return to Contracts to retry
      }
    };

    tick();
    const interval = setInterval(tick, 4000);
    return () => { cancelled = true; clearInterval(interval); };
  }, [isMinimized, analysisId, status, contractTitle, updateStatus]);

  // Create the tracking toast the moment the panel is minimized while
  // still running; replace it with a fresh success/error toast on
  // completion; dismiss entirely once restored/cleared.
  useEffect(() => {
    if (!isMinimized || !status || status === 'cancelled') {
      dismissTracking();
      return;
    }

    const isProcessing = status === 'pending' || status === 'processing';

    if (isProcessing) {
      if (trackingToastId.current === null) {
        trackingToastId.current = toast('Analysing contract…', {
          description: contractTitle || 'Contract',
          duration: Infinity, // stays up for as long as the analysis runs
          action: { label: 'View', onClick: goToContract },
          onDismiss: () => {
            trackingToastId.current = null;
            useAiAnalysisStore.getState().clear();
          },
        });
      }
      return;
    }

    // Terminal state — a fresh toast, not an update, so it gets its own
    // properly branded border colour.
    dismissTracking();
    if (status === 'completed' || status === 'confirmed') {
      toast.success('Analysis complete', {
        description: contractTitle || 'Contract',
        action: { label: 'Review', onClick: goToContract },
      });
    } else if (status === 'failed') {
      toast.error('Analysis failed', {
        description: contractTitle || 'Contract',
        action: { label: 'View details', onClick: goToContract },
      });
    }
    // goToContract/dismissTracking intentionally excluded — they close over
    // projectId/contractTitle from the same render this effect belongs to,
    // and re-running on every render would tear down/recreate the toast.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isMinimized, status]);

  // Belt-and-braces: dismiss the tracking toast if this component itself
  // ever unmounts (e.g. logout) so it never lingers with a dead handler.
  useEffect(() => () => dismissTracking(), []);

  return null;
}
