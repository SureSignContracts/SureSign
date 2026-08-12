'use client';

import { useState } from 'react';
import { MapPin, Pencil, Move, Trash2, Link2, ExternalLink, X, Plus, ChevronDown, ChevronUp } from 'lucide-react';
import type { PageGeometry } from '@/components/drawings/DrawingPdfCanvas';

/** Drawing Phase 7B3 — the three record types the Drawing Viewer can create directly. Variation is deliberately excluded — it stays Link Existing only (Part 29). */
export type CreatableRecordType = 'snag' | 'rfi' | 'qa_report';

const CREATE_RECORD_OPTIONS: { type: CreatableRecordType; label: string }[] = [
  { type: 'snag', label: 'Create Snag' },
  { type: 'rfi', label: 'Create RFI' },
  { type: 'qa_report', label: 'Create QA Report' },
];

export type Hotspot = {
  id: number;
  page_number: number;
  x: number;
  y: number;
  label: string | null;
};

/** Drawing Phase 6B — a construction record linked to a hotspot, as returned by DrawingHotspotLinkController::present(). */
export type HotspotLink = {
  id: number;
  type: string;
  type_label: string;
  record_id: number;
  label: string;
  action_url: string | null;
};

/** Clamp to [0, 1] — only meant to absorb tiny floating-point edge cases at the page boundary (Part B), never a real out-of-range value. */
function clamp01(n: number): number {
  return Math.min(1, Math.max(0, n));
}

/**
 * Inverse of the render-side geometry contract (Phase 5): given a pointer
 * event over this exact page wrapper (the same element persisted markers are
 * positioned against — never the canvas backing store, browser viewport, or
 * scroll container), returns the normalized (x, y) of the click.
 */
function normalizedPointFromEvent(e: React.MouseEvent<HTMLDivElement>): { x: number; y: number } {
  const rect = e.currentTarget.getBoundingClientRect();
  return {
    x: clamp01((e.clientX - rect.left) / rect.width),
    y: clamp01((e.clientY - rect.top) / rect.height),
  };
}

/**
 * Drawing Phase 5 (read-only rendering) + Phase 6A (authoring) + 6B
 * (linked-record display). Still deliberately dumb about data fetching: it
 * does not fetch Drawing/revision/hotspot data and does not own revision
 * routing. It DOES own its own "which marker's popover is expanded" UI
 * state (`openId`) since that's pure presentation — but the linked-record
 * DATA for whichever marker is open is fetched by the Viewer page (via
 * `onDetailsOpen`) and passed back in as `links`/`linksLoading`, so this
 * component never talks to the API directly.
 *
 * COORDINATE CONVENTION (must match DrawingHotspot's backend docblock
 * exactly): `x`/`y` are normalized 0.0-1.0 relative to `pageGeometry` (the
 * page's CSS-rendered dimensions), representing the CENTER of the marker.
 * The temporary/pending marker (Part K) uses this identical convention —
 * there is no second geometry system for unsaved placements.
 */
export default function DrawingHotspotOverlay({
  hotspots,
  pageGeometry,
  editable,
  canManageLinks,
  placementMode,
  moveHotspotId,
  pendingPlacement,
  initialOpenHotspotId,
  links,
  linksLoading,
  onPlace,
  onMove,
  onEditLabel,
  onStartMove,
  onDelete,
  onDetailsOpen,
  onLinkRecord,
  onCreateRecord,
  onUnlink,
  onOpenLink,
}: {
  hotspots: Hotspot[];
  pageGeometry: PageGeometry | null;
  /** Drawing Phase 6A — hotspot AUTHORING only (add/edit label/move/delete a location itself). Current-revision-only, unchanged by Phase 7B1/7B3. */
  editable: boolean;
  /**
   * Drawing Phase 7B3, Part 3 — construction-record RELATIONSHIP actions
   * (Create Record, Link Existing, Unlink). Deliberately a SEPARATE flag
   * from `editable`: Phase 7B1 intentionally allows these on a historical
   * revision's hotspot while hotspot authoring (`editable`) stays blocked
   * there. Never derive one from the other.
   */
  canManageLinks: boolean;
  placementMode: boolean;
  moveHotspotId: number | null;
  pendingPlacement: { x: number; y: number } | null;
  /** Drawing Phase 6B Part AA — opens this marker's popover once it renders (deep-link highlight), never pans/zooms to it. */
  initialOpenHotspotId?: number | null;
  /** Links for whichever hotspot is currently open — the Viewer page fetches these on `onDetailsOpen`. */
  links: HotspotLink[];
  linksLoading: boolean;
  onPlace: (x: number, y: number) => void;
  onMove: (hotspotId: number, x: number, y: number) => void;
  onEditLabel: (hotspot: Hotspot) => void;
  onStartMove: (hotspot: Hotspot) => void;
  onDelete: (hotspot: Hotspot) => void;
  onDetailsOpen: (hotspotId: number) => void;
  onLinkRecord: (hotspot: Hotspot) => void;
  /** Drawing Phase 7B3, Part 2/11 — the Viewer page owns which shared create modal opens; this component only reports the user's choice. */
  onCreateRecord: (hotspot: Hotspot, type: CreatableRecordType) => void;
  onUnlink: (link: HotspotLink) => void;
  onOpenLink: (url: string) => void;
}) {
  const [openId, setOpenId] = useState<number | null>(initialOpenHotspotId ?? null);
  // Drawing Phase 7B3, Part 30 — scoped to whichever hotspot's popover is
  // open, reset by toggleOpen() below whenever that changes, so switching
  // from one hotspot to another (or reopening the same one) never leaves
  // this expanded from a previous popover.
  const [createMenuOpen, setCreateMenuOpen] = useState(false);

  if (!pageGeometry) return null;

  const pageHotspots = hotspots.filter(h => h.page_number === pageGeometry.pageNumber);
  const capturing = placementMode || moveHotspotId !== null;
  const hasContent = pageHotspots.length > 0 || capturing || (pendingPlacement !== null);
  if (!hasContent) return null;

  function handleCaptureClick(e: React.MouseEvent<HTMLDivElement>) {
    const { x, y } = normalizedPointFromEvent(e);
    if (placementMode) {
      onPlace(x, y);
    } else if (moveHotspotId !== null) {
      onMove(moveHotspotId, x, y);
    }
  }

  function toggleOpen(h: Hotspot) {
    const next = openId === h.id ? null : h.id;
    setOpenId(next);
    setCreateMenuOpen(false);
    // Called directly in the event handler, never inside the setOpenId
    // updater above — calling a different component's setState (this
    // notifies the Viewer page to fetch links) from inside a state updater
    // function violates React's render-purity rule and produces a real
    // "Cannot update a component while rendering a different component"
    // warning, caught live during Phase 6B verification.
    if (next !== null) onDetailsOpen(next);
  }

  return (
    <div
      className="absolute inset-0"
      style={{ width: pageGeometry.width, height: pageGeometry.height, pointerEvents: capturing ? 'auto' : 'none' }}
    >
      {/* Click-capture layer (Part C/H) — only present while placing a new
          location or repositioning an existing one. Existing markers become
          visually dimmed and non-interactive underneath it so a click always
          means "place/move here", never an ambiguous marker click. */}
      {capturing && (
        <div
          className="absolute inset-0"
          data-testid="drawing-hotspot-capture-layer"
          style={{ cursor: 'crosshair' }}
          onClick={handleCaptureClick}
        />
      )}

      {pageHotspots.map(h => {
        const isBeingMoved = moveHotspotId === h.id;
        const isActive = openId === h.id && !capturing;
        const accessibleLabel = h.label ? `Drawing location: ${h.label}` : 'Drawing location';

        return (
          <div
            key={h.id}
            className="absolute"
            style={{
              left: `${h.x * 100}%`,
              top: `${h.y * 100}%`,
              transform: 'translate(-50%, -50%)',
              pointerEvents: capturing ? 'none' : 'auto',
              opacity: capturing && !isBeingMoved ? 0.35 : isBeingMoved ? 0.5 : 1,
            }}
          >
            <button
              type="button"
              aria-label={accessibleLabel}
              tabIndex={capturing ? -1 : 0}
              onClick={() => toggleOpen(h)}
              className="flex items-center justify-center w-6 h-6 rounded-full shadow-md transition-transform hover:scale-110"
              style={{
                backgroundColor: 'var(--gold)',
                border: isActive ? '2px solid var(--text-primary)' : '2px solid #fff',
                boxShadow: isActive ? '0 0 0 4px rgba(212,175,55,0.35)' : undefined,
              }}
            >
              <MapPin size={12} style={{ color: 'var(--accent-fg)' }} fill="currentColor" />
            </button>

            {isActive && (
              <div
                role="dialog"
                aria-label={accessibleLabel}
                className="absolute z-10 min-w-[14rem] max-w-[18rem] rounded-lg text-xs shadow-lg overflow-hidden"
                style={{
                  bottom: 'calc(100% + 6px)',
                  left: '50%',
                  transform: 'translateX(-50%)',
                  backgroundColor: 'var(--bg-surface)',
                  border: '1px solid var(--border)',
                }}
              >
                <div className="px-3 py-2 font-medium" style={{ color: 'var(--text-primary)' }}>
                  {h.label ?? 'Drawing location'}
                </div>

                {/* Linked records (Part W) — never a fabricated label, only what the backend actually resolved. */}
                <div style={{ borderTop: '1px solid var(--border)' }}>
                  {linksLoading ? (
                    <p className="px-3 py-2" style={{ color: 'var(--text-muted)' }}>Loading linked records…</p>
                  ) : links.length === 0 ? (
                    <p className="px-3 py-2" style={{ color: 'var(--text-muted)' }}>No linked records.</p>
                  ) : (
                    <ul>
                      {links.map(link => (
                        <li key={link.id} className="flex items-center justify-between gap-1 px-3 py-1.5">
                          {link.action_url ? (
                            <button
                              type="button"
                              onClick={() => onOpenLink(link.action_url as string)}
                              className="flex items-center gap-1 truncate hover:underline"
                              style={{ color: 'var(--gold)' }}
                              title={`${link.type_label}: ${link.label}`}
                            >
                              <ExternalLink size={11} className="flex-shrink-0" />
                              <span className="truncate">{link.label || link.type_label}</span>
                            </button>
                          ) : (
                            <span className="truncate" style={{ color: 'var(--text-secondary)' }}>{link.label || link.type_label}</span>
                          )}
                          {canManageLinks && (
                            <button
                              type="button"
                              aria-label={`Remove link to ${link.label || link.type_label}`}
                              onClick={() => onUnlink(link)}
                              className="p-0.5 rounded flex-shrink-0 transition-colors hover:bg-[var(--bg-hover)]"
                            >
                              <X size={11} style={{ color: 'var(--text-muted)' }} />
                            </button>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>

                {/* Drawing Phase 7B3, Part 2/3 — record-relationship actions
                    (Create Record, Link Existing) — available on the current
                    OR a historical revision (canManageLinks), deliberately
                    separate from hotspot-authoring actions below (editable,
                    current-revision-only). */}
                {canManageLinks && (
                  <div style={{ borderTop: '1px solid var(--border)' }}>
                    <button
                      type="button"
                      onClick={() => setCreateMenuOpen(v => !v)}
                      aria-expanded={createMenuOpen}
                      className="w-full flex items-center justify-between gap-2 px-3 py-2 text-left transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      <span className="flex items-center gap-2"><Plus size={12} /> Create Record</span>
                      {createMenuOpen ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
                    </button>
                    {createMenuOpen && (
                      <div style={{ backgroundColor: 'var(--bg-elevated)' }}>
                        {CREATE_RECORD_OPTIONS.map(({ type, label }) => (
                          <button
                            key={type}
                            type="button"
                            onClick={() => { setOpenId(null); setCreateMenuOpen(false); onCreateRecord(h, type); }}
                            className="w-full flex items-center gap-2 pl-8 pr-3 py-1.5 text-left transition-colors hover:bg-[var(--bg-hover)]"
                            style={{ color: 'var(--text-secondary)' }}
                          >
                            {label}
                          </button>
                        ))}
                      </div>
                    )}
                    <button
                      type="button"
                      onClick={() => { setOpenId(null); onLinkRecord(h); }}
                      className="w-full flex items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      <Link2 size={12} /> Link Existing
                    </button>
                  </div>
                )}

                {/* Hotspot AUTHORING actions — current-revision-only, unchanged from Phase 6A/6B. */}
                {editable && (
                  <div style={{ borderTop: '1px solid var(--border)' }}>
                    <button
                      type="button"
                      onClick={() => { setOpenId(null); onEditLabel(h); }}
                      className="w-full flex items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      <Pencil size={12} /> Edit label
                    </button>
                    <button
                      type="button"
                      onClick={() => { setOpenId(null); onStartMove(h); }}
                      className="w-full flex items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      <Move size={12} /> Move location
                    </button>
                    <button
                      type="button"
                      onClick={() => { setOpenId(null); onDelete(h); }}
                      className="w-full flex items-center gap-2 px-3 py-2 text-left transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: '#f87171' }}
                    >
                      <Trash2 size={12} /> Remove location
                    </button>
                  </div>
                )}
              </div>
            )}
          </div>
        );
      })}

      {/* Temporary/pending marker (Part K) — same positioning convention as
          a persisted marker, visually distinguished (dashed ring) so it
          reads as unsaved until the label form is confirmed. */}
      {pendingPlacement && (
        <div
          className="absolute pointer-events-none"
          style={{ left: `${pendingPlacement.x * 100}%`, top: `${pendingPlacement.y * 100}%`, transform: 'translate(-50%, -50%)' }}
        >
          <div
            className="flex items-center justify-center w-6 h-6 rounded-full animate-pulse"
            style={{ backgroundColor: 'var(--gold)', border: '2px dashed #fff', opacity: 0.85 }}
          >
            <MapPin size={12} style={{ color: 'var(--accent-fg)' }} fill="currentColor" />
          </div>
        </div>
      )}
    </div>
  );
}
