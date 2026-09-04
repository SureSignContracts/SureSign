'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';
import DatePicker from '@/components/ui/DatePicker';
import EvidenceSection from '@/components/documents/EvidenceSection';

const STATUSES = ['draft', 'submitted', 'approved'];

export interface ToolboxTalkRecord {
  id: number;
  title: string;
  talk_date: string;
  started_at?: string | null;
  location?: string | null;
  delivered_by_name?: string | null;
  trade_or_subcontractor?: string | null;
  summary?: string | null;
  attendee_count?: number;
  status?: string;
}

/**
 * Toolbox Talks V1A — mirrors QaModal's exact structure (the closest,
 * most recently built sibling module). Evidence attaches only once the
 * record exists, same as QaModal.
 *
 * "Delivered by" is free text only for V1 (delivered_by_name) —
 * delivered_by_user_id exists and is fully validated on the backend, but
 * no project-scoped "pick an eligible organisation member" picker exists
 * anywhere in this codebase yet (RFI's own assigned_to field was
 * deliberately removed from its UI for the same reason; Meetings'
 * attendees field is free text too) — this form follows that same
 * established convention rather than introducing a new cross-cutting
 * user-picker endpoint/component as a side effect of this module.
 */
export default function ToolboxTalkModal({ projectId, talk, onClose }: {
  projectId: string;
  talk?: ToolboxTalkRecord;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!talk;

  const [form, setForm] = useState({
    title:                   talk?.title                   ?? '',
    talk_date:               talk?.talk_date                ? String(talk.talk_date).slice(0, 10) : '',
    started_at:              talk?.started_at               ?? '',
    location:                talk?.location                 ?? '',
    delivered_by_name:       talk?.delivered_by_name         ?? '',
    trade_or_subcontractor:  talk?.trade_or_subcontractor    ?? '',
    summary:                 talk?.summary                  ?? '',
    attendee_count:          talk?.attendee_count != null ? String(talk.attendee_count) : '0',
    status:                  talk?.status                   ?? 'draft',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => {
      const payload = { ...data, attendee_count: Number(data.attendee_count) || 0 };
      return isEdit
        ? api.put(`/projects/${projectId}/toolbox-talks/${talk.id}`, payload).then(r => r.data)
        : api.post(`/projects/${projectId}/toolbox-talks`, payload).then(r => r.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-toolbox-talks', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));
  const setSelect = (k: keyof typeof form) =>
    (e: { target: { value: string } }) => setForm(f => ({ ...f, [k]: e.target.value }));

  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    border: '1px solid var(--border)',
    color: 'var(--text-primary)',
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-lg max-h-[90vh] rounded-2xl overflow-hidden flex flex-col" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4 flex-shrink-0" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {isEdit ? 'Edit Toolbox Talk' : 'New Toolbox Talk'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => {
          e.preventDefault();
          if (!form.talk_date) return;
          mutation.mutate(form);
        }} className="flex flex-col flex-1 min-h-0">
        <div className="p-6 space-y-4 flex-1 min-h-0 overflow-y-auto ss-scrollbar">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Topic / Title *</label>
            <input value={form.title} onChange={set('title')} required placeholder="e.g. Working at Height"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Date *</label>
              <DatePicker value={form.talk_date} onChange={v => setForm(f => ({ ...f, talk_date: v }))} required
                error={!form.talk_date ? 'Required' : undefined} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Start time</label>
              <input type="time" value={form.started_at ?? ''} onChange={set('started_at')}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Location</label>
              <input value={form.location ?? ''} onChange={set('location')} placeholder="e.g. Site compound"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Attendees *</label>
              <input type="number" min={0} value={form.attendee_count} onChange={set('attendee_count')} required
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Delivered by</label>
            <input value={form.delivered_by_name ?? ''} onChange={set('delivered_by_name')} placeholder="Presenter name (SureSign user or external)"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            <p className="mt-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Enter the presenter&rsquo;s name — an in-platform team member, subcontractor supervisor, or external H&amp;S adviser.
            </p>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Trade / Subcontractor / Team</label>
            <input value={form.trade_or_subcontractor ?? ''} onChange={set('trade_or_subcontractor')} placeholder="e.g. Groundworks, ABC Scaffolding"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Key points / summary</label>
            <textarea value={form.summary ?? ''} onChange={set('summary')} rows={3} placeholder="What was covered in the briefing…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
            <Select value={form.status} onChange={setSelect('status')} className="w-full">
              {STATUSES.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
            </Select>
          </div>
          {mutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>
          )}
          {isEdit && (
            <EvidenceSection
              attachmentsUrl={`/projects/${projectId}/toolbox-talks/${talk.id}/attachments`}
              queryKey={['toolbox-talk-attachments', talk.id]}
              label="Evidence (attendance sheet, photos)"
            />
          )}
        </div>
        <div className="flex justify-end gap-3 p-6 pt-4 flex-shrink-0" style={{ borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm hover:brightness-95 transition-[filter]"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60 hover:brightness-95 transition-[filter]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Talk'}
            </button>
        </div>
        </form>
      </div>
    </div>
  );
}
