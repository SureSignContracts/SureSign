'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { UserCheck, Plus, Search } from 'lucide-react';
import toast from '@/lib/toast';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import SiteInductionModal, { type SiteInductionRecord } from '@/components/siteInductions/SiteInductionModal';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. Mirrors the Toolbox
 * Talks page's structure. ONE ROW = ONE INDUCTION SESSION — never one
 * individual attendee.
 */
function ProjectSiteInductionsPage() {
  const { id } = useParams<{ id: string }>();
  const { canManageSiteInductions: canWrite } = useProjectPermissions();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [modal, setModal] = useState<{ open: boolean; induction?: SiteInductionRecord }>({ open: false });
  const [deleteTarget, setDeleteTarget] = useState<SiteInductionRecord | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-site-inductions', id],
    queryFn: () => api.get(`/projects/${id}/site-inductions`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (inductionId: number) => api.delete(`/projects/${id}/site-inductions/${inductionId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-site-inductions', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
      setDeleteTarget(null);
      toast.success('Site induction deleted');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, "Couldn't delete this site induction. Please try again.")),
  });

  const allInductions: SiteInductionRecord[] = data?.data ?? [];

  const inductions = allInductions.filter((s: SiteInductionRecord) =>
    s.session_title?.toLowerCase().includes(search.toLowerCase()) ||
    s.company_or_trade?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {modal.open && (
        <SiteInductionModal projectId={id!} induction={modal.induction} onClose={() => setModal({ open: false })} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete the site induction session for {formatDate(deleteTarget.induction_date)}? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeleteTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button onClick={() => deleteMutation.mutate(deleteTarget.id)} className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white" style={{ backgroundColor: '#a11a1a' }}>Confirm</button>
            </div>
          </div>
        </div>
      )}

      <ProjectModuleHeader
        category="Health & Safety"
        title="Site inductions"
        description="Record who was inducted onto site, by session — an auditable weekly record for Friday Packs."
        icon={UserCheck}
        action={canWrite ? (
          <button
            onClick={() => setModal({ open: true })}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> New induction session
          </button>
        ) : undefined}
      />

      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search sessions…"
            className="h-10 w-full rounded-xl bg-[var(--bg-elevated)] pl-9 pr-4 text-sm outline-none transition-colors focus:ring-2 focus:ring-[var(--gold)]/30"
            style={{ color: 'var(--text-primary)' }}
          />
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
          <UserCheck size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load site inductions</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">
            Try again
          </Button>
        </div>
      ) : inductions.length === 0 ? (
        <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
          <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
            <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
              <UserCheck size={38} strokeWidth={1.5} />
            </div>
          </div>
          <div className="flex flex-col items-start justify-center p-8 sm:p-10">
            <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>Build the induction record</h2>
            <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
              Record the first induction session to keep a reliable, auditable trail of who was inducted onto site.
            </p>
            {canWrite && (
              <Button onClick={() => setModal({ open: true })} size="sm" className="mt-5">
                <Plus size={14} /> Record first session
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[720px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Date', 'Session', 'Company / Trade', 'Number Inducted', 'Notes', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {inductions.map((s: SiteInductionRecord) => (
                <tr key={s.id} className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                  onClick={() => setModal({ open: true, induction: s })}>
                  <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(s.induction_date)}</td>
                  <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{s.session_title || '—'}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{s.company_or_trade || '—'}</td>
                  <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>{s.inductee_count}</td>
                  <td className="px-5 py-3 text-xs truncate max-w-[200px]" style={{ color: 'var(--text-muted)' }}>{s.notes || '—'}</td>
                  <td className="px-5 py-3">
                    {canWrite && (
                      <div className="flex gap-2">
                        <button onClick={e => { e.stopPropagation(); setModal({ open: true, induction: s }); }}
                          className="text-xs hover:underline" style={{ color: 'var(--text-muted)' }}>Edit</button>
                        <button onClick={e => { e.stopPropagation(); setDeleteTarget(s); }}
                          className="text-xs hover:underline" style={{ color: '#f87171' }}>Delete</button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

export default function GatedProjectSiteInductionsPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.site_inductions" title="Site Inductions" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectSiteInductionsPage />
    </FeatureAvailabilityGate>
  );
}
