'use client';

/**
 * Contract-Assisted Project Setup — Phase D.
 *
 * Lives inside the normal per-Project shell (frontend/src/app/app/projects/
 * [id]/layout.tsx already provides the sidebar/auth/mobile-top-bar for every
 * route under this segment — nothing here duplicates that).
 *
 * This page is a pure integration surface. It reuses, unchanged:
 *   - GET /projects/{id}                  (project — same query key/cache as layout.tsx)
 *   - GET /projects/{id}/contracts         (existing Contracts list)
 *   - GET /ai/status                       (AI availability gate)
 *   - POST /projects/{id}/contracts        (Contract create+upload, one request)
 *   - POST /contracts/{contract}/ai-analysis (start/resume analysis)
 *   - GET /contracts/{contract}/ai-analysis  (latest analysis for a Contract)
 *   - POST /ai/analyses/{id}/cancel        (explicit cancel only — see below)
 *   - useAiAnalysisPolling (Phase C)       (shared 3s polling, enabled ONLY
 *     while the persisted analysis status is pending/processing — never for
 *     completed/confirmed/failed/cancelled)
 *   - ContractAnalysisReview (Phase C)     (the one authoritative Review &
 *     Confirm UI — POST /ai/analyses/{id}/confirm; never AiContractWizard's
 *     bespoke mapExtractedToForm()/PUT shortcut)
 *
 * All rendered state below (`no_contract` / `choose` / `upload` / `select` /
 * per-analysis-status panels) is derived fresh from Project + Contracts +
 * the focal Contract's latest analysis on every render — none of it is
 * persisted. There is no `setup_status`/`primary_contract_id`/dismissal
 * column anywhere; reloading this page, or returning to it in a fresh
 * session, reconstructs exactly the same state from these same backend
 * records. Leaving this page never cancels a running analysis — only the
 * explicit "Cancel Analysis" action calls the cancel endpoint.
 *
 * Phase E added the "Review Project Suggestions" step, mounted only after
 * `status === 'confirmed'` and only on explicit click (never automatically):
 * `ProjectSuggestionsPanel` (same directory) reads/applies a small
 * whitelisted set of Project-summary fields from the confirmed Contract via
 * two new endpoints (`GET`/`POST .../project-suggestions`,
 * `.../apply-project-suggestions` — see
 * `App\Services\ProjectContractSetupSyncService`). This page never computes
 * or trusts a suggested value itself — it only opens/closes that panel and
 * invalidates the `['project', id]` query afterward so the rest of this
 * page (and the whole app) sees the applied values. No Client/Employer
 * record is created or linked, and Project.organization_role is only ever
 * READ (to suggest a Contract Type default, or to be suggested itself by
 * the Phase E panel) — never written by anything in this file.
 */

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import {
  Sparkles, Upload, FileText, CheckCircle, AlertTriangle, ArrowRight,
  Loader2, XCircle, ArrowLeft,
} from 'lucide-react';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useAiAnalysisPolling } from '@/hooks/useAiAnalysisPolling';
import ContractAnalysisReview, { type ReviewableContract } from '@/components/ai/ContractAnalysisReview';
import ProjectSuggestionsPanel from './ProjectSuggestionsPanel';
import type { AiAnalysisRecord } from '@/store/aiAnalysisStore';

// Canonical backend Contract types — identical list already used by
// AiContractWizard/NewContractModal (contracts/page.tsx) and by the backend's
// own validation. Kept as its own small literal here rather than imported
// from that page file, matching the same documented duplication convention
// Phase B/C already established for this exact list.
const CONTRACT_TYPES = [
  { value: 'main_contract',          label: 'Main Contract' },
  { value: 'subcontract',            label: 'Subcontract' },
  { value: 'consultant_appointment', label: 'Consultant Appointment' },
  { value: 'supplier_agreement',     label: 'Supplier Agreement' },
];

// Phase A's Project Organization Role may only ever SUGGEST an initial
// Contract Type — never enforce it, never be written to by anything here.
// `employer`/`other` deliberately have no entry: an Employer's uploaded
// agreement could reasonably be a Main Contract, a Consultant Appointment,
// or something else entirely — no safe default exists, per Phase D's own
// locked mapping.
const ROLE_TO_SUGGESTED_CONTRACT_TYPE: Record<string, string> = {
  main_contractor: 'main_contract',
  subcontractor: 'subcontract',
  consultant: 'consultant_appointment',
};

type ContractSummary = {
  id: number;
  title: string;
  type?: string | null;
  reference_number?: string | null;
  status?: string | null;
  contract_sum?: number | string | null;
  commencement_date?: string | null;
  key_dates?: unknown[] | null;
  risks?: unknown[] | null;
};

type EntryMode = 'choice' | 'upload' | 'select';

const CARD_STYLE: React.CSSProperties = {
  backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)',
};
const INPUT_CLS = 'w-full rounded-lg px-3 py-2 text-sm outline-none border border-[var(--border)] focus:border-[var(--gold)] transition-colors duration-200';
const INPUT_STYLE: React.CSSProperties = { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' };
const LABEL_STYLE: React.CSSProperties = { color: 'var(--text-muted)', fontSize: '0.75rem', marginBottom: '4px', display: 'block' };

export default function ProjectSetupPage() {
  const params = useParams();
  const id = params.id as string;
  const router = useRouter();
  const qc = useQueryClient();

  const goToWorkspace = () => router.push(`/app/projects/${id}/overview`);
  const goToContracts = () => router.push(`/app/projects/${id}/contracts`);

  // ── Project / Contracts / AI availability — same query keys the rest of
  // the app already uses, so these are cache-shared with layout.tsx/the
  // Contracts page, not duplicate fetches. ──────────────────────────────────
  const { data: project, isLoading: projectLoading } = useQuery({
    queryKey: ['project', id],
    queryFn: () => api.get(`/projects/${id}`).then(r => r.data?.data ?? r.data),
    enabled: !!id,
    staleTime: 5 * 60 * 1000,
  });

  const { data: contractsData, isLoading: contractsLoading } = useQuery({
    queryKey: ['project-contracts', id, 'active'],
    queryFn: () => api.get(`/projects/${id}/contracts`, { params: { filter: 'active' } }).then(r => r.data),
    enabled: !!id,
  });
  const contracts: ContractSummary[] = contractsData?.data ?? [];

  const { data: aiStatusData } = useQuery({
    queryKey: ['ai-status'],
    queryFn: () => api.get('/ai/status').then(r => r.data),
    staleTime: 10 * 60 * 1000,
  });
  const aiEnabled = !!aiStatusData?.ai_enabled;

  // ── Entry state (never persisted) ───────────────────────────────────────
  const [mode, setMode] = useState<EntryMode | null>(null);
  const [focalContract, setFocalContract] = useState<ReviewableContract & ContractSummary | null>(null);
  const [reviewOpen, setReviewOpen] = useState(false);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);

  const loading = projectLoading || contractsLoading;
  const effectiveMode: EntryMode = mode ?? (contracts.length > 0 ? 'choice' : 'upload');

  // ── Focal Contract's latest analysis — the sole source of derived state.
  // Reloading this page, or returning in a fresh session, re-fetches this
  // exact same record; nothing about it lives only in memory. ─────────────
  const { data: analysis, refetch: refetchAnalysis } = useQuery<AiAnalysisRecord | null>({
    queryKey: ['contract-ai-analysis', focalContract?.id ?? null],
    queryFn: () => api.get(`/contracts/${focalContract!.id}/ai-analysis`).then(r => r.data?.data ?? null),
    enabled: !!focalContract,
  });

  // Locked clarification: polling is enabled ONLY while status is genuinely
  // pending/processing — never for completed/confirmed/failed/cancelled.
  // useAiAnalysisPolling itself is unchanged from Phase C.
  useAiAnalysisPolling(
    analysis?.id ?? null,
    analysis?.status === 'pending' || analysis?.status === 'processing',
    (a) => { qc.setQueryData(['contract-ai-analysis', focalContract?.id ?? null], a); },
    () => { refetchAnalysis(); },
  );

  // ── Upload form (Add another Contract / zero-Contract path) ────────────
  const [uploadTitle, setUploadTitle] = useState('');
  // '' means "no explicit choice yet" — the Select's displayed/submitted
  // value falls back to the Project Role suggestion below (uploadType ||
  // suggestedContractType), never written here directly, so there's no
  // setState-during-render/effect to manage and no risk of fighting a
  // choice the user already made.
  const [uploadType, setUploadType] = useState('');
  const [uploadFile, setUploadFile] = useState<File | null>(null);
  const [dragOver, setDragOver] = useState(false);

  // Project.organization_role may only ever SUGGEST an initial value —
  // never enforced, never written back anywhere. See
  // ROLE_TO_SUGGESTED_CONTRACT_TYPE's own docblock for why employer/other
  // have no entry.
  const suggestedContractType = project?.organization_role ? ROLE_TO_SUGGESTED_CONTRACT_TYPE[project.organization_role] : undefined;
  const effectiveUploadType = uploadType || suggestedContractType || '';

  function resetUploadForm() {
    setUploadTitle(''); setUploadFile(null); setUploadType('');
  }

  const createContractMutation = useMutation({
    mutationFn: async () => {
      const fd = new FormData();
      fd.append('title', uploadTitle.trim() || (uploadFile?.name.replace(/\.[^.]+$/, '') ?? 'Untitled Contract'));
      fd.append('type', effectiveUploadType);
      fd.append('status', 'draft');
      fd.append('contract_file', uploadFile as File);
      return api.post(`/projects/${id}/contracts`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data);
    },
    onSuccess: async (contract: ContractSummary) => {
      qc.invalidateQueries({ queryKey: ['project-contracts', id] });
      toast.success('Contract saved');
      setFocalContract(contract);
      setMode(null);

      // Deliberate Contract-assisted path — the user explicitly chose to
      // upload here, so start analysis immediately if AI is available.
      // Never force_new: this Contract is brand new, so no prior analysis
      // can exist. A failure here is non-fatal — the Contract is already
      // saved either way.
      if (aiEnabled) {
        try {
          const res = await api.post(`/contracts/${contract.id}/ai-analysis`);
          qc.setQueryData(['contract-ai-analysis', contract.id], res.data?.data ?? null);
        } catch (err) {
          toast.error(getErrorMessage(err, "Your contract has been saved, but analysis couldn't be started."));
        }
      }
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, "We couldn't save your contract. Your project has already been created.")),
  });

  const startAnalysisMutation = useMutation({
    mutationFn: (opts?: { forceNew?: boolean }) =>
      api.post(`/contracts/${focalContract!.id}/ai-analysis`, opts?.forceNew ? { force_new: true } : {}).then(r => r.data),
    onSuccess: (data: { existing_analysis?: AiAnalysisRecord; data?: AiAnalysisRecord }) => {
      const resolved = data.existing_analysis ?? data.data ?? null;
      qc.setQueryData(['contract-ai-analysis', focalContract?.id ?? null], resolved);
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to start AI analysis.')),
  });

  const cancelAnalysisMutation = useMutation({
    mutationFn: () => api.post(`/ai/analyses/${analysis!.id}/cancel`).then(r => r.data),
    onSuccess: (res: { message?: string }) => {
      toast.success(res?.message ?? 'Analysis cancelled.');
      refetchAnalysis();
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Could not cancel the analysis. It may already have finished.')),
  });

  function handleCancelAnalysis() {
    const proceed = window.confirm(
      'Cancel this analysis?\n\nIf it has already started running, the AI usage so far may still be charged.'
    );
    if (proceed) cancelAnalysisMutation.mutate();
  }

  function handleSelectExisting(contract: ContractSummary) {
    setFocalContract(contract);
    setMode(null);
  }

  // ── Loading ──────────────────────────────────────────────────────────────
  if (loading) {
    return (
      <div className="max-w-3xl mx-auto px-6 py-10 flex flex-col items-center justify-center gap-3" style={{ minHeight: '40vh' }}>
        <Loader2 size={24} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading project…</p>
      </div>
    );
  }

  return (
    <div className="max-w-3xl mx-auto px-6 py-8 space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>Set up your project</h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{project?.name}</p>
        </div>
        <button
          onClick={goToWorkspace}
          className="text-xs font-medium flex-shrink-0 px-3 py-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--text-muted)' }}
        >
          Skip for now →
        </button>
      </div>

      {!focalContract ? (
        <EntryPanel
          mode={effectiveMode}
          contracts={contracts}
          uploadTitle={uploadTitle} setUploadTitle={setUploadTitle}
          uploadType={effectiveUploadType} setUploadType={setUploadType}
          uploadFile={uploadFile} setUploadFile={setUploadFile}
          dragOver={dragOver} setDragOver={setDragOver}
          onChooseUpload={() => { resetUploadForm(); setMode('upload'); }}
          onChooseSelect={() => setMode('select')}
          onBackToChoice={() => setMode(null)}
          onSkip={goToWorkspace}
          onSubmitUpload={() => createContractMutation.mutate()}
          submitting={createContractMutation.isPending}
          onSelectExisting={handleSelectExisting}
          hasContracts={contracts.length > 0}
        />
      ) : (
        <ContractStatusPanel
          contract={focalContract}
          analysis={analysis ?? null}
          aiEnabled={aiEnabled}
          onStartAnalysis={() => startAnalysisMutation.mutate(undefined)}
          onRetryAnalysis={() => startAnalysisMutation.mutate({ forceNew: true })}
          startPending={startAnalysisMutation.isPending}
          onCancelAnalysis={handleCancelAnalysis}
          cancelPending={cancelAnalysisMutation.isPending}
          onReview={() => setReviewOpen(true)}
          onContinue={goToWorkspace}
          onViewContract={goToContracts}
          onReviewSuggestions={() => setSuggestionsOpen(true)}
        />
      )}

      {reviewOpen && focalContract && analysis && (
        <ContractAnalysisReview
          contract={focalContract}
          projectId={id}
          initialAnalysis={analysis}
          onClose={() => { setReviewOpen(false); refetchAnalysis(); }}
        />
      )}

      {suggestionsOpen && focalContract && analysis && (
        <ProjectSuggestionsPanel
          projectId={id}
          contractId={focalContract.id}
          analysisId={analysis.id}
          onClose={() => setSuggestionsOpen(false)}
          onApplied={() => qc.invalidateQueries({ queryKey: ['project', id] })}
        />
      )}
    </div>
  );
}

// ─── Entry panel: choice / upload / select ───────────────────────────────

function EntryPanel({
  mode, contracts, uploadTitle, setUploadTitle, uploadType, setUploadType,
  uploadFile, setUploadFile, dragOver, setDragOver,
  onChooseUpload, onChooseSelect, onBackToChoice, onSkip, onSubmitUpload, submitting,
  onSelectExisting, hasContracts,
}: {
  mode: EntryMode;
  contracts: ContractSummary[];
  uploadTitle: string; setUploadTitle: (v: string) => void;
  uploadType: string; setUploadType: (v: string) => void;
  uploadFile: File | null; setUploadFile: (f: File | null) => void;
  dragOver: boolean; setDragOver: (v: boolean) => void;
  onChooseUpload: () => void;
  onChooseSelect: () => void;
  onBackToChoice: () => void;
  onSkip: () => void;
  onSubmitUpload: () => void;
  submitting: boolean;
  onSelectExisting: (c: ContractSummary) => void;
  hasContracts: boolean;
}) {
  const allowedMimeExtensions = '.pdf,.doc,.docx,.txt';

  function handleFile(f: File | null) {
    if (!f) { setUploadFile(null); return; }
    const allowed = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'text/plain'];
    if (!allowed.includes(f.type)) {
      toast.error('Unsupported file type. Please upload a PDF, DOCX, or TXT file.');
      return;
    }
    setUploadFile(f);
  }

  if (mode === 'choice') {
    return (
      <div className="rounded-2xl p-6" style={CARD_STYLE}>
        <h2 className="text-sm font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>This project already has a Contract</h2>
        <p className="text-sm mb-5" style={{ color: 'var(--text-secondary)' }}>
          Analyse one of the existing Contracts, or add another one.
        </p>
        <div className="flex flex-col sm:flex-row gap-3">
          <Button variant="secondary" onClick={onChooseSelect} className="flex-1 justify-center">
            Analyze an existing Contract
          </Button>
          <Button variant="secondary" onClick={onChooseUpload} className="flex-1 justify-center">
            Add another Contract
          </Button>
        </div>
      </div>
    );
  }

  if (mode === 'select') {
    return (
      <div className="rounded-2xl p-6" style={CARD_STYLE}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Select a Contract to analyse</h2>
          <button onClick={onBackToChoice} className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
            <ArrowLeft size={12} /> Back
          </button>
        </div>
        <div className="space-y-2">
          {contracts.map(c => (
            <button
              key={c.id}
              onClick={() => onSelectExisting(c)}
              className="w-full flex items-center justify-between gap-3 rounded-xl px-4 py-3 text-left transition-colors hover:border-[var(--gold)]"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
            >
              <div className="min-w-0">
                <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{c.title}</p>
                <div className="flex flex-wrap gap-x-3 gap-y-0.5 mt-0.5">
                  {c.type && <span className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{c.type.replace(/_/g, ' ')}</span>}
                  {c.reference_number && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{c.reference_number}</span>}
                  {c.status && <span className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{c.status}</span>}
                </div>
              </div>
              <ArrowRight size={14} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
            </button>
          ))}
        </div>
      </div>
    );
  }

  // mode === 'upload'
  return (
    <div className="rounded-2xl p-6" style={CARD_STYLE}>
      {hasContracts && (
        <button onClick={onBackToChoice} className="text-xs flex items-center gap-1 mb-4" style={{ color: 'var(--text-muted)' }}>
          <ArrowLeft size={12} /> Back
        </button>
      )}
      <h2 className="text-sm font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>
        {hasContracts ? 'Add another Contract' : 'Set up your project from an agreement'}
      </h2>
      <p className="text-sm mb-5" style={{ color: 'var(--text-secondary)' }}>
        {hasContracts
          ? 'Upload the agreement and SureSign can analyse it to help structure the Contract record.'
          : 'If you already have the agreement governing your work on this project, upload it and SureSign can analyse it to help structure the Contract record.'}
      </p>

      <div className="space-y-4">
        <div>
          <label style={LABEL_STYLE}>Contract Title</label>
          <input
            className={INPUT_CLS} style={INPUT_STYLE} value={uploadTitle}
            onChange={e => setUploadTitle(e.target.value)}
            placeholder="Defaults to the file name if left blank"
          />
        </div>
        <div>
          <label style={LABEL_STYLE}>Contract Type *</label>
          <Select className="w-full" value={uploadType} onChange={e => setUploadType(e.target.value)}>
            <option value="">Select contract type…</option>
            {CONTRACT_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            This isn&rsquo;t guessed from the document — choose the one that matches what you&rsquo;re uploading.
          </p>
        </div>
        <div>
          <label style={LABEL_STYLE}>Contract File *</label>
          <input
            id="setup-upload-file"
            type="file"
            className="hidden"
            accept={allowedMimeExtensions}
            onChange={e => handleFile(e.target.files?.[0] ?? null)}
          />
          {uploadFile ? (
            <div
              className="flex items-center justify-between gap-3 px-4 py-3 rounded-xl"
              style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-30)' }}
            >
              <div className="flex items-center gap-2 min-w-0">
                <FileText size={16} style={{ color: 'var(--gold)', flexShrink: 0 }} />
                <span className="text-sm truncate" style={{ color: 'var(--text-primary)' }}>{uploadFile.name}</span>
              </div>
              <button onClick={() => setUploadFile(null)} className="text-xs flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
                Remove
              </button>
            </div>
          ) : (
            <label
              htmlFor="setup-upload-file"
              onDragOver={e => { e.preventDefault(); setDragOver(true); }}
              onDragLeave={() => setDragOver(false)}
              onDrop={e => { e.preventDefault(); setDragOver(false); handleFile(e.dataTransfer.files?.[0] ?? null); }}
              className="w-full flex flex-col items-center justify-center gap-3 py-10 rounded-xl border-dashed transition-colors cursor-pointer"
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
            </label>
          )}
        </div>
      </div>

      <div className="flex items-center justify-between mt-6">
        {!hasContracts && (
          <button onClick={onSkip} className="text-sm font-medium" style={{ color: 'var(--text-muted)' }}>
            I&rsquo;ll add this later
          </button>
        )}
        <Button
          onClick={onSubmitUpload}
          disabled={!uploadFile || !uploadType || submitting}
          className={hasContracts ? 'ml-auto' : ''}
        >
          {submitting ? 'Saving…' : 'Upload Agreement'}
        </Button>
      </div>
    </div>
  );
}

// ─── Focal Contract status panel ─────────────────────────────────────────

function ContractStatusPanel({
  contract, analysis, aiEnabled, onStartAnalysis, onRetryAnalysis, startPending,
  onCancelAnalysis, cancelPending, onReview, onContinue, onViewContract, onReviewSuggestions,
}: {
  contract: ContractSummary;
  analysis: AiAnalysisRecord | null;
  aiEnabled: boolean;
  onStartAnalysis: () => void;
  onRetryAnalysis: () => void;
  startPending: boolean;
  onCancelAnalysis: () => void;
  cancelPending: boolean;
  onReview: () => void;
  onContinue: () => void;
  onViewContract: () => void;
  onReviewSuggestions: () => void;
}) {
  const status = analysis?.status ?? null;

  return (
    <div className="rounded-2xl p-6 space-y-4" style={CARD_STYLE}>
      <div className="flex items-center gap-2">
        <FileText size={16} style={{ color: 'var(--gold)' }} />
        <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{contract.title}</p>
      </div>

      {/* No analysis yet */}
      {!status && (
        aiEnabled ? (
          <StatusBlock
            icon={<Sparkles size={20} style={{ color: 'var(--gold)' }} />}
            title="Ready to analyse"
            body="SureSign can read this Contract and extract key terms for you to review."
            actions={
              <>
                <Button onClick={onStartAnalysis} disabled={startPending}>
                  {startPending ? 'Starting…' : 'Analyze Contract'}
                </Button>
                <Button variant="ghost" onClick={onContinue}>Continue to Project</Button>
              </>
            }
          />
        ) : (
          <StatusBlock
            icon={<AlertTriangle size={20} style={{ color: '#eab308' }} />}
            title="AI analysis isn&rsquo;t available right now"
            body="Your contract has been saved. AI contract analysis isn&rsquo;t currently enabled for your organisation, but you can continue setting up the project manually."
            actions={<Button onClick={onContinue}>Continue to Project</Button>}
          />
        )
      )}

      {(status === 'pending' || status === 'processing') && (
        <StatusBlock
          icon={<Loader2 size={20} className="animate-spin" style={{ color: 'var(--gold)' }} />}
          title="SureSign is analysing your contract"
          body="Your Project and Contract are already saved. You can continue working while the analysis runs — SureSign will notify you when it&rsquo;s ready."
          actions={
            <>
              <Button onClick={onContinue}>Continue to Project</Button>
              <Button variant="ghost" onClick={onCancelAnalysis} disabled={cancelPending}>
                {cancelPending ? 'Cancelling…' : 'Cancel Analysis'}
              </Button>
            </>
          }
        />
      )}

      {status === 'completed' && (
        <StatusBlock
          icon={<CheckCircle size={20} style={{ color: '#4ade80' }} />}
          title="Contract analysis is ready to review"
          body="Review the extracted details before they&rsquo;re saved to the Contract."
          actions={<Button onClick={onReview}>Review Analysis</Button>}
        />
      )}

      {status === 'confirmed' && (
        <StatusBlock
          icon={<CheckCircle size={20} style={{ color: '#4ade80' }} />}
          title="Contract setup complete"
          body="This Contract&rsquo;s analysis has been confirmed and saved."
          actions={
            <>
              {/* Phase E — never opened automatically; the panel itself
                  derives whether there's anything to show (including "no
                  suggestions available" and "already matches"). */}
              <Button onClick={onReviewSuggestions}>Review Project Suggestions</Button>
              <Button variant="ghost" onClick={onContinue}>Continue to Project</Button>
              <Button variant="ghost" onClick={onViewContract}>View Contract</Button>
              <Button variant="ghost" onClick={onRetryAnalysis} disabled={startPending}>Reanalyze</Button>
            </>
          }
        />
      )}

      {status === 'failed' && (
        <StatusBlock
          icon={<AlertTriangle size={20} style={{ color: '#f87171' }} />}
          title="We couldn&rsquo;t complete the contract analysis"
          body={`${analysis?.error_message ?? 'An unexpected error occurred.'} Your Project and Contract are still available.`}
          actions={
            <>
              <Button onClick={onRetryAnalysis} disabled={startPending}>{startPending ? 'Starting…' : 'Retry Analysis'}</Button>
              <Button variant="ghost" onClick={onContinue}>Continue Manually</Button>
            </>
          }
        />
      )}

      {status === 'cancelled' && (
        <StatusBlock
          icon={<XCircle size={20} style={{ color: 'var(--text-muted)' }} />}
          title="Analysis cancelled"
          body="You cancelled this analysis. Your Project and Contract are still available."
          actions={
            <>
              <Button onClick={onRetryAnalysis} disabled={startPending}>{startPending ? 'Starting…' : 'Analyze Again'}</Button>
              <Button variant="ghost" onClick={onContinue}>Continue to Project</Button>
            </>
          }
        />
      )}
    </div>
  );
}

function StatusBlock({ icon, title, body, actions }: { icon: React.ReactNode; title: string; body: string; actions: React.ReactNode }) {
  return (
    <div className="flex flex-col items-center text-center gap-3 py-6">
      {icon}
      <div>
        <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</p>
        <p className="text-xs mt-1 max-w-sm mx-auto" style={{ color: 'var(--text-muted)' }}>{body}</p>
      </div>
      <div className="flex flex-wrap items-center justify-center gap-2 mt-1">{actions}</div>
    </div>
  );
}
