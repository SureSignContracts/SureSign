'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1E.1 — RAMS. Read-only, rendered exclusively
 * from the frozen snapshot's `rams` section — sourced from the EXISTING
 * App\Models\DeliveryDocument register (`category = 'rams'`), never a new
 * RAMS model. "Current"/"Submitted this week"/"Approved this week" are
 * factual labels derived from DeliveryDocument's own recorded status/
 * dates — never a compliance conclusion. Missing records stay missing —
 * never presented as "All RAMS compliant" or similar.
 */

interface RamsItem {
  title: string;
  revision: string | null;
  status: string;
  current: boolean;
  submitted_this_week: boolean;
  approved_this_week: boolean;
  expiry_date: string | null;
}

const STATUS_LABELS: Record<string, string> = {
  required: 'Required', pending: 'Pending', submitted: 'Submitted',
  under_review: 'Under Review', approved: 'Approved', rejected: 'Rejected',
  expired: 'Expired', superseded: 'Superseded',
};

export default function RamsSection({ items }: { items: RamsItem[] }) {
  return (
    <div className="rounded-2xl p-6 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>RAMS</h2>
      {items.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          No RAMS records captured for this reporting period.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr style={{ color: 'var(--text-muted)' }}>
                <th className="text-left py-1.5 pr-3 font-medium">Title</th>
                <th className="text-left py-1.5 px-2 font-medium">Revision</th>
                <th className="text-left py-1.5 px-2 font-medium">Status</th>
                <th className="text-left py-1.5 px-2 font-medium">Activity this week</th>
                <th className="text-left py-1.5 pl-2 font-medium">Expiry</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                  <td className="py-1.5 pr-3" style={{ color: 'var(--text-primary)' }}>{item.title}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{item.revision || '—'}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{STATUS_LABELS[item.status] ?? item.status}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-muted)' }}>
                    {[item.submitted_this_week && 'Submitted this week', item.approved_this_week && 'Approved this week'].filter(Boolean).join(' · ') || '—'}
                  </td>
                  <td className="py-1.5 pl-2" style={{ color: 'var(--text-secondary)' }}>{item.expiry_date ? formatDate(item.expiry_date) : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
