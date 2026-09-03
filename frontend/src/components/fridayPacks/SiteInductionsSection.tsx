'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1E.2A — Site Inductions. Read-only, rendered
 * exclusively from the frozen snapshot's `site_inductions` section —
 * sourced from the EXISTING App\Models\SiteInduction register. One row
 * per session, never one row per attendee. `total_inducted` is summed
 * session attendance — deliberately never labelled "unique inductees" or
 * "unique workers."
 */

interface SiteInductionItem {
  date: string;
  session_title: string | null;
  company_or_trade: string | null;
  inductee_count: number;
  notes: string | null;
}

export default function SiteInductionsSection({ items, totalInducted }: {
  items: SiteInductionItem[]; totalInducted: number;
}) {
  return (
    <div className="rounded-2xl p-6 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between flex-wrap gap-2">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Site Inductions</h2>
        {items.length > 0 && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total inducted this week: {totalInducted}</p>
        )}
      </div>
      {items.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          No Site Induction records captured for this reporting period.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr style={{ color: 'var(--text-muted)' }}>
                <th className="text-left py-1.5 pr-3 font-medium">Date</th>
                <th className="text-left py-1.5 px-2 font-medium">Session</th>
                <th className="text-left py-1.5 px-2 font-medium">Company / Trade</th>
                <th className="text-left py-1.5 pl-2 font-medium">Number Inducted</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                  <td className="py-1.5 pr-3" style={{ color: 'var(--text-secondary)' }}>{formatDate(item.date)}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-primary)' }}>
                    {item.session_title || '—'}
                    {item.notes && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{item.notes}</p>}
                  </td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{item.company_or_trade || '—'}</td>
                  <td className="py-1.5 pl-2" style={{ color: 'var(--text-secondary)' }}>{item.inductee_count}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
