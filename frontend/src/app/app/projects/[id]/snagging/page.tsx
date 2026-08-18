'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import toast from '@/lib/toast';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Package, Plus, Search, AlertCircle } from 'lucide-react';
import PageTourButton from '@/components/tours/PageTourButton';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import SnagModal from '@/components/snags/SnagModal';
import { ProjectModuleHeader, ProjectModuleMetric } from '@/components/projects/ProjectModuleHeader';

const PRIORITY_COLORS: Record<string, { bg: string; text: string }> = {
  low:      { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  medium:   { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  high:     { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  critical: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:             { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  in_progress:      { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  ready_for_review: { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  closed:           { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

const STATUSES = ['open', 'in_progress', 'ready_for_review', 'closed'];

const STATUS_LABELS: Record<string, string> = {
  open:             'Open',
  in_progress:      'In Progress',
  ready_for_review: 'Ready for Review',
  closed:           'Closed',
};

const PRIORITY_LABELS: Record<string, string> = {
  low:      'Low',
  medium:   'Medium',
  high:     'High',
  critical: 'Critical',
};

// ─── Page ────────────────────────────────────────────────────────────────────

function ProjectSnaggingPage() {
  const { id } = useParams<{ id: string }>();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modal, setModal] = useState<{ open: boolean; snag?: any }>({ open: false });
  const [deleteTarget, setDeleteTarget] = useState<any | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-snagging', id],
    queryFn: () => api.get(`/projects/${id}/snagging`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (snagId: number) => api.delete(`/projects/${id}/snagging/${snagId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-snagging', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
      setDeleteTarget(null);
    },
    // Previously had no onError — a failed delete just left the confirm
    // dialog open with no explanation of why.
    onError: (e: unknown) => toast.error(getErrorMessage(e, "Couldn't delete this snag item. Please try again.")),
  });

  const allItems: any[] = data?.data ?? [];

  const items = allItems.filter((s: any) => {
    const matchSearch =
      s.title?.toLowerCase().includes(search.toLowerCase()) ||
      s.description?.toLowerCase().includes(search.toLowerCase()) ||
      s.location?.toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || s.status === statusFilter;
    return matchSearch && matchStatus;
  });

  const openCount      = allItems.filter((s: any) => s.status === 'open').length;
  const inProgressCount = allItems.filter((s: any) => s.status === 'in_progress').length;
  const closedCount    = allItems.filter((s: any) => s.status === 'closed').length;

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {modal.open && (
        <SnagModal projectId={id} snag={modal.snag} onClose={() => setModal({ open: false })} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>Delete Snag #{deleteTarget.snag_number ?? deleteTarget.id}? This cannot be undone.</p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeleteTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button onClick={() => deleteMutation.mutate(deleteTarget.id)} className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white" style={{ backgroundColor: '#a11a1a' }}>Confirm</button>
            </div>
          </div>
        </div>
      )}

      <ProjectModuleHeader
        category="Delivery control"
        title="Snagging"
        description="Capture defects, assign responsibility and follow every item through to closure."
        icon={Package}
        tour={<PageTourButton tourKey="page-snagging" label="Take a tour of this page" />}
        action={(
          <button
            data-tour="snagging-new"
            onClick={() => setModal({ open: true })}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> Add snag
          </button>
        )}
      >
        {[
          { label: 'Total',       value: allItems.length, color: 'var(--gold)' },
          { label: 'Open',        value: openCount,       color: '#f87171' },
          { label: 'In Progress', value: inProgressCount, color: '#60a5fa' },
          { label: 'Closed',      value: closedCount,     color: '#4ade80' },
        ].map(({ label, value, color }, i) => (
          <ProjectModuleMetric key={label} label={label} value={value} tone={color} index={i} />
        ))}
      </ProjectModuleHeader>

      {/* Filters */}
      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" data-tour="snagging-filters" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search snag items…"
            className="h-10 w-full rounded-xl bg-[var(--bg-elevated)] pl-9 pr-4 text-sm outline-none transition-colors focus:ring-2 focus:ring-[var(--gold)]/30"
            style={{ color: 'var(--text-primary)' }}
          />
        </div>
        <div className="flex gap-1 overflow-x-auto rounded-xl bg-[var(--bg-elevated)] p-1">
          {(['all', ...STATUSES] as const).map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-medium transition-all active:scale-[0.97]"
              style={statusFilter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }
              }>
              {s === 'all' ? 'All' : STATUS_LABELS[s] ?? s}
            </button>
          ))}
        </div>
      </div>

      {/* Items list */}
      <div className="space-y-2" data-tour="snagging-list">
        {isLoading ? (
          [...Array(5)].map((_, i) => (
            <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))
        ) : isError ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <Package size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load snagging items</p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
            <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">
              Try again
            </Button>
          </div>
        ) : items.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <Package size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No snag items found</p>
            <Button onClick={() => setModal({ open: true })} variant="secondary" size="sm" className="mt-4">
              Add First Snag
            </Button>
          </div>
        ) : items.map((s: any, i: number) => {
          const statusBadge   = STATUS_COLORS[s.status]    ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          const priorityBadge = PRIORITY_COLORS[s.priority] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div key={s.id} className="ss-animate-in flex items-center justify-between p-4 rounded-xl transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] hover:-translate-y-0.5"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${Math.min(i * 45, 360)}ms` }}>
              <div className="flex items-center gap-4 flex-1 min-w-0">
                <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                  style={{ backgroundColor: 'rgba(239,68,68,0.08)' }}>
                  <AlertCircle size={15} style={{ color: '#ef4444' }} />
                </div>
                <div className="min-w-0">
                  <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                    <span className="font-mono text-[11px] mr-2" style={{ color: 'var(--text-muted)' }}>#{s.snag_number}</span>
                    {s.title}
                  </p>
                  <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>
                    {s.location ? `${s.location} · ` : ''}
                    {s.category ? `${s.category} · ` : ''}
                    <span className="tabular-nums">{s.created_at ? formatDate(s.created_at) : ''}</span>
                    {s.assignee ? ` · ${s.assignee.name}` : ''}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-2 flex-shrink-0">
                <span className="text-xs px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: priorityBadge.bg, color: priorityBadge.text }}>
                  {PRIORITY_LABELS[s.priority] ?? s.priority ?? 'Medium'}
                </span>
                <span className="text-xs px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: statusBadge.bg, color: statusBadge.text }}>
                  {STATUS_LABELS[s.status] ?? s.status}
                </span>
                <button onClick={() => setModal({ open: true, snag: s })}
                  className="px-3 py-1 rounded-lg text-xs hover:bg-[var(--bg-hover)] transition-colors"
                  style={{ color: 'var(--text-muted)' }}>Edit</button>
                <button
                  onClick={() => setDeleteTarget(s)}
                  className="px-3 py-1 rounded-lg text-xs hover:bg-[var(--bg-hover)] transition-colors"
                  style={{ color: '#f87171' }}>Delete</button>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export default function GatedProjectSnaggingPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.snagging" title="Snagging" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectSnaggingPage />
    </FeatureAvailabilityGate>
  );
}
