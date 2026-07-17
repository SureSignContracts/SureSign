'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd, parseDateOnly } from '@/lib/dateTime';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { Plus, Search, FolderKanban, ChevronRight, X } from 'lucide-react';
import Button from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PageTourButton from '@/components/tours/PageTourButton';

const STATUS_TONE: Record<string, 'success' | 'warning' | 'info' | 'danger'> = {
  active: 'success',
  on_hold: 'warning',
  completed: 'info',
  cancelled: 'danger',
};

const CONTRACT_TYPES = ['JCT', 'NEC3', 'NEC4', 'FIDIC', 'Bespoke', 'Other'];
const WORK_TYPES = ['New Build', 'Refurbishment', 'Fitout', 'Infrastructure', 'Maintenance', 'Other'];

const EASE = 'ease-[cubic-bezier(0.32,0.72,0,1)]';
const INPUT_CLS = 'w-full rounded-lg px-3 py-2 text-sm outline-none border border-[var(--border)] focus:border-[var(--gold)] transition-colors duration-200';

/** Fraction of the programme elapsed between start and completion, for the card timeline. */
function progressPct(start?: string, end?: string): number | null {
  if (!start || !end) return null;
  const s = parseDateOnly(start).getTime();
  const e = parseDateOnly(end).getTime();
  if (isNaN(s) || isNaN(e) || e <= s) return null;
  // start/end are DATE-only fields — "now" here is "today" in the viewer's
  // effective SureSign timezone, not the raw device clock instant.
  const now = parseDateOnly(effectiveTodayYmd()).getTime();
  return Math.min(100, Math.max(0, ((now - s) / (e - s)) * 100));
}

function CreateProjectModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    name: '', code: '', contract_type: '', type: '', status: 'active',
    contract_value: '', start_date: '', end_date: '', description: '',
  });
  const [error, setError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: (data: typeof form) => api.post('/projects', data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects'] });
      onClose();
    },
    onError: (e: any) => {
      setError(e?.response?.data?.message ?? 'Failed to create project. Please check all required fields.');
    },
  });

  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }));

  // Border/focus/radius live in INPUT_CLS so :focus styles can win — inline borders would override them.
  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    color: 'var(--text-primary)',
  };

  const labelStyle = { color: 'var(--text-muted)', fontSize: '0.75rem', marginBottom: '4px', display: 'block' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div
        className="ss-animate-in w-full max-w-2xl rounded-2xl flex flex-col max-h-[90vh]"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New project</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {/* Form */}
        <div className="overflow-y-auto flex-1 px-6 py-5 space-y-4">
          {error && (
            <div className="px-4 py-3 rounded-lg text-sm" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#ef4444' }}>
              {error}
            </div>
          )}

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Project Name *</label>
              <input className={INPUT_CLS} style={inputStyle} value={form.name} onChange={e => set('name', e.target.value)} placeholder="e.g. High Street Development" />
            </div>
            <div>
              <label style={labelStyle}>Project Number / Code</label>
              <input className={INPUT_CLS} style={inputStyle} value={form.code} onChange={e => set('code', e.target.value)} placeholder="e.g. PRJ-2026-001" />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Contract Type</label>
              <select className={INPUT_CLS} style={inputStyle} value={form.contract_type} onChange={e => set('contract_type', e.target.value)}>
                <option value="">Select contract type…</option>
                {CONTRACT_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
            <div>
              <label style={labelStyle}>Type of Work</label>
              <select className={INPUT_CLS} style={inputStyle} value={form.type} onChange={e => set('type', e.target.value)}>
                <option value="">Select type of work…</option>
                {WORK_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Contract Value</label>
              <input className={INPUT_CLS} style={inputStyle} type="number" min="0" step="0.01" value={form.contract_value} onChange={e => set('contract_value', e.target.value)} placeholder="0.00" />
            </div>
            <div>
              <label style={labelStyle}>Status</label>
              <select className={INPUT_CLS} style={inputStyle} value={form.status} onChange={e => set('status', e.target.value)}>
                <option value="active">Active</option>
                <option value="on_hold">On Hold</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Start Date</label>
              <input className={INPUT_CLS} style={inputStyle} type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)} />
            </div>
            <div>
              <label style={labelStyle}>Completion Date</label>
              <input className={INPUT_CLS} style={inputStyle} type="date" value={form.end_date} onChange={e => set('end_date', e.target.value)} />
            </div>
          </div>

          <div>
            <label style={labelStyle}>Description</label>
            <textarea
              rows={3}
              className={INPUT_CLS}
              style={{ ...inputStyle, resize: 'vertical' }}
              value={form.description}
              onChange={e => set('description', e.target.value)}
              placeholder="Brief project overview…"
            />
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={() => mutation.mutate(form)}
            disabled={!form.name || mutation.isPending}
          >
            {mutation.isPending ? 'Creating…' : 'Create Project'}
          </Button>
        </div>
      </div>
    </div>
  );
}

export default function AppProjectsPage() {
  const formatCurrency = useCurrencyFormatter();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showCreate, setShowCreate] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['projects'],
    queryFn: () => api.get('/projects').then(r => r.data),
  });

  const all = data?.data ?? [];
  const counts: Record<string, number> = { all: all.length };
  for (const p of all) counts[p.status] = (counts[p.status] ?? 0) + 1;

  const projects = all.filter((p: any) => {
    const matchSearch =
      p.name?.toLowerCase().includes(search.toLowerCase()) ||
      p.code?.toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || p.status === statusFilter;
    return matchSearch && matchStatus;
  });

  return (
    <>
      {showCreate && <CreateProjectModal onClose={() => setShowCreate(false)} />}
      <div className="p-6 max-w-7xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Projects</h1>
            <PageTourButton tourKey="page-projects" label="Take a tour of this page" />
          </div>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            All construction projects for your company
          </p>
        </div>
        <Button data-tour="projects-new" onClick={() => setShowCreate(true)}>
          <Plus size={15} />
          New project
        </Button>
      </div>

      {/* Filters */}
      <div className="flex gap-3 flex-wrap" data-tour="projects-filters">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search projects…"
            className="pl-9 pr-4 py-2 rounded-xl text-sm outline-none border border-[var(--border)] focus:border-[var(--gold)] transition-colors duration-200"
            style={{ backgroundColor: 'var(--bg-surface)', color: 'var(--text-primary)', minWidth: '240px', boxShadow: 'var(--shadow-card)' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {['all', 'active', 'on_hold', 'completed', 'cancelled'].map(s => (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className={`px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all duration-200 ${EASE} active:scale-[0.97]`}
              style={statusFilter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', boxShadow: 'var(--shadow-card)' }
                : { color: 'var(--text-secondary)' }
              }
            >
              {s === 'all' ? 'All' : s.replace(/_/g, ' ')}
              {(counts[s] ?? 0) > 0 && (
                <span className="ml-1.5 tabular-nums" style={{ opacity: 0.55 }}>{counts[s]}</span>
              )}
            </button>
          ))}
        </div>
      </div>

      {/* Projects grid */}
      <div data-tour="projects-grid">
      {isLoading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="h-44 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : projects.length === 0 ? (
        <div
          className="rounded-2xl"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
        >
          <EmptyState
            icon={FolderKanban}
            title="No projects found"
            description={search ? 'Try a different search term.' : 'Create your first project to get started.'}
          />
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          {projects.map((p: any, i: number) => {
            const pct = progressPct(p.start_date, p.end_date);
            return (
              <Link
                key={p.id}
                href={`/app/projects/${p.id}/overview`}
                className={`group ss-animate-in rounded-2xl p-5 flex flex-col gap-4 transition-all duration-300 ${EASE} hover:-translate-y-0.5 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)]`}
                style={{
                  backgroundColor: 'var(--bg-surface)',
                  border: '1px solid var(--border)',
                  animationDelay: `${Math.min(i * 45, 360)}ms`,
                }}
              >
                <div className="flex items-start justify-between">
                  <div
                    className="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold"
                    style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
                  >
                    {p.name?.charAt(0)?.toUpperCase()}
                  </div>
                  <Badge tone={STATUS_TONE[p.status] ?? 'neutral'}>
                    {p.status?.replace(/_/g, ' ')}
                  </Badge>
                </div>
                <div>
                  <p className="text-sm font-semibold leading-tight" style={{ color: 'var(--text-primary)' }}>
                    {p.name}
                  </p>
                  <p className="font-mono text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                    {p.code ?? 'No code'}
                  </p>
                </div>
                {pct !== null && (
                  <div
                    className="h-1 rounded-full overflow-hidden"
                    style={{ backgroundColor: 'var(--bg-elevated)' }}
                    title={`${Math.round(pct)}% of programme elapsed`}
                  >
                    <div className="h-full rounded-full" style={{ width: `${pct}%`, backgroundColor: 'var(--gold)' }} />
                  </div>
                )}
                <div className="grid grid-cols-2 gap-2 pt-1" style={{ borderTop: '1px solid var(--border)' }}>
                  <div>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Contract Value</p>
                    <p className="text-sm font-semibold mt-0.5 tabular-nums" style={{ color: 'var(--text-primary)' }}>
                      {p.contract_value ? formatCurrency(p.contract_value) : '—'}
                    </p>
                  </div>
                  <div>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Completion</p>
                    <p className="text-sm font-semibold mt-0.5 tabular-nums" style={{ color: 'var(--text-primary)' }}>
                      {p.end_date ? formatDate(p.end_date) : '—'}
                    </p>
                  </div>
                </div>
                <div
                  className={`flex items-center justify-end gap-1 text-xs opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300 ${EASE}`}
                  style={{ color: 'var(--gold)' }}
                >
                  Open workspace <ChevronRight size={13} />
                </div>
              </Link>
            );
          })}
        </div>
      )}
      </div>
    </div>
    </>
  );
}
