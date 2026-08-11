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
      <div className="flex items-center justify-between mb-3">
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Project Map</h2>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Your projects and their current locations.</p>
        </div>
        {mapped_projects > 0 && mapped_projects < total_projects && (
          <span className="text-xs flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
            {mapped_projects} of {total_projects} projects shown
          </span>
        )}
      </div>

      <div
        className="rounded-2xl overflow-hidden"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', height: 360 }}
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
