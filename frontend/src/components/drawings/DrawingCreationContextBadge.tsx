'use client';

import { MapPin } from 'lucide-react';
import type { DrawingCreationContext } from './DrawingCreationContext';

/**
 * Drawing Phase 7B3 — the small, understated context shown inside a shared
 * Snag/RFI/QA create component only when it was opened from the Drawing
 * Viewer's "Create Record" action. Context only, never editable, never
 * persisted verbatim into any record field (see DrawingCreationContext's
 * own docblock). One shared component so all three modals render this
 * identically rather than three copies drifting apart.
 */
export default function DrawingCreationContextBadge({ context }: { context: DrawingCreationContext }) {
  const parts = [context.drawingNumber];
  if (context.revisionLabel) parts.push(`Revision ${context.revisionLabel}`);
  parts.push(`Page ${context.pageNumber}`);

  return (
    <div
      className="flex items-start gap-2 rounded-lg px-3 py-2 text-xs"
      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
    >
      <MapPin size={13} className="mt-0.5 flex-shrink-0" style={{ color: 'var(--text-muted)' }} />
      <div className="min-w-0">
        <p className="font-medium" style={{ color: 'var(--text-secondary)' }}>Creating from Drawing Location</p>
        <p className="mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>
          {parts.join(' · ')}
          {context.hotspotLabel ? ` · ${context.hotspotLabel}` : ''}
        </p>
      </div>
    </div>
  );
}
