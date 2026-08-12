/**
 * Drawing Phase 7B3 — the minimal, transient context passed into a shared
 * Snag/RFI/QA create component when it's opened from the Drawing Viewer's
 * "Create Record" action, rather than from that module's own page.
 *
 * Deliberately NOT persisted onto the operational record itself beyond the
 * approved `hotspotLabel` -> `Snag.location`/`QaReport.area` convenience
 * prefill (Parts 7/8) — the real, structural relationship is always
 * `drawing_hotspot_id` -> DrawingHotspotLink, created server-side. This
 * type exists only to (a) show the small "Creating from Drawing Location"
 * context header, and (b) supply that one prefill value; it is never
 * required by any existing module page.
 */
export interface DrawingCreationContext {
  hotspotId: number;
  drawingNumber: string;
  /** The revision actually being viewed — may be historical (Phase 7B1 intentionally allows Create Record from a historical hotspot). Never assume this is the Drawing's current revision. */
  revisionLabel?: string | null;
  pageNumber: number;
  hotspotLabel?: string | null;
}
