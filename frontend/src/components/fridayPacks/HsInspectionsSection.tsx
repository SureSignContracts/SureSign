'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1E.2C — H&S Inspections. Read-only, rendered
 * exclusively from the frozen snapshot's `hs_inspections` section.
 * Outcome and Status are shown as two separate columns — never merged
 * into one ambiguous badge (an inspection can find issues and still be
 * closed, or be satisfactory and remain open).
 */

interface HsInspectionItem {
  date: string;
  inspection_type: string;
  inspected_by: string;
  outcome: string;
  status: string;
  findings: string | null;
  actions: string | null;
}

const OUTCOME_LABELS: Record<string, string> = { satisfactory: 'Satisfactory', issues_found: 'Issues Found' };

export default function HsInspectionsSection({ items }: { items: HsInspectionItem[] }) {
  return (
    <div className="rounded-2xl p-6 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>H&amp;S Inspections</h2>
      {items.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          No H&amp;S Inspection records captured for this reporting period.
        </p>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr style={{ color: 'var(--text-muted)' }}>
                <th className="text-left py-1.5 pr-3 font-medium">Date</th>
                <th className="text-left py-1.5 px-2 font-medium">Inspection</th>
                <th className="text-left py-1.5 px-2 font-medium">Inspected By</th>
                <th className="text-left py-1.5 px-2 font-medium">Outcome</th>
                <th className="text-left py-1.5 pl-2 font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item, i) => (
                <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                  <td className="py-1.5 pr-3 whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatDate(item.date)}</td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-primary)' }}>
                    {item.inspection_type}
                    {item.findings && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{item.findings}</p>}
                    {item.actions && <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>Action: {item.actions}</p>}
                  </td>
                  <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{item.inspected_by}</td>
                  <td className="py-1.5 px-2" style={{ color: item.outcome === 'issues_found' ? '#f87171' : 'var(--text-secondary)' }}>{OUTCOME_LABELS[item.outcome] ?? item.outcome}</td>
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
