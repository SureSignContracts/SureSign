'use client';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Plus, X, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { getErrorMessage, INPUT_STYLE, CATEGORY_LABELS, SeverityBadge, StatusBadge, Field } from '@/components/risks/riskShared';
import PageTourButton from '@/components/tours/PageTourButton';

type Risk = {
  id: number;
  title: string;
  description: string | null;
  category: string;
  severity: string;
  probability: string | null;
  risk_owner: string | null;
  recommended_action: string | null;
  mitigation: string | null;
  status: string;
  review_date: string | null;
  is_ai_generated: boolean;
  contract_id: number | null;
  trade_package_id: number | null;
  source_name: string | null;
  action_url: string | null;
};

type FilterSev = 'all' | 'critical' | 'high' | 'medium' | 'low';

export default function RiskRegisterPage() {
  const { id: projectId } = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const [filter, setFilter] = useState<FilterSev>('all');
  const [showCreate, setShowCreate] = useState(false);
  const [confirmTarget, setConfirmTarget] = useState<Risk | null>(null);

  const listQueryKey = ['project-risks', projectId];

  const { data, isLoading } = useQuery<Risk[]>({
    queryKey: listQueryKey,
    queryFn: () => api.get(`/projects/${projectId}/risks`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (risk: Risk) => api.delete(`/projects/${projectId}/risks/${risk.id}`),
    onSuccess: () => {
      toast.success('Risk deleted');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setConfirmTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete risk')),
  });

  const risks = data ?? [];
  const filtered = filter === 'all' ? risks : risks.filter(r => r.severity === filter);

  const counts = {
    critical: risks.filter(r => r.severity === 'critical').length,
    high: risks.filter(r => r.severity === 'high').length,
    medium: risks.filter(r => r.severity === 'medium').length,
    low: risks.filter(r => r.severity === 'low').length,
  };

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6 pb-12">
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>
              Risk register
            </h1>
            <PageTourButton tourKey="page-risks" label="Take a tour of this page" />
          </div>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Risks across the main contract and every trade package, AI-derived and manually raised.
          </p>
        </div>
        <button
          data-tour="risks-new"
          onClick={() => setShowCreate(true)}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-all active:scale-[0.98] hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={14} /> Add risk
        </button>
      </div>

      <div className="flex gap-1 p-1 rounded-full w-fit flex-wrap" data-tour="risks-filters" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        {(['all', 'critical', 'high', 'medium', 'low'] as const).map(s => (
          <button
            key={s}
            onClick={() => setFilter(s)}
            className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
            style={filter === s
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }}
          >
            {s === 'all' ? `All (${risks.length})` : `${s[0].toUpperCase()}${s.slice(1)} (${counts[s]})`}
          </button>
        ))}
      </div>

      <div className="rounded-xl overflow-hidden" data-tour="risks-table" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Title', 'Source', 'Category', 'Severity', 'Owner', 'Review Date', 'Status', 'Origin', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            )}
            {!isLoading && filtered.length === 0 && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>
                No risks{filter !== 'all' ? ` with severity "${filter}"` : ' recorded for this project yet'}.
              </td></tr>
            )}
            {filtered.map((risk, index) => (
              <tr key={risk.id} className="ss-animate-in hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)', animationDelay: `${Math.min(index * 45, 360)}ms` }}>
                <td className="px-3 py-2.5">
                  <button
                    onClick={() => risk.action_url && router.push(risk.action_url)}
                    className="text-left hover:underline"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    {risk.title}
                  </button>
                </td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{risk.source_name ?? '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{CATEGORY_LABELS[risk.category] ?? risk.category}</td>
                <td className="px-3 py-2.5"><SeverityBadge severity={risk.severity} /></td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{risk.risk_owner ?? '—'}</td>
                <td className="px-3 py-2.5 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>{risk.review_date ? formatDate(risk.review_date) : '—'}</td>
                <td className="px-3 py-2.5"><StatusBadge status={risk.status} /></td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>{risk.is_ai_generated ? 'AI' : 'Manual'}</td>
                <td className="px-3 py-2.5 text-right">
                  {!risk.is_ai_generated && (
                    <button onClick={() => setConfirmTarget(risk)} title="Delete" className="p-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]">
                      <Trash2 size={14} style={{ color: 'var(--text-muted)' }} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {showCreate && (
        <CreateRiskModal projectId={projectId} invalidateKey={listQueryKey} onClose={() => setShowCreate(false)} />
      )}

      {confirmTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="ss-animate-in w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete risk &ldquo;{confirmTarget.title}&rdquo;? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmTarget(null)} className="px-3 py-1.5 rounded-lg text-sm transition-all active:scale-[0.98]" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => deleteMutation.mutate(confirmTarget)}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white transition-all active:scale-[0.98]"
                style={{ backgroundColor: '#a11a1a' }}
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function CreateRiskModal({ projectId, invalidateKey, onClose }: {
  projectId: string; invalidateKey: (string | number)[]; onClose: () => void;
}) {
  const qc = useQueryClient();

  const { data: contractsData } = useQuery({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data?.data ?? r.data ?? []),
  });
  const { data: subcontractsData } = useQuery({
    queryKey: ['project-subcontracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/documents/module/subcontracts`).then(r => r.data),
  });

  const contracts: Array<{ id: number; title: string }> = Array.isArray(contractsData) ? contractsData : [];
  const tradePackages: Array<{ id: number; name: string }> = subcontractsData?.trade_packages ?? [];

  // Deliberately always defaults to 'contract' rather than `contracts.length
  // ? 'contract' : 'trade_package'` — contractsData is still undefined on
  // first render (the query hasn't resolved yet), so contracts.length is
  // always 0 at mount regardless of the real count, silently defaulting
  // every risk to trade_package scope. The user can switch manually if a
  // project genuinely has no contracts.
  const [parentType, setParentType] = useState<'contract' | 'trade_package'>('contract');
  const [parentId, setParentId] = useState<number | ''>('');
  const [form, setForm] = useState({
    title: '', description: '', category: 'other', severity: 'medium', probability: '',
    risk_owner: '', recommended_action: '', mitigation: '', status: 'open', review_date: '',
  });

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        probability: form.probability || null,
        review_date: form.review_date || null,
        contract_id: parentType === 'contract' ? parentId || null : null,
        trade_package_id: parentType === 'trade_package' ? parentId || null : null,
      };
      return api.post(`/projects/${projectId}/risks`, payload);
    },
    onSuccess: () => {
      toast.success('Risk added');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save risk')),
  });

  const parentOptions = parentType === 'contract' ? contracts : tradePackages;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="ss-animate-in w-full max-w-lg rounded-xl p-6 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>Add risk</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <Field label="Applies to" required>
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={parentType} onChange={e => { setParentType(e.target.value as 'contract' | 'trade_package'); setParentId(''); }}>
                <option value="contract">Contract</option>
                <option value="trade_package">Trade Package</option>
              </select>
            </Field>
            <Field label={parentType === 'contract' ? 'Contract' : 'Trade package'} required>
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={parentId} onChange={e => setParentId(e.target.value ? Number(e.target.value) : '')}>
                <option value="">Select…</option>
                {parentOptions.map((o: { id: number; title?: string; name?: string }) => (
                  <option key={o.id} value={o.id}>{o.title ?? o.name}</option>
                ))}
              </select>
            </Field>
          </div>
          <Field label="Title" required>
            <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
              value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} />
          </Field>
          <Field label="Description">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={2}
              value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Category">
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))}>
                {Object.entries(CATEGORY_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            </Field>
            <Field label="Status">
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.status} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}>
                <option value="open">Open</option>
                <option value="in_progress">In Progress</option>
                <option value="resolved">Resolved</option>
              </select>
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Severity">
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.severity} onChange={e => setForm(f => ({ ...f, severity: e.target.value }))}>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
                <option value="critical">Critical</option>
              </select>
            </Field>
            <Field label="Probability">
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.probability} onChange={e => setForm(f => ({ ...f, probability: e.target.value }))}>
                <option value="">—</option>
                <option value="low">Low</option>
                <option value="medium">Medium</option>
                <option value="high">High</option>
              </select>
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Owner">
              <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.risk_owner} onChange={e => setForm(f => ({ ...f, risk_owner: e.target.value }))} />
            </Field>
            <Field label="Review date">
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.review_date} onChange={e => setForm(f => ({ ...f, review_date: e.target.value }))} />
            </Field>
          </div>
          <Field label="Mitigation">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={2}
              value={form.mitigation} onChange={e => setForm(f => ({ ...f, mitigation: e.target.value }))} />
          </Field>
          <Field label="Recommended action">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={2}
              value={form.recommended_action} onChange={e => setForm(f => ({ ...f, recommended_action: e.target.value }))} />
          </Field>
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <button onClick={onClose} className="px-3 py-1.5 rounded-lg text-sm transition-all active:scale-[0.98]" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => mutation.mutate()}
            disabled={!form.title || !parentId || mutation.isPending}
            className="px-3 py-1.5 rounded-lg text-sm font-semibold transition-all active:scale-[0.98] disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Saving…' : 'Add risk'}
          </button>
        </div>
      </div>
    </div>
  );
}
