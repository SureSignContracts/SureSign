'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Plus, X, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { getErrorMessage, INPUT_STYLE, CATEGORY_LABELS, SeverityBadge, StatusBadge, Field } from '@/components/risks/riskShared';

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
};

export function RisksTab({ projectId, tradePackageId, canWrite }: { projectId: string; tradePackageId: number; canWrite: boolean }) {
  const qc = useQueryClient();
  const listQueryKey = ['trade-package-risks', tradePackageId];

  const { data, isLoading } = useQuery<Risk[]>({
    queryKey: listQueryKey,
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/risks`).then(r => r.data),
  });

  const [modalRisk, setModalRisk] = useState<Risk | 'new' | null>(null);
  const [confirmTarget, setConfirmTarget] = useState<Risk | null>(null);

  const deleteMutation = useMutation({
    mutationFn: (risk: Risk) => api.delete(`/risks/${risk.id}`),
    onSuccess: () => {
      toast.success('Risk deleted');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setConfirmTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete risk')),
  });

  const risks = data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Manually tracked risks for this trade package. AI-generated risks are not yet available at the trade package level — see the main contract's Risk summary for AI-derived risks.
        </p>
        {canWrite && (
          <button
            onClick={() => setModalRisk('new')}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            <Plus size={13} /> Add Risk
          </button>
        )}
      </div>

      <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Title', 'Category', 'Severity', 'Probability', 'Owner', 'Review Date', 'Status', 'Source', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            )}
            {!isLoading && risks.length === 0 && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No risks recorded for this trade package yet.</td></tr>
            )}
            {risks.map(risk => (
              <tr key={risk.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5">
                  <button onClick={() => setModalRisk(risk)} className="text-left hover:underline" style={{ color: 'var(--text-primary)' }}>
                    {risk.title}
                  </button>
                </td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{CATEGORY_LABELS[risk.category] ?? risk.category}</td>
                <td className="px-3 py-2.5"><SeverityBadge severity={risk.severity} /></td>
                <td className="px-3 py-2.5 text-xs capitalize" style={{ color: 'var(--text-secondary)' }}>{risk.probability ?? '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{risk.risk_owner ?? '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{risk.review_date ? formatDate(risk.review_date) : '—'}</td>
                <td className="px-3 py-2.5"><StatusBadge status={risk.status} /></td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>{risk.is_ai_generated ? 'AI' : 'Manual'}</td>
                <td className="px-3 py-2.5 text-right">
                  {canWrite && (
                    <button onClick={() => setConfirmTarget(risk)} title="Delete">
                      <Trash2 size={14} style={{ color: 'var(--text-muted)' }} />
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {modalRisk && (
        <RiskModal
          projectId={projectId}
          tradePackageId={tradePackageId}
          risk={modalRisk === 'new' ? null : modalRisk}
          invalidateKey={listQueryKey}
          onClose={() => setModalRisk(null)}
        />
      )}

      {confirmTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete risk &ldquo;{confirmTarget.title}&rdquo;? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => deleteMutation.mutate(confirmTarget)}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white"
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

function RiskModal({ projectId, tradePackageId, risk, invalidateKey, onClose }: {
  projectId: string; tradePackageId: number; risk: Risk | null; invalidateKey: (string | number)[]; onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!risk;

  const [form, setForm] = useState({
    title: risk?.title ?? '',
    description: risk?.description ?? '',
    category: risk?.category ?? 'other',
    severity: risk?.severity ?? 'medium',
    probability: risk?.probability ?? '',
    risk_owner: risk?.risk_owner ?? '',
    recommended_action: risk?.recommended_action ?? '',
    mitigation: risk?.mitigation ?? '',
    status: risk?.status ?? 'open',
    review_date: risk?.review_date ?? '',
  });

  const mutation = useMutation({
    mutationFn: () => {
      const payload = { ...form, probability: form.probability || null, review_date: form.review_date || null };
      return isEdit
        ? api.put(`/risks/${risk!.id}`, payload)
        : api.post(`/projects/${projectId}/trade-packages/${tradePackageId}/risks`, payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Risk updated' : 'Risk added');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save risk')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-lg rounded-xl p-6 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>{isEdit ? 'Edit Risk' : 'Add Risk'}</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="space-y-3">
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
            <Field label="Review Date">
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.review_date} onChange={e => setForm(f => ({ ...f, review_date: e.target.value }))} />
            </Field>
          </div>
          <Field label="Mitigation">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={2}
              value={form.mitigation} onChange={e => setForm(f => ({ ...f, mitigation: e.target.value }))} />
          </Field>
          <Field label="Recommended Action">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={2}
              value={form.recommended_action} onChange={e => setForm(f => ({ ...f, recommended_action: e.target.value }))} />
          </Field>
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <button onClick={onClose} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => mutation.mutate()}
            disabled={!form.title || mutation.isPending}
            className="px-3 py-1.5 rounded-lg text-sm font-semibold disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Risk'}
          </button>
        </div>
      </div>
    </div>
  );
}
