'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1E.2D — Plant & Equipment. Read-only,
 * rendered exclusively from the frozen snapshot's `plant_equipment`
 * section. Never shows an inspection status — Statutory Inspections is
 * a separate, not-yet-implemented domain. Multiple non-overlapping
 * presence periods for the same item within the week are shown as
 * separate periods, never merged into one continuous claim.
 */

interface PresencePeriod {
  on_site_from: string;
  off_site_at: string | null;
  notes: string | null;
}

interface PlantEquipmentItem {
  name: string;
  type: string;
  identifier: string | null;
  owner_supplier: string | null;
  plant_status: string;
  presence_periods: PresencePeriod[];
}

export default function PlantEquipmentSection({ items }: { items: PlantEquipmentItem[] }) {
  return (
    <div className="rounded-2xl p-6 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Plant &amp; Equipment</h2>
      {items.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          No Plant &amp; Equipment presence records captured for this reporting period.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr style={{ color: 'var(--text-muted)' }}>
                <th className="text-left py-1.5 pr-3 font-medium">Plant / Equipment</th>
                <th className="text-left py-1.5 px-2 font-medium">Identifier</th>
                <th className="text-left py-1.5 px-2 font-medium">Type</th>
                <th className="text-left py-1.5 pl-2 font-medium">Presence</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                  <td className="py-1.5 pr-3" style={{ color: 'var(--text-primary)' }}>{item.name}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{item.identifier || '—'}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{item.type}</td>
                  <td className="py-1.5 pl-2" style={{ color: 'var(--text-secondary)' }}>
                    {item.presence_periods.map((p, pi) => (
                      <div key={pi}>
                        {formatDate(p.on_site_from)} &rarr; {p.off_site_at ? formatDate(p.off_site_at) : 'On site'}
                        {p.notes && <span style={{ color: 'var(--text-muted)' }}> &middot; {p.notes}</span>}
                      </div>
                    ))}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
