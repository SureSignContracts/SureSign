'use client';

import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { MapPin, ExternalLink } from 'lucide-react';
import api from '@/lib/api';

/**
 * Drawing Phase 6B Part Y — the reverse "Drawing Locations" section shown on
 * a Snag/RFI/QA Report/Variation's own detail/edit view, mirroring
 * `EvidenceSection`'s established pattern for a small reusable section
 * embedded in an existing record modal (Phase 0). Read-only: linking is
 * only ever initiated from the Drawing Viewer's own marker popover, never
 * from here. "Open Drawing" navigates to the exact Drawing, revision, and
 * page the location belongs to (Part Z) — never just the Drawing Register.
 */
type DrawingLocation = {
  link_id: number;
  drawing_id: number;
  drawing_number: string;
  revision_id: number;
  revision_code: string | null;
  page_number: number;
  hotspot_id: number;
  hotspot_label: string | null;
  view_url: string;
};

export default function DrawingLocationsSection({ projectId, type, recordId }: {
  projectId: string;
  type: 'snag' | 'rfi' | 'qa_report' | 'variation';
  recordId: number;
}) {
  const router = useRouter();
  const { data, isLoading } = useQuery<{ data: DrawingLocation[] }>({
    queryKey: ['drawing-locations', projectId, type, recordId],
    queryFn: () => api.get(`/projects/${projectId}/drawing-locations`, { params: { type, record_id: recordId } }).then(r => r.data),
  });
  const locations = data?.data ?? [];

  if (!isLoading && locations.length === 0) return null;

  return (
    <div>
      <h3 className="text-xs font-semibold mb-2 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <MapPin size={13} /> Drawing Locations
      </h3>
      {isLoading ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      ) : (
        <ul className="space-y-1.5">
          {locations.map(loc => (
            <li
              key={loc.link_id}
              className="flex items-center justify-between gap-2 px-3 py-2 rounded-lg text-xs"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
            >
              <span style={{ color: 'var(--text-secondary)' }}>
                <span className="font-medium" style={{ color: 'var(--text-primary)' }}>{loc.drawing_number}</span>
                {loc.revision_code && ` · Rev ${loc.revision_code}`} · Page {loc.page_number}
                {loc.hotspot_label && ` · ${loc.hotspot_label}`}
              </span>
              <button
                type="button"
                onClick={() => router.push(loc.view_url)}
                className="flex items-center gap-1 font-medium flex-shrink-0 hover:underline"
                style={{ color: 'var(--gold)' }}
              >
                Open Drawing <ExternalLink size={11} />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
