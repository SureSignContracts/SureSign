'use client';

import { useEffect, useMemo } from 'react';
import { MapContainer, TileLayer, Marker, Popup, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { ArrowRight, ExternalLink } from 'lucide-react';
import { useRouter } from 'next/navigation';

/**
 * The actual Leaflet map — isolated in its own client component and loaded
 * via `next/dynamic({ ssr: false })` from ProjectMap.tsx, since Leaflet
 * reads `window`/`document` at import time and has no server-safe path.
 *
 * Leaflet's default marker icon assets are bundled as relative image URLs
 * that don't resolve correctly through Next.js/webpack — rather than a
 * fragile CDN hotlink, the three PNGs are copied into `public/leaflet/`
 * (see `frontend/public/leaflet/`) and referenced by a stable static path.
 */

const DEFAULT_ICON = L.icon({
  iconUrl: '/leaflet/marker-icon.png',
  iconRetinaUrl: '/leaflet/marker-icon-2x.png',
  shadowUrl: '/leaflet/marker-shadow.png',
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41],
});

export type ProjectMapMarker = {
  id: number;
  name: string;
  status: string;
  city: string | null;
  country: string | null;
  latitude: number;
  longitude: number;
  overdue_count: number;
  due_soon_count: number;
  action_url: string;
};

// A default, non-project-specific world view for the (rare) moment the map
// renders before bounds are computed — never a marker location.
const WORLD_CENTER: [number, number] = [20, 0];
const WORLD_ZOOM = 2;

/**
 * `<MapContainer bounds={...}>` only ever applies bounds at the map's
 * initial creation — react-leaflet does not re-run `fitBounds()` just
 * because the `bounds` prop object changes on a later render. Since the
 * Dashboard page can receive fresh coordinates for the SAME set of project
 * ids (an edited project, or simply a React Query background refetch)
 * without Leaflet's own map instance ever being torn down and recreated,
 * relying on the declarative prop alone left the map frozen on whatever
 * view it first mounted with. This component calls Leaflet's imperative
 * `fitBounds()`/`setView()` directly, in an effect keyed on the actual
 * coordinate values, so a real position change is always reflected —
 * whether or not the surrounding React tree happened to remount.
 */
function SyncMapView({ bounds, projects }: { bounds: L.LatLngBounds | null; projects: ProjectMapMarker[] }) {
  const map = useMap();
  // Keyed on the real coordinate values (not just project ids/count) so a
  // pure coordinate edit on an already-mapped project is still detected.
  const positionsKey = projects.map(p => `${p.id}:${p.latitude},${p.longitude}`).join('|');

  useEffect(() => {
    if (bounds) {
      map.fitBounds(bounds, { padding: [32, 32], maxZoom: 15 });
    } else {
      map.setView(WORLD_CENTER, WORLD_ZOOM);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [positionsKey]);

  return null;
}

export default function ProjectMapClient({ projects }: { projects: ProjectMapMarker[] }) {
  const router = useRouter();

  const bounds = useMemo(() => {
    if (projects.length === 0) return null;
    return L.latLngBounds(projects.map(p => [p.latitude, p.longitude] as [number, number]));
  }, [projects]);

  return (
    <MapContainer
      center={bounds ? undefined : WORLD_CENTER}
      zoom={bounds ? undefined : WORLD_ZOOM}
      bounds={bounds ?? undefined}
      boundsOptions={{ padding: [32, 32], maxZoom: 15 }}
      style={{ height: '100%', width: '100%', borderRadius: 'inherit' }}
      scrollWheelZoom={false}
    >
      <SyncMapView bounds={bounds} projects={projects} />
      {/* OpenStreetMap tiles — no API key, standard required attribution
          preserved below. Only normal tile-coordinate requests (z/x/y) leave
          the browser; no project name/id/tenant data is ever sent to the
          tile provider. */}
      <TileLayer
        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
      />
      {projects.map(project => (
        <Marker key={project.id} position={[project.latitude, project.longitude]} icon={DEFAULT_ICON}>
          <Popup>
            <div style={{ minWidth: 180 }}>
              <p style={{ fontWeight: 600, fontSize: '0.875rem', marginBottom: 2 }}>{project.name}</p>
              <p style={{ fontSize: '0.75rem', color: '#666', textTransform: 'capitalize', marginBottom: 4 }}>
                {project.status.replace(/_/g, ' ')}
                {(project.city || project.country) && ` · ${[project.city, project.country].filter(Boolean).join(', ')}`}
              </p>
              {(project.overdue_count > 0 || project.due_soon_count > 0) && (
                <p style={{ fontSize: '0.75rem', color: '#b45309', marginBottom: 4 }}>
                  {project.overdue_count > 0 && `${project.overdue_count} overdue`}
                  {project.overdue_count > 0 && project.due_soon_count > 0 && ' · '}
                  {project.due_soon_count > 0 && `${project.due_soon_count} due soon`}
                </p>
              )}
              <div style={{ display: 'flex', gap: 12, marginTop: 2 }}>
                <button
                  onClick={() => router.push(project.action_url)}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: '0.75rem', fontWeight: 500, color: '#0a0a0a', background: 'none', border: 'none', padding: 0, cursor: 'pointer' }}
                >
                  Open Project <ArrowRight size={11} />
                </button>
                {/* Google's own officially documented, no-API-key URL —
                    see SiteLocationMap.tsx's identical mechanism/comment.
                    Clicking this intentionally sends the project's
                    coordinate (only) to Google Maps. */}
                <a
                  href={`https://www.google.com/maps/search/?api=1&query=${project.latitude},${project.longitude}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: '0.75rem', fontWeight: 500, color: '#666', textDecoration: 'none' }}
                >
                  Google Maps <ExternalLink size={11} />
                </a>
              </div>
            </div>
          </Popup>
        </Marker>
      ))}
    </MapContainer>
  );
}
