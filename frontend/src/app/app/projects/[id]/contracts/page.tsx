'use client';

import { useRef, useState, useEffect } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { FileText, Plus, Search, X, Sparkles, Loader2, CheckCircle, AlertTriangle, Minus, Upload, ArrowRight, Eye, Download, Paperclip, MoreHorizontal, Trash2, Archive, RotateCcw, LayoutDashboard, Pencil, FileSignature } from 'lucide-react';
import DocumentPreviewModal, { PreviewTarget } from '@/components/documents/DocumentPreviewModal';
import GeneratePackageModal from '@/components/documents/GeneratePackageModal';
import GenerateTradePackageFolderModal from '@/components/documents/GenerateTradePackageFolderModal';
import SubcontractAiOnboardingModal from '@/components/subcontracts/SubcontractAiOnboardingModal';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PromptActionButton from '@/components/prompts/PromptActionButton';
import { useAiAnalysisStore } from '@/store/aiAnalysisStore';
import SharedSection from '@/components/ai/Section';
import SharedAnalysisLoadingDisplay from '@/components/ai/AnalysisLoadingDisplay';
import PageTourButton from '@/components/tours/PageTourButton';
import Button from '@/components/ui/Button';

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

function AnalysisLoadingDisplay() {
  return <SharedAnalysisLoadingDisplay messages={ANALYSIS_MESSAGES} caption="AI is reading your contract. You can minimise this and come back." />;
}

function Section(props: { title: string; open: boolean; onToggle: () => void; children: React.ReactNode }) {
  return <SharedSection {...props} />;
}

type FormChangeEvent = React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>;

type ApiCollection<T> = {
  data?: T[];
};

type ContractTypeOption = {
  value: string;
  label: string;
};

type ContractForm = {
  title: string;
  type: string;
  reference_number: string;
  defects_liability_period: string;
  liquidated_damages: string;
  notice_requirements: string;
  variation_procedure: string;
  form_of_contract: string;
  party_name: string;
  contract_sum: string;
  currency: string;
  retention_percentage: string;
  retention_cap_percentage: string;
  payment_terms_days: string;
  commencement_date: string;
  completion_date: string;
  execution_date: string;
  notes: string;
};

type FileUploadRecord = {
  id: number;
  original_name: string;
  mime_type?: string | null;
  file_size?: number | null;
  preview_pdf_path?: string | null;
};

type ProjectContract = {
  id: number;
  reference_number?: string | null;
  title: string;
  type?: string | null;
  party_name?: string | null;
  contract_sum?: number | string | null;
  commencement_date?: string | null;
  completion_date?: string | null;
  status?: string | null;
  archived_at?: string | null;
  key_dates?: unknown[] | null;
  risks?: unknown[] | null;
  file_uploads?: FileUploadRecord[];
  payment_applications?: unknown[];
  variations?: unknown[];
  eot_requests?: unknown[];
};

type InputFieldProps = {
  label: string;
  name: string;
  type?: string;
  required?: boolean;
  value: string;
  onChange: (event: FormChangeEvent) => void;
  options?: ContractTypeOption[];
};

function getErrorMessage(error: unknown, fallback: string) {
  if (
    typeof error === 'object' &&
    error !== null &&
    'response' in error &&
    typeof error.response === 'object' &&
    error.response !== null &&
    'data' in error.response &&
    typeof error.response.data === 'object' &&
    error.response.data !== null &&
    'message' in error.response.data &&
    typeof error.response.data.message === 'string'
  ) {
    return error.response.data.message;
  }

  return fallback;
}

const CONTRACT_STATUS_LABELS: Record<string, string> = {
  draft:      'Draft',
  active:     'Active',
  expired:    'Expired',
  complete:   'Complete',
  terminated: 'Terminated',
};

// ─── AI Review Modal ──────────────────────────────────────────────────────────

type AiAnalysisModalProps = {
  contract: ProjectContract;
  projectId: string;
  onClose: () => void;
  /** When provided, renders saved results immediately without calling the AI API */
  initialAnalysis?: any;
  /** When true, immediately forces a new run (skips existing check) */
  forceNew?: boolean;
};

function AiAnalysisModal({ contract, projectId, onClose, initialAnalysis, forceNew }: AiAnalysisModalProps) {
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

  // On mount: fetch all prior analyses first — never assume which one the user wants
  useEffect(() => {
    if (initialAnalysis) return;
    if (store.analysisId && store.data && store.status === 'completed') return;

    if (forceNew) {
      setPriorAnalyses([]);
      startMutation.mutate({ forceNew: true });
      return;
    }

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
      // If the fetch fails, fall back to starting new
      setPriorAnalyses([]);
      startMutation.mutate(undefined);
    });
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Poll until complete
  useEffect(() => {
    if (!polling || !analysisId) return;
    const interval = setInterval(async () => {
      try {
        const res = await api.get(`/ai/analyses/${analysisId}`);
        const a = res.data?.data;
        if (a?.status === 'completed' || a?.status === 'failed') {
          setPolling(false);
          setAnalysis(a);
          store.updateStatus(a.status, a.status === 'completed' ? a : null);
        }
      } catch {
        setPolling(false);
      }
    }, 3000);
    return () => clearInterval(interval);
  }, [polling, analysisId]); // eslint-disable-line react-hooks/exhaustive-deps

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
    onError: () => toast.error('Could not cancel the analysis. It may already have finished.'),
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
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}>
      <div
        className="w-full max-w-3xl rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] flex flex-col"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', maxHeight: '92vh' }}
        onClick={e => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between p-5 flex-shrink-0" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <Sparkles size={16} style={{ color: 'var(--gold)' }} />
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>AI Contract Review</h2>
            <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
              {contract.title}
            </span>
          </div>
          <div className="flex items-center gap-1">
            <button
              onClick={() => store.minimize()}
              title="Minimise (analysis continues in the background)"
              className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-muted)' }}
            >
              <Minus size={13} />
              Minimise
            </button>
            {/* Hide the silent-close X while analysing — Minimise (above) or Cancel (footer)
                are the only safe actions, so closing can't abandon a billing job. */}
            {!(startMutation.isPending || polling) && (
              <button onClick={() => { store.clear(); onClose(); }} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            )}
          </div>
        </div>

        {/* Disclaimer */}
        <div
          className="mx-5 mt-4 flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs flex-shrink-0"
          style={{ backgroundColor: 'rgba(234,179,8,0.08)', border: '1px solid rgba(234,179,8,0.2)', color: '#ca8a04' }}
        >
          <AlertTriangle size={13} className="mt-0.5 flex-shrink-0" />
          AI-generated suggestions must be reviewed before use. Do not rely on this output for legal or commercial decisions without independent verification.
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-5 space-y-4">

          {/* Loading prior analyses */}
          {priorAnalyses === null && (
            <div className="flex flex-col items-center justify-center py-16 gap-3">
              <Loader2 size={24} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Checking for previous analyses…</p>
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
                Viewing a saved analysis will not consume AI credits.
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
                            ? new Date(a.completed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
                            : new Date(a.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}
                        </span>
                        {a.creator?.name && (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>by {a.creator.name}</span>
                        )}
                        {a.model && (
                          <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>
                            {a.model.replace('claude-', '')}
                          </span>
                        )}
                        {a.estimated_cost != null && (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            ${Number(a.estimated_cost).toFixed(4)}
                          </span>
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
                  <span className="text-xs ml-1" style={{ color: '#ca8a04' }}>(may consume credits)</span>
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

          {/* Failed */}
          {analysis?.status === 'failed' && (
            <div className="flex flex-col items-center justify-center py-12 gap-3">
              <AlertTriangle size={28} style={{ color: '#f87171' }} />
              <p className="text-sm font-medium" style={{ color: '#f87171' }}>Analysis failed</p>
              <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>
                {analysis.error_message ?? 'An unexpected error occurred. Please try again.'}
              </p>
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
    </div>
  );
}

// ─── Edit Contract Modal ──────────────────────────────────────────────────────

function EditContractModal({ contract, projectId, onClose }: { contract: ProjectContract & Record<string, any>; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<ContractForm>({
    title:                    contract.title ?? '',
    type:                     contract.type ?? 'main_contract',
    reference_number:         contract.reference_number ?? '',
    form_of_contract:         contract.form_of_contract ?? '',
    party_name:               contract.party_name ?? '',
    contract_sum:             String(contract.contract_sum ?? ''),
    currency:                 contract.currency ?? 'GBP',
    retention_percentage:     String(contract.retention_percentage ?? '3'),
    retention_cap_percentage: String(contract.retention_cap_percentage ?? '5'),
    payment_terms_days:       String(contract.payment_terms_days ?? '30'),
    commencement_date:        contract.commencement_date ? String(contract.commencement_date).slice(0, 10) : '',
    completion_date:          contract.completion_date ? String(contract.completion_date).slice(0, 10) : '',
    execution_date:           contract.execution_date ? String(contract.execution_date).slice(0, 10) : '',
    defects_liability_period: contract.defects_liability_period ?? '',
    liquidated_damages:       contract.liquidated_damages ?? '',
    notice_requirements:      contract.notice_requirements ?? '',
    variation_procedure:      contract.variation_procedure ?? '',
    notes:                    contract.notes ?? '',
  });

  const STATUS_OPTIONS = [
    { value: 'draft',      label: 'Draft' },
    { value: 'active',     label: 'Active' },
    { value: 'expired',    label: 'Expired' },
    { value: 'complete',   label: 'Complete' },
    { value: 'terminated', label: 'Terminated' },
  ];
  const [status, setStatus] = useState<string>(contract.status ?? 'draft');

  const { mutate, isPending } = useMutation({
    mutationFn: (data: ContractForm & { status: string }) => api.put(`/contracts/${contract.id}`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Contract updated');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to update contract')),
  });

  const handleChange = (e: FormChangeEvent) => {
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-2xl rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Edit Contract</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate({ ...form, status }); }} className="p-5 space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="col-span-2">
              <InputField label="Title" name="title" value={form.title} onChange={handleChange} required />
            </div>
            <InputField label="Contract Type" name="type" value={form.type} onChange={handleChange} required options={CONTRACT_TYPES} />
            <div>
              <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <select name="status" value={status} onChange={e => setStatus(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
                {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <InputField label="Reference Number" name="reference_number" value={form.reference_number} onChange={handleChange} />
            <InputField label="Contracting Party" name="party_name" value={form.party_name} onChange={handleChange} />
            <InputField label="Form of Contract" name="form_of_contract" value={form.form_of_contract} onChange={handleChange} />
            <InputField label="Contract Sum" name="contract_sum" type="number" value={form.contract_sum} onChange={handleChange} />
            <InputField label="Currency" name="currency" value={form.currency} onChange={handleChange} />
            <InputField label="Retention %" name="retention_percentage" type="number" value={form.retention_percentage} onChange={handleChange} />
            <InputField label="Retention Cap %" name="retention_cap_percentage" type="number" value={form.retention_cap_percentage} onChange={handleChange} />
            <InputField label="Payment Terms (days)" name="payment_terms_days" type="number" value={form.payment_terms_days} onChange={handleChange} />
            <InputField label="Execution Date" name="execution_date" type="date" value={form.execution_date} onChange={handleChange} />
            <InputField label="Commencement Date" name="commencement_date" type="date" value={form.commencement_date} onChange={handleChange} />
            <InputField label="Completion Date" name="completion_date" type="date" value={form.completion_date} onChange={handleChange} />
          </div>
          {/* Commercial clauses — populated by AI analysis on confirm */}
          <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem' }}>
            <p className="text-xs font-semibold uppercase tracking-widest mb-3" style={{ color: 'var(--text-muted)' }}>Commercial Clauses</p>
            <div className="grid grid-cols-2 gap-4">
              <InputField label="Defects Liability Period" name="defects_liability_period" value={form.defects_liability_period} onChange={handleChange} />
              <InputField label="Liquidated Damages" name="liquidated_damages" value={form.liquidated_damages} onChange={handleChange} />
            </div>
            <div className="mt-3 space-y-3">
              <div>
                <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notice Requirements</label>
                <textarea name="notice_requirements" value={form.notice_requirements} onChange={handleChange} rows={2}
                  placeholder="e.g. 7 days written notice required for extensions of time…"
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
                  style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Variation Procedure</label>
                <textarea name="variation_procedure" value={form.variation_procedure} onChange={handleChange} rows={2}
                  placeholder="e.g. All variations must be instructed in writing by the Employer…"
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
                  style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
              </div>
            </div>
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea name="notes" value={form.notes} onChange={handleChange} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:      { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  active:     { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  expired:    { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  complete:   { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  terminated: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};

const CONTRACT_TYPES = [
  { value: 'main_contract',          label: 'Main Contract' },
  { value: 'subcontract',            label: 'Subcontract' },
  { value: 'consultant_appointment', label: 'Consultant Appointment' },
  { value: 'supplier_agreement',     label: 'Supplier Agreement' },
] satisfies ContractTypeOption[];

function InputField({ label, name, type = 'text', required = false, value, onChange, options }: InputFieldProps) {
  const base = "w-full px-3 py-2 rounded-lg text-sm outline-none";
  const style = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

  if (options) {
    return (
      <div>
        <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
        <select name={name} value={value} onChange={onChange} required={required} className={base} style={style}>
          <option value="">Select…</option>
          {options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
        </select>
      </div>
    );
  }
  return (
    <div>
      <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
      <input name={name} type={type} value={value} onChange={onChange} required={required} className={base} style={style} />
    </div>
  );
}

// ─── AI Contract Creation Wizard ──────────────────────────────────────────────

type WizardStep = 'choice' | 'upload' | 'analysing' | 'reviewing' | 'select' | 'form';

function mapExtractedToForm(data: Record<string, any>): Partial<ContractForm> {
  function toDateStr(val: any): string {
    if (!val) return '';
    const d = new Date(val);
    if (isNaN(d.getTime())) return '';
    return d.toISOString().slice(0, 10);
  }

  function str(val: any, max = 255): string {
    if (val == null) return '';
    return String(val).slice(0, max);
  }

  // v2 schema
  if ('contract_overview' in data) {
    const overview   = data.contract_overview ?? {};
    const commercial = data.commercial ?? {};
    const parties    = data.parties ?? {};
    const dates      = data.dates ?? {};
    const partyName  = parties.main_contractor?.name ?? parties.subcontractor?.name ?? parties.employer?.name ?? '';
    return {
      title:                    str(overview.contract_title),
      party_name:               str(partyName),
      form_of_contract:         str(overview.standard_form),
      contract_sum:             commercial.contract_sum != null ? String(commercial.contract_sum).replace(/[^0-9.]/g, '') : '',
      currency:                 str(overview.currency || commercial.currency || 'GBP', 10),
      retention_percentage:     commercial.retention_percent != null ? String(commercial.retention_percent) : '3',
      retention_cap_percentage: commercial.retention_cap_percent != null ? String(commercial.retention_cap_percent) : '5',
      payment_terms_days:       commercial.due_date_offset_days != null ? String(commercial.due_date_offset_days) : '30',
      execution_date:           toDateStr(dates.base_date),
      commencement_date:        toDateStr(dates.commencement_date),
      completion_date:          toDateStr(dates.completion_date),
      notes:                    '',
    };
  }

  // v1 flat fields
  const fields = data.extracted_fields ?? data;
  return {
    title:                    str(fields.contract_title ?? fields.title),
    party_name:               str(fields.contracting_party ?? fields.contractor ?? fields.party_name),
    form_of_contract:         str(fields.form_of_contract),
    contract_sum:             fields.contract_sum != null ? String(fields.contract_sum).replace(/[^0-9.]/g, '') : '',
    currency:                 str(fields.currency || 'GBP', 10),
    retention_percentage:     fields.retention_percentage != null ? String(fields.retention_percentage) : '3',
    retention_cap_percentage: fields.retention_cap_percentage != null ? String(fields.retention_cap_percentage) : '5',
    payment_terms_days:       fields.payment_terms_days != null
      ? String(Math.round(parseFloat(String(fields.payment_terms_days).replace(/[^0-9.]/g, ''))) || 30)
      : '30',
    execution_date:           toDateStr(fields.execution_date),
    commencement_date:        toDateStr(fields.commencement_date ?? fields.start_date),
    completion_date:          toDateStr(fields.completion_date ?? fields.end_date),
    notes:                    str(fields.notes, 10000),
  };
}

function AiContractWizard({ projectId, aiAnalyses, onClose, onCreated }: {
  projectId: string;
  aiAnalyses: any[];
  onClose: () => void;
  onCreated: () => void;
}) {
  const qc = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const store = useAiAnalysisStore();

  // Start at the choice screen
  const [step, setStep] = useState<WizardStep>('choice');

  // Which high-level path the user chose
  const [path, setPath] = useState<'new' | 'existing' | null>(null);

  const [contractFile, setContractFile] = useState<File | null>(null);
  const [dragOver, setDragOver] = useState(false);

  // File attached on the form step (existing-analysis path — no stub was created)
  const formFileInputRef = useRef<HTMLInputElement>(null);
  const [formFile, setFormFile] = useState<File | null>(null);

  // Stub contract created when uploading a new file
  const [stubContract, setStubContract] = useState<ProjectContract | null>(null);

  // Analysis state (used by both paths)
  const [analysisId, setAnalysisId] = useState<number | null>(null);
  const [polling, setPolling] = useState(false);
  const [analysis, setAnalysis] = useState<any>(null);

  // Prior analyses found for the stub contract after upload — user must explicitly pick one
  // null = not yet checked; [] = none found (auto-start); array = picker shown
  const [stubPriorAnalyses, setStubPriorAnalyses] = useState<any[] | null>(null);

  // Which saved analysis the user selected (existing-path)
  const [selectedSavedAnalysis, setSelectedSavedAnalysis] = useState<any>(null);

  const [openSections, setOpenSections] = useState<Record<string, boolean>>({
    summary: true, fields: true, dates: false, risks: false, missing: false,
    executive: true, overview: true, deadlines: false,
  });

  const [form, setForm] = useState<ContractForm>({
    title: '', type: 'main_contract', reference_number: '', form_of_contract: '',
    party_name: '', contract_sum: '', currency: 'GBP', retention_percentage: '3',
    retention_cap_percentage: '5', payment_terms_days: '30',
    commencement_date: '', completion_date: '', execution_date: '',
    defects_liability_period: '', liquidated_damages: '',
    notice_requirements: '', variation_procedure: '', notes: '',
  });

  // Completed analyses only — used in the selector
  const completedAnalyses = aiAnalyses.filter(a => a.status === 'completed' || a.status === 'confirmed');

  // ── Upload + analyse (new-file path) ────────────────────────────────────────
  const createAndAnalyseMutation = useMutation({
    mutationFn: async (file: File) => {
      // 1. Create stub contract with the uploaded file
      const fd = new FormData();
      fd.append('title', file.name.replace(/\.[^.]+$/, ''));
      fd.append('type', 'main_contract');
      fd.append('status', 'draft');
      fd.append('contract_file', file);
      const contractRes = await api.post(`/projects/${projectId}/contracts`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      const contract = contractRes.data;
      setStubContract(contract);

      // 2. Check for prior analyses BEFORE calling AI — never auto-select
      const historyRes = await api.get(`/contracts/${contract.id}/ai-analyses`);
      const prior = (historyRes.data?.data ?? []).filter((a: any) =>
        ['completed', 'confirmed'].includes(a.status)
      );
      if (prior.length > 0) {
        return { contract, prior, analysisData: null };
      }

      // 3. No prior analyses — safe to start automatically
      const analysisRes = await api.post(`/contracts/${contract.id}/ai-analysis`, { force_new: true });
      return { contract, prior: [] as any[], analysisData: analysisRes.data };
    },
    onSuccess: ({ contract, prior, analysisData }) => {
      if (prior.length > 0) {
        // Show picker so the user explicitly selects which analysis to use
        setStubPriorAnalyses(prior);
        setStep('reviewing');
        return;
      }
      if (!analysisData) return;
      const id = analysisData.data?.id ?? null;
      setAnalysisId(id);
      store.start({ analysisId: id, contractId: contract.id, contractTitle: contract.title, projectId });
      if (analysisData.data?.status === 'completed') {
        setAnalysis(analysisData.data);
        store.updateStatus('completed', analysisData.data);
        setStep('reviewing');
      } else {
        setPolling(true);
        store.updateStatus('processing');
        setStep('analysing');
      }
    },
    onError: (err: any) => {
      toast.error(getErrorMessage(err, 'Failed to start analysis. You can continue manually.'));
      setStep('upload');
    },
  });

  // Poll for analysis completion
  useEffect(() => {
    if (!polling || !analysisId) return;
    const interval = setInterval(async () => {
      try {
        const res = await api.get(`/ai/analyses/${analysisId}`);
        const a = res.data?.data;
        if (a?.status === 'completed' || a?.status === 'failed') {
          setPolling(false);
          setAnalysis(a);
          store.updateStatus(a.status, a.status === 'completed' ? a : null);
          setStep('reviewing');
        }
      } catch {
        setPolling(false);
        setStep('reviewing');
      }
    }, 3000);
    return () => clearInterval(interval);
  }, [polling, analysisId]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Save (handles both stub-update and fresh-create) ────────────────────────
  const saveMutation = useMutation({
    mutationFn: (data: ContractForm) => {
      if (stubContract) {
        // Upload path: stub contract already exists with the file attached
        return api.put(`/contracts/${stubContract.id}`, data).then(r => r.data);
      }
      // Existing-analysis path: fresh POST, optionally with a file
      if (formFile) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) fd.append(k, String(v)); });
        fd.append('contract_file', formFile);
        return api.post(`/projects/${projectId}/contracts`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data);
      }
      return api.post(`/projects/${projectId}/contracts`, data).then(r => r.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-contracts', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      qc.invalidateQueries({ queryKey: ['project-stats', projectId] });
      qc.invalidateQueries({ queryKey: ['project-doc-explorer', projectId] });
      store.clear();
      toast.success('Contract created');
      onCreated();
      onClose();
    },
    onError: (err: any) => toast.error(getErrorMessage(err, 'Failed to save contract')),
  });

  // ── Helpers ──────────────────────────────────────────────────────────────────
  function handleFileSelect(file: File) {
    const allowed = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'text/plain'];
    if (!allowed.includes(file.type)) {
      toast.error('Unsupported file type. Please upload a PDF, DOCX, or TXT file.');
      return;
    }
    setContractFile(file);
  }

  function handleDrop(e: React.DragEvent) {
    e.preventDefault();
    setDragOver(false);
    const file = e.dataTransfer.files?.[0];
    if (file) handleFileSelect(file);
  }

  function handleAnalyse() {
    if (!contractFile) return;
    setStep('analysing');
    createAndAnalyseMutation.mutate(contractFile);
  }

  // Properly cancel a running analysis: tell the backend to stop (so the queue job
  // discards the result), then stop polling and clear local state. The backend reports
  // whether any AI credits were used, which we surface honestly to the user.
  async function handleCancelAnalysis() {
    const proceed = window.confirm(
      'Cancel this analysis?\n\nIf it has already started running, the AI usage so far may still be charged.'
    );
    if (!proceed) return;

    if (analysisId) {
      try {
        const res = await api.post(`/ai/analyses/${analysisId}/cancel`);
        toast.success(res.data?.message ?? 'Analysis cancelled.');
      } catch {
        toast.error('Could not cancel the analysis. It may already have finished.');
      }
    }

    setPolling(false);
    store.clear();
    onClose();
  }

  function handleSelectSaved(a: any) {
    setSelectedSavedAnalysis(a);
    setAnalysis(a);
    setStep('reviewing');
  }

  function handleConfirmAnalysis() {
    const result = (analysis ?? selectedSavedAnalysis)?.raw_response_json ?? null;
    setForm(prev => ({ ...prev, ...mapExtractedToForm(result ?? {}) }));
    setStep('form');
  }

  function handleContinueManually() {
    store.clear();
    if (stubContract) {
      setStep('form');
    } else {
      onClose();
    }
  }

  function handleFormChange(e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>) {
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  }

  const activeResult = (analysis ?? selectedSavedAnalysis)?.raw_response_json ?? null;
  const usingExistingResult = !!selectedSavedAnalysis && !stubContract;

  // Step indicators — differ by path
  const UPLOAD_STEPS  = ['Upload', 'Analyse', 'Review', 'Create'];
  const REUSE_STEPS   = ['Select', 'Review', 'Create'];
  const steps = path === 'existing' ? REUSE_STEPS : (path === 'new' ? UPLOAD_STEPS : []);
  const stepIndex: number = (() => {
    if (path === 'new') {
      const map: Record<WizardStep, number> = { choice: 0, upload: 0, analysing: 1, reviewing: 2, form: 3, select: 0 };
      return map[step] ?? 0;
    }
    if (path === 'existing') {
      const map: Record<WizardStep, number> = { choice: 0, select: 0, reviewing: 1, form: 2, upload: 0, analysing: 0 };
      return map[step] ?? 0;
    }
    return 0;
  })();

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}>
      <div
        className="w-full max-w-2xl rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] flex flex-col"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', maxHeight: '92vh' }}
        onClick={e => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between p-5 flex-shrink-0" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <Sparkles size={16} style={{ color: 'var(--gold)' }} />
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Contract</h2>
          </div>
          <div className="flex items-center gap-4">
            {steps.length > 0 && (
              <div className="flex items-center gap-1">
                {steps.map((label, i) => (
                  <div key={label} className="flex items-center gap-1">
                    <div
                      className="flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold"
                      style={{
                        backgroundColor: i <= stepIndex ? 'var(--gold)' : 'var(--bg-elevated)',
                        color: i <= stepIndex ? 'var(--accent-fg)' : 'var(--text-muted)',
                      }}
                    >
                      {i + 1}
                    </div>
                    {i < steps.length - 1 && (
                      <div className="w-4 h-px" style={{ backgroundColor: i < stepIndex ? 'var(--gold)' : 'var(--border)' }} />
                    )}
                  </div>
                ))}
              </div>
            )}
            {step === 'analysing' ? (
              // During analysis, closing silently would leave the job running and billing.
              // Offer Minimise instead — the only non-destructive action here (Cancel is in the footer).
              <button
                onClick={() => store.minimize()}
                title="Minimise (analysis continues in the background)"
                className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs transition-colors hover:bg-[var(--bg-hover)]"
                style={{ color: 'var(--text-muted)' }}
              >
                <Minus size={13} />
                Minimise
              </button>
            ) : (
              <button onClick={() => { store.clear(); onClose(); }} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
                <X size={16} style={{ color: 'var(--text-muted)' }} />
              </button>
            )}
          </div>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-5">

          {/* ── Choice screen ── */}
          {step === 'choice' && (
            <div className="space-y-4">
              <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                Choose how you would like to create this contract.
              </p>

              {/* Option A: Upload & Analyse */}
              <button
                type="button"
                onClick={() => { setPath('new'); setStep('upload'); }}
                className="w-full text-left rounded-xl p-4 transition-colors hover:border-[var(--gold)]"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1.5px solid var(--border)' }}
              >
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center mt-0.5" style={{ backgroundColor: 'var(--gold-15)' }}>
                    <Upload size={16} style={{ color: 'var(--gold)' }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold mb-0.5" style={{ color: 'var(--text-primary)' }}>Upload &amp; Analyse New Contract</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      Upload a contract file. SureSign Contracts will read it and suggest contract details before you create the record.
                    </p>
                    <p className="text-xs mt-2 font-medium" style={{ color: '#ca8a04' }}>
                      ⚠ Running a new analysis may consume AI credits.
                    </p>
                  </div>
                  <ArrowRight size={16} className="flex-shrink-0 mt-1" style={{ color: 'var(--text-muted)' }} />
                </div>
              </button>

              {/* Option B: Use Existing Analysis */}
              <button
                type="button"
                onClick={() => { setPath('existing'); setStep('select'); }}
                className="w-full text-left rounded-xl p-4 transition-colors hover:border-[var(--gold)]"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1.5px solid var(--border)' }}
              >
                <div className="flex items-start gap-3">
                  <div className="flex-shrink-0 w-9 h-9 rounded-lg flex items-center justify-center mt-0.5" style={{ backgroundColor: 'rgba(74,222,128,0.1)' }}>
                    <CheckCircle size={16} style={{ color: '#4ade80' }} />
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-sm font-semibold mb-0.5" style={{ color: 'var(--text-primary)' }}>Use Existing AI Analysis</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      Select a previously completed analysis to populate the contract form. No AI will run.
                    </p>
                    <p className="text-xs mt-2 font-medium" style={{ color: '#4ade80' }}>
                      ✓ Will not consume AI credits.
                    </p>
                    {completedAnalyses.length > 0 && (
                      <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                        {completedAnalyses.length} completed {completedAnalyses.length === 1 ? 'analysis' : 'analyses'} available
                      </p>
                    )}
                  </div>
                  <ArrowRight size={16} className="flex-shrink-0 mt-1" style={{ color: 'var(--text-muted)' }} />
                </div>
              </button>
            </div>
          )}

          {/* ── Select existing analysis ── */}
          {step === 'select' && (
            <div className="space-y-4">
              <div
                className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs"
                style={{ backgroundColor: 'rgba(74,222,128,0.08)', border: '1px solid rgba(74,222,128,0.2)', color: '#4ade80' }}
              >
                <CheckCircle size={12} />
                Using an existing analysis will not consume additional AI credits.
              </div>

              {completedAnalyses.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-12 gap-3 text-center">
                  <FileText size={28} style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>No completed AI analyses found</p>
                  <p className="text-xs max-w-xs" style={{ color: 'var(--text-muted)' }}>
                    You can upload and analyse a new contract instead.
                  </p>
                  <button
                    type="button"
                    onClick={() => { setPath('new'); setStep('upload'); }}
                    className="mt-1 flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
                    style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                  >
                    <Upload size={14} />
                    Upload &amp; Analyse New Contract
                  </button>
                </div>
              ) : (
                <div className="space-y-2">
                  {completedAnalyses.map((a: any) => (
                    <div
                      key={a.id}
                      className="rounded-xl p-4"
                      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
                    >
                      <div className="flex items-start justify-between gap-3">
                        <div className="flex-1 min-w-0">
                          <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>
                            {a.contract?.title ?? `Contract #${a.contract_id}`}
                          </p>
                          <div className="flex flex-wrap gap-x-4 gap-y-0.5 mt-1">
                            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                              Completed {a.completed_at ? new Date(a.completed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : (a.created_at ? new Date(a.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '—')}
                            </span>
                            {a.creator?.name && (
                              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>by {a.creator.name}</span>
                            )}
                            {a.model && (
                              <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>
                                {a.model.replace('claude-', '')}
                              </span>
                            )}
                            {a.estimated_cost != null && (
                              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                                ${Number(a.estimated_cost).toFixed(4)}
                              </span>
                            )}
                          </div>
                        </div>
                        <div className="flex items-center gap-2 flex-shrink-0">
                          <button
                            type="button"
                            onClick={() => {
                              // Preview only — user can still go back
                              setSelectedSavedAnalysis(a);
                              setAnalysis(a);
                              setStep('reviewing');
                            }}
                            className="text-xs px-2.5 py-1.5 rounded-lg transition-colors hover:bg-[var(--bg-surface)]"
                            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                          >
                            View Result
                          </button>
                          <button
                            type="button"
                            onClick={() => handleSelectSaved(a)}
                            className="text-xs px-2.5 py-1.5 rounded-lg font-medium active:scale-[0.98]"
                            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                          >
                            Use This Analysis
                          </button>
                        </div>
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          )}

          {/* ── Upload step (new-file path) ── */}
          {step === 'upload' && (
            <div className="space-y-5">
              <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                Upload the contract file. SureSign Contracts will analyse the document and suggest contract details for review before creating the record.
              </p>
              <input
                ref={fileInputRef}
                type="file"
                className="hidden"
                accept=".pdf,.doc,.docx,.txt"
                onChange={e => { const f = e.target.files?.[0]; if (f) handleFileSelect(f); }}
              />
              {contractFile ? (
                <div
                  className="flex items-center justify-between gap-3 px-4 py-3 rounded-xl"
                  style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-30)' }}
                >
                  <div className="flex items-center gap-2 min-w-0">
                    <FileText size={16} style={{ color: 'var(--gold)', flexShrink: 0 }} />
                    <span className="text-sm truncate" style={{ color: 'var(--text-primary)' }}>{contractFile.name}</span>
                  </div>
                  <button
                    type="button"
                    onClick={() => { setContractFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }}
                    className="text-xs flex-shrink-0"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    Remove
                  </button>
                </div>
              ) : (
                <button
                  type="button"
                  onClick={() => fileInputRef.current?.click()}
                  onDragOver={e => { e.preventDefault(); setDragOver(true); }}
                  onDragLeave={() => setDragOver(false)}
                  onDrop={handleDrop}
                  className="w-full flex flex-col items-center justify-center gap-3 py-10 rounded-xl border-dashed transition-colors"
                  style={{
                    border: `2px dashed ${dragOver ? 'var(--gold)' : 'var(--border)'}`,
                    backgroundColor: dragOver ? 'var(--gold-8)' : 'var(--bg-base)',
                    color: 'var(--text-muted)',
                  }}
                >
                  <Upload size={24} style={{ color: dragOver ? 'var(--gold)' : 'var(--text-muted)' }} />
                  <div className="text-center">
                    <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Click to upload or drag and drop</p>
                    <p className="text-xs mt-1">PDF, DOCX, TXT supported</p>
                  </div>
                </button>
              )}
            </div>
          )}

          {/* ── Analysing ── */}
          {step === 'analysing' && <AnalysisLoadingDisplay />}

          {/* ── Review ── */}
          {step === 'reviewing' && (
            <div className="space-y-4">
              {/* Prior-analysis picker for the stub contract — user must explicitly select one */}
              {stubPriorAnalyses !== null && stubPriorAnalyses.length > 0 && !analysis && (
                <div className="space-y-4">
                  <div>
                    <p className="text-sm font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>
                      Previous analyses found for this contract
                    </p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      Select which analysis to use, or run a new one. Each analysis is independent of the document version.
                    </p>
                  </div>
                  <div
                    className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs"
                    style={{ backgroundColor: 'rgba(74,222,128,0.08)', border: '1px solid rgba(74,222,128,0.2)', color: '#4ade80' }}
                  >
                    <CheckCircle size={12} />
                    Viewing a saved analysis will not consume AI credits.
                  </div>
                  <div className="space-y-2">
                    {stubPriorAnalyses.map((a: any) => (
                      <div
                        key={a.id}
                        className="flex items-center justify-between gap-3 rounded-xl px-4 py-3"
                        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
                      >
                        <div className="flex-1 min-w-0">
                          <p className="text-xs font-medium" style={{ color: 'var(--text-primary)' }}>
                            Analysis #{a.id}
                            {a.status === 'confirmed' && (
                              <span className="ml-2 text-[10px] px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(59,130,246,0.12)', color: '#60a5fa' }}>Confirmed</span>
                            )}
                          </p>
                          <div className="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5">
                            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                              {a.completed_at
                                ? new Date(a.completed_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
                                : new Date(a.created_at).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}
                            </span>
                            {a.creator?.name && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>by {a.creator.name}</span>}
                            {a.model && <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>{a.model.replace('claude-', '')}</span>}
                            {a.estimated_cost != null && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>${Number(a.estimated_cost).toFixed(4)}</span>}
                          </div>
                        </div>
                        <button
                          onClick={() => { setAnalysis(a); setAnalysisId(a.id); setStubPriorAnalyses([]); }}
                          className="flex-shrink-0 text-xs px-3 py-1.5 rounded-lg font-medium"
                          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                        >
                          View This Analysis
                        </button>
                      </div>
                    ))}
                  </div>
                  <button
                    onClick={() => {
                      setStubPriorAnalyses([]);
                      if (stubContract) {
                        api.post(`/contracts/${stubContract.id}/ai-analysis`, { force_new: true })
                          .then(res => {
                            const id = res.data?.data?.id ?? null;
                            setAnalysisId(id);
                            if (res.data?.data?.status === 'completed') {
                              setAnalysis(res.data.data);
                            } else {
                              setPolling(true);
                              setStep('analysing');
                            }
                          })
                          .catch(() => toast.error('Failed to start new analysis.'));
                      }
                    }}
                    className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm border-dashed transition-colors hover:border-[var(--gold)] hover:text-[var(--gold)]"
                    style={{ border: '1.5px dashed var(--border)', color: 'var(--text-muted)' }}
                  >
                    <Sparkles size={13} />
                    Run New Analysis
                    <span className="text-xs ml-1" style={{ color: '#ca8a04' }}>(may consume credits)</span>
                  </button>
                </div>
              )}

              {/* Analysis failed */}
              {analysis?.status === 'failed' && (
                <div className="flex flex-col items-center justify-center py-10 gap-3">
                  <AlertTriangle size={28} style={{ color: '#f87171' }} />
                  <p className="text-sm font-medium" style={{ color: '#f87171' }}>Analysis failed</p>
                  <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>
                    {analysis.error_message ?? 'An unexpected error occurred.'}
                  </p>
                  <button onClick={handleContinueManually} className="mt-1 px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                    Continue Manually
                  </button>
                </div>
              )}

              {/* Credit-safe note for reused analyses */}
              {usingExistingResult && (
                <div
                  className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs"
                  style={{ backgroundColor: 'rgba(74,222,128,0.08)', border: '1px solid rgba(74,222,128,0.2)', color: '#4ade80' }}
                >
                  <CheckCircle size={12} />
                  Viewing a saved analysis. No AI credits are being used.
                </div>
              )}

              {/* AI disclaimer */}
              {activeResult && (
                <div
                  className="flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs"
                  style={{ backgroundColor: 'rgba(234,179,8,0.08)', border: '1px solid rgba(234,179,8,0.2)', color: '#ca8a04' }}
                >
                  <AlertTriangle size={13} className="mt-0.5 flex-shrink-0" />
                  AI-generated suggestions must be reviewed. Do not rely on this output for legal or commercial decisions without independent verification.
                </div>
              )}

              {/* Result sections */}
              {activeResult && (() => {
                const isV2wiz = 'contract_overview' in activeResult;
                return (
                  <>
                    {isV2wiz ? (
                      <>
                        {activeResult.executive_summary && (
                          <Section title="Executive Summary" open={openSections.executive} onToggle={() => setOpenSections(p => ({ ...p, executive: !p.executive }))}>
                            <div className="flex flex-wrap items-center gap-3 mb-2">
                              {activeResult.executive_summary.overall_risk_rating && (() => {
                                const rv = String(activeResult.executive_summary.overall_risk_rating).toLowerCase();
                                const c = rv === 'critical' ? '#ef4444' : rv === 'high' ? '#f87171' : rv === 'medium' ? '#eab308' : '#4ade80';
                                return <span className="text-xs px-2.5 py-1 rounded-full font-semibold uppercase" style={{ backgroundColor: `${c}20`, color: c, border: `1px solid ${c}40` }}>{activeResult.executive_summary.overall_risk_rating} Risk</span>;
                              })()}
                              {activeResult.executive_summary.intelligence_score != null && (
                                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Score: <strong style={{ color: 'var(--text-primary)' }}>{activeResult.executive_summary.intelligence_score}/100</strong></span>
                              )}
                            </div>
                            {activeResult.executive_summary.commercial_summary && (
                              <p className="text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>{activeResult.executive_summary.commercial_summary}</p>
                            )}
                          </Section>
                        )}
                        {activeResult.contract_overview && (
                          <Section title="Contract Overview" open={openSections.overview} onToggle={() => setOpenSections(p => ({ ...p, overview: !p.overview }))}>
                            <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                              {([
                                ['Contract Type', activeResult.contract_overview.contract_type],
                                ['Standard Form', activeResult.contract_overview.standard_form],
                                ['Procurement Route', activeResult.contract_overview.procurement_route],
                                ['Design Responsibility', activeResult.contract_overview.design_responsibility],
                              ] as [string, any][]).filter(([, v]) => v).map(([label, val]) => (
                                <div key={label}>
                                  <p className="text-xs font-medium mb-0.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
                                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{String(val)}</p>
                                </div>
                              ))}
                            </div>
                          </Section>
                        )}
                        {activeResult.dates && (
                          <Section title="Key Dates" open={openSections.dates} onToggle={() => setOpenSections(p => ({ ...p, dates: !p.dates }))}>
                            <div className="space-y-2">
                              {([
                                ['Commencement', activeResult.dates.commencement_date],
                                ['Completion', activeResult.dates.completion_date],
                                ['Possession', activeResult.dates.possession_date],
                              ] as [string, any][]).filter(([, v]) => v).map(([label, val]) => (
                                <div key={label} className="flex items-center justify-between">
                                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{label}</p>
                                  <p className="text-xs font-medium" style={{ color: 'var(--gold)' }}>{String(val)}</p>
                                </div>
                              ))}
                            </div>
                          </Section>
                        )}
                        {(activeResult.risks ?? []).length > 0 && (
                          <Section title={`Risks (${activeResult.risks.length})`} open={openSections.risks} onToggle={() => setOpenSections(p => ({ ...p, risks: !p.risks }))}>
                            <div className="space-y-2">
                              {activeResult.risks.slice(0, 5).map((r: any, i: number) => {
                                const sev = ({ low: { bg: 'rgba(34,197,94,0.1)', color: '#4ade80' }, medium: { bg: 'rgba(234,179,8,0.1)', color: '#eab308' }, high: { bg: 'rgba(239,68,68,0.1)', color: '#f87171' }, critical: { bg: 'rgba(239,68,68,0.15)', color: '#ef4444' } } as Record<string, { bg: string; color: string }>)[r.severity] ?? { bg: 'var(--bg-elevated)', color: 'var(--text-muted)' };
                                return (
                                  <div key={i} className="rounded-lg p-2.5" style={{ backgroundColor: sev.bg, border: `1px solid ${sev.color}30` }}>
                                    <div className="flex items-center justify-between gap-2">
                                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{r.title}</p>
                                      <span className="text-xs px-2 py-0.5 rounded-full capitalize flex-shrink-0" style={{ backgroundColor: sev.bg, color: sev.color }}>{r.severity}</span>
                                    </div>
                                  </div>
                                );
                              })}
                              {activeResult.risks.length > 5 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>+ {activeResult.risks.length - 5} more risks</p>}
                            </div>
                          </Section>
                        )}
                      </>
                    ) : (
                      <>
                        <Section title="Contract Summary" open={openSections.summary} onToggle={() => setOpenSections(p => ({ ...p, summary: !p.summary }))}>
                          <p className="text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>{activeResult.contract_summary ?? 'No summary provided.'}</p>
                        </Section>
                        <Section title="Extracted Contract Details" open={openSections.fields} onToggle={() => setOpenSections(p => ({ ...p, fields: !p.fields }))}>
                          <div className="grid grid-cols-2 gap-x-6 gap-y-3">
                            {Object.entries(activeResult.extracted_fields ?? {}).map(([key, val]) => (
                              <div key={key}>
                                <p className="text-xs font-medium capitalize mb-0.5" style={{ color: 'var(--text-muted)' }}>{key.replace(/_/g, ' ')}</p>
                                <p className="text-sm" style={{ color: val ? 'var(--text-primary)' : 'var(--text-muted)' }}>{val != null ? String(val) : '—'}</p>
                              </div>
                            ))}
                          </div>
                        </Section>
                        {(activeResult.key_dates ?? []).length > 0 && (
                          <Section title="Key Dates" open={openSections.dates} onToggle={() => setOpenSections(p => ({ ...p, dates: !p.dates }))}>
                            <div className="space-y-2">
                              {activeResult.key_dates.map((d: any, i: number) => (
                                <div key={i} className="flex items-center justify-between">
                                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{d.name}</p>
                                  <p className="text-xs font-medium" style={{ color: 'var(--gold)' }}>{d.date ?? '—'}</p>
                                </div>
                              ))}
                            </div>
                          </Section>
                        )}
                        {(activeResult.risks ?? []).length > 0 && (
                          <Section title={`Risks / Warnings (${activeResult.risks.length})`} open={openSections.risks} onToggle={() => setOpenSections(p => ({ ...p, risks: !p.risks }))}>
                            <div className="space-y-3">
                              {activeResult.risks.map((r: any, i: number) => {
                                const sev = ({ low: { bg: 'rgba(34,197,94,0.1)', color: '#4ade80' }, medium: { bg: 'rgba(234,179,8,0.1)', color: '#eab308' }, high: { bg: 'rgba(239,68,68,0.1)', color: '#f87171' } } as Record<string, { bg: string; color: string }>)[r.severity] ?? { bg: 'var(--bg-elevated)', color: 'var(--text-muted)' };
                                return (
                                  <div key={i} className="rounded-lg p-3" style={{ backgroundColor: sev.bg, border: `1px solid ${sev.color}30` }}>
                                    <div className="flex items-center justify-between mb-1">
                                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{r.title}</p>
                                      <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: sev.bg, color: sev.color }}>{r.severity}</span>
                                    </div>
                                    <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>{r.description}</p>
                                  </div>
                                );
                              })}
                            </div>
                          </Section>
                        )}
                      </>
                    )}

                    {/* Shared: Missing Information */}
                    {(activeResult.missing_information ?? []).length > 0 && (
                      <Section title={`Missing Information (${activeResult.missing_information.length})`} open={openSections.missing} onToggle={() => setOpenSections(p => ({ ...p, missing: !p.missing }))}>
                        <ul className="space-y-1">
                          {activeResult.missing_information.map((item: string, i: number) => (
                            <li key={i} className="text-xs flex items-start gap-1.5" style={{ color: 'var(--text-secondary)' }}>
                              <span className="flex-shrink-0 mt-1">•</span>{item}
                            </li>
                          ))}
                        </ul>
                      </Section>
                    )}
                  </>
                );
              })()}
            </div>
          )}

          {/* ── Contract form ── */}
          {step === 'form' && (
            <form id="wizard-form" onSubmit={e => { e.preventDefault(); saveMutation.mutate(form); }} className="space-y-4">
              <div
                className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs"
                style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-15)', color: 'var(--gold)' }}
              >
                <Sparkles size={12} />
                Fields pre-filled from AI analysis. Review and edit before saving.
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="col-span-2">
                  <InputField label="Title" name="title" value={form.title} onChange={handleFormChange} required />
                </div>
                <InputField label="Contract Type" name="type" value={form.type} onChange={handleFormChange} required options={CONTRACT_TYPES} />
                <InputField label="Reference Number" name="reference_number" value={form.reference_number} onChange={handleFormChange} />
                <InputField label="Contracting Party" name="party_name" value={form.party_name} onChange={handleFormChange} />
                <InputField label="Form of Contract" name="form_of_contract" value={form.form_of_contract} onChange={handleFormChange} />
                <InputField label="Contract Sum" name="contract_sum" type="number" value={form.contract_sum} onChange={handleFormChange} />
                <InputField label="Currency" name="currency" value={form.currency} onChange={handleFormChange} />
                <InputField label="Retention %" name="retention_percentage" type="number" value={form.retention_percentage} onChange={handleFormChange} />
                <InputField label="Retention Cap %" name="retention_cap_percentage" type="number" value={form.retention_cap_percentage} onChange={handleFormChange} />
                <InputField label="Payment Terms (days)" name="payment_terms_days" type="number" value={form.payment_terms_days} onChange={handleFormChange} />
                <InputField label="Execution Date" name="execution_date" type="date" value={form.execution_date} onChange={handleFormChange} />
                <InputField label="Commencement Date" name="commencement_date" type="date" value={form.commencement_date} onChange={handleFormChange} />
                <InputField label="Completion Date" name="completion_date" type="date" value={form.completion_date} onChange={handleFormChange} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
                <textarea
                  name="notes" value={form.notes} onChange={handleFormChange} rows={3}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
                  style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                />
              </div>

              {/* File upload — only shown on the existing-analysis path (no stub was created) */}
              {!stubContract && (
                <div>
                  <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>
                    Contract File
                    <span className="ml-1" style={{ color: 'var(--gold-80)' }}>(Optional)</span>
                  </label>
                  <input
                    ref={formFileInputRef}
                    type="file"
                    className="hidden"
                    accept=".pdf,.doc,.docx,.txt"
                    onChange={e => setFormFile(e.target.files?.[0] ?? null)}
                  />
                  {formFile ? (
                    <div
                      className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg"
                      style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-30)' }}
                    >
                      <div className="flex items-center gap-2 min-w-0">
                        <FileText size={14} style={{ color: 'var(--gold)', flexShrink: 0 }} />
                        <span className="text-xs truncate" style={{ color: 'var(--text-primary)' }}>{formFile.name}</span>
                      </div>
                      <button
                        type="button"
                        onClick={() => { setFormFile(null); if (formFileInputRef.current) formFileInputRef.current.value = ''; }}
                        className="text-xs flex-shrink-0"
                        style={{ color: 'var(--text-muted)' }}
                      >
                        Remove
                      </button>
                    </div>
                  ) : (
                    <button
                      type="button"
                      onClick={() => formFileInputRef.current?.click()}
                      className="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border-dashed text-sm transition-colors hover:border-[var(--gold)]"
                      style={{ border: '1.5px dashed var(--border)', color: 'var(--text-muted)' }}
                    >
                      <Upload size={14} />
                      Attach contract document
                    </button>
                  )}
                  <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
                    Upload the contract document to attach it to the record. PDF, DOCX, TXT supported.
                  </p>
                </div>
              )}
            </form>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between p-5 flex-shrink-0" style={{ borderTop: '1px solid var(--border)' }}>
          {/* Left: back or cancel */}
          {step === 'choice' && (
            <button type="button" onClick={() => { store.clear(); onClose(); }} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              Cancel
            </button>
          )}
          {step === 'upload' && (
            <button type="button" onClick={() => setStep('choice')} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              ← Back
            </button>
          )}
          {step === 'select' && (
            <button type="button" onClick={() => setStep('choice')} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              ← Back
            </button>
          )}
          {step === 'analysing' && (
            <button type="button" onClick={handleCancelAnalysis} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              Cancel
            </button>
          )}
          {step === 'reviewing' && (
            <button
              type="button"
              onClick={() => path === 'existing' ? setStep('select') : setStep('upload')}
              className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
            >
              ← Back
            </button>
          )}
          {step === 'form' && (
            <button type="button" onClick={() => setStep('reviewing')} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              ← Back
            </button>
          )}

          {/* Right: primary action */}
          {step === 'choice' && <div />}

          {step === 'upload' && (
            <button
              type="button"
              disabled={!contractFile}
              onClick={handleAnalyse}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: contractFile ? 'var(--gold)' : 'var(--bg-elevated)', color: contractFile ? 'var(--accent-fg)' : 'var(--text-muted)' }}
            >
              <Sparkles size={14} />
              Analyse Contract
              <ArrowRight size={14} />
            </button>
          )}

          {step === 'analysing' && (
            <button disabled className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium opacity-60" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              Analysing…
            </button>
          )}

          {step === 'select' && <div />}

          {step === 'reviewing' && activeResult && (analysis?.status !== 'failed') && (
            <div className="flex items-center gap-2">
              {path === 'new' && (
                <button type="button" onClick={handleContinueManually} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                  Skip: Enter Manually
                </button>
              )}
              <button
                type="button"
                onClick={handleConfirmAnalysis}
                className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                <CheckCircle size={14} />
                Use These Details
                <ArrowRight size={14} />
              </button>
            </div>
          )}

          {step === 'form' && (
            <button
              type="submit"
              form="wizard-form"
              disabled={saveMutation.isPending}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: saveMutation.isPending ? 0.7 : 1 }}
            >
              {saveMutation.isPending ? 'Creating…' : 'Create Contract'}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── New Contract Modal ───────────────────────────────────────────────────────

function AttachFileModal({ contract, projectId, onClose }: { contract: ProjectContract; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);

  const { mutate, isPending } = useMutation({
    mutationFn: () => {
      const fd = new FormData();
      fd.append('contract_file', file!);
      return api.post(`/contracts/${contract.id}/attach-file`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-doc-explorer', projectId] });
      toast.success('File attached');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to attach file')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl ss-animate-in shadow-[var(--shadow-pop)]" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Attach Contract File</h2>
            <p className="text-xs mt-0.5 truncate max-w-xs" style={{ color: 'var(--text-muted)' }}>{contract.title}</p>
          </div>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="p-5 space-y-4">
          <input
            ref={fileInputRef}
            type="file"
            className="hidden"
            accept=".pdf,.doc,.docx,.txt"
            onChange={e => setFile(e.target.files?.[0] ?? null)}
          />
          {file ? (
            <div className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg" style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-30)' }}>
              <span className="text-xs truncate" style={{ color: 'var(--text-primary)' }}>{file.name}</span>
              <button type="button" onClick={() => { setFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }} className="text-xs flex-shrink-0" style={{ color: 'var(--text-muted)' }}>Remove</button>
            </div>
          ) : (
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              className="w-full flex items-center justify-center gap-2 px-3 py-4 rounded-lg border-dashed text-sm transition-colors hover:border-[var(--gold)]"
              style={{ border: '1.5px dashed var(--border)', color: 'var(--text-muted)' }}
            >
              <Upload size={15} />
              Click to select contract file
            </button>
          )}
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Accepted: PDF, DOC, DOCX, TXT. Max 50 MB.</p>
          <div className="flex justify-end gap-3 pt-1">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button
              type="button"
              disabled={!file || isPending}
              onClick={() => mutate()}
              className="px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: (!file || isPending) ? 0.5 : 1 }}
            >
              {isPending ? 'Attaching…' : 'Attach File'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

function SubcontractFilesModal({ projectId, packageId, packageName, onClose }: { projectId: string; packageId: number; packageName: string; onClose: () => void }) {
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['package-files', projectId, packageId],
    queryFn: () => api.get(`/projects/${projectId}/documents/module/subcontracts/package/${packageId}`).then(r => r.data),
  });

  const files: any[] = data?.files ?? data?.data ?? [];

  return (
    <>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
        <div className="w-full max-w-2xl rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] max-h-[80vh] flex flex-col" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <div className="flex items-center justify-between p-5 flex-shrink-0" style={{ borderBottom: '1px solid var(--border)' }}>
            <div>
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Package Files</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{packageName}</p>
            </div>
            <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
          </div>
          <div className="overflow-y-auto flex-1 p-5">
            {isLoading ? (
              <div className="flex items-center justify-center py-10">
                <Loader2 size={20} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
              </div>
            ) : files.length === 0 ? (
              <div className="text-center py-10">
                <FileText size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No files uploaded for this package yet.</p>
              </div>
            ) : (
              <div className="space-y-2">
                {files.map((f: any) => (
                  <div key={f.id} className="flex items-center justify-between gap-3 px-4 py-3 rounded-xl" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                    <div className="flex items-center gap-3 min-w-0">
                      <FileText size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                      <div className="min-w-0">
                        <p className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{f.original_name}</p>
                        {f.file_size && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{(f.file_size / 1024).toFixed(0)} KB</p>}
                      </div>
                    </div>
                    <div className="flex items-center gap-1 flex-shrink-0">
                      <button
                        onClick={() => setPreviewTarget({ id: f.id, name: f.original_name, mimeType: f.mime_type, previewEndpoint: `/file-uploads/${f.id}/preview`, downloadEndpoint: `/file-uploads/${f.id}/download` })}
                        className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-surface)]"
                        style={{ color: 'var(--text-muted)' }}
                      >
                        <Eye size={11} />
                        Preview
                      </button>
                      <a
                        href={`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/file-uploads/${f.id}/download`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-surface)]"
                        style={{ color: 'var(--text-muted)' }}
                      >
                        <Download size={11} />
                        Download
                      </a>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
      {previewTarget && <DocumentPreviewModal target={previewTarget} onClose={() => setPreviewTarget(null)} />}
    </>
  );
}

function NewContractModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [contractFile, setContractFile] = useState<File | null>(null);
  const [form, setForm] = useState<ContractForm>({
    title: '', type: 'main_contract', reference_number: '', form_of_contract: '',
    party_name: '', contract_sum: '', currency: 'GBP', retention_percentage: '3',
    retention_cap_percentage: '5', payment_terms_days: '30',
    commencement_date: '', completion_date: '', execution_date: '',
    defects_liability_period: '', liquidated_damages: '',
    notice_requirements: '', variation_procedure: '', notes: '',
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: ContractForm) => {
      if (contractFile) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) fd.append(k, v); });
        fd.append('contract_file', contractFile);
        return api.post(`/projects/${projectId}/contracts`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data);
      }
      return api.post(`/projects/${projectId}/contracts`, data).then(r => r.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-doc-explorer', projectId] });
      toast.success('Contract added');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to create contract')),
  });

  const handleChange = (e: FormChangeEvent) => {
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-2xl rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Contract</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="col-span-2">
              <InputField label="Title" name="title" value={form.title} onChange={handleChange} required />
            </div>
            <InputField label="Contract Type" name="type" value={form.type} onChange={handleChange} required options={CONTRACT_TYPES} />
            <InputField label="Reference Number" name="reference_number" value={form.reference_number} onChange={handleChange} />
            <InputField label="Contracting Party" name="party_name" value={form.party_name} onChange={handleChange} />
            <InputField label="Form of Contract" name="form_of_contract" value={form.form_of_contract} onChange={handleChange} />
            <InputField label="Contract Sum" name="contract_sum" type="number" value={form.contract_sum} onChange={handleChange} />
            <InputField label="Currency" name="currency" value={form.currency} onChange={handleChange} />
            <InputField label="Retention %" name="retention_percentage" type="number" value={form.retention_percentage} onChange={handleChange} />
            <InputField label="Retention Cap %" name="retention_cap_percentage" type="number" value={form.retention_cap_percentage} onChange={handleChange} />
            <InputField label="Payment Terms (days)" name="payment_terms_days" type="number" value={form.payment_terms_days} onChange={handleChange} />
            <InputField label="Execution Date" name="execution_date" type="date" value={form.execution_date} onChange={handleChange} />
            <InputField label="Commencement Date" name="commencement_date" type="date" value={form.commencement_date} onChange={handleChange} />
            <InputField label="Completion Date" name="completion_date" type="date" value={form.completion_date} onChange={handleChange} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea
              name="notes" value={form.notes} onChange={handleChange} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          {/* Contract file upload — required */}
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>
              Contract File <span style={{ color: '#f87171' }}>*</span>
            </label>
            <input
              ref={fileInputRef}
              type="file"
              className="hidden"
              accept=".pdf,.doc,.docx,.txt"
              onChange={e => setContractFile(e.target.files?.[0] ?? null)}
            />
            {contractFile ? (
              <div
                className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg"
                style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-30)' }}
              >
                <span className="text-xs truncate" style={{ color: 'var(--text-primary)' }}>{contractFile.name}</span>
                <button
                  type="button"
                  onClick={() => { setContractFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }}
                  className="text-xs flex-shrink-0"
                  style={{ color: 'var(--text-muted)' }}
                >
                  Remove
                </button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border-dashed text-sm transition-colors hover:border-[var(--gold)]"
                style={{ border: '1.5px dashed var(--border)', color: 'var(--text-muted)' }}
              >
                <Upload size={15} />
                Click to attach contract file
              </button>
            )}
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              PDF, DOC, DOCX or TXT. Max 50 MB. Required.
            </p>
          </div>

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending || !contractFile} className="px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: (isPending || !contractFile) ? 0.5 : 1 }}>
              {isPending ? 'Creating…' : 'Create Contract'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Delete Confirmation Modal ────────────────────────────────────────────────

function DeleteContractModal({ contract, onClose }: { contract: ProjectContract; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [typed, setTyped] = useState('');

  const { mutate: doDelete, isPending } = useMutation({
    mutationFn: () => api.delete(`/contracts/${contract.id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts'] });
      toast.success('Contract deleted.');
      onClose();
    },
    onError: (err: unknown) => {
      toast.error(getErrorMessage(err, 'Failed to delete contract.'));
    },
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'rgba(239,68,68,0.1)' }}>
              <Trash2 size={16} style={{ color: '#f87171' }} />
            </div>
            <div>
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Delete Contract</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{contract.title}</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]" style={{ color: 'var(--text-muted)' }}><X size={15} /></button>
        </div>

        <div className="rounded-xl p-3 text-sm space-y-1" style={{ backgroundColor: 'rgba(239,68,68,0.06)', border: '1px solid rgba(239,68,68,0.2)' }}>
          <p style={{ color: '#f87171' }}>This contract is currently a draft and has no linked records.</p>
          <p style={{ color: 'var(--text-muted)' }}>Deleting this contract will permanently remove it from the project. This action cannot be undone.</p>
        </div>

        <div className="space-y-1.5">
          <label className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
            Type <span className="font-mono font-bold" style={{ color: 'var(--text-primary)' }}>DELETE</span> to confirm
          </label>
          <input
            value={typed}
            onChange={e => setTyped(e.target.value)}
            placeholder="DELETE"
            autoFocus
            className="w-full px-3 py-2 rounded-lg text-sm outline-none font-mono"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>

        <div className="flex justify-end gap-2 pt-1">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => doDelete()}
            disabled={typed !== 'DELETE' || isPending}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-40 disabled:cursor-not-allowed"
            style={{ backgroundColor: '#dc2626', color: '#fff' }}
          >
            {isPending ? <Loader2 size={13} className="animate-spin" /> : <Trash2 size={13} />}
            Delete Contract
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Archive Confirmation Modal ───────────────────────────────────────────────

function ArchiveContractModal({ contract, onClose }: { contract: ProjectContract; onClose: () => void }) {
  const queryClient = useQueryClient();

  const { mutate: doArchive, isPending } = useMutation({
    mutationFn: () => api.post(`/contracts/${contract.id}/archive`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts'] });
      toast.success('Contract archived.');
      onClose();
    },
    onError: (err: unknown) => {
      toast.error(getErrorMessage(err, 'Failed to archive contract.'));
    },
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'rgba(234,179,8,0.1)' }}>
              <Archive size={16} style={{ color: '#facc15' }} />
            </div>
            <div>
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Archive Contract</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{contract.title}</p>
            </div>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]" style={{ color: 'var(--text-muted)' }}><X size={15} /></button>
        </div>

        <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
          This contract will be archived and removed from active contract lists. All linked documents, commercial records, and audit history will be retained.
        </p>
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
          You can restore the contract later if required.
        </p>

        <div className="flex justify-end gap-2 pt-1">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => doArchive()}
            disabled={isPending}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-40 active:scale-[0.98]"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {isPending ? <Loader2 size={13} className="animate-spin" /> : <Archive size={13} />}
            Archive Contract
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Trade package status badge ────────────────────────────────────────────

function TpStatusBadge({ status }: { status?: string | null }) {
  const META: Record<string, { label: string; bg: string; text: string }> = {
    tendering:        { label: 'Tendering',        bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
    tender_returned:  { label: 'Tender Returned',  bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
    under_review:     { label: 'Under Review',     bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
    awarded:          { label: 'Awarded',          bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
    documents_issued: { label: 'Documents Issued', bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
    executed:         { label: 'Executed',         bg: 'rgba(167,139,250,0.15)',text: '#a78bfa' },
    active:           { label: 'Active',           bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
    completed:        { label: 'Completed',        bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
    closed:           { label: 'Closed',           bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
    archived:         { label: 'Archived',         bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
    inactive:         { label: 'Inactive',         bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  };
  const m = META[status ?? ''] ?? { label: status ?? '—', bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
  return (
    <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: m.bg, color: m.text }}>
      {m.label}
    </span>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ProjectContractsPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id } = useParams<{ id: string }>();
  // Contracts + Trade Packages are both reviewed for Batch 2 — Client has
  // full operational authority here, same as a platform operator.
  const { canManageContracts: canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editContract, setEditContract] = useState<(ProjectContract & Record<string, any>) | null>(null);
  const [analyseContract, setAnalyseContract] = useState<ProjectContract | null>(null);
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);
  const [attachFileContract, setAttachFileContract] = useState<ProjectContract | null>(null);
  const [viewPackageId, setViewPackageId] = useState<number | null>(null);
  const [generatePackage, setGeneratePackage] = useState<{ id: number; name: string; package_code?: string | null; package_reference?: string | null; contractor_name?: string | null } | null>(null);
  const [openMenuId, setOpenMenuId] = useState<number | null>(null);
  const [viewAnalysis, setViewAnalysis] = useState<any>(null);
  const [contractFilter, setContractFilter] = useState<'active' | 'archived'>('active');
  const [deleteContract, setDeleteContract] = useState<ProjectContract | null>(null);
  const [archiveContract, setArchiveContract] = useState<ProjectContract | null>(null);
  const [showCreatePackageModal, setShowCreatePackageModal] = useState(false);
  const [postCreatePackages, setPostCreatePackages] = useState<Array<{ id: number; name: string; is_custom?: boolean }> | null>(null);
  const [onboardingTarget, setOnboardingTarget] = useState<{ id: number; name: string; is_custom?: boolean } | null>(null);
  const aiStore = useAiAnalysisStore();
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery<ApiCollection<ProjectContract>>({
    queryKey: ['project-contracts', id, contractFilter],
    queryFn: () => api.get(`/projects/${id}/contracts`, { params: { filter: contractFilter } }).then(r => r.data),
  });

  const { data: projectData } = useQuery({
    queryKey: ['project', id],
    queryFn: () => api.get(`/projects/${id}`).then(r => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000,
    enabled: !!id,
  });

  const { mutate: doRestore } = useMutation({
    mutationFn: (contractId: number) => api.post(`/contracts/${contractId}/restore`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts', id] });
      toast.success('Contract restored.');
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to restore contract.')),
  });

  const [generatingBriefId, setGeneratingBriefId] = useState<number | null>(null);
  const { mutate: doGenerateBrief } = useMutation({
    mutationFn: (analysisId: number) => api.post(`/ai/analyses/${analysisId}/generate-brief`).then(r => r.data),
    onMutate: (analysisId) => setGeneratingBriefId(analysisId),
    onSuccess: () => {
      toast.success('Contract Intelligence Brief generated and saved to documents.');
      setGeneratingBriefId(null);
    },
    onError: (err: unknown) => {
      toast.error(getErrorMessage(err, 'Failed to generate brief.'));
      setGeneratingBriefId(null);
    },
  });

  const { data: aiStatus } = useQuery({
    queryKey: ['ai-status'],
    queryFn: () => api.get('/ai/status').then(r => r.data),
    staleTime: 60_000,
  });

  const aiEnabled = !!(aiStatus?.ai_enabled);

  const { data: subcontractsData, refetch: refetchSubcontracts } = useQuery({
    queryKey: ['project-subcontracts', id],
    queryFn: () => api.get(`/projects/${id}/documents/module/subcontracts`).then(r => r.data),
    enabled: !!id,
  });
  const tradePackages: Array<{ id: number; name: string; package_code?: string | null; package_reference?: string | null; contractor_name?: string | null; status?: string | null; files_count?: number; key: string; is_custom?: boolean }> =
    subcontractsData?.trade_packages ?? [];

  const { data: analysesData, refetch: refetchAnalyses } = useQuery({
    queryKey: ['project-ai-analyses', id],
    queryFn: () => api.get(`/projects/${id}/ai-analyses`).then(r => r.data),
    enabled: aiEnabled && !!id,
  });
  const aiAnalyses: any[] = analysesData?.data ?? [];

  // Restore minimised analysis when returning to this page
  useEffect(() => {
    if (!data?.data || !aiStore.analysisId || !aiStore.isMinimized) return;
    if (aiStore.projectId !== id) return;
    const contract = data.data.find(c => c.id === aiStore.contractId);
    if (contract) {
      aiStore.restore();
      setAnalyseContract(contract);
    }
  }, [data?.data]); // eslint-disable-line react-hooks/exhaustive-deps

  const contracts = (data?.data ?? []).filter(contract =>
    contract.title?.toLowerCase().includes(search.toLowerCase()) ||
    contract.reference_number?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Contracts</h1>
            <PageTourButton tourKey="page-contracts" label="Take a tour of this page" />
          </div>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Contract documents and sub-contract agreements</p>
        </div>
        {canWrite && (
        <button
          data-tour="contracts-new"
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New Contract
        </button>
        )}
      </div>

      <div className="flex items-center gap-3 flex-wrap" data-tour="contracts-filters">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search contracts…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', width: 240, boxShadow: 'var(--shadow-card)' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {(['active', 'archived'] as const).map(f => (
            <button
              key={f}
              onClick={() => setContractFilter(f)}
              className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
              style={{
                backgroundColor: contractFilter === f ? 'var(--gold)' : 'transparent',
                color: contractFilter === f ? 'var(--accent-fg)' : 'var(--text-secondary)',
              }}
            >
              {f === 'active' ? 'Active' : 'Archived'}
            </button>
          ))}
        </div>
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : contracts.length === 0 ? (
        <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <FileText size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No contracts added yet</p>
          <Button onClick={() => setShowModal(true)} variant="secondary" size="sm" className="mt-4">
            Add first contract
          </Button>
        </div>
      ) : (
        <div className="rounded-2xl overflow-visible" data-tour="contracts-table" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Ref #', 'Title', 'Party', 'Contract Sum', 'Commencement', 'Completion', 'Status', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {contracts.map((c, index) => {
                const badge = STATUS_COLORS[c.status as string] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                const primaryFile = c.file_uploads?.[0] ?? null;
                const hasFile = !!primaryFile;
                return (
                  <tr key={c.id} className="ss-animate-in hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)', animationDelay: `${Math.min(index * 45, 360)}ms` }}>
                    <td className="px-5 py-3 font-mono text-[11px] font-semibold" style={{ color: 'var(--gold)' }}>{c.reference_number ?? `#${c.id}`}</td>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>
                      <div>{c.title}</div>
                      <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                        {CONTRACT_TYPES.find(t => t.value === c.type)?.label ?? c.type?.replace(/_/g, ' ') ?? ''}
                      </div>
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{c.party_name ?? '—'}</td>
                    <td className="px-5 py-3 text-xs font-medium tabular-nums" style={{ color: 'var(--text-primary)' }}>{formatCurrency(c.contract_sum ?? 0)}</td>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{c.commencement_date ? formatDate(c.commencement_date) : '—'}</td>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{c.completion_date ? formatDate(c.completion_date) : '—'}</td>
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-2">
                        <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: badge.bg, color: badge.text }}>
                          {CONTRACT_STATUS_LABELS[c.status ?? ''] ?? c.status ?? 'Draft'}
                        </span>
                        {!hasFile && (
                          <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
                            No File
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="relative flex justify-end">
                        <button
                          onClick={() => setOpenMenuId(openMenuId === c.id ? null : c.id)}
                          className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                          style={{ color: 'var(--text-muted)' }}
                        >
                          <MoreHorizontal size={15} />
                        </button>
                        {openMenuId === c.id && (
                          <>
                            <div className="fixed inset-0 z-10" onClick={() => setOpenMenuId(null)} />
                            <div
                              className="absolute right-0 top-full mt-1 z-20 min-w-[160px] rounded-xl shadow-lg overflow-hidden"
                              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
                            >
                              {hasFile && (
                                <>
                                  <button
                                    onClick={() => { setOpenMenuId(null); setPreviewTarget({ id: primaryFile!.id, name: primaryFile!.original_name, mimeType: primaryFile!.mime_type ?? undefined, previewEndpoint: `/file-uploads/${primaryFile!.id}/preview`, downloadEndpoint: `/file-uploads/${primaryFile!.id}/download` }); }}
                                    className="flex items-center gap-2.5 w-full px-3 py-2 text-xs text-left transition-colors hover:bg-[var(--bg-hover)]"
                                    style={{ color: 'var(--text-secondary)' }}
                                  >
                                    <Eye size={13} /> Preview File
                                  </button>
                                  <a
                                    href={`${process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api'}/file-uploads/${primaryFile!.id}/download`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    onClick={() => setOpenMenuId(null)}
                                    className="flex items-center gap-2.5 w-full px-3 py-2 text-xs transition-colors hover:bg-[var(--bg-hover)]"
                                    style={{ color: 'var(--text-secondary)' }}
                                  >
                                    <Download size={13} /> Download File
                                  </a>
                                  <div style={{ borderTop: '1px solid var(--border)' }} />
                                </>
                              )}
                              {!hasFile && canWrite && (
                                <>
                                  <button
                                    onClick={() => { setOpenMenuId(null); setAttachFileContract(c); }}
                                    className="flex items-center gap-2.5 w-full px-3 py-2 text-xs text-left transition-colors hover:bg-[var(--bg-hover)]"
                                    style={{ color: '#f87171' }}
                                  >
                                    <Paperclip size={13} /> Attach File
                                  </button>
                                  <div style={{ borderTop: '1px solid var(--border)' }} />
                                </>
                              )}
                              {canWrite && (
                                <button
                                  onClick={() => { setOpenMenuId(null); setEditContract(c as any); }}
                                  className="flex items-center gap-2.5 w-full px-3 py-2 text-xs text-left transition-colors hover:bg-[var(--bg-hover)]"
                                  style={{ color: 'var(--text-secondary)' }}
                                >
                                  <FileText size={13} /> Edit Details
                                </button>
                              )}
                              <div className="px-1 py-0.5" onClick={() => setOpenMenuId(null)}>
                                <PromptActionButton
                                  label="Prompt"
                                  module="Contracts"
                                  recordType="contract"
                                  recordId={c.id}
                                  projectId={id!}
                                />
                              </div>
                              {aiEnabled && (
                                <button
                                  onClick={() => { if (!hasFile) return; setOpenMenuId(null); setAnalyseContract(c); }}
                                  disabled={!hasFile}
                                  className="flex items-center gap-2.5 w-full px-3 py-2 text-xs text-left transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-40 disabled:cursor-not-allowed"
                                  style={{ color: 'var(--gold)' }}
                                  title={!hasFile ? 'Attach a contract file before running analysis' : undefined}
                                >
                                  <Sparkles size={13} /> AI Analyse
                                </button>
                              )}
                              {canWrite && (
                                <>
                                  <div style={{ borderTop: '1px solid var(--border)' }} />
                                  {/* Archived: show Restore only */}
                                  {c.archived_at ? (
                                    <button
                                      onClick={() => { setOpenMenuId(null); doRestore(c.id); }}
                                      className="flex items-center gap-2.5 w-full px-3 py-2 text-xs text-left transition-colors hover:bg-[var(--bg-hover)]"
                                      style={{ color: '#4ade80' }}
                                    >
                                      <RotateCcw size={13} /> Restore Contract
                                    </button>
                                  ) : (
                                    <>
                                      <button
                                        onClick={() => { setOpenMenuId(null); setArchiveContract(c); }}
                                        className="flex items-center gap-2.5 w-full px-3 py-2 text-xs text-left transition-colors hover:bg-[var(--bg-hover)]"
                                        style={{ color: 'var(--text-muted)' }}
                                      >
                                        <Archive size={13} /> Archive Contract
                                      </button>
                                      {/* Delete only shown for clean draft contracts */}
                                      {c.status === 'draft' &&
                                        (c.payment_applications?.length ?? 0) === 0 &&
                                        (c.variations?.length ?? 0) === 0 &&
                                        (c.eot_requests?.length ?? 0) === 0 && (
                                        <button
                                          onClick={() => { setOpenMenuId(null); setDeleteContract(c); }}
                                          className="flex items-center gap-2.5 w-full px-3 py-2 text-xs text-left transition-colors hover:bg-[var(--bg-hover)]"
                                          style={{ color: '#f87171' }}
                                        >
                                          <Trash2 size={13} /> Delete Contract
                                        </button>
                                      )}
                                    </>
                                  )}
                                </>
                              )}
                            </div>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* ── Subcontracts (Trade Packages) ── */}
      <div className="space-y-3" data-tour="contracts-subcontracts">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Subcontracts</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Trade package subcontract agreements</p>
          </div>
          {canWrite && (
            <button
              onClick={() => setShowCreatePackageModal(true)}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Plus size={15} />
              New trade package
            </button>
          )}
        </div>

        {tradePackages.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <FileSignature size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No trade packages created yet</p>
            {canWrite && (
              <Button onClick={() => setShowCreatePackageModal(true)} variant="secondary" size="sm" className="mt-4">
                Create first trade package
              </Button>
            )}
          </div>
        ) : (
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <table className="w-full text-sm">
              <thead>
                <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                  {['Package', 'Reference', 'Contractor', 'Status', 'Files', ''].map(h => (
                    <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                {tradePackages.map((pkg, index) => (
                  <tr key={pkg.id} className="ss-animate-in hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)', animationDelay: `${Math.min(index * 45, 360)}ms` }}>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>
                      <Link href={`/app/projects/${id}/subcontracts/${pkg.id}`} className="hover:underline">{pkg.name}</Link>
                    </td>
                    <td className="px-5 py-3 font-mono text-[11px] font-semibold" style={{ color: 'var(--gold)' }}>
                      {pkg.package_reference ?? pkg.package_code ?? '—'}
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      {pkg.contractor_name
                        ? pkg.contractor_name
                        : <span className="italic" style={{ color: 'var(--text-muted)' }}>Not assigned</span>}
                    </td>
                    <td className="px-5 py-3"><TpStatusBadge status={pkg.status} /></td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{pkg.files_count ?? 0} file{(pkg.files_count ?? 0) !== 1 ? 's' : ''}</td>
                    <td className="px-5 py-3">
                      <div className="flex items-center gap-1 flex-wrap">
                        <Link
                          href={`/app/projects/${id}/subcontracts/${pkg.id}`}
                          className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                          style={{ color: 'var(--gold)' }}
                        >
                          <LayoutDashboard size={11} />
                          Open Workspace
                        </Link>
                        <button
                          onClick={() => setViewPackageId(pkg.id)}
                          className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                          style={{ color: 'var(--text-muted)' }}
                        >
                          <Eye size={11} />
                          Documents
                        </button>
                        {canWrite && (
                          <>
                            <Link
                              href={`/app/projects/${id}/subcontracts/${pkg.id}?edit=1`}
                              className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                              style={{ color: 'var(--text-muted)' }}
                            >
                              <Pencil size={11} />
                              Edit
                            </Link>
                            <button
                              onClick={() => setGeneratePackage({ id: pkg.id, name: pkg.name, package_code: pkg.package_code, package_reference: pkg.package_reference, contractor_name: pkg.contractor_name })}
                              className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                              style={{ color: 'var(--text-muted)' }}
                            >
                              <FileSignature size={11} />
                              Generate Docs
                            </button>
                          </>
                        )}
                        <PromptActionButton
                          label="Prompt"
                          module="Subcontracts"
                          recordType="trade_package"
                          recordId={pkg.id}
                          projectId={id!}
                        />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ── AI Analysis History ── */}
      {aiEnabled && aiAnalyses.length > 0 && (
        <div className="space-y-3" data-tour="contracts-ai-history">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>AI analysis history</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Previous AI contract analysis runs</p>
          </div>
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <table className="w-full text-sm">
              <thead>
                <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                  {['Date', 'Contract', 'Status', 'Created by', 'Model', 'Cost', ''].map(h => (
                    <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                {aiAnalyses.map((a: any, index: number) => {
                  const statusColors: Record<string, { bg: string; text: string }> = {
                    completed:  { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
                    confirmed:  { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
                    failed:     { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
                    pending:    { bg: 'rgba(234,179,8,0.1)',   text: '#eab308' },
                    processing: { bg: 'rgba(234,179,8,0.1)',   text: '#eab308' },
                    cancelled:  { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
                  };
                  const badge = statusColors[a.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                  const canView = a.status === 'completed' || a.status === 'confirmed';
                  const contractForRow = data?.data?.find((c: ProjectContract) => c.id === a.contract_id);
                  return (
                    <tr key={a.id} className="ss-animate-in hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)', animationDelay: `${Math.min(index * 45, 360)}ms` }}>
                      <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                        {a.created_at ? new Date(a.created_at).toLocaleDateString() : '—'}
                      </td>
                      <td className="px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-primary)' }}>
                        {a.contract?.title ?? contractForRow?.title ?? `Contract #${a.contract_id}`}
                      </td>
                      <td className="px-5 py-3">
                        <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: badge.bg, color: badge.text }}>
                          {a.status}
                        </span>
                      </td>
                      <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                        {a.creator?.name ?? '—'}
                      </td>
                      <td className="px-5 py-3 text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>
                        {a.model ? a.model.replace('claude-', '') : '—'}
                      </td>
                      <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                        {a.estimated_cost != null ? `$${Number(a.estimated_cost).toFixed(4)}` : '—'}
                      </td>
                      <td className="px-5 py-3">
                        <div className="flex items-center gap-1">
                          {canView && (
                            <button
                              onClick={() => setViewAnalysis(a)}
                              className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                              style={{ color: 'var(--gold)' }}
                            >
                              View Result
                            </button>
                          )}
                          {a.status === 'confirmed' && (
                            <button
                              onClick={() => doGenerateBrief(a.id)}
                              disabled={generatingBriefId === a.id}
                              className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-50"
                              style={{ color: 'var(--text-secondary)' }}
                              title="Generate Contract Intelligence Brief PDF"
                            >
                              {generatingBriefId === a.id
                                ? <Loader2 size={11} className="animate-spin" />
                                : <FileText size={11} />}
                              Generate Brief
                            </button>
                          )}
                          {canView && contractForRow && (
                            <button
                              onClick={() => { setAnalyseContract(contractForRow); }}
                              className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                              style={{ color: 'var(--text-muted)' }}
                            >
                              Re-run
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {canWrite && showModal && (
        aiEnabled
          ? <AiContractWizard projectId={id!} aiAnalyses={aiAnalyses} onClose={() => setShowModal(false)} onCreated={() => { refetchAnalyses(); }} />
          : <NewContractModal projectId={id!} onClose={() => setShowModal(false)} />
      )}
      {canWrite && editContract && (
        <EditContractModal contract={editContract} projectId={id!} onClose={() => setEditContract(null)} />
      )}
      {canWrite && attachFileContract && (
        <AttachFileModal
          contract={attachFileContract}
          projectId={id!}
          onClose={() => setAttachFileContract(null)}
        />
      )}
      {canWrite && generatePackage && (
        <GeneratePackageModal
          projectId={id!}
          tradePackage={generatePackage}
          onClose={() => setGeneratePackage(null)}
          onViewInPackage={() => {
            setGeneratePackage(null);
            queryClient.invalidateQueries({ queryKey: ['project-subcontracts', id] });
          }}
        />
      )}
      {canWrite && showCreatePackageModal && (
        <GenerateTradePackageFolderModal
          isOpen={showCreatePackageModal}
          onClose={() => setShowCreatePackageModal(false)}
          projectId={Number(id)}
          projectReference={projectData?.code ?? ''}
          existingPackageNames={tradePackages.map(p => p.name)}
          apiPath={`/projects/${id}/subcontracts/generate-trade-packages`}
          title="Create Trade Package"
          description="Select the trade packages you want to create for this contract."
          onSuccess={async (result) => {
            setShowCreatePackageModal(false);
            const refreshed = await refetchSubcontracts();
            const refreshedPackages: Array<{ id: number; name: string; is_custom?: boolean }> = refreshed.data?.trade_packages ?? [];
            const created = refreshedPackages.filter(p => result.created.includes(p.name));
            if (created.length > 0) setPostCreatePackages(created);
          }}
        />
      )}
      {onboardingTarget && (
        <SubcontractAiOnboardingModal
          isOpen={!!onboardingTarget}
          onClose={() => setOnboardingTarget(null)}
          tradePackage={onboardingTarget}
          projectId={id!}
          onConfirmed={() => {
            queryClient.invalidateQueries({ queryKey: ['project-subcontracts', id] });
          }}
        />
      )}
      {postCreatePackages && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
          <div className="ss-animate-in w-full max-w-md rounded-2xl p-6 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <div className="flex items-start justify-between">
              <div>
                <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
                  {postCreatePackages.length === 1 ? `"${postCreatePackages[0].name}" created` : `${postCreatePackages.length} trade packages created`}
                </h2>
                <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>What would you like to do next?</p>
              </div>
              <button onClick={() => setPostCreatePackages(null)} aria-label="Close">
                <X size={18} style={{ color: 'var(--text-muted)' }} />
              </button>
            </div>
            <div className="space-y-2">
              {(() => {
                const canOnboard = aiEnabled && postCreatePackages.length === 1;
                return (
                  <button
                    disabled={!canOnboard}
                    title={!aiEnabled ? 'AI features are disabled for this organisation' : !canOnboard ? 'Upload one package at a time to use AI onboarding' : undefined}
                    onClick={() => {
                      if (!canOnboard) return;
                      const target = postCreatePackages[0];
                      setPostCreatePackages(null);
                      setOnboardingTarget(target);
                    }}
                    className={`w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-left transition-colors ${canOnboard ? 'hover:bg-[var(--bg-hover)]' : 'opacity-50 cursor-not-allowed'}`}
                    style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                  >
                    <Upload size={16} />
                    <span>
                      Upload Subcontract
                      <span className="block text-xs font-normal" style={{ color: 'var(--text-muted)' }}>AI reads the executed subcontract and pre-fills this package for you to review</span>
                    </span>
                  </button>
                );
              })()}
              {postCreatePackages.length === 1 ? (
                <Link
                  href={`/app/projects/${id}/subcontracts/${postCreatePackages[0].id}`}
                  onClick={() => setPostCreatePackages(null)}
                  className="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium transition-opacity hover:opacity-90"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  <ArrowRight size={16} />
                  Continue Manually
                </Link>
              ) : (
                <button
                  onClick={() => setPostCreatePackages(null)}
                  className="w-full flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-left transition-opacity hover:opacity-90"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  <ArrowRight size={16} />
                  Continue Manually
                </button>
              )}
            </div>
          </div>
        </div>
      )}
      {viewPackageId != null && (() => {
        const pkg = tradePackages.find(p => p.id === viewPackageId);
        return (
          <SubcontractFilesModal
            projectId={id!}
            packageId={viewPackageId}
            packageName={pkg?.name ?? `Package #${viewPackageId}`}
            onClose={() => setViewPackageId(null)}
          />
        );
      })()}
      {previewTarget && (
        <DocumentPreviewModal target={previewTarget} onClose={() => setPreviewTarget(null)} />
      )}
      {analyseContract && (
        <AiAnalysisModal
          contract={analyseContract}
          projectId={id!}
          onClose={() => { setAnalyseContract(null); refetchAnalyses(); }}
        />
      )}
      {viewAnalysis && (() => {
        const contractForView = data?.data?.find((c: ProjectContract) => c.id === viewAnalysis.contract_id);
        if (!contractForView) return null;
        return (
          <AiAnalysisModal
            contract={contractForView}
            projectId={id!}
            initialAnalysis={viewAnalysis}
            onClose={() => setViewAnalysis(null)}
          />
        );
      })()}
      {deleteContract && (
        <DeleteContractModal
          contract={deleteContract}
          onClose={() => setDeleteContract(null)}
        />
      )}
      {archiveContract && (
        <ArchiveContractModal
          contract={archiveContract}
          onClose={() => setArchiveContract(null)}
        />
      )}
    </div>
  );
}
