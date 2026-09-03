'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1E.2B — Incidents / Accidents / Near Misses.
 * Read-only, rendered exclusively from the frozen snapshot's `incidents`
 * section. `local_date`/`local_time` are pre-formatted strings frozen at
 * generation time using the reporting period's own timezone — rendered
 * verbatim here, never re-derived from `occurred_at` at display time, so
 * a later organisation timezone change can never retroactively shift an
 * already-generated historical Friday Pack's displayed time. `injury_occurred`
 * is a genuine tri-state — `null` ("Not confirmed") must never render as
 * "No injury." `description` is deliberately absent from the snapshot
 * (data minimisation) and is never shown here.
 */

interface IncidentItem {
  occurred_at: string;
  local_date: string;
  local_time: string;
  type: string;
  title: string;
  location: string | null;
  injury_occurred: boolean | null;
  regulatory_reportability: string;
  status: string;
}

const TYPE_LABELS: Record<string, string> = { accident: 'Accident', incident: 'Incident', near_miss: 'Near Miss' };
const REPORTABILITY_LABELS: Record<string, string> = { unknown: 'Unknown', not_reportable: 'Not reportable', reportable: 'Reportable' };

function injuryLabel(value: boolean | null): string {
  if (value === true) return 'Injury occurred';
  if (value === false) return 'No injury';
  return 'Not confirmed';
}

export default function IncidentsSection({ items }: { items: IncidentItem[] }) {
  return (
    <div className="rounded-2xl p-6 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Accidents / Incidents / Near Misses</h2>
      {items.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          No Incident records captured for this reporting period.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr style={{ color: 'var(--text-muted)' }}>
                <th className="text-left py-1.5 pr-3 font-medium">Date / Time</th>
                <th className="text-left py-1.5 px-2 font-medium">Type</th>
                <th className="text-left py-1.5 px-2 font-medium">Summary</th>
                <th className="text-left py-1.5 px-2 font-medium">Location</th>
                <th className="text-left py-1.5 px-2 font-medium">Injury</th>
                <th className="text-left py-1.5 px-2 font-medium">Reportability</th>
                <th className="text-left py-1.5 pl-2 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                  <td className="py-1.5 pr-3 whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>
                    {formatDate(item.local_date)} {item.local_time}
                  </td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{TYPE_LABELS[item.type] ?? item.type}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-primary)' }}>{item.title}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{item.location || '—'}</td>
                  <td className="py-1.5 px-2" style={{ color: item.injury_occurred === true ? '#f87171' : 'var(--text-secondary)' }}>{injuryLabel(item.injury_occurred)}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{REPORTABILITY_LABELS[item.regulatory_reportability] ?? item.regulatory_reportability}</td>
                  <td className="py-1.5 pl-2 capitalize" style={{ color: 'var(--text-secondary)' }}>{item.status}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
