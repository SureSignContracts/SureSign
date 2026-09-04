'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import DatePicker from '@/components/ui/DatePicker';
import EvidenceSection from '@/components/documents/EvidenceSection';

export interface SiteInductionRecord {
  id: number;
  induction_date: string;
  session_title?: string | null;
  company_or_trade?: string | null;
  inductee_count: number;
  notes?: string | null;
}

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. Mirrors
 * ToolboxTalkModal's exact structure. ONE FORM = ONE INDUCTION SESSION —
 * no individual attendee inputs, only an aggregate headcount. Evidence
 * attaches only once the record exists, same as ToolboxTalkModal.
 */
export default function SiteInductionModal({ projectId, induction, onClose }: {
  projectId: string;
  induction?: SiteInductionRecord;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!induction;

  const [form, setForm] = useState({
    induction_date:     induction?.induction_date ? String(induction.induction_date).slice(0, 10) : '',
    session_title:       induction?.session_title ?? '',
    company_or_trade:    induction?.company_or_trade ?? '',
    inductee_count:      induction?.inductee_count != null ? String(induction.inductee_count) : '1',
    notes:               induction?.notes ?? '',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => {
      const payload = { ...data, inductee_count: Number(data.inductee_count) || 1 };
      return isEdit
        ? api.put(`/projects/${projectId}/site-inductions/${induction.id}`, payload).then(r => r.data)
        : api.post(`/projects/${projectId}/site-inductions`, payload).then(r => r.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-site-inductions', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));

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
            {isEdit ? 'Edit Site Induction' : 'New Site Induction'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => {
          e.preventDefault();
          if (!form.induction_date) return;
          mutation.mutate(form);
        }} className="flex flex-col flex-1 min-h-0">
        <div className="p-6 space-y-4 flex-1 min-h-0 overflow-y-auto ss-scrollbar">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Induction Date *</label>
              <DatePicker value={form.induction_date} onChange={v => setForm(f => ({ ...f, induction_date: v }))} required
                error={!form.induction_date ? 'Required' : undefined} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Number Inducted *</label>
              <input type="number" min={1} value={form.inductee_count} onChange={set('inductee_count')} required
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Session Title</label>
            <input value={form.session_title ?? ''} onChange={set('session_title')} placeholder="e.g. Morning Site Induction"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Company / Trade</label>
            <input value={form.company_or_trade ?? ''} onChange={set('company_or_trade')} placeholder="e.g. ABC Scaffolding"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea value={form.notes ?? ''} onChange={set('notes')} rows={3} placeholder="Anything else worth recording about this session…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          {mutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>
          )}
          {isEdit && (
            <EvidenceSection
              attachmentsUrl={`/projects/${projectId}/site-inductions/${induction.id}/attachments`}
              queryKey={['site-induction-attachments', induction.id]}
              label="Evidence (sign-in sheet, photos)"
            />
          )}
        </div>
        <div className="flex justify-end gap-3 p-6 pt-4 flex-shrink-0" style={{ borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm hover:brightness-95 transition-[filter]"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60 hover:brightness-95 transition-[filter]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Session'}
            </button>
        </div>
        </form>
      </div>
    </div>
  );
}
