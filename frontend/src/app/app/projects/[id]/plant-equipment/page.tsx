'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { HardHat, Plus, Search } from 'lucide-react';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PlantItemModal, { type PlantItemRecord } from '@/components/plantEquipment/PlantItemModal';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';

interface Deployment { id: number; on_site_from: string; off_site_at: string | null; }
interface PlantItemWithDeployments extends PlantItemRecord { deployments?: Deployment[]; }

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  active:   { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  inactive: { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

/** Current Site Presence derives from an OPEN deployment — never from PlantItem.status. */
function presenceLabel(item: PlantItemWithDeployments): string {
  const isOnSite = (item.deployments ?? []).some(d => d.off_site_at === null);
  return isOnSite ? 'On site' : 'Not currently on site';
}

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. One coherent page
 * managing both reusable Plant Items and their site-presence periods —
 * deliberately no separate Deployments navigation page.
 */
function ProjectPlantEquipmentPage() {
  const { id } = useParams<{ id: string }>();
  const { canManagePlantEquipment: canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [modal, setModal] = useState<{ open: boolean; item?: PlantItemWithDeployments }>({ open: false });

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-plant-items', id],
    queryFn: () => api.get(`/projects/${id}/plant-items`).then(r => r.data),
  });

  const allItems: PlantItemWithDeployments[] = data?.data ?? [];

  const items = allItems.filter((i: PlantItemWithDeployments) =>
    i.name?.toLowerCase().includes(search.toLowerCase()) ||
    i.type?.toLowerCase().includes(search.toLowerCase()) ||
    i.identifier?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      {modal.open && (
        <PlantItemModal projectId={id!} plantItem={modal.item} readOnly={!canWrite} onClose={() => setModal({ open: false })} />
      )}

      <ProjectModuleHeader
        category="Health & Safety"
        title="Plant & equipment"
        description="Reusable plant/equipment records and their site-presence periods for the project."
        icon={HardHat}
        action={canWrite ? (
          <button
            onClick={() => setModal({ open: true })}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> New plant item
          </button>
        ) : undefined}
      />

      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search plant / equipment…"
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
          <HardHat size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load plant &amp; equipment</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">
            Try again
          </Button>
        </div>
      ) : items.length === 0 ? (
        <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
          <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
            <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
              <HardHat size={38} strokeWidth={1.5} />
            </div>
          </div>
          <div className="flex flex-col items-start justify-center p-8 sm:p-10">
            <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>No plant recorded</h2>
            <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
              Add a plant or equipment item and record its site-presence periods to keep a reliable trail for the project.
            </p>
            {canWrite && (
              <Button onClick={() => setModal({ open: true })} size="sm" className="mt-5">
                <Plus size={14} /> Add first item
              </Button>
            )}
          </div>
        </div>
      ) : (
        <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[820px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Plant / Equipment', 'Type', 'Identifier', 'Owner / Supplier', 'Register Status', 'Current Site Presence'].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {items.map((item) => {
                const badge = STATUS_COLORS[item.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={item.id} className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => setModal({ open: true, item })}>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{item.name}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{item.type}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{item.identifier || '—'}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{item.owner_supplier || '—'}</td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: badge.bg, color: badge.text }}>{item.status}</span>
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{presenceLabel(item)}</td>
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

export default function GatedProjectPlantEquipmentPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.plant_equipment" title="Plant & Equipment" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectPlantEquipmentPage />
    </FeatureAvailabilityGate>
  );
}
