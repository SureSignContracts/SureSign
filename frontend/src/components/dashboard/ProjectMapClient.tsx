'use client';

import { useEffect, useMemo } from 'react';
import { MapContainer, TileLayer, Marker, Popup, ZoomControl, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import CollapsedMapAttribution from '@/components/maps/CollapsedMapAttribution';
import { ArrowRight, ExternalLink, AlertTriangle, Clock, MapPin } from 'lucide-react';
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

  const markerIcons = useMemo(() => new Map(projects.map((project, index) => {
    const health = project.overdue_count > 0 ? 'urgent' : project.due_soon_count > 0 ? 'warning' : 'healthy';
    return [project.id, L.divIcon({
      className: 'ss-project-marker-shell',
      html: `<span class="ss-project-marker ss-project-marker--${health}" style="animation-delay:${Math.min(index * 70, 420)}ms"><span class="ss-project-marker-core"></span></span>`,
      iconSize: [34, 42],
      iconAnchor: [17, 38],
      popupAnchor: [0, -34],
    })];
  })), [projects]);

  return (
    <MapContainer
      center={bounds ? undefined : WORLD_CENTER}
      zoom={bounds ? undefined : WORLD_ZOOM}
      bounds={bounds ?? undefined}
      boundsOptions={{ padding: [32, 32], maxZoom: 15 }}
      style={{ height: '100%', width: '100%', borderRadius: 'inherit' }}
      scrollWheelZoom={false}
      zoomControl={false}
      attributionControl={false}
      className="ss-project-map-canvas"
    >
      <SyncMapView bounds={bounds} projects={projects} />
      <ZoomControl position="bottomright" />
      {/* OpenStreetMap tiles — no API key. Only normal tile-coordinate
          requests (z/x/y) leave the browser; no project name/id/tenant data
          is ever sent to the tile provider. Required attribution is
          rendered via CollapsedMapAttribution below, not this TileLayer's
          own `attribution` prop (Leaflet's default control is disabled
          above), positioned bottom-left so it never collides with the
          ZoomControl at bottom-right. */}
      <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
      <CollapsedMapAttribution />
      {projects.map(project => (
        <Marker key={project.id} position={[project.latitude, project.longitude]} icon={markerIcons.get(project.id)!}>
          <Popup className="ss-project-map-popup" minWidth={250} maxWidth={290}>
            <div className="ss-project-map-popup-content">
              <div className="ss-project-map-popup-heading">
                <span className="ss-project-map-popup-icon"><MapPin size={15} /></span>
                <div>
                  <p className="ss-project-map-popup-title">{project.name}</p>
                  <p className="ss-project-map-popup-location">
                    {[project.city, project.country].filter(Boolean).join(', ') || 'Location coordinates added'}
                  </p>
                </div>
              </div>

              <div className="ss-project-map-popup-meta">
                <span className="ss-project-map-status">{project.status.replace(/_/g, ' ')}</span>
              {(project.overdue_count > 0 || project.due_soon_count > 0) && (
                  <span className={project.overdue_count > 0 ? 'ss-project-map-risk ss-project-map-risk--urgent' : 'ss-project-map-risk'}>
                    {project.overdue_count > 0 ? <AlertTriangle size={11} /> : <Clock size={11} />}
                    {project.overdue_count > 0
                      ? `${project.overdue_count} overdue`
                      : `${project.due_soon_count} due soon`}
                  </span>
              )}
              </div>

              <div className="ss-project-map-popup-actions">
                <button
                  onClick={() => router.push(project.action_url)}
                  className="ss-project-map-primary-action"
                >
                  Open project <ArrowRight size={12} />
                </button>
                {/* Google's own officially documented, no-API-key URL —
                    see SiteLocationMap.tsx's identical mechanism/comment.
                    Clicking this intentionally sends the project's
                    coordinate (only) to Google Maps. */}
                <a
                  href={`https://www.google.com/maps/search/?api=1&query=${project.latitude},${project.longitude}`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="ss-project-map-secondary-action"
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
