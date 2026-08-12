'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';
import EvidenceSection from '@/components/documents/EvidenceSection';
import DrawingLocationsSection from '@/components/drawings/DrawingLocationsSection';
import DrawingCreationContextBadge from '@/components/drawings/DrawingCreationContextBadge';
import type { DrawingCreationContext } from '@/components/drawings/DrawingCreationContext';

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

/**
 * Drawing Phase 7B2 — extracted unchanged from snagging/page.tsx's
 * page-local SnagModal. Drawing Phase 7B3 added the optional `drawingContext`
 * prop (Part 4) — absent for every existing module page, so their behaviour
 * is byte-for-byte unchanged; present only when opened from the Drawing
 * Viewer's "Create Record" action.
 */
export interface SnagRecord {
  id: number;
  snag_number?: number;
  title: string;
  description?: string | null;
  location?: string | null;
  category?: string | null;
  priority?: string;
  status?: string;
  due_date?: string | null;
  notes?: string | null;
}

export default function SnagModal({ projectId, snag, onClose, onCreated, drawingContext }: {
  projectId: string;
  snag?: SnagRecord;
  onClose: () => void;
  /** Fires only after a successful CREATE (never on edit) — optional, additive to existing query invalidation/close behaviour. */
  onCreated?: (snag: SnagRecord) => void;
  /** Drawing Phase 7B3, Part 4/7 — only ever set when opened from the Drawing Viewer. Never present for the ordinary Snagging page. */
  drawingContext?: DrawingCreationContext;
}) {
  const qc = useQueryClient();
  const isEdit = !!snag;

  const [form, setForm] = useState({
    title:       snag?.title       ?? '',
    description: snag?.description ?? '',
    // Part 7 — convenience prefill only, from the hotspot's own label; never
    // fabricated, never a "Drawing S-204..." string. Editable/clearable
    // like any other field. `drawingContext` is only ever supplied on
    // create (the caller never passes both `snag` and `drawingContext`),
    // so this never overrides a real edit value.
    location:    snag?.location    ?? drawingContext?.hotspotLabel ?? '',
    category:    snag?.category    ?? '',
    priority:    snag?.priority    ?? 'medium',
    status:      snag?.status      ?? 'open',
    due_date:    snag?.due_date    ? String(snag.due_date).slice(0, 10) : '',
    notes:       snag?.notes       ?? '',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) =>
      isEdit
        ? api.put(`/projects/${projectId}/snagging/${snag.id}`, data).then(r => r.data)
        // Part 5 — drawing_hotspot_id sent ONLY on create, never on an
        // ordinary edit save (an already-linked Snag never needs to
        // re-resolve/re-link on every edit).
        : api.post(`/projects/${projectId}/snagging`, drawingContext ? { ...data, drawing_hotspot_id: drawingContext.hotspotId } : data).then(r => r.data),
    onSuccess: (created: SnagRecord) => {
      qc.invalidateQueries({ queryKey: ['project-snagging', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      qc.invalidateQueries({ queryKey: ['project-stats', projectId] });
      if (!isEdit) onCreated?.(created);
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));
  // Same update, narrowed to what the shared `Select` component's onChange
  // actually provides — see qa/page.tsx's identical helper for why.
  const setSelect = (k: keyof typeof form) =>
    (e: { target: { value: string } }) => setForm(f => ({ ...f, [k]: e.target.value }));

  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    border: '1px solid var(--border)',
    color: 'var(--text-primary)',
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-lg rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {isEdit ? 'Edit Snag' : 'Add Snag Item'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutation.mutate(form); }} className="p-6 space-y-4">
          {/* Part 6/10 (DoD) — only ever shown for a Drawing-origin create; drawingContext is never passed alongside an edit. */}
          {!isEdit && drawingContext && <DrawingCreationContextBadge context={drawingContext} />}
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
              <Select value={form.priority} onChange={setSelect('priority')} className="w-full">
                {PRIORITIES.map(p => <option key={p} value={p}>{PRIORITY_LABELS[p]}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <Select value={form.status} onChange={setSelect('status')} className="w-full">
                {STATUSES.map(s => <option key={s} value={s}>{STATUS_LABELS[s]}</option>)}
              </Select>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Due date</label>
            <input type="date" value={form.due_date} onChange={set('due_date')}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea value={form.notes} onChange={set('notes')} rows={2} placeholder="Additional notes…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          {mutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>
          )}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Snag'}
            </button>
          </div>
        </form>
        {isEdit && (
          <div className="px-6 pb-6 space-y-4">
            <EvidenceSection
              attachmentsUrl={`/projects/${projectId}/snagging/${snag.id}/attachments`}
              queryKey={['snag-attachments', snag.id]}
              label="Evidence"
            />
            <DrawingLocationsSection projectId={projectId} type="snag" recordId={snag.id} />
          </div>
        )}
      </div>
    </div>
  );
}
