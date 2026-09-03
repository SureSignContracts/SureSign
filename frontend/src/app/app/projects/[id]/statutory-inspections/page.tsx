'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { ClipboardCheck, Plus, Search } from 'lucide-react';
import toast from '@/lib/toast';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import StatutoryInspectionModal, { type StatutoryInspectionRecord } from '@/components/statutoryInspections/StatutoryInspectionModal';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';

const OUTCOME_COLORS: Record<string, { bg: string; text: string }> = {
  satisfactory: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  issues_found: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};
const OUTCOME_LABELS: Record<string, string> = { satisfactory: 'Satisfactory', issues_found: 'Issues Found' };
const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:   { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  closed: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

/**
 * Friday Pack Realignment, R1E.2E — Statutory Inspections. Mirrors the
 * H&S Inspections page's structure. Outcome and Status are shown as two
 * separate badges — never visually merged. `Next Due` is shown as a
 * plain recorded date only — never as an "overdue"/compliance judgement.
 * Plant / Equipment shows an em dash for a non-plant subject — no fake
 * Plant Item is ever implied.
 */
function ProjectStatutoryInspectionsPage() {
  const { id } = useParams<{ id: string }>();
  const { canManageStatutoryInspections: canWrite } = useProjectPermissions();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [modal, setModal] = useState<{ open: boolean; inspection?: StatutoryInspectionRecord }>({ open: false });
  const [deleteTarget, setDeleteTarget] = useState<StatutoryInspectionRecord | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-statutory-inspections', id],
    queryFn: () => api.get(`/projects/${id}/statutory-inspections`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (inspectionId: number) => api.delete(`/projects/${id}/statutory-inspections/${inspectionId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-statutory-inspections', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
      setDeleteTarget(null);
      toast.success('Inspection deleted');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, "Couldn't delete this inspection. Please try again.")),
  });

  const allInspections: StatutoryInspectionRecord[] = data?.data ?? [];

  const inspections = allInspections.filter((i: StatutoryInspectionRecord) =>
    i.inspection_type?.toLowerCase().includes(search.toLowerCase()) ||
    i.subject_description?.toLowerCase().includes(search.toLowerCase()) ||
    i.reference?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {modal.open && (
        <StatutoryInspectionModal projectId={id!} inspection={modal.inspection} onClose={() => setModal({ open: false })} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="ss-animate-in w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete the {deleteTarget.inspection_type} inspection for {formatDate(deleteTarget.inspection_date)}? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeleteTarget(null)} className="px-3 py-1.5 rounded-lg text-sm transition-all active:scale-[0.98] hover:bg-[var(--bg-hover)]" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => deleteMutation.mutate(deleteTarget.id)}
                disabled={deleteMutation.isPending}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white transition-all active:scale-[0.98] hover:opacity-90 disabled:opacity-70"
                style={{ backgroundColor: '#a11a1a' }}
              >
                {deleteMutation.isPending ? 'Deleting…' : 'Confirm'}
              </button>
            </div>
          </div>
        </div>
      )}

      <ProjectModuleHeader
        category="Health & Safety"
        title="Statutory inspections"
        description="Statutory inspection and check events — plant/equipment-linked or standalone (scaffold, temporary works, other site checks)."
        icon={ClipboardCheck}
        action={canWrite ? (
          <button
            onClick={() => setModal({ open: true })}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> Record inspection
          </button>
        ) : undefined}
      />

      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search inspections…"
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
          <ClipboardCheck size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load statutory inspections</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">
            Try again
          </Button>
        </div>
      ) : inspections.length === 0 ? (
        <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
          <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
            <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
              <ClipboardCheck size={38} strokeWidth={1.5} />
            </div>
          </div>
          <div className="flex flex-col items-start justify-center p-8 sm:p-10">
            <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>No statutory inspections recorded</h2>
            <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
              Record a statutory inspection or check to keep a reliable, auditable trail for the project — plant/equipment-linked or standalone.
            </p>
            {canWrite && (
              <Button onClick={() => setModal({ open: true })} size="sm" className="mt-5">
                <Plus size={14} /> Record first inspection
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[980px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Date', 'Inspection', 'Subject', 'Plant / Equipment', 'Reference', 'Outcome', 'Status', 'Next Due', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {inspections.map((i: StatutoryInspectionRecord) => {
                const outcomeBadge = OUTCOME_COLORS[i.outcome] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                const statusBadge = STATUS_COLORS[i.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={i.id} className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => setModal({ open: true, inspection: i })}>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(i.inspection_date)}</td>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{i.inspection_type}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{i.subject_description}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      {i.plant_item ? `${i.plant_item.name}${i.plant_item.identifier ? ` (${i.plant_item.identifier})` : ''}` : '—'}
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{i.reference || '—'}</td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: outcomeBadge.bg, color: outcomeBadge.text }}>{OUTCOME_LABELS[i.outcome] ?? i.outcome}</span>
                    </td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: statusBadge.bg, color: statusBadge.text }}>{i.status}</span>
                    </td>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{i.next_due_date ? formatDate(i.next_due_date) : '—'}</td>
                    <td className="px-5 py-3">
                      {canWrite && (
                        <div className="flex gap-2">
                          <button onClick={e => { e.stopPropagation(); setModal({ open: true, inspection: i }); }}
                            className="text-xs hover:underline" style={{ color: 'var(--text-muted)' }}>Edit</button>
                          <button onClick={e => { e.stopPropagation(); setDeleteTarget(i); }}
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

export default function GatedProjectStatutoryInspectionsPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.statutory_inspections" title="Statutory Inspections" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectStatutoryInspectionsPage />
    </FeatureAvailabilityGate>
  );
}
