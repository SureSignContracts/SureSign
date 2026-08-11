'use client';

import { useState } from 'react';
import { MapPin } from 'lucide-react';
import type { PageGeometry } from '@/components/drawings/DrawingPdfCanvas';

export type Hotspot = {
  id: number;
  page_number: number;
  x: number;
  y: number;
  label: string | null;
};

/**
 * Drawing Phase 5 — read-only hotspot overlay. Deliberately dumb: it does
 * not fetch Drawing/revision/hotspot data, does not know about PDF.js, and
 * does not own revision routing — it only converts already-loaded
 * normalized coordinates into on-screen marker positions for the CURRENTLY
 * rendered page, using the geometry DrawingPdfCanvas reports.
 *
 * COORDINATE CONVENTION (must match DrawingHotspot's backend docblock
 * exactly): `x`/`y` are normalized 0.0-1.0 relative to `pageGeometry`
 * (the page's CSS-rendered dimensions), and represent the CENTER of the
 * marker — hence `translate(-50%, -50%)` below, not a corner/tip anchor.
 *
 * MARKER SIZE: screen-size-constant (Part D) — only `left`/`top` (from
 * `x`/`y`) scale with the page; the marker's own CSS size never scales
 * with zoom, so it stays legible at 50% and readable (not oversized) at
 * 200%.
 */
export default function DrawingHotspotOverlay({ hotspots, pageGeometry }: {
  hotspots: Hotspot[];
  pageGeometry: PageGeometry | null;
}) {
  const [activeId, setActiveId] = useState<number | null>(null);

  if (!pageGeometry) return null;

  const pageHotspots = hotspots.filter(h => h.page_number === pageGeometry.pageNumber);
  if (pageHotspots.length === 0) return null;

  return (
    <div
      className="absolute inset-0 pointer-events-none"
      style={{ width: pageGeometry.width, height: pageGeometry.height }}
    >
      {pageHotspots.map(h => {
        const isActive = activeId === h.id;
        const accessibleLabel = h.label ? `Drawing location: ${h.label}` : 'Drawing location';
        return (
          <div
            key={h.id}
            className="absolute pointer-events-auto"
            style={{ left: `${h.x * 100}%`, top: `${h.y * 100}%`, transform: 'translate(-50%, -50%)' }}
          >
            <button
              type="button"
              aria-label={accessibleLabel}
              onMouseEnter={() => setActiveId(h.id)}
              onMouseLeave={() => setActiveId(a => (a === h.id ? null : a))}
              onFocus={() => setActiveId(h.id)}
              onBlur={() => setActiveId(a => (a === h.id ? null : a))}
              onClick={() => setActiveId(a => (a === h.id ? null : h.id))}
              className="flex items-center justify-center w-6 h-6 rounded-full shadow-md transition-transform hover:scale-110"
              style={{ backgroundColor: 'var(--gold)', border: '2px solid #fff' }}
            >
              <MapPin size={12} style={{ color: 'var(--accent-fg)' }} fill="currentColor" />
            </button>

            {isActive && (
              <div
                role="tooltip"
                className="absolute z-10 px-2.5 py-1.5 rounded-lg text-xs font-medium whitespace-nowrap shadow-lg"
                style={{
                  bottom: 'calc(100% + 6px)',
                  left: '50%',
                  transform: 'translateX(-50%)',
                  backgroundColor: 'var(--bg-surface)',
                  border: '1px solid var(--border)',
                  color: 'var(--text-primary)',
                }}
              >
                {h.label ?? 'Drawing location'}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
