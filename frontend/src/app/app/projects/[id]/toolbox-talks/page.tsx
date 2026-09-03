'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { ShieldCheck, Plus, Search } from 'lucide-react';
import toast from '@/lib/toast';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import ToolboxTalkModal, { type ToolboxTalkRecord } from '@/components/toolboxTalks/ToolboxTalkModal';
import { ProjectModuleHeader, ProjectModuleMetric } from '@/components/projects/ProjectModuleHeader';

// ─── Constants ───────────────────────────────────────────────────────────────

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  submitted: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  approved:  { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

const STATUSES = ['draft', 'submitted', 'approved'];

// ─── Page ────────────────────────────────────────────────────────────────────

function ProjectToolboxTalksPage() {
  const { id } = useParams<{ id: string }>();
  const { canManageToolboxTalks: canWrite } = useProjectPermissions();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [modal, setModal] = useState<{ open: boolean; talk?: ToolboxTalkRecord }>({ open: false });
  const [deleteTarget, setDeleteTarget] = useState<ToolboxTalkRecord | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-toolbox-talks', id],
    queryFn: () => api.get(`/projects/${id}/toolbox-talks`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (talkId: number) => api.delete(`/projects/${id}/toolbox-talks/${talkId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-toolbox-talks', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
      setDeleteTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, "Couldn't delete this toolbox talk. Please try again.")),
  });

  const allTalks: ToolboxTalkRecord[] = data?.data ?? [];

  const talks = allTalks.filter((t: ToolboxTalkRecord) => {
    const matchSearch =
      t.title?.toLowerCase().includes(search.toLowerCase()) ||
      t.trade_or_subcontractor?.toLowerCase().includes(search.toLowerCase()) ||
      t.delivered_by_name?.toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || t.status === statusFilter;
    return matchSearch && matchStatus;
  });

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {modal.open && (
        <ToolboxTalkModal projectId={id!} talk={modal.talk} onClose={() => setModal({ open: false })} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>Delete the toolbox talk &ldquo;{deleteTarget.title}&rdquo;? This cannot be undone.</p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeleteTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button onClick={() => deleteMutation.mutate(deleteTarget.id)} className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white" style={{ backgroundColor: '#a11a1a' }}>Confirm</button>
            </div>
          </div>
        </div>
      )}

      <ProjectModuleHeader
        category="Delivery control"
        title="Toolbox talks"
        description="Record short site safety briefings — topic, attendance and evidence in one auditable place."
        icon={ShieldCheck}
        metricColumns={3}
        action={canWrite ? (
          <button
            onClick={() => setModal({ open: true })}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> New toolbox talk
          </button>
        ) : undefined}
      >
        {STATUSES.map((s, i) => {
          const count = allTalks.filter((t: ToolboxTalkRecord) => t.status === s).length;
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
      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search toolbox talks…"
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
          <ShieldCheck size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load toolbox talks</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">
            Try again
          </Button>
        </div>
      ) : talks.length === 0 ? (
        <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
          <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
            <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
              <ShieldCheck size={38} strokeWidth={1.5} />
            </div>
          </div>
          <div className="flex flex-col items-start justify-center p-8 sm:p-10">
            <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>Build the safety record</h2>
            <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
              Record the first toolbox talk to keep a reliable, auditable trail of site safety briefings.
            </p>
            {canWrite && (
              <Button onClick={() => setModal({ open: true })} size="sm" className="mt-5">
                <Plus size={14} /> Create first talk
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Topic', 'Date', 'Delivered by', 'Trade / Team', 'Attendees', 'Status', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {talks.map((t: ToolboxTalkRecord) => {
                const badge = STATUS_COLORS[t.status ?? 'draft'] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={t.id} className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => setModal({ open: true, talk: t })}>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{t.title}</td>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{t.talk_date ? formatDate(t.talk_date) : '—'}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{t.delivered_by_name || '—'}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{t.trade_or_subcontractor || '—'}</td>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>{t.attendee_count ?? 0}</td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                        style={{ backgroundColor: badge.bg, color: badge.text }}>
                        {t.status ?? 'draft'}
                      </span>
                    </td>
                    <td className="px-5 py-3">
                      {canWrite && (
                        <div className="flex gap-2">
                          <button onClick={e => { e.stopPropagation(); setModal({ open: true, talk: t }); }}
                            className="text-xs hover:underline" style={{ color: 'var(--text-muted)' }}>Edit</button>
                          <button onClick={e => { e.stopPropagation(); setDeleteTarget(t); }}
                            className="text-xs hover:underline" style={{ color: '#f87171' }}>Delete</button>
                        </div>
                      )}
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

export default function GatedProjectToolboxTalksPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.toolbox_talks" title="Toolbox Talks" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectToolboxTalksPage />
    </FeatureAvailabilityGate>
  );
}
