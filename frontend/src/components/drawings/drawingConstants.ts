/**
 * Drawing Register — Discipline/Status option lists (Phase 1B).
 *
 * Both `discipline` and `status` are flexible, string-backed metadata on the
 * backend (no DB enum — see Drawing Phase 1A) so these lists are UI-only
 * conveniences, never an enforced taxonomy. A Drawing can carry any string
 * value (legacy data, a future admin-configurable list, etc.) — every place
 * that renders one of these values must fall back to the raw string instead
 * of hiding/breaking on an unknown value.
 */

export const DISCIPLINE_OPTIONS = [
  'Architectural',
  'Structural',
  'Civil',
  'Mechanical',
  'Electrical',
  'Plumbing',
  'Fire',
  'Landscape',
  'General',
  'Other',
] as const;

export const STATUS_OPTIONS = [
  'Draft',
  'For Review',
  'For Information',
  'For Approval',
  'For Construction',
  'As Built',
  'Superseded',
] as const;

/** Restrained, semantic status colouring — deliberately not the lifecycle-workflow STATUS_TONE map in Badge.tsx (that's for approve/reject-style statuses; a Drawing status is descriptive metadata, not a workflow outcome). Falls back to neutral for any custom/unrecognised value. */
export const DRAWING_STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  'Draft':             { bg: 'rgba(148,163,184,0.12)', text: '#94a3b8' },
  'For Review':        { bg: 'rgba(234,179,8,0.12)',   text: '#facc15' },
  'For Information':   { bg: 'rgba(59,130,246,0.12)',  text: '#60a5fa' },
  'For Approval':      { bg: 'rgba(249,115,22,0.12)',  text: '#fb923c' },
  'For Construction':  { bg: 'rgba(34,197,94,0.12)',   text: '#4ade80' },
  'As Built':          { bg: 'rgba(20,184,166,0.12)',  text: '#2dd4bf' },
  'Superseded':        { bg: 'rgba(90,86,82,0.2)',     text: '#9a9490' },
};

export function drawingStatusColor(status: string | null | undefined): { bg: string; text: string } {
  if (!status) return { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
  return DRAWING_STATUS_COLORS[status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
}
