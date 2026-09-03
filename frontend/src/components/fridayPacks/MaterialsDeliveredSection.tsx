'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1D — Materials Delivered This Week.
 * Read-only, rendered from the frozen snapshot's deterministic Mon-Fri
 * `SiteDiary.materials_delivered` source list — no confirmed-narrative
 * field exists for this section (R1D's own decision); the source list IS
 * the final content. Missing data is never presented as a confirmed "no
 * deliveries."
 */

interface MaterialsItem {
  date: string;
  day_name: string;
  materials_delivered: string;
}

export default function MaterialsDeliveredSection({ items }: { items: MaterialsItem[] }) {
  return (
    <div className="rounded-2xl p-6 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Materials Delivered</h2>
      {items.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          No materials deliveries were recorded on a Site Report for this reporting week.
        </p>
      ) : (
        <div className="space-y-2">
          {items.map(item => (
            <div key={item.date} className="rounded-lg p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                {item.day_name} {formatDate(item.date)} &middot; Site Report
              </p>
              <p className="text-xs whitespace-pre-wrap" style={{ color: 'var(--text-muted)' }}>{item.materials_delivered}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
