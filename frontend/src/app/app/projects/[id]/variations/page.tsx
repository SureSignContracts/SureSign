'use client';

import { useState, Fragment } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import CountUp from '@/components/ui/CountUp';
import {
  FileDown, GitBranch, Plus, Search, X, ChevronRight,
  AlertTriangle, Info, Clock, CheckCircle, XCircle, Send, Wrench,
  FileText, Eye, RotateCcw,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PromptActionButton from '@/components/prompts/PromptActionButton';

// ─── Status Config ────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:       { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  pending:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },   // legacy
  submitted:   { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  instructed:  { bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  quoted:      { bg: 'rgba(168,85,247,0.12)', text: '#c084fc' },
  assessed:    { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  approved:    { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  rejected:    { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  on_hold:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const STATUS_LABELS: Record<string, string> = {
  draft:      'Draft',
  pending:    'Draft',          // display legacy as Draft
  submitted:  'Submitted',
  instructed: 'Instructed',
  quoted:     'Quoted',
  assessed:   'Assessed',
  approved:   'Approved',
  rejected:   'Rejected',
  on_hold:    'On Hold',
};

// States that require action-endpoint transitions (not free-form update)
const WORKFLOW_STATUSES = ['submitted','instructed','quoted','assessed','approved','rejected'];

// Statuses shown in the filter bar
const FILTER_STATUSES = ['draft','submitted','instructed','quoted','assessed','approved','rejected'] as const;

const VARIATION_TYPES = [
  { value: 'architect_instruction',  label: "Architect's Instruction" },
  { value: 'engineers_instruction',  label: "Engineer's Instruction" },
  { value: 'client_request',         label: "Client Request" },
  { value: 'site_instruction',       label: "Site Instruction" },
  { value: 'other',                  label: "Other" },
];

const VALUATION_METHODS = [
  { value: 'schedule_rates',  label: 'Commercial Schedule Rates' },
  { value: 'fair_reasonable', label: 'Fair & Reasonable' },
  { value: 'daywork',         label: 'Daywork' },
];

// ─── Types ────────────────────────────────────────────────────────────────────

type ContractOption = { id: number; title: string; reference_number?: string | null };

type VariationForm = {
  title: string;
  description: string;
  type: string;
  quoted_amount: string;
  agreed_amount: string;
  variation_date: string;
  programme_impact_days: string;
  contract_id: string;
  instruction_method: string;
  valuation_method: string;
  quotation_submitted_at: string;
  agreed_in_writing: boolean;
};

type WorkflowAction = {
  action: string;
  label: string;
  variation: any;
};

// ─── AI Contract Variation Procedure Panel ────────────────────────────────────

function VariationProcedurePanel({ contractId }: { contractId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['contract-ai-analysis', contractId],
    queryFn: () => api.get(`/contracts/${contractId}/ai-analysis`).then(r => r.data),
    enabled: !!contractId,
    staleTime: 5 * 60 * 1000,
  });

  const analysis = data?.data;
  if (!analysis || analysis.status !== 'confirmed') return null;

  const fields = analysis.confirmed_data_json?.extracted_fields ?? {};
  const procedure = fields.variation_procedure as string | undefined;
  if (!procedure) return null;

  const quotationWindow = (() => {
    const m = procedure.match(/(\d+)\s*working\s*day/i);
    return m ? `${m[1]} working days` : null;
  })();
  const isVerbal = /verbal/i.test(procedure);

  return (
    <div className="rounded-xl p-4 mt-4" style={{ backgroundColor: 'rgba(234,179,8,0.05)', border: '1px solid rgba(234,179,8,0.2)' }}>
      <div className="flex items-center gap-2 mb-3">
        <Info size={13} style={{ color: 'var(--gold)' }} />
        <p className="text-xs font-semibold" style={{ color: 'var(--gold)' }}>Contract Variation Procedure</p>
        {isLoading && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading…</span>}
      </div>
      <p className="text-xs leading-relaxed mb-3" style={{ color: 'var(--text-secondary)' }}>{procedure}</p>
      {(quotationWindow || isVerbal) && (
        <div className="flex flex-wrap gap-2">
          {quotationWindow && (
            <span className="text-xs px-2 py-1 rounded-lg" style={{ backgroundColor: 'rgba(234,179,8,0.1)', color: '#facc15' }}>
              Quotation window: {quotationWindow}
            </span>
          )}
          {isVerbal && (
            <span className="text-xs px-2 py-1 rounded-lg" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
              Verbal instructions — written confirmation required
            </span>
          )}
        </div>
      )}
    </div>
  );
}

// ─── New Variation Modal ──────────────────────────────────────────────────────

function NewVariationModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();

  const { data: contractsData } = useQuery<{ data?: ContractOption[] }>({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data),
  });
  const contracts = contractsData?.data ?? [];

  const [form, setForm] = useState<VariationForm>({
    contract_id: '', title: '', description: '', type: '',
    quoted_amount: '', agreed_amount: '',
    variation_date: new Date().toISOString().split('T')[0],
    programme_impact_days: '', instruction_method: 'written',
    valuation_method: '', quotation_submitted_at: '', agreed_in_writing: false,
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: VariationForm) =>
      api.post(`/contracts/${data.contract_id}/variations`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-variations', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('Variation created');
      onClose();
    },
    onError: () => toast.error('Failed to create variation'),
  });

  const set = (field: keyof VariationForm, value: string) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };
  const sectionStyle = { color: 'var(--text-muted)', borderTop: '1px solid var(--border)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-xl rounded-2xl shadow-xl max-h-[92vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Variation</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-5">

          <div className="space-y-3">
            <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Details</p>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Contract *</label>
              <select value={form.contract_id} onChange={e => set('contract_id', e.target.value)} required
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="">Select contract…</option>
                {contracts.map(c => (
                  <option key={c.id} value={c.id}>{c.title}{c.reference_number ? ` (${c.reference_number})` : ''}</option>
                ))}
              </select>
              {contracts.length === 0 && <p className="text-xs mt-1" style={{ color: '#f87171' }}>No contracts — add one first.</p>}
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Title *</label>
              <input value={form.title} onChange={e => set('title', e.target.value)} required
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Description</label>
              <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={2}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Type</label>
                <select value={form.type} onChange={e => set('type', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  <option value="">Select type…</option>
                  {VARIATION_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Variation Date</label>
                <input type="date" value={form.variation_date} onChange={e => set('variation_date', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
          </div>

          {form.contract_id && <VariationProcedurePanel contractId={form.contract_id} />}

          <div className="space-y-3 pt-4" style={sectionStyle}>
            <p className="text-xs font-semibold uppercase tracking-wide pt-3" style={{ color: 'var(--text-muted)' }}>Commercial</p>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Quoted Amount (£)</label>
                <input type="number" value={form.quoted_amount} onChange={e => set('quoted_amount', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Instruction Method</label>
                <select value={form.instruction_method} onChange={e => set('instruction_method', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  <option value="written">Written</option>
                  <option value="verbal_emergency">Verbal (Emergency)</option>
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Valuation Method</label>
                <select value={form.valuation_method} onChange={e => set('valuation_method', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  <option value="">Auto (from contract)</option>
                  {VALUATION_METHODS.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Programme Impact (days)</label>
                <input type="number" value={form.programme_impact_days} onChange={e => set('programme_impact_days', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
          </div>

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending || !form.contract_id}
              className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: (isPending || !form.contract_id) ? 0.7 : 1 }}>
              {isPending ? 'Creating…' : 'Create Variation'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Edit Modal (data fields only — status via action buttons) ────────────────

function EditVariationModal({ variation, projectId, onClose }: { variation: any; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();

  const [form, setForm] = useState<VariationForm>({
    contract_id:            String(variation.contract_id ?? ''),
    title:                  variation.title ?? '',
    description:            variation.description ?? '',
    type:                   variation.type ?? '',
    quoted_amount:          String(variation.quoted_amount ?? ''),
    agreed_amount:          String(variation.agreed_amount ?? ''),
    variation_date:         variation.variation_date ? String(variation.variation_date).slice(0, 10) : '',
    programme_impact_days:  String(variation.programme_impact_days ?? ''),
    instruction_method:     variation.instruction_method ?? 'written',
    valuation_method:       variation.valuation_method ?? '',
    quotation_submitted_at: variation.quotation_submitted_at ? String(variation.quotation_submitted_at).slice(0, 10) : '',
    agreed_in_writing:      variation.agreed_in_writing ?? false,
  });

  const isWorkflowControlled = WORKFLOW_STATUSES.includes(variation.status);

  const { mutate, isPending } = useMutation({
    mutationFn: (data: VariationForm) =>
      api.put(`/variations/${variation.id}`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-variations', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Variation updated');
      onClose();
    },
    onError: () => toast.error('Failed to update variation'),
  });

  const set = (field: keyof VariationForm, value: string) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };
  const sectionStyle = { color: 'var(--text-muted)', borderTop: '1px solid var(--border)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-xl rounded-2xl shadow-xl max-h-[92vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              Edit Variation #{variation.variation_number}
            </h2>
            {isWorkflowControlled && (
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Status is managed via workflow actions — use action buttons on the variation list.
              </p>
            )}
          </div>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-5">

          <div className="space-y-3">
            <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Details</p>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Title *</label>
              <input value={form.title} onChange={e => set('title', e.target.value)} required
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Description</label>
              <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={2}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Type</label>
                <select value={form.type} onChange={e => set('type', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  <option value="">Select type…</option>
                  {VARIATION_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Variation Date</label>
                <input type="date" value={form.variation_date} onChange={e => set('variation_date', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Instruction Method</label>
                <select value={form.instruction_method} onChange={e => set('instruction_method', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  <option value="written">Written</option>
                  <option value="verbal_emergency">Verbal (Emergency)</option>
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Programme Impact (days)</label>
                <input type="number" value={form.programme_impact_days} onChange={e => set('programme_impact_days', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
          </div>

          {variation.contract_id && <VariationProcedurePanel contractId={String(variation.contract_id)} />}

          <div className="space-y-3 pt-4" style={sectionStyle}>
            <p className="text-xs font-semibold uppercase tracking-wide pt-3" style={{ color: 'var(--text-muted)' }}>Commercial</p>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Quoted Amount (£)</label>
                <input type="number" value={form.quoted_amount} onChange={e => set('quoted_amount', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Agreed Amount (£)</label>
                <input type="number" value={form.agreed_amount} onChange={e => set('agreed_amount', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Valuation Method</label>
                <select value={form.valuation_method} onChange={e => set('valuation_method', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  <option value="">Not set</option>
                  {VALUATION_METHODS.map(m => <option key={m.value} value={m.value}>{m.label}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Quotation Submitted</label>
                <input type="date" value={form.quotation_submitted_at} onChange={e => set('quotation_submitted_at', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
            <label className="flex items-center gap-2 cursor-pointer">
              <input type="checkbox" checked={form.agreed_in_writing}
                onChange={e => setForm(p => ({ ...p, agreed_in_writing: e.target.checked }))} />
              <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>Agreed in writing</span>
            </label>
          </div>

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Workflow Action Modal ────────────────────────────────────────────────────

function WorkflowActionModal({
  action,
  projectId,
  onClose,
}: {
  action: WorkflowAction;
  projectId: string;
  onClose: () => void;
}) {
  const variation = action.variation;
  const queryClient = useQueryClient();
  const [notes, setNotes] = useState('');
  const [quotedAmount, setQuotedAmount] = useState(String(variation.quoted_amount ?? ''));
  const [agreedAmount, setAgreedAmount] = useState(String(variation.agreed_amount ?? ''));
  const [rejectionReason, setRejectionReason] = useState('');

  const { mutate, isPending } = useMutation({
    mutationFn: (payload: Record<string, any>) =>
      api.post(`/variations/${action.variation.id}/${action.action}`, payload).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-variations', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      queryClient.invalidateQueries({ queryKey: ['dashboard-intelligence', projectId] });
      toast.success(`Variation ${action.label.toLowerCase()}`);
      onClose();
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.message ?? `Failed to ${action.label.toLowerCase()} variation`);
    },
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    const payload: Record<string, any> = {};
    if (action.action === 'quote')   { payload.quoted_amount = parseFloat(quotedAmount) || 0; }
    if (action.action === 'assess')  { payload.agreed_amount = agreedAmount ? parseFloat(agreedAmount) : undefined; payload.assessment_notes = notes || undefined; }
    if (action.action === 'approve') { payload.agreed_amount = agreedAmount ? parseFloat(agreedAmount) : undefined; payload.approval_notes = notes || undefined; }
    if (action.action === 'reject')  { payload.rejection_reason = rejectionReason; }
    if (action.action === 'instruct'){ payload.instruction_notes = notes || undefined; }
    mutate(payload);
  };

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };

  const isApproveOrAssess = action.action === 'approve' || action.action === 'assess';
  const isReject = action.action === 'reject';
  const isQuote = action.action === 'quote';

  const actionColors: Record<string, string> = {
    submit:    'var(--gold)',
    instruct:  '#60a5fa',
    quote:     '#c084fc',
    assess:    '#facc15',
    approve:   '#4ade80',
    reject:    '#f87171',
    resubmit:  '#fb923c',
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}>
      <div className="w-full max-w-md rounded-2xl shadow-xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{action.label} Variation</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              #{variation.variation_number} — {variation.title}
            </p>
          </div>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={handleSubmit} className="p-5 space-y-4">

          {isQuote && (
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Quoted Amount (£) *</label>
              <input type="number" value={quotedAmount} onChange={e => setQuotedAmount(e.target.value)}
                required min="0" step="0.01"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          )}

          {isApproveOrAssess && (
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>
                {action.action === 'approve' ? 'Agreed Amount (£)' : 'Counter-Assessed Amount (£)'}
              </label>
              <input type="number" value={agreedAmount} onChange={e => setAgreedAmount(e.target.value)}
                min="0" step="0.01"
                placeholder={variation.quoted_amount ? `Quoted: £${parseFloat(variation.quoted_amount).toLocaleString()}` : 'Enter amount…'}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          )}

          {(isApproveOrAssess || action.action === 'instruct') && (
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Notes (optional)</label>
              <textarea value={notes} onChange={e => setNotes(e.target.value)} rows={3}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle}
                placeholder={action.action === 'approve' ? 'Approval notes…' : action.action === 'assess' ? 'Assessment comments…' : 'Instruction notes…'} />
            </div>
          )}

          {isReject && (
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Rejection Reason *</label>
              <textarea value={rejectionReason} onChange={e => setRejectionReason(e.target.value)}
                required rows={3}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle}
                placeholder="State the reason for rejection…" />
            </div>
          )}

          {/* Simple transitions with no extra fields */}
          {!isQuote && !isApproveOrAssess && !isReject && action.action !== 'instruct' && (
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              Confirm you want to <strong style={{ color: actionColors[action.action] ?? 'var(--text-primary)' }}>{action.label.toLowerCase()}</strong> this variation.
            </p>
          )}

          <div className="flex justify-end gap-3 pt-1">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: actionColors[action.action] ?? 'var(--gold)', color: '#000', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Processing…' : action.label}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── PDF Button ───────────────────────────────────────────────────────────────

function GeneratePdfButton({ variationId }: { variationId: number }) {
  const { mutate, isPending } = useMutation({
    mutationFn: () => api.post(`/variations/${variationId}/generate-pdf`).then(r => r.data),
    onSuccess: () => toast.success('PDF generated — check Documents'),
    onError: () => toast.error('Failed to generate PDF'),
  });
  return (
    <button onClick={e => { e.stopPropagation(); mutate(); }} disabled={isPending}
      className="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-50"
      style={{ color: 'var(--text-gold)' }}>
      <FileDown size={13} />
      {isPending ? '…' : 'PDF'}
    </button>
  );
}

// ─── Workflow Action Buttons ──────────────────────────────────────────────────

function VariationActionButton({
  label, icon: Icon, color, onClick,
}: {
  label: string;
  icon: any;
  color: string;
  onClick: () => void;
}) {
  return (
    <button
      onClick={e => { e.stopPropagation(); onClick(); }}
      className="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:opacity-80"
      style={{ backgroundColor: `${color}20`, color, border: `1px solid ${color}40` }}>
      <Icon size={11} />
      {label}
    </button>
  );
}

function getWorkflowActions(
  status: string,
  canWrite: boolean,
  onAction: (action: string, label: string) => void
): React.ReactNode {
  if (!canWrite) return null;

  if (status === 'draft' || status === 'pending') {
    return <VariationActionButton label="Submit" icon={Send} color="#fb923c" onClick={() => onAction('submit', 'Submit')} />;
  }
  if (status === 'submitted') {
    return <VariationActionButton label="Instruct" icon={Wrench} color="#60a5fa" onClick={() => onAction('instruct', 'Instruct')} />;
  }
  if (status === 'instructed') {
    return <VariationActionButton label="Quote Received" icon={FileText} color="#c084fc" onClick={() => onAction('quote', 'Quote Received')} />;
  }
  if (status === 'quoted') {
    return <VariationActionButton label="Assess" icon={Eye} color="#facc15" onClick={() => onAction('assess', 'Assess')} />;
  }
  if (status === 'assessed') {
    return (
      <>
        <VariationActionButton label="Approve" icon={CheckCircle} color="#4ade80" onClick={() => onAction('approve', 'Approve')} />
        <VariationActionButton label="Reject" icon={XCircle} color="#f87171" onClick={() => onAction('reject', 'Reject')} />
      </>
    );
  }
  if (status === 'rejected') {
    return <VariationActionButton label="Resubmit" icon={RotateCcw} color="#fb923c" onClick={() => onAction('resubmit', 'Resubmit')} />;
  }
  return null;
}

// ─── Expandable Row Detail ────────────────────────────────────────────────────

function VariationDetailRow({ v, colSpan }: { v: any; colSpan: number }) {
  const formatCurrency = useCurrencyFormatter();

  return (
    <tr style={{ backgroundColor: 'rgba(0,0,0,0.12)' }}>
      <td colSpan={colSpan} className="px-5 py-4">
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
          {v.description && (
            <div className="col-span-full mb-1">
              <span style={{ color: 'var(--text-muted)' }}>Description: </span>{v.description}
            </div>
          )}
          <div><span style={{ color: 'var(--text-muted)' }}>Instruction Method: </span>
            {v.instruction_method === 'verbal_emergency' ? 'Verbal (Emergency)' : 'Written'}
          </div>
          <div><span style={{ color: 'var(--text-muted)' }}>Instruction Date: </span>
            {v.variation_date ? formatDate(v.variation_date) : '—'}
          </div>
          {v.written_confirmation_due && (
            <div><span style={{ color: 'var(--text-muted)' }}>Written Confirmation Due: </span>
              <span style={{ color: new Date(v.written_confirmation_due) < new Date() ? '#f87171' : 'inherit' }}>
                {formatDate(v.written_confirmation_due)}
              </span>
            </div>
          )}
          <div><span style={{ color: 'var(--text-muted)' }}>Quotation Due: </span>
            {v.quotation_due_date
              ? <span style={{ color: !v.quotation_submitted_at && new Date(v.quotation_due_date) < new Date() ? '#f87171' : 'inherit' }}>
                  {formatDate(v.quotation_due_date)}
                </span>
              : '—'}
          </div>
          <div><span style={{ color: 'var(--text-muted)' }}>Quotation Submitted: </span>
            {v.quotation_submitted_at ? formatDate(v.quotation_submitted_at) : '—'}
          </div>
          <div><span style={{ color: 'var(--text-muted)' }}>Valuation Method: </span>
            {VALUATION_METHODS.find(m => m.value === v.valuation_method)?.label ?? v.valuation_method ?? '—'}
          </div>
          {v.programme_impact_days > 0 && (
            <div><span style={{ color: 'var(--text-muted)' }}>Programme Impact: </span>
              <span style={{ color: '#fb923c' }}>+{v.programme_impact_days} days</span>
            </div>
          )}
          {v.contract && (
            <div><span style={{ color: 'var(--text-muted)' }}>Contract: </span>{v.contract.title}</div>
          )}

          {/* Audit trail */}
          {v.submitted_at && (
            <div><span style={{ color: 'var(--text-muted)' }}>Submitted: </span>
              {formatDate(v.submitted_at)}{v.submitted_by?.name ? ` by ${v.submitted_by.name}` : ''}
            </div>
          )}
          {v.instructed_at && (
            <div><span style={{ color: 'var(--text-muted)' }}>Instructed: </span>
              {formatDate(v.instructed_at)}{v.instructed_by?.name ? ` by ${v.instructed_by.name}` : ''}
            </div>
          )}
          {v.assessed_at && (
            <div><span style={{ color: 'var(--text-muted)' }}>Assessed: </span>
              {formatDate(v.assessed_at)}{v.assessed_by?.name ? ` by ${v.assessed_by.name}` : ''}
            </div>
          )}
          {v.approved_at && (
            <div><span style={{ color: '#4ade80' }}>Approved: </span>
              <span style={{ color: '#4ade80' }}>{formatDate(v.approved_at)}{v.approved_by?.name ? ` by ${v.approved_by.name}` : ''}</span>
            </div>
          )}
          {v.rejected_at && (
            <div><span style={{ color: '#f87171' }}>Rejected: </span>
              <span style={{ color: '#f87171' }}>{formatDate(v.rejected_at)}{v.rejected_by?.name ? ` by ${v.rejected_by.name}` : ''}</span>
            </div>
          )}
          {v.rejection_reason && (
            <div className="col-span-full">
              <span style={{ color: 'var(--text-muted)' }}>Rejection Reason: </span>
              <span style={{ color: '#f87171' }}>{v.rejection_reason}</span>
            </div>
          )}
          {v.approval_notes && (
            <div className="col-span-full">
              <span style={{ color: 'var(--text-muted)' }}>Approval Notes: </span>{v.approval_notes}
            </div>
          )}
          {v.assessment_notes && (
            <div className="col-span-full">
              <span style={{ color: 'var(--text-muted)' }}>Assessment Notes: </span>{v.assessment_notes}
            </div>
          )}

          {v.agreed_amount && (
            <div><span style={{ color: 'var(--text-muted)' }}>Agreed Amount: </span>
              <span style={{ color: '#4ade80' }}>{formatCurrency(v.agreed_amount)}</span>
            </div>
          )}
          {v.status === 'approved' && !v.agreed_in_writing && (
            <div className="col-span-full mt-1">
              <span className="inline-flex items-center gap-1 text-xs px-2 py-1 rounded-lg"
                style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
                <AlertTriangle size={10} /> Approved but not agreed in writing
              </span>
            </div>
          )}
        </div>
      </td>
    </tr>
  );
}

// ─── Commercial Summary ───────────────────────────────────────────────────────

function CommercialSummary({
  allVariations,
  formatCurrency,
}: {
  allVariations: any[];
  formatCurrency: (v: number) => string;
}) {
  const approvedTotal = allVariations
    .filter((v: any) => v.status === 'approved')
    .reduce((s: number, v: any) => s + parseFloat(v.agreed_amount ?? v.quoted_amount ?? 0), 0);

  const inProgressTotal = allVariations
    .filter((v: any) => ['submitted','instructed','quoted','assessed'].includes(v.status))
    .reduce((s: number, v: any) => s + parseFloat(v.quoted_amount ?? 0), 0);

  // Pipeline counts
  const pipelineStatuses = ['draft','submitted','instructed','quoted','assessed','approved','rejected'] as const;

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-2">
        {pipelineStatuses.map((s, i) => {
          // Merge 'draft' and 'pending' counts
          const count = s === 'draft'
            ? allVariations.filter((v: any) => v.status === 'draft' || v.status === 'pending').length
            : allVariations.filter((v: any) => v.status === s).length;
          const cfg = STATUS_COLORS[s] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div key={s} className="ss-animate-in rounded-xl p-3 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${i * 50}ms` }}>
              <p className="text-lg font-bold" style={{ color: cfg.text }}><CountUp value={count} delay={i * 50} /></p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{STATUS_LABELS[s]}</p>
            </div>
          );
        })}
        <div className="ss-animate-in rounded-xl p-3 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: '350ms' }}>
          <p className="text-lg font-bold" style={{ color: 'var(--gold)' }}><CountUp value={allVariations.length} delay={350} /></p>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Total</p>
        </div>
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: '400ms' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Approved Value</p>
          <p className="text-xl font-bold mt-1" style={{ color: '#4ade80' }}>{formatCurrency(approvedTotal)}</p>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Agreed &amp; commercially approved</p>
        </div>
        {(() => {
          const notIncluded = allVariations.filter(
            (v: any) => v.status === 'approved' && (v.pa_inclusion_count ?? 0) === 0
          );
          const notIncludedValue = notIncluded.reduce(
            (s: number, v: any) => s + parseFloat(v.agreed_amount ?? 0), 0
          );
          const hasOutstanding = notIncluded.length > 0;
          return (
            <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: hasOutstanding ? 'rgba(234,179,8,0.06)' : 'var(--bg-surface)', border: hasOutstanding ? '1px solid rgba(234,179,8,0.25)' : '1px solid var(--border)', animationDelay: '450ms' }}>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Approved, Not Yet in PA</p>
              <p className="text-xl font-bold mt-1" style={{ color: hasOutstanding ? '#facc15' : '#4ade80' }}>
                <CountUp value={notIncluded.length} delay={450} />
              </p>
              {hasOutstanding ? (
                <p className="text-xs mt-0.5" style={{ color: '#facc15' }}>
                  {formatCurrency(notIncludedValue)} not yet claimed
                </p>
              ) : (
                <p className="text-xs mt-0.5" style={{ color: '#4ade80' }}>All included in applications</p>
              )}
            </div>
          );
        })()}
        <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: '500ms' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>In Progress Value</p>
          <p className="text-xl font-bold mt-1" style={{ color: '#facc15' }}>{formatCurrency(inProgressTotal)}</p>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Submitted through to assessed</p>
        </div>
      </div>
    </div>
  );
}

// ─── Programme Impact Banner ──────────────────────────────────────────────────

function ProgrammeImpactBanner({ variations }: { variations: any[] }) {
  const withImpact = variations.filter((v: any) => parseInt(v.programme_impact_days ?? 0) > 0);
  const totalDays  = withImpact.reduce((s: number, v: any) => s + parseInt(v.programme_impact_days ?? 0), 0);
  const eotCount   = withImpact.filter((v: any) => v.status !== 'rejected').length;

  if (withImpact.length === 0) return null;

  return (
    <div className="rounded-xl p-4 flex flex-wrap items-center gap-4"
      style={{ backgroundColor: 'rgba(249,115,22,0.07)', border: '1px solid rgba(249,115,22,0.2)' }}>
      <div className="flex items-center gap-2">
        <Clock size={14} style={{ color: '#fb923c' }} />
        <span className="text-sm font-semibold" style={{ color: '#fb923c' }}>Programme Impact</span>
      </div>
      <div className="flex flex-wrap gap-4 text-sm">
        <span style={{ color: 'var(--text-secondary)' }}>
          <span className="font-bold" style={{ color: '#fb923c' }}>{totalDays}d</span> total impact
        </span>
        <span style={{ color: 'var(--text-secondary)' }}>
          <span className="font-bold">{withImpact.length}</span> variation{withImpact.length !== 1 ? 's' : ''} with impact
        </span>
        {eotCount > 0 && (
          <span className="flex items-center gap-1" style={{ color: '#facc15' }}>
            <AlertTriangle size={12} />
            {eotCount} may require EOT review
          </span>
        )}
      </div>
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function ProjectVariationsPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [editVariation, setEditVariation] = useState<any | null>(null);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [workflowAction, setWorkflowAction] = useState<WorkflowAction | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['project-variations', id],
    queryFn: () => api.get(`/projects/${id}/variations`).then(r => r.data),
  });

  const allVariations: any[] = data?.data ?? [];

  const filtered = allVariations.filter((v: any) => {
    const matchSearch = v.title?.toLowerCase().includes(search.toLowerCase()) || String(v.variation_number).includes(search);
    const matchStatus = statusFilter === 'all'
      || v.status === statusFilter
      || (statusFilter === 'draft' && v.status === 'pending'); // legacy alias
    return matchSearch && matchStatus;
  });

  const today = new Date().toISOString().split('T')[0];
  const TABLE_COLS = 10;

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Variations</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Contract variations and change orders</p>
        </div>
        {canWrite && (
          <button onClick={() => setShowModal(true)}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            <Plus size={15} />
            New Variation
          </button>
        )}
      </div>

      {!isLoading && <CommercialSummary allVariations={allVariations} formatCurrency={formatCurrency} />}
      {!isLoading && <ProgrammeImpactBanner variations={allVariations} />}

      {/* Filters */}
      <div className="flex gap-3 flex-wrap">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search variations…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }} />
        </div>
        <div className="flex flex-wrap gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <button onClick={() => setStatusFilter('all')}
            className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
            style={statusFilter === 'all' ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
            All
          </button>
          {FILTER_STATUSES.map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
              style={statusFilter === s ? { backgroundColor: STATUS_COLORS[s].bg, color: STATUS_COLORS[s].text, border: `1px solid ${STATUS_COLORS[s].text}40` } : { color: 'var(--text-secondary)' }}>
              {STATUS_LABELS[s]}
            </button>
          ))}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full min-w-[860px] text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              <th className="w-6 px-2 py-3" />
              {['Var #', 'Title', 'Type', 'Status', 'Quoted', 'Agreed', 'Quotation Due', 'Programme', 'Actions'].map(h => (
                <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(TABLE_COLS)].map((_, j) => (
                    <td key={j} className="px-4 py-4">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : filtered.length === 0 ? (
              <tr>
                <td colSpan={TABLE_COLS} className="px-5 py-12 text-center">
                  <GitBranch size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                    {allVariations.length === 0 ? 'No variations yet' : 'No variations match this filter.'}
                  </p>
                  {canWrite && allVariations.length === 0 && (
                    <button onClick={() => setShowModal(true)}
                      className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium"
                      style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                      Create First Variation
                    </button>
                  )}
                </td>
              </tr>
            ) : filtered.map((v: any) => {
              const badge = STATUS_COLORS[v.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
              const isExpanded = expandedId === v.id;
              const quotationOverdue = v.quotation_due_date && !v.quotation_submitted_at && v.quotation_due_date < today;

              return (
                <Fragment key={v.id}>
                  <tr
                    className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer"
                    style={{ borderBottom: isExpanded ? 'none' : '1px solid var(--border)' }}
                    onClick={() => setExpandedId(isExpanded ? null : v.id)}>
                    <td className="px-2 py-3">
                      <ChevronRight size={13} style={{ color: 'var(--text-muted)', transform: isExpanded ? 'rotate(90deg)' : 'none', transition: '0.15s' }} />
                    </td>
                    <td className="px-4 py-3 font-mono font-semibold" style={{ color: 'var(--gold)' }}>
                      #{v.variation_number}
                    </td>
                    <td className="px-4 py-3 font-medium max-w-[160px]" style={{ color: 'var(--text-primary)' }}>
                      <span className="block truncate">{v.title}</span>
                      {v.status === 'approved' && !v.agreed_in_writing && (
                        <span className="flex items-center gap-1 text-xs" style={{ color: '#f87171' }}>
                          <AlertTriangle size={9} />Not in writing
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      {VARIATION_TYPES.find(t => t.value === v.type)?.label ?? v.type?.replace(/_/g, ' ') ?? '—'}
                    </td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-0.5 rounded-full text-xs font-medium"
                        style={{ backgroundColor: badge.bg, color: badge.text }}>
                        {STATUS_LABELS[v.status] ?? v.status}
                      </span>
                    </td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      {v.quoted_amount ? formatCurrency(v.quoted_amount) : '—'}
                    </td>
                    <td className="px-4 py-3 text-xs font-medium" style={{ color: v.agreed_amount ? '#4ade80' : 'var(--text-muted)' }}>
                      {v.agreed_amount ? formatCurrency(v.agreed_amount) : '—'}
                    </td>
                    <td className="px-4 py-3 text-xs">
                      <span style={{ color: quotationOverdue ? '#f87171' : 'var(--text-muted)' }}>
                        {v.quotation_due_date ? formatDate(v.quotation_due_date) : '—'}
                      </span>
                      {quotationOverdue && (
                        <span className="flex items-center gap-1 mt-0.5 text-xs" style={{ color: '#f87171' }}>
                          <AlertTriangle size={9} />Overdue
                        </span>
                      )}
                      {v.quotation_submitted_at && (
                        <span className="block text-xs" style={{ color: '#4ade80' }}>Submitted</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-xs">
                      {parseInt(v.programme_impact_days ?? 0) > 0
                        ? <span style={{ color: '#fb923c' }}>+{v.programme_impact_days}d</span>
                        : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                    </td>
                    <td className="px-4 py-3" onClick={e => e.stopPropagation()}>
                      <div className="flex items-center flex-wrap gap-1">
                        {getWorkflowActions(v.status, canWrite, (action, label) =>
                          setWorkflowAction({ action, label, variation: v })
                        )}
                        {canWrite && (
                          <button onClick={() => setEditVariation(v)}
                            className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                            style={{ color: 'var(--text-muted)' }}>
                            Edit
                          </button>
                        )}
                        <GeneratePdfButton variationId={v.id} />
                        <PromptActionButton
                          label="Prompt"
                          module="Variations"
                          recordType="variation"
                          recordId={v.id}
                          projectId={id}
                        />
                      </div>
                    </td>
                  </tr>
                  {isExpanded && <VariationDetailRow v={v} colSpan={TABLE_COLS} />}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Foundation note */}
      <div className="rounded-xl px-4 py-3 flex items-start gap-3"
        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <Info size={13} style={{ color: 'var(--text-muted)', flexShrink: 0, marginTop: 2 }} />
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Variations marked as <span className="font-medium" style={{ color: '#4ade80' }}>Approved</span> and agreed in writing
          are available for inclusion in Payment Applications and will contribute to the Final Account adjusted contract sum.
        </p>
      </div>

      {canWrite && showModal && <NewVariationModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && editVariation && (
        <EditVariationModal variation={editVariation} projectId={id!} onClose={() => setEditVariation(null)} />
      )}
      {workflowAction && (
        <WorkflowActionModal
          action={workflowAction}
          projectId={id!}
          onClose={() => setWorkflowAction(null)}
        />
      )}
    </div>
  );
}
