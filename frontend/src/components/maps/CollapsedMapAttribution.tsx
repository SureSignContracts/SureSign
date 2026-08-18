'use client';

import { useState } from 'react';

/**
 * Collapsed OpenStreetMap attribution badge, shared by SiteLocationMap.tsx
 * and ProjectMapClient.tsx.
 *
 * The attribution text/link below is a required condition of using OSM's
 * free tile server (https://operations.osmfoundation.org/policies/tiles/),
 * not decorative — it must never be removed, only made visually
 * unobtrusive. Leaflet's own built-in AttributionControl has no "collapsed"
 * toggle (unlike its Layers control), so both maps disable it
 * (`attributionControl={false}` on `MapContainer`) and render this instead:
 * a small "i" badge, collapsed by default, that reveals the full required
 * text on hover/focus/click. Rendered as a normal child of `MapContainer` —
 * react-leaflet mounts non-Leaflet children directly inside the map
 * container div, so a plain absolutely-positioned element layers correctly
 * over the tile/marker panes as long as its z-index matches Leaflet's own
 * control layer (1000).
 *
 * Positioned bottom-left deliberately: ProjectMapClient already places a
 * ZoomControl at bottom-right.
 */
export default function CollapsedMapAttribution() {
  const [open, setOpen] = useState(false);

  return (
    <div
      className="absolute bottom-2 left-2 flex flex-col items-start"
      style={{ zIndex: 1000 }}
      onMouseEnter={() => setOpen(true)}
      onMouseLeave={() => setOpen(false)}
    >
      {open && (
        <div className="mb-1 whitespace-nowrap rounded-md border border-black/10 bg-white/95 px-2 py-1 text-[10px] text-slate-700 shadow dark:border-white/10 dark:bg-slate-800/95 dark:text-slate-200">
          &copy;{' '}
          <a
            href="https://www.openstreetmap.org/copyright"
            target="_blank"
            rel="noopener noreferrer"
            className="underline"
          >
            OpenStreetMap
          </a>{' '}
          contributors
        </div>
      )}
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        aria-label="Map data attribution"
        className="flex h-5 w-5 items-center justify-center rounded-full border border-black/15 bg-white/90 text-[10px] font-semibold leading-none text-slate-600 shadow hover:bg-white dark:border-white/15 dark:bg-slate-800/90 dark:text-slate-300"
      >
        i
      </button>
    </div>
  );
}
