'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { CheckSquare, Plus, Search, X } from 'lucide-react';
import PageTourButton from '@/components/tours/PageTourButton';
import Button from '@/components/ui/Button';

// ─── Constants ───────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:  { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  open:   { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  failed: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  passed: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed: { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const STATUSES = ['draft', 'open', 'failed', 'passed', 'closed'];

// ─── Modal ───────────────────────────────────────────────────────────────────

function QaModal({ projectId, report, onClose }: { projectId: string; report?: any; onClose: () => void }) {
  const qc = useQueryClient();
  const isEdit = !!report;

  const [form, setForm] = useState({
    title:             report?.title             ?? '',
    inspection_type:   report?.inspection_type   ?? '',
    area:              report?.area              ?? '',
    inspection_date:   report?.inspection_date   ? String(report.inspection_date).slice(0, 10) : '',
    status:            report?.status            ?? 'draft',
    result:            report?.result            ?? '',
    observations:      report?.observations      ?? '',
    corrective_action: report?.corrective_action ?? '',
    follow_up_required: report?.follow_up_required ? '1' : '0',
  });

  const mutation = useMutation({
    mutationFn: (data: any) =>
      isEdit
        ? api.put(`/projects/${projectId}/qa-reports/${report.id}`, data).then(r => r.data)
        : api.post(`/projects/${projectId}/qa-reports`, data).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-qa', projectId] });
      qc.invalidateQueries({ queryKey: ['project-activities', projectId] });
      onClose();
    },
  });

  const set = (k: keyof typeof form) =>
    (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) =>
      setForm(f => ({ ...f, [k]: e.target.value }));

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
            {isEdit ? 'Edit QA Report' : 'New QA Report'}
          </h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutation.mutate({ ...form, follow_up_required: form.follow_up_required === '1' }); }} className="p-6 space-y-4 max-h-[80vh] overflow-y-auto">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Title *</label>
            <input value={form.title} onChange={set('title')} required placeholder="QA Report title"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspection type</label>
              <input value={form.inspection_type} onChange={set('inspection_type')} placeholder="e.g. Structural, M&E"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Area / location</label>
              <input value={form.area} onChange={set('area')} placeholder="e.g. Level 2"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Inspection date</label>
              <input type="date" value={form.inspection_date} onChange={set('inspection_date')}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <select value={form.status} onChange={set('status')} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {STATUSES.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
              </select>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Result</label>
            <input value={form.result} onChange={set('result')} placeholder="e.g. Pass, Fail, Conditional"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Observations</label>
            <textarea value={form.observations} onChange={set('observations')} rows={3} placeholder="Inspection findings…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Corrective action</label>
            <textarea value={form.corrective_action} onChange={set('corrective_action')} rows={2} placeholder="Required corrective actions…"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="flex items-center gap-3">
            <label className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Follow-up required</label>
            <select value={form.follow_up_required} onChange={set('follow_up_required')}
              className="px-3 py-1.5 rounded-lg text-sm outline-none" style={inputStyle}>
              <option value="0">No</option>
              <option value="1">Yes</option>
            </select>
          </div>
          {mutation.isError && <p className="text-xs text-red-400">Failed to save. Please try again.</p>}
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Report'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function ProjectQaPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modal, setModal] = useState<{ open: boolean; report?: any }>({ open: false });
  const [deleteTarget, setDeleteTarget] = useState<any | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['project-qa', id],
    queryFn: () => api.get(`/projects/${id}/qa-reports`).then(r => r.data).catch(() => ({ data: [] })),
  });

  const deleteMutation = useMutation({
    mutationFn: (reportId: number) => api.delete(`/projects/${id}/qa-reports/${reportId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-qa', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
      setDeleteTarget(null);
    },
  });

  const allReports: any[] = data?.data ?? [];

  const reports = allReports.filter((r: any) => {
    const matchSearch =
      r.title?.toLowerCase().includes(search.toLowerCase()) ||
      r.inspection_type?.toLowerCase().includes(search.toLowerCase()) ||
      r.area?.toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || r.status === statusFilter;
    return matchSearch && matchStatus;
  });

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {modal.open && (
        <QaModal projectId={id} report={modal.report} onClose={() => setModal({ open: false })} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>Delete QA Report #{deleteTarget.report_number ?? deleteTarget.id}? This cannot be undone.</p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeleteTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button onClick={() => deleteMutation.mutate(deleteTarget.id)} className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white" style={{ backgroundColor: '#a11a1a' }}>Confirm</button>
            </div>
          </div>
        </div>
      )}

      <div className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>QA reports</h1>
            <PageTourButton tourKey="page-qa" label="Take a tour of this page" />
          </div>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Quality assurance inspections and records</p>
        </div>
        <button
          data-tour="qa-new"
          onClick={() => setModal({ open: true })}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98]"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New QA Report
        </button>
      </div>

      {/* Summary */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3" data-tour="qa-summary">
        {STATUSES.map((s, i) => {
          const count = allReports.filter((r: any) => r.status === s).length;
          const badge = STATUS_COLORS[s];
          return (
            <div key={s} className="ss-animate-in rounded-xl p-3 cursor-pointer transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] hover:-translate-y-0.5"
              onClick={() => setStatusFilter(statusFilter === s ? 'all' : s)}
              style={{
                backgroundColor: statusFilter === s ? badge.bg : 'var(--bg-surface)',
                border: `1px solid ${statusFilter === s ? badge.text + '40' : 'var(--border)'}`,
                boxShadow: 'var(--shadow-card)',
                animationDelay: `${i * 50}ms`,
              }}>
              <p className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{s}</p>
              <p className="text-lg font-bold mt-0.5 tabular-nums" style={{ color: badge.text }}>{count}</p>
            </div>
          );
        })}
      </div>

      {/* Search + Filter */}
      <div className="flex gap-3 flex-wrap" data-tour="qa-filters">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search QA reports…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px', boxShadow: 'var(--shadow-card)' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {['all', ...STATUSES].map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
              style={statusFilter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }
              }>
              {s === 'all' ? 'All' : s}
            </button>
          ))}
        </div>
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-16 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : reports.length === 0 ? (
        <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <CheckSquare size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No QA reports yet</p>
          <Button onClick={() => setModal({ open: true })} variant="secondary" size="sm" className="mt-4">
            Create First Report
          </Button>
        </div>
      ) : (
        <div className="rounded-2xl overflow-x-auto" data-tour="qa-table" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['#', 'Title', 'Type', 'Area', 'Date', 'Status', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {reports.map((r: any) => {
                const badge = STATUS_COLORS[r.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={r.id} className="hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-5 py-3 font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>#{r.report_number ?? r.id}</td>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{r.title}</td>
                    <td className="px-5 py-3 text-xs capitalize" style={{ color: 'var(--text-secondary)' }}>{r.inspection_type || '—'}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{r.area || '—'}</td>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{r.inspection_date ? formatDate(r.inspection_date) : '—'}</td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                        style={{ backgroundColor: badge.bg, color: badge.text }}>
                        {r.status ?? 'draft'}
                      </span>
                    </td>
                    <td className="px-5 py-3">
                      <div className="flex gap-2">
                        <button onClick={() => setModal({ open: true, report: r })}
                          className="text-xs hover:underline" style={{ color: 'var(--text-muted)' }}>Edit</button>
                        <button onClick={() => setDeleteTarget(r)}
                          className="text-xs hover:underline" style={{ color: '#f87171' }}>Delete</button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
