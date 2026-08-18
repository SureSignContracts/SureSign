'use client';

import { useEffect } from 'react';
import { MapContainer, TileLayer, Marker, useMap } from 'react-leaflet';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import CollapsedMapAttribution from '@/components/maps/CollapsedMapAttribution';

/**
 * Single-project site map — Project Overview's "Site Location" section.
 * Deliberately its own small component rather than a reuse/extension of the
 * Dashboard's ProjectMap/ProjectMapClient (organisation-wide, multi-marker,
 * bounds-fitting): a single point at a fixed zoom needs none of that
 * machinery, and coupling the two would only make the Dashboard map harder
 * to change later. Shares the same marker-icon asset setup (see
 * `frontend/public/leaflet/`) so both maps look identical.
 */

const SITE_ICON = L.icon({
  iconUrl: '/leaflet/marker-icon.png',
  iconRetinaUrl: '/leaflet/marker-icon-2x.png',
  shadowUrl: '/leaflet/marker-shadow.png',
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41],
});

// Close enough to see access roads and neighbouring buildings without being
// so tight the marker sits on an unhelpfully empty tile.
const SITE_ZOOM = 16;

/**
 * Leaflet's `<MapContainer center/zoom>` props, like `bounds`, only apply at
 * initial mount — see ProjectMapClient.tsx's identical comment for the bug
 * this pattern fixes. A project's coordinates can change later via Edit
 * Project without this component ever unmounting (e.g. a background React
 * Query refetch), so the view must be re-centred imperatively whenever the
 * actual lat/lng values change, not just once at creation.
 */
function RecenterOnChange({ lat, lng }: { lat: number; lng: number }) {
  const map = useMap();
  useEffect(() => {
    map.setView([lat, lng], SITE_ZOOM);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [lat, lng]);
  return null;
}

export default function SiteLocationMap({ latitude, longitude }: { latitude: number; longitude: number }) {
  return (
    <MapContainer
      center={[latitude, longitude]}
      zoom={SITE_ZOOM}
      style={{ height: '100%', width: '100%', borderRadius: 'inherit' }}
      scrollWheelZoom={false}
      attributionControl={false}
    >
      <RecenterOnChange lat={latitude} lng={longitude} />
      {/* OpenStreetMap tiles — no API key. Only normal tile-coordinate
          requests leave the browser; no project data is sent to the tile
          provider. Required attribution is rendered via
          CollapsedMapAttribution below, not this TileLayer's own
          `attribution` prop (Leaflet's default control is disabled above). */}
      <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
      <Marker position={[latitude, longitude]} icon={SITE_ICON} />
      <CollapsedMapAttribution />
    </MapContainer>
  );
}
