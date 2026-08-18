'use client';

import { useState, useEffect } from 'react';
import { useQueryClient, useMutation } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import { AlertTriangle, CheckCircle, FileText, Minus, Sparkles, Loader2, X } from 'lucide-react';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useAuthStore } from '@/store/authStore';
import { useAiAnalysisStore } from '@/store/aiAnalysisStore';
import { useAiAnalysisPolling } from '@/hooks/useAiAnalysisPolling';
import SharedSection from '@/components/ai/Section';
import SharedAnalysisLoadingDisplay from '@/components/ai/AnalysisLoadingDisplay';
import FullscreenDialogPortal from '@/components/ui/FullscreenDialogPortal';

/**
 * Phase C — Contract AI Foundation.
 *
 * The authoritative Contract Analysis Review & Confirm UI, extracted
 * verbatim (behaviourally) from the existing-contract flow in
 * `app/app/projects/[id]/contracts/page.tsx` (previously `AiAnalysisModal`)
 * — that flow already called the authoritative confirmation endpoint,
 * `POST /ai/analyses/{id}/confirm`, via `AiController::confirmAnalysis()`,
 * which drives `ContractIntelligenceSyncService`, calendar sync, and
 * notification regeneration. This extraction changes NONE of that: the
 * confirm request shape (`confirmed_data`/`force_overwrite`), the
 * overwrite-warning gate, the prior-analysis picker, and every rendered
 * section are unchanged from the original implementation.
 *
 * Deliberately NOT extracted from `AiContractWizard`'s own new-Contract
 * flow — that flow's post-analysis save path maps the AI result into a
 * bespoke flat form and saves via a plain `PUT /contracts/{id}`, bypassing
 * this same confirm endpoint entirely. That is known, pre-existing
 * technical debt (see the Phase C final report for the full before/after
 * comparison) and is NOT converged onto this component in this phase —
 * doing so safely would require reconciling two materially different data
 * shapes, not a thin wrapper change.
 *
 * This component is what a future Contract-Assisted Project Setup phase
 * should mount directly, so it never inherits AiContractWizard's shortcut.
 */

const ANALYSIS_MESSAGES = [
  { at: 0,   text: 'Reading contract document…' },
  { at: 8,   text: 'Extracting key commercial terms…' },
  { at: 18,  text: 'Identifying payment conditions…' },
  { at: 30,  text: 'Analysing obligations and risks…' },
  { at: 45,  text: 'Cross-referencing contract clauses…' },
  { at: 60,  text: 'Almost there, finalising results…' },
  { at: 80,  text: 'Nearly done, just a few more seconds…' },
  { at: 100, text: 'Wrapping up the analysis…' },
];

// `Section`/`AnalysisLoadingDisplay`/`ANALYSIS_MESSAGES` mirror the identical
// local wrappers still in contracts/page.tsx (used there by AiContractWizard,
// which remains unconverged in this phase) — kept as small, documented
// duplication rather than a cross-module import, matching the same
// CONTRACT_TYPES precedent from Phase B. Both wrap the same underlying
// shared base components (`SharedSection`/`SharedAnalysisLoadingDisplay`).
function AnalysisLoadingDisplay() {
  return <SharedAnalysisLoadingDisplay messages={ANALYSIS_MESSAGES} caption="AI is reading your contract. You can minimise this and come back." />;
}

function Section(props: { title: string; open: boolean; onToggle: () => void; children: React.ReactNode }) {
  return <SharedSection {...props} />;
}

// AI analysis completed_at/created_at are genuine DATETIME instants —
// resolved to the viewer's effective SureSign timezone rather than the
// browser's own local OS timezone. Mirrors the identical helper still in
// contracts/page.tsx (also used there by AiContractWizard) — see the
// Section/AnalysisLoadingDisplay comment above for why this is duplicated
// rather than cross-imported.
function formatAiAnalysisDate(iso: string): string {
  return new Date(iso).toLocaleDateString('en-GB', {
    day: 'numeric', month: 'short', year: 'numeric',
    timeZone: useAuthStore.getState().user?.effective_timezone,
  });
}

/**
 * The narrow slice of a Contract this component actually reads — not the
 * page's full Contract shape. Any object with at least these fields (the
 * real `ProjectContract` type in contracts/page.tsx already satisfies this
 * structurally) can be passed in without a giant page object being required.
 */
export type ReviewableContract = {
  id: number;
  title: string;
  contract_sum?: number | string | null;
  commencement_date?: string | null;
  key_dates?: unknown[] | null;
  risks?: unknown[] | null;
};

export type ContractAnalysisReviewProps = {
  contract: ReviewableContract;
  projectId: string;
  onClose: () => void;
  /** When provided, renders saved results immediately without calling the AI API */
  initialAnalysis?: any;
  /** When true, immediately forces a new run (skips existing check) */
  forceNew?: boolean;
};

export default function ContractAnalysisReview({ contract, projectId, onClose, initialAnalysis, forceNew }: ContractAnalysisReviewProps) {
  const qc = useQueryClient();
  const store = useAiAnalysisStore();

  const [analysisId, setAnalysisId] = useState<number | null>(
    initialAnalysis?.id ?? store.analysisId ?? null
  );
  const [polling, setPolling] = useState(false);
  const [analysis, setAnalysis] = useState<any>(
    initialAnalysis ?? store.data ?? null
  );
  const [openSections, setOpenSections] = useState<Record<string, boolean>>({
    summary: true, fields: true, workflows: false, documents: false, dates: false, risks: false, missing: false,
    executive: true, overview: true, commercial: true, deadlines: false, notices: false, obligations: false,
  });
  const [showOverwriteWarning, setShowOverwriteWarning] = useState(false);

  // All completed/confirmed analyses for this contract, fetched before any AI call
  // null = still loading; [] = none found (auto-start will trigger); array = pick shown
  const [priorAnalyses, setPriorAnalyses] = useState<any[] | null>(
    initialAnalysis ? [] : null   // if we already have data, skip the picker
  );
  // Previously a failed fetch here fell through to setPriorAnalyses([]),
  // which is indistinguishable from "confirmed no prior analyses exist" and
  // auto-starts a brand-new AI analysis as a side effect of an unrelated
  // network failure — a real, uncosted-for retry the user never asked for.
  // This tracks that distinctly so the mount effect can show a real error
  // state instead of silently taking that action.
  const [priorAnalysesFetchFailed, setPriorAnalysesFetchFailed] = useState(false);

  const startMutation = useMutation({
    mutationFn: (opts?: { forceNew?: boolean }) =>
      api.post(`/contracts/${contract.id}/ai-analysis`, opts?.forceNew ? { force_new: true } : {}).then(r => r.data),
    onSuccess: (data: any) => {
      // Should not happen anymore (we pass force_new), but handle defensively
      if (data.existing_analysis && !data.data) {
        api.get(`/contracts/${contract.id}/ai-analyses`).then(res => {
          const completed = (res.data?.data ?? []).filter((a: any) =>
            ['completed', 'confirmed'].includes(a.status)
          );
          setPriorAnalyses(completed.length > 0 ? completed : []);
        }).catch(() => setPriorAnalyses([]));
        return;
      }
      const id = data.data?.id ?? null;
      setAnalysisId(id);
      store.start({ analysisId: id, contractId: contract.id, contractTitle: contract.title, projectId });
      if (data.data?.status === 'completed') {
        setAnalysis(data.data);
        store.updateStatus('completed', data.data);
      } else {
        setPolling(true);
        store.updateStatus('processing');
      }
    },
    onError: (err: any) => toast.error(getErrorMessage(err, 'Failed to start AI analysis.')),
  });

  const fetchPriorAnalyses = () => {
    setPriorAnalysesFetchFailed(false);
    api.get(`/contracts/${contract.id}/ai-analyses`).then(res => {
      const completed = (res.data?.data ?? []).filter((a: any) =>
        ['completed', 'confirmed'].includes(a.status)
      );
      if (completed.length > 0) {
        // Show picker — let the user explicitly choose which analysis to open
        setPriorAnalyses(completed);
      } else {
        // No prior analyses — safe to start automatically
        setPriorAnalyses([]);
        startMutation.mutate(undefined);
      }
    }).catch(() => {
      // Previously silently started a brand-new analysis here too — the
      // same outcome as "confirmed no prior analyses", even though a fetch
      // failure proves nothing of the kind (there may be a prior completed
      // analysis this just couldn't retrieve). Show a real retry choice
      // instead of an uncosted-for side effect.
      setPriorAnalysesFetchFailed(true);
    });
  };

  // On mount: fetch all prior analyses first — never assume which one the user wants
  useEffect(() => {
    if (initialAnalysis) return;
    if (store.analysisId && store.data && store.status === 'completed') return;

    if (forceNew) {
      setPriorAnalyses([]);
      startMutation.mutate({ forceNew: true });
      return;
    }

    fetchPriorAnalyses();
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Poll until complete — see useAiAnalysisPolling's own docblock for why
  // this is shared with AiContractWizard's identical polling need but NOT
  // with AiAnalysisWidget's separate background poll.
  useAiAnalysisPolling(analysisId, polling, (a) => {
    setPolling(false);
    setAnalysis(a);
    store.updateStatus(a.status, a.status === 'completed' ? a : null);
  }, () => setPolling(false));

  const confirmMutation = useMutation({
    mutationFn: ({ confirmed, overwrite }: { confirmed: any; overwrite?: boolean }) =>
      api.post(`/ai/analyses/${analysisId}/confirm`, {
        confirmed_data: confirmed,
        ...(overwrite ? { force_overwrite: true } : {}),
      }).then(r => r.data),
    onSuccess: () => {
      toast.success('Analysis confirmed and saved.');
      qc.invalidateQueries({ queryKey: ['project-contracts'] });
      store.clear();
      onClose();
    },
    onError: (err: any) => toast.error(getErrorMessage(err, 'Failed to confirm analysis.')),
  });

  const hasExistingData = !!(contract.contract_sum || contract.commencement_date || contract.key_dates?.length || contract.risks?.length);

  function handleConfirmClick() {
    if (hasExistingData && analysis?.status !== 'confirmed') {
      setShowOverwriteWarning(true);
    } else {
      confirmMutation.mutate({ confirmed: result });
    }
  }

  const cancelMutation = useMutation({
    mutationFn: () => api.post(`/ai/analyses/${analysisId}/cancel`),
    onSuccess: (res: any) => {
      setPolling(false);
      toast.success(res?.data?.message ?? 'Analysis cancelled.');
      store.clear();
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Could not cancel the analysis. It may already have finished.')),
  });

  function handleCancelClick() {
    const proceed = window.confirm(
      'Cancel this analysis?\n\nIf it has already started running, the AI usage so far may still be charged.'
    );
    if (proceed) cancelMutation.mutate(undefined);
  }

  function toggleSection(key: string) {
    setOpenSections(prev => ({ ...prev, [key]: !prev[key] }));
  }

  const result = analysis?.raw_response_json ?? null;
  const isV2 = result != null && 'contract_overview' in result;
  const showPicker = priorAnalyses !== null && priorAnalyses.length > 0 && !analysis && !startMutation.isPending && !polling;

  // Minimized — keep component mounted (polling continues) but render nothing
  if (store.isMinimized) return null;

  return (
    <FullscreenDialogPortal>
      <div
        className="flex w-full max-w-4xl flex-col overflow-hidden rounded-2xl bg-[#f2f2f2] shadow-[0_32px_90px_rgba(0,0,0,0.32)] ss-animate-in"
        style={{ maxHeight: '92dvh' }}
        onClick={e => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex flex-shrink-0 items-center justify-between bg-[#18211d] px-6 py-5 text-white">
          <div className="flex items-center gap-2">
            <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-[#9ee5b5] text-[#18211d]"><Sparkles size={16} /></span>
            <div className="ml-1"><p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-[#9ee5b5]">Contract intelligence</p><h2 className="text-base font-semibold">Review the extracted record</h2></div>
            <span className="ml-2 max-w-48 truncate rounded-full bg-white/10 px-2.5 py-1 text-xs font-medium text-[#d9e1dd]">
              {contract.title}
            </span>
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => store.minimize()}
              title="Minimise (analysis continues in the background)"
              className="flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs text-[#b9c5bf] transition-colors hover:bg-white/10 hover:text-white"
            >
              <Minus size={13} />
              Minimise
            </button>
            {/* Hide the silent-close X while analysing — Minimise (above) or Cancel (footer)
                are the only safe actions, so closing can't abandon a billing job. */}
            {!(startMutation.isPending || polling) && (
              <button onClick={() => { store.clear(); onClose(); }} className="rounded-lg p-1.5 text-[#b9c5bf] hover:bg-white/10 hover:text-white">
                <X size={16} />
              </button>
            )}
          </div>
        </div>

        {/* Disclaimer */}
        <div
          className="mx-5 mt-4 flex flex-shrink-0 items-start gap-2 rounded-lg bg-[#fff8df] px-3 py-2.5 text-xs text-[#805f00]"
        >
          <AlertTriangle size={13} className="mt-0.5 flex-shrink-0" />
          AI-generated suggestions must be reviewed before use. Do not rely on this output for legal or commercial decisions without independent verification.
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-5 space-y-4">

          {/* Loading prior analyses */}
          {priorAnalyses === null && !priorAnalysesFetchFailed && (
            <div className="flex flex-col items-center justify-center py-16 gap-3">
              <Loader2 size={24} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Checking for previous analyses…</p>
            </div>
          )}

          {/* Couldn't check for a prior analysis — never silently start a
              new (potentially unnecessary) one on the user's behalf. */}
          {priorAnalyses === null && priorAnalysesFetchFailed && (
            <div className="flex flex-col items-center justify-center py-16 gap-3">
              <AlertTriangle size={24} style={{ color: '#f87171' }} />
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t check for previous analyses</p>
              <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>
                There may already be a completed analysis for this contract. Try again, or start a new one anyway.
              </p>
              <div className="flex items-center gap-2">
                <button
                  onClick={fetchPriorAnalyses}
                  className="mt-1 px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  Try again
                </button>
                <button
                  onClick={() => { setPriorAnalyses([]); setPriorAnalysesFetchFailed(false); startMutation.mutate(undefined); }}
                  className="mt-1 px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                >
                  Start new anyway
                </button>
              </div>
            </div>
          )}

          {/* Prior analysis picker — explicit selection required */}
          {showPicker && (
            <div className="space-y-4">
              <div>
                <p className="text-sm font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>
                  Previous analyses for this contract
                </p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  Select a specific analysis to view, or run a new one. Each analysis is independent of the current document version.
                </p>
              </div>
              <div
                className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs"
                style={{ backgroundColor: 'rgba(74,222,128,0.08)', border: '1px solid rgba(74,222,128,0.2)', color: '#4ade80' }}
              >
                <CheckCircle size={12} />
                Viewing a saved analysis will not affect your monthly AI usage.
              </div>
              <div className="space-y-2">
                {priorAnalyses.map((a: any) => (
                  <div
                    key={a.id}
                    className="flex items-center justify-between gap-3 rounded-xl px-4 py-3"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
                  >
                    <div className="flex-1 min-w-0">
                      <p className="text-xs font-medium" style={{ color: 'var(--text-primary)' }}>
                        Analysis #{a.id}
                        {a.status === 'confirmed' && (
                          <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(59,130,246,0.12)', color: '#60a5fa' }}>
                            Confirmed
                          </span>
                        )}
                      </p>
                      <div className="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5">
                        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                          {a.completed_at
                            ? formatAiAnalysisDate(a.completed_at)
                            : formatAiAnalysisDate(a.created_at)}
                        </span>
                        {a.creator?.name && (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>by {a.creator.name}</span>
                        )}
                      </div>
                    </div>
                    <button
                      onClick={() => { setAnalysis(a); setAnalysisId(a.id); setPriorAnalyses([]); }}
                      className="flex-shrink-0 text-xs px-3 py-1.5 rounded-lg font-medium active:scale-[0.98]"
                      style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                    >
                      View This Analysis
                    </button>
                  </div>
                ))}
              </div>
              <div className="pt-1">
                <button
                  onClick={() => { setPriorAnalyses([]); startMutation.mutate({ forceNew: true }); }}
                  className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm border-dashed transition-colors hover:border-[var(--gold)] hover:text-[var(--gold)]"
                  style={{ border: '1.5px dashed var(--border)', color: 'var(--text-muted)' }}
                >
                  <Sparkles size={13} />
                  Run New Analysis
                  <span className="text-xs ml-1" style={{ color: '#ca8a04' }}>(counts towards monthly AI usage)</span>
                </button>
              </div>
            </div>
          )}

          {/* Loading / running new analysis */}
          {(startMutation.isPending || polling) && <AnalysisLoadingDisplay />}

          {/* Start error (e.g. already in progress, no file) */}
          {startMutation.isError && !polling && !analysis && (
            <div className="flex flex-col items-center justify-center py-12 gap-3">
              <AlertTriangle size={28} style={{ color: '#f87171' }} />
              <p className="text-sm font-medium" style={{ color: '#f87171' }}>Could not start analysis</p>
              <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>
                {getErrorMessage(startMutation.error, 'Failed to start AI analysis.')}
              </p>
              <button
                onClick={() => startMutation.mutate(undefined)}
                className="mt-1 px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                Retry
              </button>
            </div>
          )}

          {/* Failed — the document itself is untouched (see error copy
              below); previously had no recovery action here at all, so the
              only way to retry was to close this and reopen it (which
              happens to work, since startAnalysis() treats a failed
              analysis as no longer "in progress" — but nothing told the
              user that). Reuses the exact same forceNew action already
              used above for "Run New Analysis". */}
          {analysis?.status === 'failed' && (
            <div className="flex flex-col items-center justify-center py-12 gap-3">
              <AlertTriangle size={28} style={{ color: '#f87171' }} />
              <p className="text-sm font-medium" style={{ color: '#f87171' }}>Analysis failed</p>
              <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>
                {analysis.error_message ?? 'An unexpected error occurred. Please try again.'} The uploaded document is still available.
              </p>
              <button
                onClick={() => { setPriorAnalyses([]); startMutation.mutate({ forceNew: true }); }}
                className="mt-1 px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                Try Again
              </button>
            </div>
          )}

          {/* Results */}
          {result && (
            <>
              {isV2 ? (
                <>
                  {/* ── V2: Executive Summary ────────────────────────────── */}
                  {result.executive_summary && (
                    <Section title="Executive Summary" open={openSections.executive} onToggle={() => toggleSection('executive')}>
                      <div className="flex flex-wrap items-center gap-3 mb-3">
                        {result.executive_summary.overall_risk_rating && (() => {
                          const r = String(result.executive_summary.overall_risk_rating).toLowerCase();
                          const c = r === 'critical' ? '#ef4444' : r === 'high' ? '#f87171' : r === 'medium' ? '#eab308' : '#4ade80';
                          return (
                            <span className="text-xs px-2.5 py-1 rounded-full font-semibold uppercase" style={{ backgroundColor: `${c}20`, color: c, border: `1px solid ${c}40` }}>
                              {result.executive_summary.overall_risk_rating} Risk
                            </span>
                          );
                        })()}
                        {result.executive_summary.intelligence_score != null && (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            Intelligence: <span className="font-semibold" style={{ color: 'var(--text-primary)' }}>{result.executive_summary.intelligence_score}/100</span>
                          </span>
                        )}
                        {result.executive_summary.contract_complexity && (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            Complexity: <span className="font-semibold" style={{ color: 'var(--text-primary)' }}>{result.executive_summary.contract_complexity}</span>
                          </span>
                        )}
                      </div>
                      {(() => {
                        const healthItems = [
                          { label: 'Payment', val: result.executive_summary.payment_health },
                          { label: 'Programme', val: result.executive_summary.programme_health },
                          { label: 'Compliance', val: result.executive_summary.compliance_health },
                        ].filter(h => h.val);
                        if (!healthItems.length) return null;
                        return (
                          <div className="grid grid-cols-3 gap-2 mb-3">
                            {healthItems.map(h => {
                              const v = String(h.val).toLowerCase();
                              const c = v.includes('poor') || v.includes('red') ? '#f87171' : v.includes('good') || v.includes('green') ? '#4ade80' : '#eab308';
                              return (
                                <div key={h.label} className="rounded-lg px-3 py-2 text-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{h.label}</p>
                                  <p className="text-sm font-medium mt-0.5" style={{ color: c }}>{h.val}</p>
                                </div>
                              );
                            })}
                          </div>
                        );
                      })()}
                      {result.executive_summary.commercial_summary && (
                        <p className="text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
                          {result.executive_summary.commercial_summary}
                        </p>
                      )}
                    </Section>
                  )}

                  {/* ── V2: Contract Overview ────────────────────────────── */}
                  {result.contract_overview && (
                    <Section title="Contract Overview" open={openSections.overview} onToggle={() => toggleSection('overview')}>
                      <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                        {([
                          ['Contract Type', result.contract_overview.contract_type],
                          ['Standard Form', result.contract_overview.standard_form],
                          ['Edition', result.contract_overview.standard_form_edition],
                          ['Procurement Route', result.contract_overview.procurement_route],
                          ['Design Responsibility', result.contract_overview.design_responsibility],
                          ['Governing Law', result.contract_overview.governing_law],
                          ['Currency', result.contract_overview.currency],
                        ] as [string, any][]).filter(([, val]) => val).map(([label, val]) => (
                          <div key={label}>
                            <p className="text-xs font-medium mb-0.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
                            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{String(val)}</p>
                          </div>
                        ))}
                      </div>
                    </Section>
                  )}

                  {/* ── V2: Commercial Terms ─────────────────────────────── */}
                  {result.commercial && (
                    <Section title="Commercial Terms" open={openSections.commercial} onToggle={() => toggleSection('commercial')}>
                      <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                        {([
                          ['Contract Sum', result.commercial.contract_sum != null ? String(result.commercial.contract_sum) : null],
                          ['Retention %', result.commercial.retention_percent != null ? `${result.commercial.retention_percent}%` : null],
                          ['Retention Cap %', result.commercial.retention_cap_percent != null ? `${result.commercial.retention_cap_percent}%` : null],
                          ['Retention Half 1 Release', result.commercial.retention_half_1_release_event],
                          ['Retention Half 2 Release', result.commercial.retention_half_2_release_event],
                          ['Due Date Offset', result.commercial.due_date_offset_days != null ? `${result.commercial.due_date_offset_days} days` : null],
                          ['Final Date Offset', result.commercial.final_date_offset_days != null ? `${result.commercial.final_date_offset_days} days` : null],
                          ['Payment Notice Offset', result.commercial.payment_notice_offset_days != null ? `${result.commercial.payment_notice_offset_days} days` : null],
                          ['Pay Less Notice Offset', result.commercial.pay_less_notice_offset_days != null ? `${result.commercial.pay_less_notice_offset_days} days` : null],
                          ['Valuation Method', result.commercial.valuation_method],
                          ['Payment Frequency', result.commercial.interim_payment_frequency],
                          ['Liquidated Damages', result.commercial.liquidated_damages_rate != null ? `${result.commercial.liquidated_damages_rate} per ${result.commercial.liquidated_damages_per ?? 'week'}` : null],
                        ] as [string, string | null][]).filter(([, val]) => val != null).map(([label, val]) => (
                          <div key={label}>
                            <p className="text-xs font-medium mb-0.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
                            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{String(val)}</p>
                          </div>
                        ))}
                        {result.commercial.vat_reverse_charge && (
                          <div>
                            <p className="text-xs font-medium mb-0.5" style={{ color: 'var(--text-muted)' }}>VAT Reverse Charge</p>
                            <p className="text-sm" style={{ color: '#4ade80' }}>Applicable</p>
                          </div>
                        )}
                        {result.commercial.performance_bond_required && (
                          <div>
                            <p className="text-xs font-medium mb-0.5" style={{ color: 'var(--text-muted)' }}>Performance Bond</p>
                            <p className="text-sm" style={{ color: '#eab308' }}>Required</p>
                          </div>
                        )}
                      </div>
                    </Section>
                  )}

                  {/* ── V2: Key Dates ────────────────────────────────────── */}
                  {result.dates && (
                    <Section title="Key Dates" open={openSections.dates} onToggle={() => toggleSection('dates')}>
                      <div className="space-y-2">
                        {([
                          ['Base Date', result.dates.base_date],
                          ['Commencement', result.dates.commencement_date],
                          ['Possession', result.dates.possession_date],
                          ['Completion', result.dates.completion_date],
                          ['Defects Period', result.dates.defects_liability_period_months != null ? `${result.dates.defects_liability_period_months} months` : null],
                        ] as [string, string | null][]).filter(([, val]) => val).map(([label, val]) => (
                          <div key={label} className="flex items-center justify-between">
                            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{label}</p>
                            <p className="text-xs font-medium" style={{ color: 'var(--gold)' }}>{String(val)}</p>
                          </div>
                        ))}
                        {(result.dates.key_milestones ?? []).map((m: any, i: number) => (
                          <div key={i} className="flex items-center justify-between">
                            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{m.name ?? m.milestone}</p>
                            <p className="text-xs font-medium" style={{ color: 'var(--gold)' }}>{m.date ?? '—'}</p>
                          </div>
                        ))}
                      </div>
                    </Section>
                  )}

                  {/* ── V2: Deadlines ────────────────────────────────────── */}
                  {(result.deadlines ?? []).length > 0 && (
                    <Section title={`Deadlines (${result.deadlines.length})`} open={openSections.deadlines} onToggle={() => toggleSection('deadlines')}>
                      <div className="space-y-2">
                        {result.deadlines.map((d: any, i: number) => (
                          <div key={i} className="rounded-lg px-3 py-2" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                            <div className="flex items-start justify-between gap-2 mb-0.5">
                              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{d.name}</p>
                              <div className="flex gap-1.5 flex-shrink-0">
                                {d.is_statutory && <span className="text-[10px] px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(59,130,246,0.15)', color: '#60a5fa' }}>Statutory</span>}
                                {d.category && <span className="text-[10px] px-1.5 py-0.5 rounded-full capitalize" style={{ backgroundColor: 'var(--bg-hover)', color: 'var(--text-muted)' }}>{d.category}</span>}
                              </div>
                            </div>
                            <div className="flex flex-wrap gap-x-4 gap-y-0.5">
                              {d.time_period_text && <span className="text-xs" style={{ color: 'var(--gold)' }}>{d.time_period_text}</span>}
                              {d.responsible_party && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{d.responsible_party}</span>}
                              {d.clause_reference && <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>{d.clause_reference}</span>}
                            </div>
                          </div>
                        ))}
                      </div>
                    </Section>
                  )}

                  {/* ── V2: Recommended Workflows ────────────────────────── */}
                  {result.recommended_workflows && Object.keys(result.recommended_workflows).length > 0 && (
                    <Section title="Recommended Workflows" open={openSections.workflows} onToggle={() => toggleSection('workflows')}>
                      <div className="space-y-2">
                        {Object.entries(result.recommended_workflows).map(([key, wf]: [string, any]) => {
                          const isRec = typeof wf === 'object' ? wf?.recommended : Boolean(wf);
                          const reason = typeof wf === 'object' ? wf?.reason : null;
                          return (
                            <div key={key} className="flex items-start gap-2">
                              <span className="flex-shrink-0 mt-0.5 text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: isRec ? 'rgba(34,197,94,0.12)' : 'rgba(90,86,82,0.15)', color: isRec ? '#4ade80' : 'var(--text-muted)' }}>
                                {isRec ? '✓' : '—'}
                              </span>
                              <div>
                                <p className="text-sm" style={{ color: isRec ? 'var(--text-primary)' : 'var(--text-muted)' }}>{key.replace(/_/g, ' ')}</p>
                                {reason && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{reason}</p>}
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    </Section>
                  )}

                  {/* ── V2: Risks ────────────────────────────────────────── */}
                  {(result.risks ?? []).length > 0 && (
                    <Section title={`Risks (${result.risks.length})`} open={openSections.risks} onToggle={() => toggleSection('risks')}>
                      <div className="space-y-3">
                        {result.risks.map((r: any, i: number) => {
                          const sev = ({ low: { bg: 'rgba(34,197,94,0.1)', color: '#4ade80' }, medium: { bg: 'rgba(234,179,8,0.1)', color: '#eab308' }, high: { bg: 'rgba(239,68,68,0.1)', color: '#f87171' }, critical: { bg: 'rgba(239,68,68,0.15)', color: '#ef4444' } } as Record<string, { bg: string; color: string }>)[r.severity] ?? { bg: 'var(--bg-elevated)', color: 'var(--text-muted)' };
                          return (
                            <div key={i} className="rounded-lg p-3" style={{ backgroundColor: sev.bg, border: `1px solid ${sev.color}30` }}>
                              <div className="flex items-center justify-between mb-1 gap-2">
                                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{r.title}</p>
                                <div className="flex gap-1.5 flex-shrink-0">
                                  {r.is_non_standard_amendment && <span className="text-[10px] px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(251,146,60,0.15)', color: '#fb923c' }}>Non-Standard</span>}
                                  <span className="text-xs px-2 py-0.5 rounded-full font-medium capitalize" style={{ backgroundColor: sev.bg, color: sev.color }}>{r.severity}</span>
                                </div>
                              </div>
                              {r.description && <p className="text-xs mb-1" style={{ color: 'var(--text-secondary)' }}>{r.description}</p>}
                              <div className="flex flex-wrap gap-x-4 gap-y-0.5 mt-1">
                                {r.clause_reference && <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>{r.clause_reference}</span>}
                                {r.commercial_impact && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Commercial: {r.commercial_impact}</span>}
                                {r.urgency && r.urgency !== 'monitor' && <span className="text-xs" style={{ color: '#eab308' }}>Urgency: {r.urgency}</span>}
                              </div>
                            </div>
                          );
                        })}
                      </div>
                    </Section>
                  )}

                  {/* ── V2: Notices ──────────────────────────────────────── */}
                  {(result.notices ?? []).length > 0 && (
                    <Section title={`Notices (${result.notices.length})`} open={openSections.notices} onToggle={() => toggleSection('notices')}>
                      <div className="space-y-2">
                        {result.notices.map((n: any, i: number) => (
                          <div key={i} className="rounded-lg px-3 py-2" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                            <div className="flex items-start justify-between gap-2">
                              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{n.name}</p>
                              <div className="flex gap-1.5 flex-shrink-0">
                                {n.is_statutory && <span className="text-[10px] px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(59,130,246,0.15)', color: '#60a5fa' }}>Statutory</span>}
                                {n.notice_type && <span className="text-[10px] px-1.5 py-0.5 rounded-full capitalize" style={{ backgroundColor: 'var(--bg-hover)', color: 'var(--text-muted)' }}>{n.notice_type}</span>}
                              </div>
                            </div>
                            {n.time_limit_days != null && <p className="text-xs mt-0.5" style={{ color: 'var(--gold)' }}>{n.time_limit_days} days {n.time_direction ?? ''}</p>}
                            {n.consequence_if_missed && <p className="text-xs mt-0.5 italic" style={{ color: 'var(--text-muted)' }}>{n.consequence_if_missed}</p>}
                          </div>
                        ))}
                      </div>
                    </Section>
                  )}

                  {/* ── V2: Obligations ──────────────────────────────────── */}
                  {result.obligations && (Object.values(result.obligations) as any[][]).flat().some((o: any) => o?.title) && (
                    <Section title="Key Obligations" open={openSections.obligations} onToggle={() => toggleSection('obligations')}>
                      <div className="space-y-3">
                        {(Object.entries(result.obligations) as [string, any[]][]).filter(([, items]) => Array.isArray(items) && items.length > 0).map(([party, items]) => (
                          <div key={party}>
                            <p className="text-xs font-semibold uppercase mb-1.5" style={{ color: 'var(--text-muted)' }}>{party.replace(/_/g, ' ')}</p>
                            <div className="space-y-1.5">
                              {items.map((o: any, i: number) => o?.title && (
                                <div key={i} className="flex items-start gap-2">
                                  <span className="text-xs mt-0.5 flex-shrink-0" style={{ color: 'var(--text-muted)' }}>•</span>
                                  <div>
                                    <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{o.title}</p>
                                    {o.clause_reference && <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>{o.clause_reference}</span>}
                                  </div>
                                </div>
                              ))}
                            </div>
                          </div>
                        ))}
                      </div>
                    </Section>
                  )}
                </>
              ) : (
                <>
                  {/* ── V1: Contract Summary ─────────────────────────────── */}
                  <Section title="Contract Summary" open={openSections.summary} onToggle={() => toggleSection('summary')}>
                    <p className="text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
                      {result.contract_summary ?? 'No summary provided.'}
                    </p>
                  </Section>

                  {/* ── V1: Extracted Fields ─────────────────────────────── */}
                  <Section title="Extracted Contract Details" open={openSections.fields} onToggle={() => toggleSection('fields')}>
                    <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                      {Object.entries(result.extracted_fields ?? {}).map(([key, val]) => (
                        <div key={key}>
                          <p className="text-xs font-medium capitalize mb-0.5" style={{ color: 'var(--text-muted)' }}>
                            {key.replace(/_/g, ' ')}
                          </p>
                          <p className="text-sm" style={{ color: val ? 'var(--text-primary)' : 'var(--text-muted)' }}>
                            {val != null ? String(val) : '—'}
                          </p>
                        </div>
                      ))}
                    </div>
                  </Section>

                  {/* ── V1: Recommended Workflows ────────────────────────── */}
                  <Section title="Recommended Workflows" open={openSections.workflows} onToggle={() => toggleSection('workflows')}>
                    <div className="flex flex-wrap gap-2">
                      {Object.entries(result.recommended_workflows ?? {}).map(([key, enabled]) => (
                        <span key={key} className="text-xs px-2.5 py-1 rounded-full font-medium" style={{ backgroundColor: enabled ? 'rgba(34,197,94,0.12)' : 'rgba(90,86,82,0.15)', color: enabled ? '#4ade80' : 'var(--text-muted)' }}>
                          {enabled ? '✓ ' : ''}{key.replace(/_/g, ' ')}
                        </span>
                      ))}
                    </div>
                  </Section>

                  {/* ── V1: Key Dates ────────────────────────────────────── */}
                  {(result.key_dates ?? []).length > 0 && (
                    <Section title="Key Dates" open={openSections.dates} onToggle={() => toggleSection('dates')}>
                      <div className="space-y-2">
                        {result.key_dates.map((d: any, i: number) => (
                          <div key={i} className="flex items-center justify-between">
                            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{d.name}</p>
                            <div className="text-right">
                              <p className="text-xs font-medium" style={{ color: 'var(--gold)' }}>{d.date ?? '—'}</p>
                              {d.source && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{d.source}</p>}
                            </div>
                          </div>
                        ))}
                      </div>
                    </Section>
                  )}

                  {/* ── V1: Risks ────────────────────────────────────────── */}
                  {(result.risks ?? []).length > 0 && (
                    <Section title={`Risks / Warnings (${result.risks.length})`} open={openSections.risks} onToggle={() => toggleSection('risks')}>
                      <div className="space-y-3">
                        {result.risks.map((r: any, i: number) => {
                          const sev = ({ low: { bg: 'rgba(34,197,94,0.1)', color: '#4ade80' }, medium: { bg: 'rgba(234,179,8,0.1)', color: '#eab308' }, high: { bg: 'rgba(239,68,68,0.1)', color: '#f87171' } } as Record<string, { bg: string; color: string }>)[r.severity] ?? { bg: 'var(--bg-elevated)', color: 'var(--text-muted)' };
                          return (
                            <div key={i} className="rounded-lg p-3" style={{ backgroundColor: sev.bg, border: `1px solid ${sev.color}30` }}>
                              <div className="flex items-center justify-between mb-1">
                                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{r.title}</p>
                                <span className="text-xs px-2 py-0.5 rounded-full font-medium capitalize" style={{ backgroundColor: sev.bg, color: sev.color }}>{r.severity}</span>
                              </div>
                              <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>{r.description}</p>
                              {r.source && <p className="text-xs mt-1 italic" style={{ color: 'var(--text-muted)' }}>{r.source}</p>}
                            </div>
                          );
                        })}
                      </div>
                    </Section>
                  )}
                </>
              )}

              {/* Shared: Suggested Documents */}
              {(result.recommended_documents ?? []).length > 0 && (
                <Section title="Suggested Documents" open={openSections.documents} onToggle={() => toggleSection('documents')}>
                  <ul className="space-y-2">
                    {result.recommended_documents.map((doc: any, i: number) => (
                      <li key={i} className="flex items-start gap-2">
                        <FileText size={13} className="mt-0.5 flex-shrink-0" style={{ color: 'var(--gold)' }} />
                        <div>
                          <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{doc.document_type}</p>
                          {doc.reason && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{doc.reason}</p>}
                        </div>
                      </li>
                    ))}
                  </ul>
                </Section>
              )}

              {/* Shared: Missing Information */}
              {(result.missing_information ?? []).length > 0 && (
                <Section title={`Missing Information (${result.missing_information.length})`} open={openSections.missing} onToggle={() => toggleSection('missing')}>
                  <ul className="space-y-1">
                    {result.missing_information.map((item: string, i: number) => (
                      <li key={i} className="text-xs flex items-start gap-1.5" style={{ color: 'var(--text-secondary)' }}>
                        <span className="flex-shrink-0 mt-1">•</span>{item}
                      </li>
                    ))}
                  </ul>
                </Section>
              )}
            </>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between p-5 flex-shrink-0" style={{ borderTop: '1px solid var(--border)' }}>
          {['completed', 'confirmed', 'failed'].includes(analysis?.status) || initialAnalysis || !analysisId ? (
            <button
              type="button"
              onClick={() => { store.clear(); onClose(); }}
              className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
            >
              Close
            </button>
          ) : (
            <button
              type="button"
              onClick={handleCancelClick}
              disabled={cancelMutation.isPending}
              className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
            >
              {cancelMutation.isPending ? 'Cancelling…' : 'Cancel Analysis'}
            </button>
          )}

          {result && analysis?.status !== 'failed' && (
            <>
              {showOverwriteWarning ? (
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-xs" style={{ color: '#facc15' }}>
                    ⚠ This will overwrite existing contract data. Continue?
                  </span>
                  <button
                    type="button"
                    onClick={() => setShowOverwriteWarning(false)}
                    className="px-3 py-1.5 rounded-lg text-xs"
                    style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    onClick={() => { setShowOverwriteWarning(false); confirmMutation.mutate({ confirmed: result, overwrite: true }); }}
                    disabled={confirmMutation.isPending}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                    style={{ backgroundColor: '#f87171', color: '#fff' }}
                  >
                    <CheckCircle size={13} />
                    Yes, Overwrite
                  </button>
                </div>
              ) : (
                <button
                  type="button"
                  onClick={handleConfirmClick}
                  disabled={confirmMutation.isPending}
                  className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: confirmMutation.isPending ? 0.7 : 1 }}
                >
                  <CheckCircle size={14} />
                  {confirmMutation.isPending ? 'Saving…' : 'Confirm & Save Analysis'}
                </button>
              )}
            </>
          )}
        </div>
      </div>
    </FullscreenDialogPortal>
  );
}
