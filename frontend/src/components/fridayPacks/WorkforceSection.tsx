'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1C — Workforce. Read-only presentation of the
 * frozen schema-2 `workforce` snapshot section (see
 * App\Services\FridayPack\FridayPackWorkforceService) — this component
 * never recalculates anything, it only renders what was captured.
 * Ambiguity (conflicting Site Reports / conflicting breakdowns) is shown
 * as an explicit, neutral message — never silently resolved to a guessed
 * number.
 */

interface WorkforceDay {
  date: string;
  day: string;
  daily_total: number | null;
  source_count: number;
  status: 'no_data' | 'recorded' | 'conflicting_site_reports';
  has_breakdown: boolean;
  breakdown_total: number | null;
}

interface WorkforceRow {
  trade_or_role: string;
  counts: Record<string, number | null>;
  person_days_total: number;
}

interface WorkforceSectionData {
  days: WorkforceDay[];
  rows: WorkforceRow[];
  has_trade_breakdown: boolean;
  person_days_total: number;
  has_conflicts: boolean;
}

const DAY_ORDER = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

function dailyTotalLabel(day: WorkforceDay): string {
  if (day.status === 'conflicting_site_reports') return 'Conflicting';
  if (day.status === 'no_data') return '—';
  return String(day.daily_total);
}

export default function WorkforceSection({ workforce }: { workforce: WorkforceSectionData }) {
  return (
    <div className="rounded-2xl p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between flex-wrap gap-2">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Workforce</h2>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Person-days this week: {workforce.person_days_total}</p>
      </div>

      {workforce.has_conflicts && (
        <p className="text-xs rounded-lg px-3 py-2" style={{ backgroundColor: 'rgba(239,68,68,0.08)', color: '#f87171' }}>
          Multiple Site Reports contain conflicting workforce data for one or more days this week — see the days below.
        </p>
      )}

      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead>
            <tr style={{ color: 'var(--text-muted)' }}>
              <th className="text-left py-1.5 pr-3 font-medium">Day</th>
              <th className="text-right py-1.5 px-2 font-medium">Recorded total</th>
              <th className="text-right py-1.5 px-2 font-medium">Breakdown total</th>
            </tr>
          </thead>
          <tbody>
            {workforce.days.map(day => (
              <tr key={day.date} style={{ borderTop: '1px solid var(--border)' }}>
                <td className="py-1.5 pr-3" style={{ color: 'var(--text-secondary)' }}>{day.day} {formatDate(day.date)}</td>
                <td className="py-1.5 px-2 text-right" style={{ color: day.status === 'conflicting_site_reports' ? '#f87171' : 'var(--text-primary)' }}>
                  {dailyTotalLabel(day)}
                </td>
                <td className="py-1.5 px-2 text-right" style={{ color: 'var(--text-primary)' }}>
                  {day.has_breakdown ? day.breakdown_total : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {workforce.has_trade_breakdown ? (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr style={{ color: 'var(--text-muted)' }}>
                <th className="text-left py-1.5 pr-3 font-medium">Trade / Role</th>
                {DAY_ORDER.map(d => (
                  <th key={d} className="text-right py-1.5 px-2 font-medium capitalize">{d.slice(0, 3)}</th>
                ))}
                <th className="text-right py-1.5 pl-2 font-medium">Total</th>
              </tr>
            </thead>
            <tbody>
              {workforce.rows.map(row => (
                <tr key={row.trade_or_role} style={{ borderTop: '1px solid var(--border)' }}>
                  <td className="py-1.5 pr-3" style={{ color: 'var(--text-secondary)' }}>{row.trade_or_role}</td>
                  {DAY_ORDER.map(d => (
                    <td key={d} className="py-1.5 px-2 text-right" style={{ color: 'var(--text-primary)' }}>
                      {row.counts[d] ?? '—'}
                    </td>
                  ))}
                  <td className="py-1.5 pl-2 text-right font-medium" style={{ color: 'var(--text-primary)' }}>{row.person_days_total}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No trade/role breakdown was recorded for this reporting week.</p>
      )}
    </div>
  );
}
