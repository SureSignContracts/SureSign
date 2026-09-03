'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';
import EvidenceSection from '@/components/documents/EvidenceSection';
import PlantDeploymentsEditor from './PlantDeploymentsEditor';

const STATUSES = ['active', 'inactive'] as const;

export interface PlantItemRecord {
  id: number;
  name: string;
  type: string;
  identifier?: string | null;
  owner_supplier?: string | null;
  status: string;
}

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. Manages the
 * reusable Plant Item identity AND its site-presence periods in one
 * coherent modal — deliberately no separate navigation page for
 * deployments. No inspection status/next-inspection-date field —
 * that belongs to a future Statutory Inspections phase (R1E.2E).
 */
export default function PlantItemModal({ projectId, plantItem, readOnly, onClose }: {
  projectId: string;
  plantItem?: PlantItemRecord;
  readOnly: boolean;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!plantItem;

  const [form, setForm] = useState({
    name:            plantItem?.name ?? '',
    type:            plantItem?.type ?? '',
    identifier:      plantItem?.identifier ?? '',
    owner_supplier:  plantItem?.owner_supplier ?? '',
    status:          plantItem?.status ?? 'active',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => isEdit
      ? api.put(`/projects/${projectId}/plant-items/${plantItem.id}`, data).then(r => r.data)
      : api.post(`/projects/${projectId}/plant-items`, data).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-plant-items', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      onClose();
    },
  });

  const deleteMutation = useMutation({
    mutationFn: () => api.delete(`/projects/${projectId}/plant-items/${plantItem!.id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-plant-items', projectId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement>) =>
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
            {readOnly ? 'Plant Item' : isEdit ? 'Edit Plant Item' : 'New Plant Item'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutation.mutate(form); }} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
          <fieldset disabled={readOnly} className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Name *</label>
                <input value={form.name} onChange={set('name')} required placeholder="e.g. Tower Crane A"
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Type *</label>
                <input value={form.type} onChange={set('type')} required placeholder="e.g. Tower Crane, MEWP"
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Identifier</label>
                <input value={form.identifier ?? ''} onChange={set('identifier')} placeholder="Registration / fleet no."
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Owner / Supplier</label>
                <input value={form.owner_supplier ?? ''} onChange={set('owner_supplier')} placeholder="Optional"
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Register Status</label>
              <Select value={form.status} onChange={setSelect('status')} className="w-full">
                {STATUSES.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
              </Select>
              <p className="mt-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                Register status only — this does not represent whether the item is currently on site. Record that below.
              </p>
            </div>
          </fieldset>
          {mutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>
          )}
          {!readOnly && (
            <div className="flex justify-between items-center gap-3 pt-2">
              {isEdit ? (
                <button type="button" onClick={() => deleteMutation.mutate()} disabled={deleteMutation.isPending}
                  className="text-xs" style={{ color: '#f87171' }}>Delete Plant Item</button>
              ) : <span />}
              <div className="flex gap-3">
                <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
                <button type="submit" disabled={mutation.isPending}
                  className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                  {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Item'}
                </button>
              </div>
            </div>
          )}
          {deleteMutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(deleteMutation.error, 'Failed to delete this plant item.')}</p>
          )}
        </form>
        {isEdit && (
          <div className="px-6 pb-6 space-y-5">
            <PlantDeploymentsEditor projectId={projectId} plantItemId={plantItem.id} readOnly={readOnly} />
            <EvidenceSection
              attachmentsUrl={`/projects/${projectId}/plant-items/${plantItem.id}/attachments`}
              queryKey={['plant-item-attachments', plantItem.id]}
              label="Evidence (certificates, photos, manuals)"
            />
          </div>
        )}
      </div>
    </div>
  );
}
