'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { parseDateOnly, formatDateOnly, toDateOnlyString, effectiveTodayYmd } from '@/lib/dateTime';
import { FileBarChart, Plus, Settings2, RefreshCw } from 'lucide-react';
import toast from '@/lib/toast';
import Button from '@/components/ui/Button';
import DatePicker from '@/components/ui/DatePicker';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import { useFridayPackEntitlement } from '@/hooks/useFridayPackEntitlement';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';
import FridayPackSettingsModal from '@/components/fridayPacks/FridayPackSettingsModal';
import { FridayPackUpgradeBanner, FridayPackUpgradeEmptyState } from '@/components/fridayPacks/FridayPackUpgradeNotice';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:             { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  ready_for_review:  { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  approved:          { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  sent:              { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  failed:            { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};

interface FridayPackListItem {
  id: number;
  week_ending: string;
  period_start: string;
  period_end: string;
  status: string;
  generated_at: string | null;
  generated_by?: { name: string } | null;
  generation_source: string;
  reviewed_at: string | null;
}

/** Never rendered as a blank/dash for a scheduled pack — a real,
 * deliberate automation event, not a data gap. */
function generatedByLabel(p: Pick<FridayPackListItem, 'generation_source' | 'generated_by'>): string {
  return p.generation_source === 'scheduled' ? 'SureSign Automation' : (p.generated_by?.name ?? '—');
}

/**
 * Finds the most recent Friday on/before today (client-side convenience
 * only — the backend is always the final authority on whether a
 * week_ending is actually a Friday, via FridayPackPeriodResolver).
 */
function mostRecentFriday(): string {
  const today = parseDateOnly(effectiveTodayYmd());
  const day = today.getDay(); // 0=Sun..6=Sat
  const diffToFriday = (day + 2) % 7; // days since last Friday (Fri=5 -> 0)
  const friday = new Date(today);
  friday.setDate(today.getDate() - diffToFriday);
  return toDateOnlyString(friday);
}

function GenerateFridayPackModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [weekEnding, setWeekEnding] = useState(mostRecentFriday());

  const mutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs`, { week_ending: weekEnding }).then(r => r.data),
    onSuccess: (pack) => {
      qc.invalidateQueries({ queryKey: ['project-friday-packs', projectId] });
      toast.success('Friday Pack generated');
      onClose();
      window.location.href = `/app/projects/${projectId}/friday-packs/${pack.id}`;
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to generate Friday Pack')),
  });

  const selectedDate = parseDateOnly(weekEnding);
  const isFriday = selectedDate.getDay() === 5;
  const periodStartDate = new Date(selectedDate);
  periodStartDate.setDate(selectedDate.getDate() - 6);
  const periodStartYmd = toDateOnlyString(periodStartDate);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-md rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Generate Friday Pack</h2>
        </div>
        <div className="p-6 space-y-4">
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Week ending (Friday)</label>
            <DatePicker
              value={weekEnding}
              onChange={setWeekEnding}
              isDateDisabled={date => date.getDay() !== 5}
              aria-label="Week ending (Friday)"
              helperText="Only Fridays are selectable."
            />
            {/* Defensive only — the picker above already disables every non-Friday day, so this
                should never actually render; kept as a safety net (e.g. a future default that
                isn't computed via mostRecentFriday()). */}
            {!isFriday && (
              <p className="mt-1 text-[11px]" style={{ color: '#f87171' }}>Week ending must fall on a Friday.</p>
            )}
          </div>
          {isFriday && (
            <div className="rounded-lg p-3 text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              Reporting period: <strong>{formatDateOnly(periodStartYmd)}</strong> &rarr; <strong>{formatDateOnly(weekEnding)}</strong>
            </div>
          )}
          {mutation.isError && (
            <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to generate. Please try again.')}</p>
          )}
        </div>
        <div className="flex justify-end gap-3 px-6 pb-6">
          <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            type="button"
            disabled={!isFriday || mutation.isPending}
            onClick={() => mutation.mutate()}
            className="px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-60"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Generating…' : 'Generate'}
          </button>
        </div>
      </div>
    </div>
  );
}

function ProjectFridayPacksPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { canManageFridayPacks: canWrite } = useProjectPermissions();
  const { entitled, isLoading: isLoadingEntitlement } = useFridayPackEntitlement(id);
  const canMutate = canWrite && entitled;
  const qc = useQueryClient();
  const [statusFilter, setStatusFilter] = useState('all');
  const [showGenerate, setShowGenerate] = useState(false);
  const [showSettings, setShowSettings] = useState(false);

  const { data, isLoading: isLoadingPacks, isError, error, refetch } = useQuery({
    queryKey: ['project-friday-packs', id, statusFilter],
    queryFn: () => api.get(`/projects/${id}/friday-packs`, { params: statusFilter !== 'all' ? { status: statusFilter } : {} }).then(r => r.data),
  });
  // Combined so the entitlement state is known before any mutation
  // control renders — never a flash of "Generate" for an Essential org.
  const isLoading = isLoadingPacks || isLoadingEntitlement;

  const regenerateMutation = useMutation({
    mutationFn: (packId: number) => api.post(`/projects/${id}/friday-packs/${packId}/regenerate`).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-friday-packs', id] });
      toast.success('Friday Pack regenerated');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to regenerate Friday Pack')),
  });

  const packs: FridayPackListItem[] = data?.data ?? [];

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {showGenerate && <GenerateFridayPackModal projectId={id!} onClose={() => setShowGenerate(false)} />}
      {showSettings && <FridayPackSettingsModal projectId={id!} onClose={() => setShowSettings(false)} />}

      <ProjectModuleHeader
        category="Reporting"
        title="Friday packs"
        description="Manually generate a frozen weekly reporting snapshot for this project."
        icon={FileBarChart}
        action={canMutate ? (
          <div className="flex gap-2">
            <button
              onClick={() => setShowSettings(true)}
              className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-medium transition-all"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              <Settings2 size={16} /> Settings
            </button>
            <button
              onClick={() => setShowGenerate(true)}
              className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
            >
              <Plus size={16} /> Generate Friday Pack
            </button>
          </div>
        ) : undefined}
      />

      {!isLoading && !entitled && packs.length > 0 && <FridayPackUpgradeBanner />}

      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" style={{ animationDelay: '100ms' }}>
        <div className="flex gap-1 overflow-x-auto rounded-xl bg-[var(--bg-elevated)] p-1">
          {['all', 'draft', 'ready_for_review', 'approved', 'sent', 'failed'].map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-medium capitalize transition-all active:scale-[0.97]"
              style={statusFilter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }
              }>
              {s === 'all' ? 'All' : s.replace(/_/g, ' ')}
            </button>
          ))}
        </div>
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-16 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : isError ? (
        <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <FileBarChart size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load Friday Packs</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">Try again</Button>
        </div>
      ) : packs.length === 0 ? (
        entitled ? (
          <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
            <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
              <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
                <FileBarChart size={38} strokeWidth={1.5} />
              </div>
            </div>
            <div className="flex flex-col items-start justify-center p-8 sm:p-10">
              <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>Generate the first Friday Pack</h2>
              <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
                Create a frozen weekly reporting snapshot from this project&rsquo;s current data.
              </p>
              {canWrite && (
                <Button onClick={() => setShowGenerate(true)} size="sm" className="mt-5">
                  <Plus size={14} /> Generate Friday Pack
                </Button>
              )}
            </div>
          </div>
        ) : (
          <FridayPackUpgradeEmptyState />
        )
      ) : (
        <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Week Ending', 'Reporting Period', 'Status', 'Generated', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {packs.map((p) => {
                const badge = STATUS_COLORS[p.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={p.id} className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => router.push(`/app/projects/${id}/friday-packs/${p.id}`)}>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{formatDate(p.week_ending)}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{formatDate(p.period_start)} &rarr; {formatDate(p.period_end)}</td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: badge.bg, color: badge.text }}>
                        {p.status.replace(/_/g, ' ')}
                      </span>
                      {p.status === 'ready_for_review' && p.reviewed_at && (
                        <span className="ml-1.5 text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(34,197,94,0.12)', color: '#4ade80' }}>
                          Reviewed
                        </span>
                      )}
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                      {p.generated_at ? formatDate(p.generated_at) : '—'} · {generatedByLabel(p)}
                    </td>
                    <td className="px-5 py-3">
                      {canMutate && p.status === 'draft' && (
                        <button
                          onClick={e => { e.stopPropagation(); regenerateMutation.mutate(p.id); }}
                          disabled={regenerateMutation.isPending}
                          className="flex items-center gap-1 text-xs hover:underline disabled:opacity-50"
                          style={{ color: 'var(--text-muted)' }}
                        >
                          <RefreshCw size={12} /> Regenerate
                        </button>
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

export default function GatedProjectFridayPacksPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.friday_packs" title="Friday Packs" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectFridayPacksPage />
    </FeatureAvailabilityGate>
  );
}
