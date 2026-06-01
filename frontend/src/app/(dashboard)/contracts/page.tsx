'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { FileText, Plus, Search, Building2, DollarSign, Calendar } from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';

const statusColors: Record<string, string> = {
  draft:     'rgba(90,86,82,0.3)',
  active:    'rgba(34,197,94,0.15)',
  completed: 'rgba(59,130,246,0.15)',
  terminated:'rgba(239,68,68,0.15)',
};
const statusText: Record<string, string> = {
  draft:     '#9a9490',
  active:    '#4ade80',
  completed: '#60a5fa',
  terminated:'#f87171',
};

export default function ContractsPage() {
  const formatCurrency = useCurrencyFormatter();
  const [search, setSearch] = useState('');
  const [showNew, setShowNew] = useState(false);
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['contracts'],
    queryFn: () => api.get('/contracts').then(r => r.data),
  });

  const contracts = (data?.data ?? []).filter((c: any) =>
    c.title?.toLowerCase().includes(search.toLowerCase()) ||
    c.contract_number?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-7xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Contracts</h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>Manage all project contracts</p>
        </div>
        <button
          onClick={() => setShowNew(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={16} />
          New Contract
        </button>
      </div>

      {/* Search */}
      <div className="relative mb-6">
        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search contracts..."
          className="w-full pl-9 pr-4 py-2.5 rounded-lg text-sm outline-none"
          style={{
            backgroundColor: 'var(--bg-elevated)',
            border: '1px solid var(--border)',
            color: 'var(--text-primary)',
          }}
        />
      </div>

      {/* List */}
      {isLoading ? (
        <div className="space-y-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : contracts.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20">
          <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
               style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <FileText size={24} style={{ color: 'var(--text-muted)' }} />
          </div>
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>No contracts yet</p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Contracts linked to projects will appear here</p>
        </div>
      ) : (
        <div className="space-y-3">
          {contracts.map((contract: any) => (
            <div key={contract.id}
              className="flex items-center gap-4 p-4 rounded-xl border cursor-pointer hover:border-[var(--gold)] transition-colors"
              style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)' }}
            >
              <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                   style={{ backgroundColor: 'rgba(185,149,102,0.1)' }}>
                <FileText size={18} style={{ color: 'var(--gold)' }} />
              </div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-3">
                  <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{contract.title}</p>
                  <span className="text-xs px-2 py-0.5 rounded-full flex-shrink-0"
                        style={{ backgroundColor: statusColors[contract.status] || statusColors.draft, color: statusText[contract.status] || statusText.draft }}>
                    {contract.status}
                  </span>
                </div>
                <div className="flex items-center gap-4 mt-1">
                  <span className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                    <Building2 size={11} />{contract.contract_number}
                  </span>
                  {contract.contract_value && (
                    <span className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                      <DollarSign size={11} />{formatCurrency(contract.contract_value)}
                    </span>
                  )}
                  {contract.start_date && (
                    <span className="text-xs flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                      <Calendar size={11} />{formatDate(contract.start_date)}
                    </span>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {showNew && (
        <NewContractModal
          onClose={() => setShowNew(false)}
          onCreated={() => { setShowNew(false); qc.invalidateQueries({ queryKey: ['contracts'] }); }}
        />
      )}
    </div>
  );
}

function NewContractModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const [form, setForm] = useState({
    title: '',
    contract_number: '',
    contract_type: 'construct_only',
    contract_value: '',
    start_date: '',
    end_date: '',
  });
  const [error, setError] = useState('');

  const mutation = useMutation({
    mutationFn: (payload: typeof form) => api.post('/contracts', payload),
    onSuccess: onCreated,
    onError: (err: any) => setError(err.response?.data?.message || 'Failed to create contract.'),
  });

  function field(label: string, key: keyof typeof form, type = 'text', placeholder = '') {
    return (
      <div>
        <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>{label}</label>
        <input
          type={type}
          placeholder={placeholder}
          value={form[key]}
          onChange={e => setForm(f => ({ ...f, [key]: e.target.value }))}
          className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4"
         style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}
         onClick={onClose}>
      <div className="w-full max-w-lg rounded-2xl p-6 space-y-5"
           style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }}
           onClick={e => e.stopPropagation()}>
        <div>
          <h2 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>New Contract</h2>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Create a new contract record</p>
        </div>
        {error && (
          <p className="text-xs px-3 py-2 rounded-lg"
             style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}>
            {error}
          </p>
        )}
        <div className="space-y-4">
          {field('Contract Title *', 'title', 'text', 'e.g. Head Contract – Stage 1')}
          <div className="grid grid-cols-2 gap-3">
            {field('Contract Number', 'contract_number', 'text', 'e.g. HC-001')}
            {field('Contract Value (AUD)', 'contract_value', 'number', '0.00')}
          </div>
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Contract Type</label>
            <select
              value={form.contract_type}
              onChange={e => setForm(f => ({ ...f, contract_type: e.target.value }))}
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none appearance-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              <option value="construct_only">Construct Only</option>
              <option value="design_construct">Design &amp; Construct</option>
              <option value="epc">EPC</option>
              <option value="cost_plus">Cost Plus</option>
              <option value="lump_sum">Lump Sum</option>
              <option value="subcontract">Subcontract</option>
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            {field('Start Date', 'start_date', 'date')}
            {field('End Date', 'end_date', 'date')}
          </div>
        </div>
        <div className="flex gap-3 pt-1">
          <button type="button" onClick={onClose}
            className="flex-1 py-2.5 rounded-lg text-sm font-medium"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => mutation.mutate(form)}
            disabled={!form.title || mutation.isPending}
            className="flex-1 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            {mutation.isPending ? 'Creating...' : 'Create Contract'}
          </button>
        </div>
      </div>
    </div>
  );
}

