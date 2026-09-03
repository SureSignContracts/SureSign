'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, X } from 'lucide-react';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';

/**
 * Friday Pack Realignment, R1C — Workforce. Optional, supplementary
 * trade/role breakdown against a single Site Report. `workersOnSite`
 * (the existing simple field) remains the authoritative overall daily
 * headcount — this editor never changes it, and a mismatch between the
 * two is shown as a neutral, non-blocking observation, never an error.
 */

interface WorkforceEntry {
  id: number;
  trade_or_role: string;
  operative_count: number;
}

const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

function WorkforceRow({ entry, readOnly, onSave, onRemove }: {
  entry: WorkforceEntry; readOnly: boolean; onSave: (entry: WorkforceEntry) => void; onRemove: () => void;
}) {
  const [role, setRole] = useState(entry.trade_or_role);
  const [count, setCount] = useState(String(entry.operative_count));

  return (
    <div className="flex items-center gap-2">
      <input
        value={role}
        disabled={readOnly}
        onChange={e => setRole(e.target.value)}
        onBlur={() => !readOnly && role.trim() && onSave({ ...entry, trade_or_role: role })}
        placeholder="Trade / Role"
        className="flex-1 px-2.5 py-1.5 rounded-lg text-xs outline-none disabled:opacity-60"
        style={inputStyle}
      />
      <input
        type="number"
        min={1}
        value={count}
        disabled={readOnly}
        onChange={e => setCount(e.target.value)}
        onBlur={() => !readOnly && Number(count) > 0 && onSave({ ...entry, operative_count: Number(count) })}
        placeholder="Operatives"
        className="w-24 px-2.5 py-1.5 rounded-lg text-xs outline-none disabled:opacity-60"
        style={inputStyle}
      />
      {!readOnly && (
        <button type="button" onClick={onRemove} aria-label="Remove trade / role" className="p-1">
          <X size={13} style={{ color: '#f87171' }} />
        </button>
      )}
    </div>
  );
}

export default function WorkforceBreakdownEditor({ projectId, siteDiaryId, workersOnSite, readOnly }: {
  projectId: string; siteDiaryId: number; workersOnSite: number | null; readOnly: boolean;
}) {
  const qc = useQueryClient();
  const queryKey = ['site-diary-workforce-entries', projectId, siteDiaryId];

  const { data: entries } = useQuery<WorkforceEntry[]>({
    queryKey,
    queryFn: () => api.get(`/projects/${projectId}/site-diaries/${siteDiaryId}/workforce-entries`).then(r => r.data),
  });

  const rows = entries ?? [];
  const breakdownTotal = rows.reduce((sum, r) => sum + r.operative_count, 0);

  const [newRole, setNewRole] = useState('');
  const [newCount, setNewCount] = useState('');

  const addMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/site-diaries/${siteDiaryId}/workforce-entries`, {
      trade_or_role: newRole, operative_count: Number(newCount),
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey });
      setNewRole('');
      setNewCount('');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to add trade / role')),
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/projects/${projectId}/site-diaries/${siteDiaryId}/workforce-entries/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey }),
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to remove trade / role')),
  });

  const updateMutation = useMutation({
    mutationFn: (entry: WorkforceEntry) => api.put(`/projects/${projectId}/site-diaries/${siteDiaryId}/workforce-entries/${entry.id}`, {
      trade_or_role: entry.trade_or_role, operative_count: entry.operative_count,
    }),
    onSuccess: () => qc.invalidateQueries({ queryKey }),
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to update trade / role')),
  });

  let comparisonMessage: string | null = null;
  if (rows.length > 0 && workersOnSite != null) {
    comparisonMessage = breakdownTotal === workersOnSite
      ? 'Matched to recorded site total.'
      : `Recorded site total: ${workersOnSite} · Breakdown total: ${breakdownTotal}`;
  }

  return (
    <div>
      <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Workforce breakdown (optional)</label>

      {rows.length > 0 && (
        <div className="space-y-2 mb-2">
          {rows.map(entry => (
            <WorkforceRow key={entry.id} entry={entry} readOnly={readOnly} onSave={updated => updateMutation.mutate(updated)} onRemove={() => removeMutation.mutate(entry.id)} />
          ))}
        </div>
      )}

      {!readOnly && (
        <div className="flex items-center gap-2 mb-2">
          <input
            value={newRole}
            onChange={e => setNewRole(e.target.value)}
            placeholder="Trade / Role"
            className="flex-1 px-2.5 py-1.5 rounded-lg text-xs outline-none"
            style={inputStyle}
          />
          <input
            type="number"
            min={1}
            value={newCount}
            onChange={e => setNewCount(e.target.value)}
            placeholder="Operatives"
            className="w-24 px-2.5 py-1.5 rounded-lg text-xs outline-none"
            style={inputStyle}
          />
          <button
            type="button"
            onClick={() => newRole.trim() && newCount && addMutation.mutate()}
            disabled={addMutation.isPending || !newRole.trim() || !newCount}
            className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
          >
            <Plus size={12} /> Add
          </button>
        </div>
      )}

      {rows.length > 0 && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Breakdown total: {breakdownTotal}{comparisonMessage ? ` · ${comparisonMessage}` : ''}
        </p>
      )}
    </div>
  );
}
