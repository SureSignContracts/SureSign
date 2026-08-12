'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';
import EvidenceSection from '@/components/documents/EvidenceSection';
import DrawingLocationsSection from '@/components/drawings/DrawingLocationsSection';

const STATUSES = ['draft', 'open', 'failed', 'passed', 'closed'];

export interface QaReportRecord {
  id: number;
  report_number?: number;
  title: string;
  inspection_type?: string | null;
  area?: string | null;
  inspection_date?: string | null;
  status?: string;
  result?: string | null;
  observations?: string | null;
  corrective_action?: string | null;
  follow_up_required?: boolean;
}

/**
 * Drawing Phase 7B2 — extracted unchanged from qa/page.tsx's page-local
 * QaModal. No visual or behavioural change from the pre-extraction version.
 */
export default function QaModal({ projectId, report, onClose, onCreated }: {
  projectId: string;
  report?: QaReportRecord;
  onClose: () => void;
  /** Fires only after a successful CREATE (never on edit) — optional, additive to existing query invalidation/close behaviour. */
  onCreated?: (report: QaReportRecord) => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!report;

  const [form, setForm] = useState({
    title:             report?.title             ?? '',
    inspection_type:   report?.inspection_type   ?? '',
    area:              report?.area              ?? '',
    inspection_date:   report?.inspection_date   ? String(report.inspection_date).slice(0, 10) : '',
    status:            report?.status            ?? 'draft',
    result:            report?.result            ?? '',
    observations:      report?.observations      ?? '',
    corrective_action: report?.corrective_action ?? '',
    follow_up_required: report?.follow_up_required ? '1' : '0',
  });

  const mutation = useMutation({
    mutationFn: (data: Omit<typeof form, 'follow_up_required'> & { follow_up_required: boolean }) =>
      isEdit
        ? api.put(`/projects/${projectId}/qa-reports/${report.id}`, data).then(r => r.data)
        : api.post(`/projects/${projectId}/qa-reports`, data).then(r => r.data),
    onSuccess: (created: QaReportRecord) => {
      qc.invalidateQueries({ queryKey: ['project-qa', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      if (!isEdit) onCreated?.(created);
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));
  // Same update, narrowed to what the shared `Select` component's onChange
  // actually provides (no real DOM event, just `{ target: { value } }`) —
  // `set()` above stays typed for the real `<input>`/`<textarea>` elements.
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
            {isEdit ? 'Edit QA Report' : 'New QA Report'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutation.mutate({ ...form, follow_up_required: form.follow_up_required === '1' }); }} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Title *</label>
            <input value={form.title} onChange={set('title')} required placeholder="QA Report title"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspection type</label>
              <input value={form.inspection_type} onChange={set('inspection_type')} placeholder="e.g. Structural, M&E"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Area / location</label>
              <input value={form.area} onChange={set('area')} placeholder="e.g. Level 2"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspection date</label>
              <input type="date" value={form.inspection_date} onChange={set('inspection_date')}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <Select value={form.status} onChange={setSelect('status')} className="w-full">
                {STATUSES.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
              </Select>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Result</label>
            <input value={form.result} onChange={set('result')} placeholder="e.g. Pass, Fail, Conditional"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Observations</label>
            <textarea value={form.observations} onChange={set('observations')} rows={3} placeholder="Inspection findings…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Corrective action</label>
            <textarea value={form.corrective_action} onChange={set('corrective_action')} rows={2} placeholder="Required corrective actions…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="flex items-center gap-3">
            <label className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Follow-up required</label>
            <Select value={form.follow_up_required} onChange={setSelect('follow_up_required')} size="sm">
              <option value="0">No</option>
              <option value="1">Yes</option>
            </Select>
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
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Report'}
            </button>
          </div>
        </form>
        {isEdit && (
          <div className="px-6 pb-6 space-y-4">
            <EvidenceSection
              attachmentsUrl={`/projects/${projectId}/qa-reports/${report.id}/attachments`}
              queryKey={['qa-report-attachments', report.id]}
              label="Evidence"
            />
            <DrawingLocationsSection projectId={projectId} type="qa_report" recordId={report.id} />
          </div>
        )}
      </div>
    </div>
  );
}
