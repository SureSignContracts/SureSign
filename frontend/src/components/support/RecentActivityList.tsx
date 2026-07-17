import { History } from 'lucide-react';
import { formatDate } from '@/lib/utils';

export interface RecentActivityEntry {
  timestamp: string;
  module: string;
  action_type: string;
  project: string | null;
  route: string | null;
  description: string;
}

/** Safe, allowlisted project-activity snapshot attached to a ticket — never raw logs. Shared by the admin and client-facing ticket detail views. */
export function RecentActivityList({ entries }: { entries: RecentActivityEntry[] }) {
  return (
    <div>
      <h3 className="text-xs font-semibold uppercase tracking-wider mb-2 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
        <History size={11} /> Recent Activity
      </h3>
      <div className="rounded-xl p-3 space-y-1.5 max-h-48 overflow-y-auto" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        {entries.map((entry, i) => (
          <div key={i} className="text-xs flex items-start gap-2" style={{ color: 'var(--text-secondary)' }}>
            <span className="flex-shrink-0 tabular-nums" style={{ color: 'var(--text-muted)' }}>
              {formatDate(entry.timestamp)}
            </span>
            <span className="truncate">
              {entry.project ? <span style={{ color: 'var(--text-muted)' }}>[{entry.project}] </span> : null}
              {entry.description}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}
