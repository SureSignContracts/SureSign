'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { CheckSquare, Plus, Search } from 'lucide-react';
import PageTourButton from '@/components/tours/PageTourButton';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import QaModal from '@/components/qa/QaModal';
import { ProjectModuleHeader, ProjectModuleMetric } from '@/components/projects/ProjectModuleHeader';

// ─── Constants ───────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:  { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  open:   { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  failed: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  passed: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed: { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const STATUSES = ['draft', 'open', 'failed', 'passed', 'closed'];


// ─── Page ────────────────────────────────────────────────────────────────────

function ProjectQaPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modal, setModal] = useState<{ open: boolean; report?: any }>({ open: false });
  const [deleteTarget, setDeleteTarget] = useState<any | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    // The queryFn previously swallowed every failure into a fake empty
    // result (`.catch(() => ({ data: [] }))`) — a genuine load failure was
    // indistinguishable from "no QA reports exist yet". The endpoint always
    // returns 200 with a (possibly empty) paginated collection, so there is
    // no legitimate case that needs catching here.
    queryKey: ['project-qa', id],
    queryFn: () => api.get(`/projects/${id}/qa-reports`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (reportId: number) => api.delete(`/projects/${id}/qa-reports/${reportId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-qa', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
      setDeleteTarget(null);
    },
    // Previously had no onError — a failed delete just left the confirm
    // dialog open with no explanation of why.
    onError: (e: unknown) => toast.error(getErrorMessage(e, "Couldn't delete this QA report. Please try again.")),
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
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
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

      <ProjectModuleHeader
        category="Delivery control"
        title="QA reports"
        description="Record inspections, resolve quality issues and maintain an auditable assurance trail."
        icon={CheckSquare}
        metricColumns={5}
        tour={<PageTourButton tourKey="page-qa" label="Take a tour of this page" />}
        action={(
          <button
            data-tour="qa-new"
            onClick={() => setModal({ open: true })}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> New QA report
          </button>
        )}
      >
        {STATUSES.map((s, i) => {
          const count = allReports.filter((r: any) => r.status === s).length;
          const badge = STATUS_COLORS[s];
          return (
            <ProjectModuleMetric
              key={s}
              label={s}
              value={count}
              tone={badge.text}
              active={statusFilter === s}
              onClick={() => setStatusFilter(statusFilter === s ? 'all' : s)}
              index={i}
            />
          );
        })}
      </ProjectModuleHeader>

      {/* Search + Filter */}
      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" data-tour="qa-filters" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search QA reports…"
            className="h-10 w-full rounded-xl bg-[var(--bg-elevated)] pl-9 pr-4 text-sm outline-none transition-colors focus:ring-2 focus:ring-[var(--gold)]/30"
            style={{ color: 'var(--text-primary)' }}
          />
        </div>
        <div className="flex gap-1 overflow-x-auto rounded-xl bg-[var(--bg-elevated)] p-1">
          {['all', ...STATUSES].map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-medium capitalize transition-all active:scale-[0.97]"
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
      ) : isError ? (
        <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <CheckSquare size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load QA reports</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">
            Try again
          </Button>
        </div>
      ) : reports.length === 0 ? (
        <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
          <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
            <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
              <CheckSquare size={38} strokeWidth={1.5} />
            </div>
          </div>
          <div className="flex flex-col items-start justify-center p-8 sm:p-10">
            <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>Build the quality record</h2>
            <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
              Create the first inspection report to document checks, outcomes and follow-up actions in one place.
            </p>
            <Button onClick={() => setModal({ open: true })} size="sm" className="mt-5">
              <Plus size={14} /> Create first report
            </Button>
          </div>
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

export default function GatedProjectQaPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.qa" title="QA Reports" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectQaPage />
    </FeatureAvailabilityGate>
  );
}
