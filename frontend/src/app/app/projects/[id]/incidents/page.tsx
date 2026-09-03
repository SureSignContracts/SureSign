'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDateTime } from '@/lib/dateTime';
import { ShieldAlert, Plus, Search } from 'lucide-react';
import toast from '@/lib/toast';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import IncidentModal, { type IncidentRecord } from '@/components/incidents/IncidentModal';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';

const TYPE_LABELS: Record<string, string> = { accident: 'Accident', incident: 'Incident', near_miss: 'Near Miss' };
const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:   { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  closed: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};
const REPORTABILITY_LABELS: Record<string, string> = { unknown: 'Unknown', not_reportable: 'Not reportable', reportable: 'Reportable' };

/** Tri-state: null must never render as "No injury." */
function injuryLabel(value: boolean | null): string {
  if (value === true) return 'Injury occurred';
  if (value === false) return 'No injury';
  return 'Not confirmed';
}

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * Mirrors the Site Inductions page's structure. No evidence/attachment
 * UI — deliberately not implemented in V1.
 */
function ProjectIncidentsPage() {
  const { id } = useParams<{ id: string }>();
  const { canManageIncidents: canWrite } = useProjectPermissions();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [modal, setModal] = useState<{ open: boolean; incident?: IncidentRecord }>({ open: false });
  const [deleteTarget, setDeleteTarget] = useState<IncidentRecord | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-incidents', id],
    queryFn: () => api.get(`/projects/${id}/incidents`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (incidentId: number) => api.delete(`/projects/${id}/incidents/${incidentId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-incidents', id] });
      qc.invalidateQueries({ queryKey: ['project-activities', id] });
      setDeleteTarget(null);
      toast.success('Incident deleted');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, "Couldn't delete this incident. Please try again.")),
  });

  const allIncidents: IncidentRecord[] = data?.data ?? [];

  const incidents = allIncidents.filter((i: IncidentRecord) =>
    i.title?.toLowerCase().includes(search.toLowerCase()) ||
    i.location?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {modal.open && (
        <IncidentModal projectId={id!} incident={modal.incident} onClose={() => setModal({ open: false })} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete the {TYPE_LABELS[deleteTarget.type]?.toLowerCase() ?? 'incident'} &ldquo;{deleteTarget.title}&rdquo;? This cannot be undone.
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
        title="Incidents"
        description="Accidents, incidents, and near misses — a privacy-minimal safety record for the project."
        icon={ShieldAlert}
        action={canWrite ? (
          <button
            onClick={() => setModal({ open: true })}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> Record incident
          </button>
        ) : undefined}
      />

      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search incidents…"
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
          <ShieldAlert size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load incidents</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">
            Try again
          </Button>
        </div>
      ) : incidents.length === 0 ? (
        <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
          <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
            <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
              <ShieldAlert size={38} strokeWidth={1.5} />
            </div>
          </div>
          <div className="flex flex-col items-start justify-center p-8 sm:p-10">
            <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>No incidents recorded</h2>
            <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
              Record an accident, incident, or near miss to keep a reliable, auditable safety trail for the project.
            </p>
            {canWrite && (
              <Button onClick={() => setModal({ open: true })} size="sm" className="mt-5">
                <Plus size={14} /> Record first incident
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[820px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Date / Time', 'Type', 'Title', 'Location', 'Injury', 'Reportability', 'Status', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {incidents.map((i: IncidentRecord) => {
                const badge = STATUS_COLORS[i.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={i.id} className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => setModal({ open: true, incident: i })}>
                    <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDateTime(i.occurred_at)}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{TYPE_LABELS[i.type] ?? i.type}</td>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{i.title}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{i.location || '—'}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: i.injury_occurred === true ? '#f87171' : 'var(--text-secondary)' }}>{injuryLabel(i.injury_occurred)}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{REPORTABILITY_LABELS[i.regulatory_reportability] ?? i.regulatory_reportability}</td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: badge.bg, color: badge.text }}>{i.status}</span>
                    </td>
                    <td className="px-5 py-3">
                      {canWrite && (
                        <div className="flex gap-2">
                          <button onClick={e => { e.stopPropagation(); setModal({ open: true, incident: i }); }}
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

export default function GatedProjectIncidentsPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.incidents" title="Incidents" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectIncidentsPage />
    </FeatureAvailabilityGate>
  );
}
