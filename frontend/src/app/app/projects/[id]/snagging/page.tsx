'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Package, Plus, Search, AlertCircle, X } from 'lucide-react';

const PRIORITY_COLORS: Record<string, { bg: string; text: string }> = {
  low:      { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  medium:   { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  high:     { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  critical: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:             { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  in_progress:      { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  ready_for_review: { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  closed:           { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const STATUSES = ['open', 'in_progress', 'ready_for_review', 'closed'];
const PRIORITIES = ['low', 'medium', 'high', 'critical'];

const STATUS_LABELS: Record<string, string> = {
  open:             'Open',
  in_progress:      'In Progress',
  ready_for_review: 'Ready for Review',
  closed:           'Closed',
};

const PRIORITY_LABELS: Record<string, string> = {
  low:      'Low',
  medium:   'Medium',
  high:     'High',
  critical: 'Critical',
};

// ─── Modal ───────────────────────────────────────────────────────────────────

function SnagModal({ projectId, snag, onClose }: { projectId: string; snag?: any; onClose: () => void }) {
  const qc = useQueryClient();
  const isEdit = !!snag;

  const [form, setForm] = useState({
    title:       snag?.title       ?? '',
    description: snag?.description ?? '',
    location:    snag?.location    ?? '',
    category:    snag?.category    ?? '',
    priority:    snag?.priority    ?? 'medium',
    status:      snag?.status      ?? 'open',
    due_date:    snag?.due_date    ? String(snag.due_date).slice(0, 10) : '',
    notes:       snag?.notes       ?? '',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) =>
      isEdit
        ? api.put(`/snagging/${snag.id}`, data).then(r => r.data)
        : api.post(`/projects/${projectId}/snagging`, data).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-snagging', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      qc.invalidateQueries({ queryKey: ['project-stats', projectId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));

  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    border: '1px solid var(--border)',
    color: 'var(--text-primary)',
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-lg rounded-2xl overflow-hidden shadow-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {isEdit ? 'Edit Snag' : 'Add Snag Item'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutation.mutate(form); }} className="p-6 space-y-4">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Title *</label>
            <input value={form.title} onChange={set('title')} required placeholder="Brief description of defect"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Description</label>
            <textarea value={form.description} onChange={set('description')} rows={3} placeholder="Detailed description…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Location</label>
              <input value={form.location} onChange={set('location')} placeholder="e.g. Level 3, Room 301"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Category</label>
              <input value={form.category} onChange={set('category')} placeholder="e.g. Finishes, M&E"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Priority</label>
              <select value={form.priority} onChange={set('priority')} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {PRIORITIES.map(p => <option key={p} value={p}>{PRIORITY_LABELS[p]}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <select value={form.status} onChange={set('status')} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {STATUSES.map(s => <option key={s} value={s}>{STATUS_LABELS[s]}</option>)}
              </select>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Due Date</label>
            <input type="date" value={form.due_date} onChange={set('due_date')}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea value={form.notes} onChange={set('notes')} rows={2} placeholder="Additional notes…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          {mutation.isError && <p className="text-xs text-red-400">Failed to save. Please try again.</p>}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 disabled:opacity-60"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Snag'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function ProjectSnaggingPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modal, setModal] = useState<{ open: boolean; snag?: any }>({ open: false });

  const { data, isLoading } = useQuery({
    queryKey: ['project-snagging', id],
    queryFn: () => api.get(`/projects/${id}/snagging`).then(r => r.data).catch(() => ({ data: [] })),
  });

  const deleteMutation = useMutation({
    mutationFn: (snagId: number) => api.delete(`/snagging/${snagId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-snagging', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
    },
  });

  const allItems: any[] = data?.data ?? [];

  const items = allItems.filter((s: any) => {
    const matchSearch =
      s.title?.toLowerCase().includes(search.toLowerCase()) ||
      s.description?.toLowerCase().includes(search.toLowerCase()) ||
      s.location?.toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || s.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const openCount      = allItems.filter((s: any) => s.status === 'open').length;
  const inProgressCount = allItems.filter((s: any) => s.status === 'in_progress').length;
  const closedCount    = allItems.filter((s: any) => s.status === 'closed').length;

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {modal.open && (
        <SnagModal projectId={id} snag={modal.snag} onClose={() => setModal({ open: false })} />
      )}

      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Snagging</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Defect tracking and snag list management</p>
        </div>
        <button
          onClick={() => setModal({ open: true })}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          Add Snag
        </button>
      </div>

      {/* Summary */}
      <div className="grid grid-cols-4 gap-4">
        {[
          { label: 'Total',       value: allItems.length, color: 'var(--gold)' },
          { label: 'Open',        value: openCount,       color: '#f87171' },
          { label: 'In Progress', value: inProgressCount, color: '#60a5fa' },
          { label: 'Closed',      value: closedCount,     color: '#4ade80' },
        ].map(({ label, value, color }) => (
          <div key={label} className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
            <p className="text-xl font-bold mt-1" style={{ color }}>{value}</p>
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
            placeholder="Search snag items…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {(['all', ...STATUSES] as const).map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
              style={statusFilter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }
              }>
              {s === 'all' ? 'All' : STATUS_LABELS[s] ?? s}
            </button>
          ))}
        </div>
      </div>

      {/* Items list */}
      <div className="space-y-2">
        {isLoading ? (
          [...Array(5)].map((_, i) => (
            <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))
        ) : items.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <Package size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No snag items found</p>
            <button onClick={() => setModal({ open: true })}
              className="mt-4 px-4 py-2 rounded-lg text-xs font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              Add First Snag
            </button>
          </div>
        ) : items.map((s: any) => {
          const statusBadge   = STATUS_COLORS[s.status]    ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          const priorityBadge = PRIORITY_COLORS[s.priority] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div key={s.id} className="flex items-center justify-between p-4 rounded-xl transition-colors"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
              <div className="flex items-center gap-4 flex-1 min-w-0">
                <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ backgroundColor: 'rgba(239,68,68,0.08)' }}>
                  <AlertCircle size={15} style={{ color: '#ef4444' }} />
                </div>
                <div className="min-w-0">
                  <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                    <span className="font-mono text-xs mr-2" style={{ color: 'var(--text-muted)' }}>#{s.snag_number}</span>
                    {s.title}
                  </p>
                  <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>
                    {s.location ? `${s.location} · ` : ''}
                    {s.category ? `${s.category} · ` : ''}
                    {s.created_at ? formatDate(s.created_at) : ''}
                    {s.assignee ? ` · ${s.assignee.name}` : ''}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2 flex-shrink-0">
                <span className="text-xs px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: priorityBadge.bg, color: priorityBadge.text }}>
                  {PRIORITY_LABELS[s.priority] ?? s.priority ?? 'Medium'}
                </span>
                <span className="text-xs px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: statusBadge.bg, color: statusBadge.text }}>
                  {STATUS_LABELS[s.status] ?? s.status}
                </span>
                <button onClick={() => setModal({ open: true, snag: s })}
                  className="px-3 py-1 rounded-lg text-xs hover:bg-[var(--bg-hover)] transition-colors"
                  style={{ color: 'var(--text-muted)' }}>Edit</button>
                <button
                  onClick={() => { if (confirm('Delete this snag?')) deleteMutation.mutate(s.id); }}
                  className="px-3 py-1 rounded-lg text-xs hover:bg-[var(--bg-hover)] transition-colors"
                  style={{ color: '#f87171' }}>Delete</button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
