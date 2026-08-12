'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { X } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';

const PRIORITY_LABELS: Record<string, string> = {
  urgent: 'Urgent',
  high:   'High',
  normal: 'Normal',
  low:    'Low',
};

type RfiForm = {
  subject: string;
  description: string;
  priority: string;
  raised_date: string;
  response_due_date: string;
  programme_impact: boolean;
  programme_impact_days: string;
  cost_impact_amount: string;
};

export interface RfiRecord {
  id: number;
  rfi_number?: number;
  subject: string;
  status?: string;
}

/**
 * Drawing Phase 7B2 — extracted unchanged from rfis/page.tsx's page-local
 * NewRfiModal (create-only — RfiResponseModal is a separate, unaffected
 * component). No visual or behavioural change from the pre-extraction
 * version.
 */
export default function NewRfiModal({ projectId, onClose, onCreated }: {
  projectId: string;
  onClose: () => void;
  /** Fires only after a successful create — optional, additive to existing query invalidation/close behaviour. */
  onCreated?: (rfi: RfiRecord) => void;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<RfiForm>({
    subject: '', description: '', priority: 'normal',
    raised_date: effectiveTodayYmd(),
    response_due_date: '', programme_impact: false,
    programme_impact_days: '', cost_impact_amount: '',
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: RfiForm) => api.post(`/projects/${projectId}/rfis`, data).then(r => r.data),
    onSuccess: (created: RfiRecord) => {
      queryClient.invalidateQueries({ queryKey: ['project-rfis', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('RFI raised');
      onCreated?.(created);
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to raise RFI')),
  });

  const set = (field: keyof RfiForm, value: string | boolean) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const labelStyle = { color: 'var(--text-muted)' };
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-lg rounded-2xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New RFI</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Subject *</label>
            <input value={form.subject} onChange={e => set('subject', e.target.value)} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Description</label>
            <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Priority</label>
              <Select value={form.priority} onChange={e => set('priority', e.target.value)} className="w-full">
                {(['urgent', 'high', 'normal', 'low'] as const).map(p => (
                  <option key={p} value={p}>{PRIORITY_LABELS[p]}</option>
                ))}
              </Select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Date raised</label>
              <input type="date" value={form.raised_date} onChange={e => set('raised_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Response required by</label>
              <input type="date" value={form.response_due_date} onChange={e => set('response_due_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Cost impact</label>
              <input type="number" value={form.cost_impact_amount} onChange={e => set('cost_impact_amount', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={form.programme_impact} onChange={e => set('programme_impact', e.target.checked)}
              className="rounded" />
            <span className="text-xs" style={{ color: 'var(--text-secondary)' }}>Programme Impact</span>
          </label>
          {form.programme_impact && (
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Programme impact (days)</label>
              <input type="number" value={form.programme_impact_days} onChange={e => set('programme_impact_days', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          )}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium transition-all active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Raising…' : 'Raise RFI'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
