'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState, useEffect } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd, parseDateOnly } from '@/lib/dateTime';
import { Scale, Plus, Search, X, ChevronRight, AlertCircle, Clock, CheckCircle2 } from 'lucide-react';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import { useSiteSettings } from '@/hooks/useSiteSettings';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';

// ─── Constants ───────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:                   { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  notice_of_dispute:       { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  notice_of_adjudication:  { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  adjudicator_appointment: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  referral_submission:     { bg: 'rgba(168,85,247,0.12)', text: '#c084fc' },
  response_analysis:       { bg: 'rgba(20,184,166,0.12)', text: '#2dd4bf' },
  further_submissions:     { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  decision_analysis:       { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  enforcement:             { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  closed:                  { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

const DISPUTE_TYPE_LABELS: Record<string, string> = {
  payment_dispute:        'Payment Dispute',
  variation_dispute:      'Variation Dispute',
  delay_dispute:          'Delay Dispute',
  defect_dispute:         'Defect Dispute',
  contract_interpretation:'Contract Interpretation',
  non_payment:            'Non-Payment',
  other:                  'Other',
};

const DISPUTE_TYPES = Object.keys(DISPUTE_TYPE_LABELS);
const STATUSES = ['draft','notice_of_dispute','notice_of_adjudication','adjudicator_appointment','referral_submission','response_analysis','further_submissions','decision_analysis','enforcement','closed'];
const STEP_LABELS: Record<string, string> = {
  notice_of_dispute:       'Notice of Dispute',
  notice_of_adjudication:  'Notice of Adjudication',
  adjudicator_appointment: 'Adjudicator Appointment',
  referral_submission:     'Referral Submission',
  response_analysis:       'Response Analysis',
  further_submissions:     'Further Submissions',
  decision_analysis:       'Decision Analysis',
  enforcement:             'Enforcement',
};

// ─── Create Case Modal ───────────────────────────────────────────────────────

function CreateCaseModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const { data: siteSettings } = useSiteSettings();

  const [form, setForm] = useState({
    title:                       '',
    dispute_type:                'payment_dispute',
    claimant_name:               '',
    respondent_name:             '',
    contract_id:                 '',
    payment_application_id:      '',
    variation_id:                '',
    claim_amount:                '',
    currency:                    'GBP',
    summary:                     '',
    notice_of_dispute_date:      '',
    notice_of_adjudication_date: '',
    referral_due_date:           '',
    response_due_date:           '',
    decision_due_date:           '',
  });

  useEffect(() => {
    if (siteSettings?.currency) {
      setForm(prev => ({ ...prev, currency: siteSettings.currency }));
    }
  }, [siteSettings?.currency]);

  const { data: contractsData } = useQuery({
    queryKey: ['project-contracts-list', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data).catch(() => ({ data: [] })),
  });
  const { data: payAppsData } = useQuery({
    queryKey: ['project-payapps-list', projectId],
    queryFn: () => api.get(`/projects/${projectId}/payment-applications`).then(r => r.data).catch(() => ({ data: [] })),
  });
  const { data: variationsData } = useQuery({
    queryKey: ['project-variations-list', projectId],
    queryFn: () => api.get(`/projects/${projectId}/variations`).then(r => r.data).catch(() => ({ data: [] })),
  });

  const contracts    = contractsData?.data ?? [];
  const payApps      = payAppsData?.data ?? [];
  const variations   = variationsData?.data ?? [];

  const mutation = useMutation({
    mutationFn: (data: Record<string, string>) => {
      const payload: Record<string, unknown> = { ...data };
      if (!payload.contract_id)             delete payload.contract_id;
      if (!payload.payment_application_id)  delete payload.payment_application_id;
      if (!payload.variation_id)            delete payload.variation_id;
      if (!payload.claim_amount)            delete payload.claim_amount;
      return api.post(`/projects/${projectId}/adjudication-cases`, payload).then(r => r.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-adjudication-cases', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      qc.invalidateQueries({ queryKey: ['project-stats', projectId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));
  // Narrowed to what the shared `Select` component's onChange provides —
  // see qa/page.tsx's identical helper for why.
  const setSelect = (k: keyof typeof form) =>
    (e: { target: { value: string } }) => setForm(f => ({ ...f, [k]: e.target.value }));

  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    border: '1px solid var(--border)',
    color: 'var(--text-primary)',
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-2xl rounded-2xl overflow-hidden ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-3">
            <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ backgroundColor: 'var(--gold-15)' }}>
              <Scale size={15} style={{ color: 'var(--gold)' }} />
            </div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Adjudication Case</h2>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <form
          onSubmit={e => { e.preventDefault(); mutation.mutate(form); }}
          className="p-6 space-y-4 max-h-[80vh] overflow-y-auto"
        >
          {/* Core details */}
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Case Title *</label>
            <input value={form.title} onChange={set('title')} required placeholder="Brief title for this dispute"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Dispute Type *</label>
              <Select value={form.dispute_type} onChange={setSelect('dispute_type')} className="w-full">
                {DISPUTE_TYPES.map(t => <option key={t} value={t}>{DISPUTE_TYPE_LABELS[t]}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Claim Amount</label>
              <div className="flex gap-2">
                <Select value={form.currency} onChange={setSelect('currency')} className="w-20">
                  <option value="GBP">GBP</option>
                  <option value="USD">USD</option>
                  <option value="EUR">EUR</option>
                  <option value="AUD">AUD</option>
                </Select>
                <input type="number" value={form.claim_amount} onChange={set('claim_amount')} placeholder="0.00" min="0" step="0.01"
                  className="flex-1 px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Claimant Name *</label>
              <input value={form.claimant_name} onChange={set('claimant_name')} required placeholder="Party bringing the claim"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Respondent Name *</label>
              <input value={form.respondent_name} onChange={set('respondent_name')} required placeholder="Responding party"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>

          {/* Related records */}
          <div className="pt-1 pb-1">
            <p className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>Link Related Records (optional)</p>
            <div className="grid grid-cols-3 gap-3">
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Contract</label>
                <Select value={form.contract_id} onChange={setSelect('contract_id')} className="w-full">
                  <option value="">None</option>
                  {contracts.map((c: any) => <option key={c.id} value={c.id}>{c.reference_number ?? c.title}</option>)}
                </Select>
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Payment Application</label>
                <Select value={form.payment_application_id} onChange={setSelect('payment_application_id')} className="w-full">
                  <option value="">None</option>
                  {payApps.map((p: any) => <option key={p.id} value={p.id}>App #{p.application_number}</option>)}
                </Select>
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Variation</label>
                <Select value={form.variation_id} onChange={setSelect('variation_id')} className="w-full">
                  <option value="">None</option>
                  {variations.map((v: any) => <option key={v.id} value={v.id}>Var #{v.variation_number} – {v.title}</option>)}
                </Select>
              </div>
            </div>
          </div>

          {/* Key dates */}
          <div className="pt-1 pb-1">
            <p className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>Key Dates (optional)</p>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Notice of Dispute</label>
                <input type="date" value={form.notice_of_dispute_date} onChange={set('notice_of_dispute_date')}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Notice of Adjudication</label>
                <input type="date" value={form.notice_of_adjudication_date} onChange={set('notice_of_adjudication_date')}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Referral Due</label>
                <input type="date" value={form.referral_due_date} onChange={set('referral_due_date')}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Response Due</label>
                <input type="date" value={form.response_due_date} onChange={set('response_due_date')}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Decision Due</label>
                <input type="date" value={form.decision_due_date} onChange={set('decision_due_date')}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
          </div>

          {/* Summary */}
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Summary</label>
            <textarea value={form.summary} onChange={set('summary')} rows={3} placeholder="Brief description of the dispute…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>

          {mutation.isError && (
            <p className="text-xs text-red-400">Failed to create case. Please check all fields and try again.</p>
          )}

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose}
              className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              Cancel
            </button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 disabled:opacity-60 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Creating…' : 'Create Case'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Deadline badge helper ────────────────────────────────────────────────────

// due_date is a DATE-only field — compared against "today" in the viewer's
// effective SureSign timezone, not the browser's raw current instant, or
// this could disagree with the backend's own overdue computation near a
// midnight boundary.
function nextDeadlineBadge(deadlines: any[]) {
  if (!deadlines?.length) return null;
  const now = parseDateOnly(effectiveTodayYmd());
  const upcoming = deadlines
    .filter((d: any) => d.status !== 'completed' && d.due_date)
    .sort((a: any, b: any) => new Date(a.due_date).getTime() - new Date(b.due_date).getTime());
  if (!upcoming.length) return null;
  const next = upcoming[0];
  const dueDate = parseDateOnly(next.due_date);
  const diffMs = dueDate.getTime() - now.getTime();
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));
  const isOverdue = diffDays < 0;
  const isDueSoon = diffDays >= 0 && diffDays <= 3;
  return { title: next.title, dueDate, diffDays, isOverdue, isDueSoon };
}

// ─── Page ────────────────────────────────────────────────────────────────────

function ProjectAdjudicationPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const { canManageAdjudication: canWrite } = useProjectPermissions();

  const [search, setSearch]             = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [typeFilter, setTypeFilter]     = useState('all');
  const [showCreate, setShowCreate]     = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['project-adjudication-cases', id],
    queryFn: () =>
      api.get(`/projects/${id}/adjudication-cases`).then(r => r.data).catch(() => ({ data: [] })),
  });

  const deleteMutation = useMutation({
    mutationFn: (caseId: number) => api.delete(`/projects/${id}/adjudication-cases/${caseId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-adjudication-cases', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
    },
  });

  const allCases: any[] = data?.data ?? [];

  const cases = allCases.filter((c: any) => {
    const matchSearch =
      !search ||
      c.title?.toLowerCase().includes(search.toLowerCase()) ||
      c.case_number?.toLowerCase().includes(search.toLowerCase()) ||
      c.claimant_name?.toLowerCase().includes(search.toLowerCase()) ||
      c.respondent_name?.toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || c.status === statusFilter;
    const matchType   = typeFilter   === 'all' || c.dispute_type === typeFilter;
    return matchSearch && matchStatus && matchType;
  });

  const totalCount  = allCases.length;
  const activeCount = allCases.filter((c: any) => c.status !== 'closed' && c.status !== 'draft').length;
  const closedCount = allCases.filter((c: any) => c.status === 'closed').length;
  const overdueCount = allCases.filter((c: any) => {
    const nd = nextDeadlineBadge(c.deadlines ?? []);
    return nd?.isOverdue;
  }).length;

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {showCreate && (
        <CreateCaseModal projectId={id} onClose={() => setShowCreate(false)} />
      )}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Adjudication</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Construction dispute and adjudication workflow management</p>
        </div>
        {canWrite && (
          <button
            onClick={() => setShowCreate(true)}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            <Plus size={15} />
            New Case
          </button>
        )}
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-4 gap-4">
        {[
          { label: 'Total Cases',   value: totalCount,   color: 'var(--gold)' },
          { label: 'Active',        value: activeCount,  color: '#60a5fa' },
          { label: 'Closed',        value: closedCount,  color: '#4ade80' },
          { label: 'Overdue',       value: overdueCount, color: '#f87171' },
        ].map(({ label, value, color }, i) => (
          <div key={label} className="rounded-xl p-4 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color }}>{value}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="flex gap-3 flex-wrap">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search cases…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }}
          />
        </div>
        <Select
          value={statusFilter}
          onChange={e => setStatusFilter(e.target.value)}
        >
          <option value="all">All Statuses</option>
          {STATUSES.map(s => (
            <option key={s} value={s}>{s.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())}</option>
          ))}
        </Select>
        <Select
          value={typeFilter}
          onChange={e => setTypeFilter(e.target.value)}
        >
          <option value="all">All Dispute Types</option>
          {DISPUTE_TYPES.map(t => (
            <option key={t} value={t}>{DISPUTE_TYPE_LABELS[t]}</option>
          ))}
        </Select>
      </div>

      {/* Cases list */}
      <div className="space-y-3">
        {isLoading ? (
          [...Array(4)].map((_, i) => (
            <div key={i} className="h-24 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))
        ) : cases.length === 0 ? (
          <div className="rounded-2xl p-14 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <Scale size={36} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>No adjudication cases</p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {allCases.length > 0 ? 'No cases match your filters.' : 'Create a new case to begin tracking a construction dispute.'}
            </p>
            {canWrite && allCases.length === 0 && (
              <Button onClick={() => setShowCreate(true)} variant="secondary" size="sm" className="mt-4">
                Create First Case
              </Button>
            )}
          </div>
        ) : cases.map((c: any, i: number) => {
          const statusBadge = STATUS_COLORS[c.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          const nextDl = nextDeadlineBadge(c.deadlines ?? []);

          return (
            <div
              key={c.id}
              className="p-4 rounded-xl transition-colors cursor-pointer ss-animate-in"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
              onClick={() => router.push(`/app/projects/${id}/adjudication/${c.id}`)}
            >
              <div className="flex items-start justify-between gap-4">
                {/* Left: identity */}
                <div className="flex items-start gap-3 flex-1 min-w-0">
                  <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
                    style={{ backgroundColor: 'var(--gold-15)' }}>
                    <Scale size={15} style={{ color: 'var(--gold)' }} />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>{c.case_number}</span>
                      <span className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{c.title}</span>
                    </div>
                    <div className="flex items-center gap-3 mt-1 flex-wrap">
                      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {DISPUTE_TYPE_LABELS[c.dispute_type] ?? c.dispute_type}
                      </span>
                      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {c.claimant_name} <span style={{ color: 'var(--text-muted)' }}>vs</span> {c.respondent_name}
                      </span>
                      {c.claim_amount && (
                        <span className="text-xs font-medium tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                          {c.currency} {Number(c.claim_amount).toLocaleString()}
                        </span>
                      )}
                    </div>
                    {/* Current step */}
                    <div className="flex items-center gap-2 mt-2">
                      <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: statusBadge.bg, color: statusBadge.text }}>
                        {c.status?.replace(/_/g, ' ').replace(/\b\w/g, (ch: string) => ch.toUpperCase())}
                      </span>
                      {c.current_step && (
                        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                          Step: {STEP_LABELS[c.current_step] ?? c.current_step}
                        </span>
                      )}
                    </div>
                  </div>
                </div>

                {/* Right: deadline + actions */}
                <div className="flex flex-col items-end gap-2 flex-shrink-0">
                  {nextDl && (
                    <div className="flex items-center gap-1.5">
                      {nextDl.isOverdue
                        ? <AlertCircle size={12} style={{ color: '#f87171' }} />
                        : nextDl.isDueSoon
                        ? <Clock size={12} style={{ color: '#facc15' }} />
                        : <Clock size={12} style={{ color: 'var(--text-muted)' }} />}
                      <span
                        className="text-xs"
                        style={{ color: nextDl.isOverdue ? '#f87171' : nextDl.isDueSoon ? '#facc15' : 'var(--text-muted)' }}
                      >
                        {nextDl.isOverdue
                          ? `Overdue by ${Math.abs(nextDl.diffDays)}d`
                          : nextDl.diffDays === 0
                          ? 'Due today'
                          : `Due in ${nextDl.diffDays}d`}
                      </span>
                    </div>
                  )}
                  <div className="flex items-center gap-2">
                    <button
                      onClick={e => { e.stopPropagation(); router.push(`/app/projects/${id}/adjudication/${c.id}`); }}
                      className="flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--gold)' }}
                    >
                      View <ChevronRight size={12} />
                    </button>
                    {canWrite && (
                      <button
                        onClick={e => {
                          e.stopPropagation();
                          if (confirm(`Archive case ${c.case_number}?`)) deleteMutation.mutate(c.id);
                        }}
                        className="px-3 py-1.5 rounded-lg text-xs hover:bg-[var(--bg-hover)] transition-colors"
                        style={{ color: '#f87171' }}
                      >
                        Archive
                      </button>
                    )}
                  </div>
                  <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                    {c.created_at ? formatDate(c.created_at) : ''}
                  </span>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export default function GatedProjectAdjudicationPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.adjudication" title="Adjudication" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectAdjudicationPage />
    </FeatureAvailabilityGate>
  );
}
