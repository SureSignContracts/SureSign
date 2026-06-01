'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { MessageSquare, Plus, Search, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PromptActionButton from '@/components/prompts/PromptActionButton';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:             { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  pending_response: { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  responded:        { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed:           { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  draft:            { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const RFI_STATUS_LABELS: Record<string, string> = {
  open:             'Open',
  pending_response: 'Pending Response',
  responded:        'Responded',
  closed:           'Closed',
  draft:            'Draft',
};

const PRIORITY_LABELS: Record<string, string> = {
  urgent: 'Urgent',
  high:   'High',
  normal: 'Normal',
  low:    'Low',
};

type RfiForm = {
  subject: string;
  description: string;
  priority: string;
  raised_date: string;
  response_due_date: string;
  programme_impact: boolean;
  programme_impact_days: string;
  cost_impact_amount: string;
};

function NewRfiModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<RfiForm>({
    subject: '', description: '', priority: 'normal',
    raised_date: new Date().toISOString().split('T')[0],
    response_due_date: '', programme_impact: false,
    programme_impact_days: '', cost_impact_amount: '',
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: RfiForm) => api.post(`/projects/${projectId}/rfis`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-rfis', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('RFI raised');
      onClose();
    },
    onError: () => toast.error('Failed to raise RFI'),
  });

  const set = (field: keyof RfiForm, value: string | boolean) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const labelStyle = { color: 'var(--text-muted)' };
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-lg rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New RFI</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Subject *</label>
            <input value={form.subject} onChange={e => set('subject', e.target.value)} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Description</label>
            <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Priority</label>
              <select value={form.priority} onChange={e => set('priority', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {(['urgent', 'high', 'normal', 'low'] as const).map(p => (
                  <option key={p} value={p}>{PRIORITY_LABELS[p]}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Date Raised</label>
              <input type="date" value={form.raised_date} onChange={e => set('raised_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Response Required By</label>
              <input type="date" value={form.response_due_date} onChange={e => set('response_due_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Cost Impact (£)</label>
              <input type="number" value={form.cost_impact_amount} onChange={e => set('cost_impact_amount', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={form.programme_impact} onChange={e => set('programme_impact', e.target.checked)}
              className="rounded" />
            <span className="text-xs" style={{ color: 'var(--text-secondary)' }}>Programme Impact</span>
          </label>
          {form.programme_impact && (
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Programme Impact (days)</label>
              <input type="number" value={form.programme_impact_days} onChange={e => set('programme_impact_days', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          )}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Raising…' : 'Raise RFI'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── RFI Response Modal ────────────────────────────────────────────────────────

function RfiResponseModal({ rfi, projectId, onClose }: { rfi: any; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    response:       rfi.response ?? '',
    responded_at:   rfi.responded_at ? String(rfi.responded_at).slice(0, 10) : new Date().toISOString().split('T')[0],
    assigned_to:    rfi.assigned_to ?? '',
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) =>
      api.put(`/rfis/${rfi.id}`, { ...data, status: 'responded' }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-rfis', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Response recorded');
      onClose();
    },
    onError: () => toast.error('Failed to record response'),
  });

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl shadow-xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Respond to RFI</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>#{rfi.rfi_number} — {rfi.subject}</p>
          </div>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Response *</label>
            <textarea value={form.response} onChange={e => setForm(p => ({ ...p, response: e.target.value }))} required rows={5}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Response Date</label>
              <input type="date" value={form.responded_at} onChange={e => setForm(p => ({ ...p, responded_at: e.target.value }))}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Assigned To</label>
              <input value={form.assigned_to} onChange={e => setForm(p => ({ ...p, assigned_to: e.target.value }))}
                placeholder="Name or email"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : 'Record Response'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function ProjectRfisPage() {
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [respondRfi, setRespondRfi] = useState<any | null>(null);
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['project-rfis', id],
    queryFn: () => api.get(`/projects/${id}/rfis`).then(r => r.data),
  });

  const rfis = (data?.data ?? []).filter((r: any) => {
    const matchSearch = r.subject?.toLowerCase().includes(search.toLowerCase()) || String(r.rfi_number).includes(search);
    const matchStatus = statusFilter === 'all' || r.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const openCount  = (data?.data ?? []).filter((r: any) => r.status === 'open').length;
  const pendingCount = (data?.data ?? []).filter((r: any) => r.status === 'pending_response').length;

  const { mutate: closeRfi } = useMutation({
    mutationFn: (rfi: any) => api.put(`/rfis/${rfi.id}`, { status: 'closed' }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-rfis', id] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', id] });
      toast.success('RFI closed');
    },
    onError: () => toast.error('Failed to close RFI'),
  });

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>RFIs</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Requests for Information</p>
        </div>
        {canWrite && (
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New RFI
        </button>
        )}
      </div>

      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        {[
          { label: 'Total', value: (data?.data ?? []).length, color: 'var(--gold)' },
          { label: 'Open', value: openCount, color: '#facc15' },
          { label: 'Pending Response', value: pendingCount, color: '#fb923c' },
        ].map(s => (
          <div key={s.label} className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{s.label}</p>
            <p className="text-xl font-bold mt-1" style={{ color: s.color }}>{s.value}</p>
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
            placeholder="Search RFIs…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {(['all', 'open', 'pending_response', 'responded', 'closed'] as const).map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
              style={statusFilter === s ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
              {s === 'all' ? 'All' : RFI_STATUS_LABELS[s] ?? s}
            </button>
          ))}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['RFI #', 'Subject', 'Priority', 'Status', 'Raised', 'Response Due', ''].map(h => (
                <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(5)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(7)].map((_, j) => (
                    <td key={j} className="px-5 py-4">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : rfis.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center">
                  <MessageSquare size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No RFIs yet</p>
                  {canWrite && (
                  <button onClick={() => setShowModal(true)} className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                    Raise First RFI
                  </button>
                  )}
                </td>
              </tr>
            ) : rfis.map((r: any) => {
              const badge = STATUS_COLORS[r.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
              return (
                <tr key={r.id} className="hover:bg-[var(--bg-elevated)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-5 py-3 font-mono font-semibold" style={{ color: 'var(--gold)' }}>#{r.rfi_number}</td>
                  <td className="px-5 py-3 font-medium max-w-[240px] truncate" style={{ color: 'var(--text-primary)' }}>{r.subject}</td>
                  <td className="px-5 py-3">
                    <span className="text-xs font-medium" style={{ color: r.priority === 'urgent' ? '#ef4444' : r.priority === 'high' ? '#f59e0b' : 'var(--text-muted)' }}>
                      {PRIORITY_LABELS[r.priority] ?? r.priority}
                    </span>
                  </td>
                  <td className="px-5 py-3">
                    <span className="px-2 py-0.5 rounded-full text-xs font-medium"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                      {RFI_STATUS_LABELS[r.status] ?? r.status}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{r.raised_date ? formatDate(r.raised_date) : '—'}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: r.response_due_date ? 'var(--text-secondary)' : 'var(--text-muted)' }}>
                    {r.response_due_date ? formatDate(r.response_due_date) : '—'}
                  </td>
                  <td className="px-5 py-3">
                    {canWrite && r.status !== 'closed' && (
                      <div className="flex gap-1">
                        {r.status !== 'responded' && (
                          <button onClick={e => { e.stopPropagation(); setRespondRfi(r); }}
                            className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-elevated)]"
                            style={{ color: 'var(--gold)' }}>
                            Respond
                          </button>
                        )}
                        <button onClick={e => { e.stopPropagation(); closeRfi(r); }}
                          className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-elevated)]"
                          style={{ color: 'var(--text-muted)' }}>
                          Close
                        </button>
                      </div>
                    )}
                    <PromptActionButton
                      label="Prompt"
                      module="RFIs"
                      recordType="rfi"
                      recordId={r.id}
                      projectId={id}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {canWrite && showModal && <NewRfiModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && respondRfi && (
        <RfiResponseModal rfi={respondRfi} projectId={id!} onClose={() => setRespondRfi(null)} />
      )}
    </div>
  );
}
