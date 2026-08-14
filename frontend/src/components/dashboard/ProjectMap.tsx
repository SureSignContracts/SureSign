'use client';

import dynamic from 'next/dynamic';
import { MapPin } from 'lucide-react';
import EmptyState from '@/components/ui/EmptyState';
import type { ProjectMapMarker } from './ProjectMapClient';

// Leaflet touches `window`/`document` at import time — never safe on the
// server. Loaded client-only, and only once this section is actually
// rendered (so it never adds weight to the initial SSR/first-paint path).
const ProjectMapClient = dynamic(() => import('./ProjectMapClient'), {
  ssr: false,
  loading: () => (
    <div className="w-full h-full flex items-center justify-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
      <MapPin size={20} style={{ color: 'var(--text-muted)', opacity: 0.5 }} />
    </div>
  ),
});

export type ProjectMapData = {
  projects: ProjectMapMarker[];
  total_projects: number;
  mapped_projects: number;
};

/**
 * Dashboard "Project Map" card. Only projects with a real, manually-entered
 * latitude AND longitude produce a marker — never a guessed or 0,0 fallback
 * (see OrganisationDashboardService::buildProjectMap()). Three deliberate
 * states: no projects at all (handled by the Dashboard page's own
 * no-projects EmptyState, before this component ever renders), projects
 * exist but none are mapped, and a normal mapped view (optionally showing
 * how many of the total are shown, when partial).
 */
export default function ProjectMap({ data }: { data: ProjectMapData }) {
  const { projects, total_projects, mapped_projects } = data;

  return (
    <section data-tour="dashboard-project-map">
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Project map</h2>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>See where active work is happening across the portfolio.</p>
        </div>
        <div className="flex flex-wrap items-center justify-end gap-x-3 gap-y-1">
          {mapped_projects > 0 && (
            <div className="flex items-center gap-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              <span className="flex items-center gap-1.5"><i className="h-2 w-2 rounded-full bg-[#4ade80]" /> On track</span>
              <span className="flex items-center gap-1.5"><i className="h-2 w-2 rounded-full bg-[#facc15]" /> Due soon</span>
              <span className="flex items-center gap-1.5"><i className="h-2 w-2 rounded-full bg-[#f87171]" /> Overdue</span>
            </div>
          )}
          {mapped_projects > 0 && mapped_projects < total_projects && (
            <span className="text-xs flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
              {mapped_projects} of {total_projects} shown
            </span>
          )}
        </div>
      </div>

      <div
        className="ss-project-map group overflow-hidden rounded-2xl transition-all duration-300 hover:shadow-[var(--shadow-pop)]"
        // `isolation: 'isolate'` is load-bearing, not decorative: Leaflet's
        // own panes/controls carry internal z-index values up to 1000
        // (.leaflet-top/.leaflet-bottom), which is higher than any modal or
        // dropdown in this app (e.g. Modal.tsx's z-50). Without a stacking
        // context of its own here, this card's `overflow-hidden` still
        // clips the map's pixels to its bounds, but Leaflet's z-index values
        // are compared directly against unrelated body-level siblings (a
        // portaled modal, a dropdown) with no containing ancestor between
        // them — so the map paints ON TOP of them regardless of DOM order.
        // `isolation: isolate` creates a real stacking context here, so
        // every z-index inside this card is contained within it instead.
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', height: 330, isolation: 'isolate' }}
      >
        {mapped_projects === 0 ? (
          <div className="w-full h-full flex items-center justify-center">
            <EmptyState
              icon={MapPin}
              title="No project locations have been added yet"
              description="Add coordinates to a project to show it on the map."
            />
          </div>
        ) : (
          <ProjectMapClient projects={projects} />
        )}
      </div>
    </section>
  );
}
