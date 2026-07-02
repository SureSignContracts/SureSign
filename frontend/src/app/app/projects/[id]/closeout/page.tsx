'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { CheckCircle2, Circle, Lock, Plus, X, Loader2 } from 'lucide-react';

// ─── Constants ───────────────────────────────────────────────────────────────

const ITEM_STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  pending:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  in_progress: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  completed:   { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  approved:    { bg: 'rgba(185,149,102,0.15)', text: 'var(--gold)' },
};

const ITEM_STATUSES = ['pending', 'in_progress', 'completed', 'approved'];

// ─── Add Item Modal ───────────────────────────────────────────────────────────

function AddItemModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({ category: '', title: '', due_date: '', notes: '' });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => api.post(`/projects/${projectId}/closeout/items`, data).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-closeout', projectId] });
      onClose();
    },
  });

  const inputStyle = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl overflow-hidden shadow-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Add Closeout Item</h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutation.mutate(form); }} className="p-6 space-y-4">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Category *</label>
            <input value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))} required
              placeholder="e.g. Warranties, Certificates" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Title *</label>
            <input value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} required
              placeholder="Item description" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Due Date</label>
            <input type="date" value={form.due_date} onChange={e => setForm(f => ({ ...f, due_date: e.target.value }))}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          {mutation.isError && <p className="text-xs text-red-400">Failed to add item.</p>}
          <div className="flex justify-end gap-3 pt-1">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 disabled:opacity-60"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Adding…' : 'Add Item'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function ProjectCloseoutPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [showAddModal, setShowAddModal] = useState(false);

  const { data: closeout, isLoading } = useQuery({
    queryKey: ['project-closeout', id],
    queryFn: () => api.get(`/projects/${id}/closeout`).then(r => r.data).catch(() => null),
    staleTime: 30 * 1000,
  });

  const updateItemMutation = useMutation({
    mutationFn: ({ itemId, status }: { itemId: number; status: string }) =>
      api.put(`/projects/${id}/closeout/items/${itemId}`, { status }).then(r => r.data),
    onSuccess: (updated) => {
      qc.setQueryData(['project-closeout', id], updated);
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
    },
  });

  const markCompleteMutation = useMutation({
    mutationFn: () => api.put(`/projects/${id}/closeout`, { status: 'completed' }).then(r => r.data),
    onSuccess: (updated) => {
      qc.setQueryData(['project-closeout', id], updated);
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
    },
  });

  const items: any[] = closeout?.items ?? [];
  const totalItems = items.length;
  const completedItems = items.filter((i: any) => i.status === 'completed' || i.status === 'approved').length;
  const progress = totalItems > 0 ? Math.round((completedItems / totalItems) * 100) : 0;
  const allComplete = totalItems > 0 && completedItems === totalItems;

  // Group items by category
  const categories = items.reduce((acc: Record<string, any[]>, item: any) => {
    const cat = item.category || 'Other';
    if (!acc[cat]) acc[cat] = [];
    acc[cat].push(item);
    return acc;
  }, {});

  if (isLoading) {
    return (
      <div className="p-6 max-w-4xl mx-auto flex items-center justify-center h-64">
        <Loader2 size={24} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
      </div>
    );
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      {showAddModal && <AddItemModal projectId={id} onClose={() => setShowAddModal(false)} />}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Closeout</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Project closeout checklist and handover</p>
        </div>
        <div className="flex items-center gap-3">
          <div className="text-right">
            <p className="text-2xl font-bold" style={{ color: 'var(--gold)' }}>{progress}%</p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{completedItems}/{totalItems} complete</p>
          </div>
        </div>
      </div>

      {/* Progress bar */}
      <div className="h-2 rounded-full w-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        <div
          className="h-full rounded-full transition-all duration-500"
          style={{ width: `${progress}%`, backgroundColor: allComplete ? '#4ade80' : 'var(--gold)' }}
        />
      </div>

      {/* Status badge + actions */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <span className="text-xs px-3 py-1 rounded-full font-medium capitalize"
            style={{
              backgroundColor: closeout?.status === 'completed' ? 'rgba(34,197,94,0.12)' : 'rgba(185,149,102,0.12)',
              color: closeout?.status === 'completed' ? '#4ade80' : 'var(--gold)',
            }}>
            {closeout?.status?.replace(/_/g, ' ') ?? 'pending'}
          </span>
        </div>
        <button onClick={() => setShowAddModal(true)}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-80"
          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}>
          <Plus size={13} />
          Add Item
        </button>
      </div>

      {/* Checklist sections */}
      <div className="space-y-5">
        {Object.entries(categories).map(([category, catItems]) => {
          const catCompleted = catItems.filter((i: any) => i.status === 'completed' || i.status === 'approved').length;
          return (
            <div key={category} className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              <div className="px-5 py-3 flex items-center justify-between" style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{category}</h2>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{catCompleted}/{catItems.length}</span>
              </div>
              <div style={{ backgroundColor: 'var(--bg-surface)' }}>
                {(catItems as any[]).map((item: any, idx: number) => {
                  const isDone = item.status === 'completed' || item.status === 'approved';
                  const badge = ITEM_STATUS_COLORS[item.status] ?? ITEM_STATUS_COLORS.pending;
                  const isUpdating = updateItemMutation.isPending && updateItemMutation.variables?.itemId === item.id;
                  return (
                    <div key={item.id}
                      className="flex items-center justify-between px-5 py-3.5 transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ borderBottom: idx < catItems.length - 1 ? '1px solid var(--border)' : undefined }}>
                      <div className="flex items-center gap-3 flex-1 min-w-0">
                        <button
                          onClick={() => updateItemMutation.mutate({
                            itemId: item.id,
                            status: isDone ? 'pending' : 'completed',
                          })}
                          disabled={isUpdating}
                          className="flex-shrink-0 transition-transform hover:scale-110"
                        >
                          {isUpdating ? (
                            <Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
                          ) : isDone ? (
                            <CheckCircle2 size={18} style={{ color: '#4ade80' }} />
                          ) : (
                            <Circle size={18} style={{ color: 'var(--text-muted)' }} />
                          )}
                        </button>
                        <span className="text-sm" style={{
                          color: isDone ? 'var(--text-muted)' : 'var(--text-secondary)',
                          textDecoration: isDone ? 'line-through' : undefined,
                        }}>
                          {item.title}
                        </span>
                      </div>
                      {/* Status selector */}
                      <select
                        value={item.status}
                        onChange={e => updateItemMutation.mutate({ itemId: item.id, status: e.target.value })}
                        className="ml-3 px-2 py-0.5 rounded-md text-xs outline-none capitalize"
                        style={{ backgroundColor: badge.bg, color: badge.text, border: 'none', cursor: 'pointer' }}
                      >
                        {ITEM_STATUSES.map(s => (
                          <option key={s} value={s}>{s.replace(/_/g, ' ')}</option>
                        ))}
                      </select>
                    </div>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>

      {/* Mark Complete button */}
      <div className="flex justify-end pt-2">
        <button
          onClick={() => markCompleteMutation.mutate()}
          disabled={!allComplete || closeout?.status === 'completed' || markCompleteMutation.isPending}
          className="flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-medium transition-opacity hover:opacity-90 disabled:opacity-40 disabled:cursor-not-allowed"
          style={{ backgroundColor: '#4ade80', color: '#052e16' }}
        >
          {markCompleteMutation.isPending ? (
            <Loader2 size={14} className="animate-spin" />
          ) : (
            <Lock size={14} />
          )}
          {closeout?.status === 'completed' ? 'Project Marked Complete' : 'Mark Project Complete'}
        </button>
      </div>
    </div>
  );
}
