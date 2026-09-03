'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';

const TYPES = ['accident', 'incident', 'near_miss'] as const;
const TYPE_LABELS: Record<string, string> = { accident: 'Accident', incident: 'Incident', near_miss: 'Near Miss' };
const INJURY_OPTIONS = [
  { value: '', label: 'Not confirmed' },
  { value: 'false', label: 'No injury' },
  { value: 'true', label: 'Injury occurred' },
];
const REPORTABILITY_OPTIONS = [
  { value: 'unknown', label: 'Unknown' },
  { value: 'not_reportable', label: 'Not reportable' },
  { value: 'reportable', label: 'Reportable' },
];
const STATUSES = ['open', 'closed'] as const;

export interface IncidentRecord {
  id: number;
  occurred_at: string;
  type: string;
  title: string;
  description?: string | null;
  location?: string | null;
  injury_occurred: boolean | null;
  regulatory_reportability: string;
  status: string;
}

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * Deliberately no evidence/attachment UI (R1E.2B's own scope limit).
 * `injury_occurred` is a genuine tri-state — the dropdown never defaults
 * to "No injury"; an unset value stays "Not confirmed" (null), never
 * silently coerced.
 */
export default function IncidentModal({ projectId, incident, onClose }: {
  projectId: string;
  incident?: IncidentRecord;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!incident;

  const [form, setForm] = useState({
    occurred_at:                incident?.occurred_at ? String(incident.occurred_at).slice(0, 16) : '',
    type:                       incident?.type ?? 'near_miss',
    title:                      incident?.title ?? '',
    description:                incident?.description ?? '',
    location:                   incident?.location ?? '',
    injury_occurred:            incident?.injury_occurred === true ? 'true' : incident?.injury_occurred === false ? 'false' : '',
    regulatory_reportability:   incident?.regulatory_reportability ?? 'unknown',
    status:                     incident?.status ?? 'open',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => {
      const payload = {
        ...data,
        injury_occurred: data.injury_occurred === '' ? null : data.injury_occurred === 'true',
      };
      return isEdit
        ? api.put(`/projects/${projectId}/incidents/${incident.id}`, payload).then(r => r.data)
        : api.post(`/projects/${projectId}/incidents`, payload).then(r => r.data);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-incidents', projectId] });
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
      <div className="ss-animate-in w-full max-w-lg rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {isEdit ? 'Edit Incident' : 'Record Incident'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutation.mutate(form); }} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Record the event and site circumstances only. Avoid unnecessary personal or medical information.
          </p>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Occurred At *</label>
              <input type="datetime-local" value={form.occurred_at} onChange={set('occurred_at')} required
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Type *</label>
              <Select value={form.type} onChange={setSelect('type')} className="w-full">
                {TYPES.map(t => <option key={t} value={t}>{TYPE_LABELS[t]}</option>)}
              </Select>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Title *</label>
            <input value={form.title} onChange={set('title')} required placeholder="e.g. Minor slip near loading area"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Description *</label>
            <textarea value={form.description ?? ''} onChange={set('description')} rows={3} required
              placeholder="What happened, and the site circumstances…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Location</label>
            <input value={form.location ?? ''} onChange={set('location')} placeholder="e.g. Level 3, Loading bay"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Injury</label>
              <Select value={form.injury_occurred} onChange={setSelect('injury_occurred')} className="w-full">
                {INJURY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Regulatory Reportability</label>
              <Select value={form.regulatory_reportability} onChange={setSelect('regulatory_reportability')} className="w-full">
                {REPORTABILITY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
              </Select>
            </div>
          </div>
          <p className="text-[11px] -mt-2" style={{ color: 'var(--text-muted)' }}>
            Reportability is your own classification only — SureSign does not determine legal reportability, and selecting &ldquo;Reportable&rdquo; does not mean a regulator has been notified.
          </p>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
            <Select value={form.status} onChange={setSelect('status')} className="w-full">
              {STATUSES.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
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
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Record Incident'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
