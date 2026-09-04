'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';
import DatePicker from '@/components/ui/DatePicker';
import EvidenceSection from '@/components/documents/EvidenceSection';

const OUTCOME_OPTIONS = [
  { value: 'satisfactory', label: 'Satisfactory' },
  { value: 'issues_found', label: 'Issues Found' },
];
const STATUSES = ['open', 'closed'] as const;

export interface HsInspectionRecord {
  id: number;
  inspection_date: string;
  inspection_type: string;
  inspected_by: string;
  outcome: string;
  status: string;
  findings?: string | null;
  actions?: string | null;
}

/**
 * Friday Pack Realignment, R1E.2C — H&S Inspections. Mirrors
 * ToolboxTalkModal/SiteInductionModal's exact structure. Outcome
 * ("what was found") and Status ("lifecycle") are two independent
 * controls — the form never derives one from the other. "Inspected By"
 * is free text — an inspector may be an external consultant with no
 * SureSign account.
 */
export default function HsInspectionModal({ projectId, inspection, onClose }: {
  projectId: string;
  inspection?: HsInspectionRecord;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!inspection;

  const [form, setForm] = useState({
    inspection_date:  inspection?.inspection_date ? String(inspection.inspection_date).slice(0, 10) : '',
    inspection_type:  inspection?.inspection_type ?? '',
    inspected_by:     inspection?.inspected_by ?? '',
    outcome:          inspection?.outcome ?? 'satisfactory',
    status:           inspection?.status ?? 'open',
    findings:         inspection?.findings ?? '',
    actions:          inspection?.actions ?? '',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => isEdit
      ? api.put(`/projects/${projectId}/hs-inspections/${inspection.id}`, data).then(r => r.data)
      : api.post(`/projects/${projectId}/hs-inspections`, data).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-hs-inspections', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) =>
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
            {isEdit ? 'Edit H&S Inspection' : 'Record H&S Inspection'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => {
          e.preventDefault();
          // DatePicker has no native form control to drive HTML5's own `required`
          // validation — this guard preserves that behaviour explicitly.
          if (!form.inspection_date) return;
          mutation.mutate(form);
        }} className="flex flex-col flex-1 min-h-0">
        <div className="p-6 space-y-4 flex-1 min-h-0 overflow-y-auto ss-scrollbar">
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspection Date *</label>
              <DatePicker value={form.inspection_date} onChange={v => setForm(f => ({ ...f, inspection_date: v }))} required
                error={!form.inspection_date ? 'Required' : undefined} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspected By *</label>
              <input value={form.inspected_by} onChange={set('inspected_by')} required placeholder="Name (SureSign user or external)"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspection Type *</label>
            <input value={form.inspection_type} onChange={set('inspection_type')} required placeholder="e.g. General H&S Inspection, Working at Height Inspection"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Outcome *</label>
              <Select value={form.outcome} onChange={setSelect('outcome')} className="w-full">
                {OUTCOME_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <Select value={form.status} onChange={setSelect('status')} className="w-full">
                {STATUSES.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
              </Select>
            </div>
          </div>
          <p className="text-[11px] -mt-2" style={{ color: 'var(--text-muted)' }}>
            Outcome and Status are independent — an inspection can find issues and still be closed once followed up, or be satisfactory and remain open pending sign-off.
          </p>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Findings</label>
            <textarea value={form.findings ?? ''} onChange={set('findings')} rows={3} placeholder="What was observed…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Actions</label>
            <textarea value={form.actions ?? ''} onChange={set('actions')} rows={2} placeholder="Any follow-up action required…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          {mutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>
          )}
          {isEdit && (
            <EvidenceSection
              attachmentsUrl={`/projects/${projectId}/hs-inspections/${inspection.id}/attachments`}
              queryKey={['hs-inspection-attachments', inspection.id]}
              label="Evidence (inspection photos)"
            />
          )}
        </div>
        <div className="flex justify-end gap-3 p-6 pt-4 flex-shrink-0" style={{ borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm hover:brightness-95 transition-[filter]"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60 hover:brightness-95 transition-[filter]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Record Inspection'}
            </button>
        </div>
        </form>
      </div>
    </div>
  );
}
