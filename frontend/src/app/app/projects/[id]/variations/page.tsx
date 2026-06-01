'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { GitBranch, Plus, Search, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PromptActionButton from '@/components/prompts/PromptActionButton';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  pending:   { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  submitted: { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  approved:  { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  rejected:  { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  on_hold:   { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const STATUS_LABELS: Record<string, string> = {
  pending:   'Pending',
  submitted: 'Submitted',
  approved:  'Approved',
  rejected:  'Rejected',
  on_hold:   'On Hold',
};

const VARIATION_TYPES = [
  { value: 'architect_instruction', label: "Architect Instruction" },
  { value: 'engineers_instruction', label: "Engineer's Instruction" },
  { value: 'client_request',        label: "Client Request" },
  { value: 'site_instruction',      label: "Site Instruction" },
  { value: 'other',                 label: "Other" },
];

type ContractOption = { id: number; title: string; reference_number?: string | null };
type VariationForm = {
  title: string; description: string; type: string;
  quoted_amount: string; agreed_amount: string;
  variation_date: string; programme_impact_days: string;
  contract_id: string;
};

function NewVariationModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();

  const { data: contractsData } = useQuery<{ data?: ContractOption[] }>({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data),
  });
  const contracts = contractsData?.data ?? [];

  const [form, setForm] = useState<VariationForm>({
    contract_id: '', title: '', description: '', type: '',
    quoted_amount: '', agreed_amount: '',
    variation_date: new Date().toISOString().split('T')[0],
    programme_impact_days: '',
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: VariationForm) =>
      api.post(`/contracts/${data.contract_id}/variations`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-variations', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('Variation created');
      onClose();
    },
    onError: () => toast.error('Failed to create variation'),
  });

  const set = (field: keyof VariationForm, value: string) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-lg rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Variation</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Contract *</label>
            <select value={form.contract_id} onChange={e => set('contract_id', e.target.value)} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
              <option value="">Select contract…</option>
              {contracts.map(c => (
                <option key={c.id} value={c.id}>{c.title}{c.reference_number ? ` (${c.reference_number})` : ''}</option>
              ))}
            </select>
            {contracts.length === 0 && <p className="text-xs mt-1" style={{ color: '#f87171' }}>No contracts — add one first.</p>}
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Title *</label>
            <input value={form.title} onChange={e => set('title', e.target.value)} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Description</label>
            <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Type</label>
              <select value={form.type} onChange={e => set('type', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="">Select type…</option>
                {VARIATION_TYPES.map(t => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Variation Date</label>
              <input type="date" value={form.variation_date} onChange={e => set('variation_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Quoted Amount (£)</label>
              <input type="number" value={form.quoted_amount} onChange={e => set('quoted_amount', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Agreed Amount (£)</label>
              <input type="number" value={form.agreed_amount} onChange={e => set('agreed_amount', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Programme Impact (days)</label>
              <input type="number" value={form.programme_impact_days} onChange={e => set('programme_impact_days', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending || !form.contract_id} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: (isPending || !form.contract_id) ? 0.7 : 1 }}>
              {isPending ? 'Creating…' : 'Create Variation'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Edit Variation Modal ─────────────────────────────────────────────────────

function EditVariationModal({ variation, projectId, onClose }: { variation: any; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();

  const [form, setForm] = useState<VariationForm>({
    contract_id:           String(variation.contract_id ?? ''),
    title:                 variation.title ?? '',
    description:           variation.description ?? '',
    type:                  variation.type ?? '',
    quoted_amount:         String(variation.quoted_amount ?? ''),
    agreed_amount:         String(variation.agreed_amount ?? ''),
    variation_date:        variation.variation_date ? String(variation.variation_date).slice(0, 10) : '',
    programme_impact_days: String(variation.programme_impact_days ?? ''),
  });

  const STATUS_OPTIONS = [
    { value: 'pending',   label: 'Pending' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'approved',  label: 'Approved' },
    { value: 'rejected',  label: 'Rejected' },
    { value: 'on_hold',   label: 'On Hold' },
  ];
  const [status, setStatus] = useState<string>(variation.status ?? 'pending');

  const { mutate, isPending } = useMutation({
    mutationFn: (data: VariationForm & { status: string }) =>
      api.put(`/variations/${variation.id}`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-variations', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Variation updated');
      onClose();
    },
    onError: () => toast.error('Failed to update variation'),
  });

  const set = (field: keyof VariationForm, value: string) =>
    setForm(prev => ({ ...prev, [field]: value }));

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-lg rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Edit Variation</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate({ ...form, status }); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Title *</label>
            <input value={form.title} onChange={e => set('title', e.target.value)} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Description</label>
            <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Type</label>
              <select value={form.type} onChange={e => set('type', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="">Select type…</option>
                {VARIATION_TYPES.map(t => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Status</label>
              <select value={status} onChange={e => setStatus(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Variation Date</label>
              <input type="date" value={form.variation_date} onChange={e => set('variation_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Programme Impact (days)</label>
              <input type="number" value={form.programme_impact_days} onChange={e => set('programme_impact_days', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Quoted Amount (£)</label>
              <input type="number" value={form.quoted_amount} onChange={e => set('quoted_amount', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Agreed Amount (£)</label>
              <input type="number" value={form.agreed_amount} onChange={e => set('agreed_amount', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function ProjectVariationsPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [editVariation, setEditVariation] = useState<any | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['project-variations', id],
    queryFn: () => api.get(`/projects/${id}/variations`).then(r => r.data),
  });

  const variations = (data?.data ?? []).filter((v: any) => {
    const matchSearch = v.title?.toLowerCase().includes(search.toLowerCase()) || String(v.variation_number).includes(search);
    const matchStatus = statusFilter === 'all' || v.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const totalApproved = variations
    .filter((v: any) => v.status === 'approved')
    .reduce((sum: number, v: any) => sum + parseFloat(v.agreed_amount ?? v.quoted_amount ?? 0), 0);

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Variations</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Contract variations and change orders</p>
        </div>
        {canWrite && (
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New Variation
        </button>
        )}
      </div>

      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        {[
          { label: 'Total Variations', value: variations.length, color: 'var(--gold)' },
          { label: 'Pending', value: variations.filter((v: any) => v.status === 'pending' || v.status === 'submitted').length, color: '#f59e0b' },
          { label: 'Approved Value', value: formatCurrency(totalApproved), color: '#4ade80' },
        ].map(stat => (
          <div key={stat.label} className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
            <p className="text-xl font-bold mt-1" style={{ color: stat.color }}>{stat.value}</p>
          </div>
        ))}
      </div>

      {/* Filters */}
      <div className="flex gap-3 flex-wrap">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search variations…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {(['all', 'pending', 'submitted', 'approved', 'rejected'] as const).map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
              style={statusFilter === s ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
              {s === 'all' ? 'All' : STATUS_LABELS[s] ?? s}
            </button>
          ))}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Var #', 'Title', 'Type', 'Status', 'Quoted', 'Agreed', 'Date'].map(h => (
                <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(7)].map((_, j) => (
                    <td key={j} className="px-5 py-4">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : variations.length === 0 ? (
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center">
                  <GitBranch size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No variations yet</p>
                  {canWrite && (
                  <button onClick={() => setShowModal(true)} className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                    Create First Variation
                  </button>
                  )}
                </td>
              </tr>
            ) : variations.map((v: any) => {
              const badge = STATUS_COLORS[v.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
              return (
                <tr key={v.id} className="hover:bg-[var(--bg-elevated)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-5 py-3 font-mono font-semibold" style={{ color: 'var(--gold)' }}>#{v.variation_number}</td>
                  <td className="px-5 py-3 font-medium max-w-[200px] truncate" style={{ color: 'var(--text-primary)' }}>{v.title}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                    {VARIATION_TYPES.find(t => t.value === v.type)?.label ?? v.type?.replace(/_/g, ' ') ?? '—'}
                  </td>
                  <td className="px-5 py-3">
                    <span className="px-2 py-0.5 rounded-full text-xs font-medium"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                      {STATUS_LABELS[v.status] ?? v.status}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                    {v.quoted_amount ? formatCurrency(v.quoted_amount) : '—'}
                  </td>
                  <td className="px-5 py-3 text-xs font-medium" style={{ color: v.agreed_amount ? '#4ade80' : 'var(--text-muted)' }}>
                    {v.agreed_amount ? formatCurrency(v.agreed_amount) : '—'}
                  </td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                    {v.variation_date ? formatDate(v.variation_date) : '—'}
                  </td>
                  <td className="px-5 py-3">
                    {canWrite && (
                      <button onClick={() => setEditVariation(v)}
                        className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-elevated)]"
                        style={{ color: 'var(--text-muted)' }}>
                        Edit
                      </button>
                    )}
                    <PromptActionButton
                      label="Prompt"
                      module="Variations"
                      recordType="variation"
                      recordId={v.id}
                      projectId={id}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {canWrite && showModal && <NewVariationModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && editVariation && (
        <EditVariationModal variation={editVariation} projectId={id!} onClose={() => setEditVariation(null)} />
      )}
    </div>
  );
}
