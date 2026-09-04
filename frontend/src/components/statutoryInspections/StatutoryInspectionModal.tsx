'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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

interface PlantOption {
  id: number;
  name: string;
  identifier?: string | null;
}

export interface StatutoryInspectionRecord {
  id: number;
  inspection_date: string;
  inspection_type: string;
  subject_description: string;
  plant_item_id?: number | null;
  plant_item?: PlantOption | null;
  reference?: string | null;
  outcome: string;
  status: string;
  notes?: string | null;
  next_due_date?: string | null;
}

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. Mirrors
 * HsInspectionModal's exact structure (independent Outcome/Status
 * controls, same inline explanation copy). The one addition is the
 * optional Plant & Equipment link — the selector only ever lists this
 * Project's own ACTIVE plant items as new choices (the backend's
 * PlantLinkResolver enforces the same rule server-side regardless); when
 * editing a record already linked to a since-soft-deleted PlantItem,
 * that item is injected as its own extra option (mirrors
 * CountrySelect/RegionField's `withLegacyOption()` convention) purely so
 * the currently selected value still displays correctly — it is never
 * offered as a choice for any other record.
 */
export default function StatutoryInspectionModal({ projectId, inspection, onClose }: {
  projectId: string;
  inspection?: StatutoryInspectionRecord;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!inspection;

  const { data: plantData } = useQuery({
    queryKey: ['project-plant-items', projectId],
    queryFn: () => api.get(`/projects/${projectId}/plant-items`).then(r => r.data),
  });
  const activePlantItems: PlantOption[] = (plantData?.data ?? []) as PlantOption[];

  const historicalPlantItem = inspection?.plant_item && !activePlantItems.some(p => p.id === inspection.plant_item!.id)
    ? inspection.plant_item
    : null;

  const [form, setForm] = useState({
    inspection_date:      inspection?.inspection_date ? String(inspection.inspection_date).slice(0, 10) : '',
    inspection_type:      inspection?.inspection_type ?? '',
    subject_description:  inspection?.subject_description ?? '',
    plant_item_id:        inspection?.plant_item_id ? String(inspection.plant_item_id) : '',
    reference:            inspection?.reference ?? '',
    outcome:              inspection?.outcome ?? 'satisfactory',
    status:               inspection?.status ?? 'open',
    notes:                inspection?.notes ?? '',
    next_due_date:        inspection?.next_due_date ? String(inspection.next_due_date).slice(0, 10) : '',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => {
      const payload = { ...data, plant_item_id: data.plant_item_id ? Number(data.plant_item_id) : null };
      return isEdit
        ? api.put(`/projects/${projectId}/statutory-inspections/${inspection.id}`, payload).then(r => r.data)
        : api.post(`/projects/${projectId}/statutory-inspections`, payload).then(r => r.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-statutory-inspections', projectId] });
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
            {isEdit ? 'Edit Statutory Inspection' : 'Record Statutory Inspection'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => {
          e.preventDefault();
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
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspection Type *</label>
              <input value={form.inspection_type} onChange={set('inspection_type')} required placeholder="e.g. Lifting Equipment Inspection, Scaffold Inspection"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Subject Description *</label>
            <input value={form.subject_description} onChange={set('subject_description')} required placeholder="e.g. Tower Crane A, North Elevation Scaffold, MEWP 03"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Plant / Equipment (optional)</label>
            <Select value={form.plant_item_id} onChange={setSelect('plant_item_id')} className="w-full">
              <option value="">— Not linked to a Plant &amp; Equipment item —</option>
              {historicalPlantItem && (
                <option value={String(historicalPlantItem.id)}>
                  {historicalPlantItem.name}{historicalPlantItem.identifier ? ` (${historicalPlantItem.identifier})` : ''} — historical
                </option>
              )}
              {activePlantItems.map(p => (
                <option key={p.id} value={String(p.id)}>{p.name}{p.identifier ? ` (${p.identifier})` : ''}</option>
              ))}
            </Select>
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
              Only needed for a plant/equipment-linked inspection — leave unset for scaffold, temporary works, or other non-plant subjects.
            </p>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Reference</label>
              <input value={form.reference} onChange={set('reference')} placeholder="Certificate / internal reference"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Next Due Date</label>
              <DatePicker value={form.next_due_date} onChange={v => setForm(f => ({ ...f, next_due_date: v }))} clearable />
            </div>
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
            Outcome and Status are independent — an inspection can find issues and still be closed once followed up, or be satisfactory and remain open pending sign-off. A recorded outcome never states legal compliance, certification, or safety for all purposes.
          </p>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea value={form.notes ?? ''} onChange={set('notes')} rows={3} placeholder="Concise inspection context…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          {mutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>
          )}
          {isEdit && (
            <EvidenceSection
              attachmentsUrl={`/projects/${projectId}/statutory-inspections/${inspection.id}/attachments`}
              queryKey={['statutory-inspection-attachments', inspection.id]}
              label="Evidence (certificate, check sheet, photos)"
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
