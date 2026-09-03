'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, X } from 'lucide-react';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment site-presence
 * periods. ONE ROW = ONE CONTINUOUS PHYSICAL PRESENCE PERIOD — the
 * backend (App\Services\Plant\PlantDeploymentService) is the sole
 * authority on overlap validation; this editor surfaces its 409 response
 * as a plain error, never re-implements the overlap check itself. An
 * open deployment (no Off Site At) is shown as factual "On site" —
 * never "certified"/"inspected"/"compliant".
 */

interface Deployment {
  id: number;
  on_site_from: string;
  off_site_at: string | null;
  notes: string | null;
}

export default function PlantDeploymentsEditor({ projectId, plantItemId, readOnly }: {
  projectId: string; plantItemId: number; readOnly: boolean;
}) {
  const qc = useQueryClient();
  const queryKey = ['plant-item-deployments', projectId, plantItemId];

  const { data: deployments } = useQuery<Deployment[]>({
    queryKey,
    queryFn: () => api.get(`/projects/${projectId}/plant-items/${plantItemId}/deployments`).then(r => r.data),
  });

  const rows = deployments ?? [];

  const [onSiteFrom, setOnSiteFrom] = useState('');
  const [offSiteAt, setOffSiteAt] = useState('');
  const [notes, setNotes] = useState('');

  const invalidate = () => {
    qc.invalidateQueries({ queryKey });
    qc.invalidateQueries({ queryKey: ['project-plant-items', projectId] });
  };

  const addMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/plant-items/${plantItemId}/deployments`, {
      on_site_from: onSiteFrom, off_site_at: offSiteAt || null, notes: notes || null,
    }),
    onSuccess: () => {
      invalidate();
      setOnSiteFrom(''); setOffSiteAt(''); setNotes('');
      toast.success('Site presence period added');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to add site presence period')),
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/projects/${projectId}/plant-items/${plantItemId}/deployments/${id}`),
    onSuccess: () => { invalidate(); toast.success('Site presence period removed'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to remove site presence period')),
  });

  return (
    <div>
      <label className="block text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Site Presence</label>

      {rows.length > 0 && (
        <div className="space-y-2 mb-3">
          {rows.map(d => (
            <div key={d.id} className="flex items-center gap-2 text-xs rounded-lg px-3 py-2" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <span style={{ color: 'var(--text-primary)' }}>
                {formatDate(d.on_site_from)} &rarr; {d.off_site_at ? formatDate(d.off_site_at) : 'On site'}
              </span>
              {d.notes && <span style={{ color: 'var(--text-muted)' }}>&middot; {d.notes}</span>}
              {!readOnly && (
                <button type="button" onClick={() => removeMutation.mutate(d.id)} aria-label="Remove site presence period" className="ml-auto p-1">
                  <X size={12} style={{ color: '#f87171' }} />
                </button>
              )}
            </div>
          ))}
        </div>
      )}

      {!readOnly && (
        <div className="flex flex-wrap items-end gap-2">
          <div>
            <label className="block text-[10px] mb-0.5" style={{ color: 'var(--text-muted)' }}>On Site From</label>
            <input type="date" value={onSiteFrom} onChange={e => setOnSiteFrom(e.target.value)}
              className="px-2.5 py-1.5 rounded-lg text-xs outline-none" style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div>
            <label className="block text-[10px] mb-0.5" style={{ color: 'var(--text-muted)' }}>Off Site At</label>
            <input type="date" value={offSiteAt} onChange={e => setOffSiteAt(e.target.value)}
              className="px-2.5 py-1.5 rounded-lg text-xs outline-none" style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div className="flex-1 min-w-[120px]">
            <label className="block text-[10px] mb-0.5" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <input value={notes} onChange={e => setNotes(e.target.value)} placeholder="Optional"
              className="w-full px-2.5 py-1.5 rounded-lg text-xs outline-none" style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <button
            type="button"
            onClick={() => onSiteFrom && addMutation.mutate()}
            disabled={addMutation.isPending || !onSiteFrom}
            className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
          >
            <Plus size={12} /> Add
          </button>
        </div>
      )}
      {addMutation.isError && (
        <p className="text-[11px] mt-1 text-red-400">{getErrorMessage(addMutation.error, 'Failed to add site presence period')}</p>
      )}
    </div>
  );
}
