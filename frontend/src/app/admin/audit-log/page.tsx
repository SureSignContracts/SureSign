'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { ClipboardList, Search, ChevronDown, ChevronRight } from 'lucide-react';
import EmptyState from '@/components/ui/EmptyState';
import { useAuthStore } from '@/store/authStore';

const ACTION_LABELS: Record<string, { label: string; color: string }> = {
  'contract.created':               { label: 'Contract Created',       color: '#60a5fa' },
  'contract.updated':               { label: 'Contract Updated',       color: '#a78bfa' },
  'contract.archived':              { label: 'Contract Archived',      color: '#9a9490' },
  'contract.deleted':               { label: 'Contract Deleted',       color: '#f87171' },
  'ai_analysis.confirmed':          { label: 'AI Analysis Confirmed',  color: '#4ade80' },
  'ai_analysis.cancelled':          { label: 'AI Analysis Cancelled',  color: '#9a9490' },
  'payment_application.submitted':  { label: 'Payment App Submitted',  color: '#60a5fa' },
  'payment_application.certified':  { label: 'Payment App Certified',  color: '#4ade80' },
  'payment_application.cancelled':  { label: 'Payment App Cancelled',  color: '#9a9490' },
  'payment_application.deleted':    { label: 'Payment App Deleted',    color: '#f87171' },
  'payment_notice.issued':          { label: 'Payment Notice Issued',  color: '#facc15' },
  'pay_less_notice.issued':         { label: 'Pay Less Notice Issued', color: '#fb923c' },
};

const ACTION_OPTIONS = [
  { value: '', label: 'All actions' },
  ...Object.entries(ACTION_LABELS).map(([value, { label }]) => ({ value, label })),
];

// created_at is a genuine DATETIME instant — resolved to the viewer's
// effective SureSign timezone (organisation/user preference), not the
// browser's own local OS timezone, before formatting.
function formatTimestamp(ts: string) {
  const d = new Date(ts);
  const timeZone = useAuthStore.getState().user?.effective_timezone;
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone })
    + ' ' + d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone });
}

function ActionBadge({ action }: { action: string }) {
  const cfg = ACTION_LABELS[action] ?? { label: action, color: '#9a9490' };
  return (
    <span
      className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
      style={{ backgroundColor: cfg.color + '18', color: cfg.color }}
    >
      {cfg.label}
    </span>
  );
}

function LogRow({ entry }: { entry: any }) {
  const [expanded, setExpanded] = useState(false);
  const hasMetadata = entry.metadata && Object.keys(entry.metadata).length > 0;

  return (
    <>
      <tr
        className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer"
        style={{ borderBottom: '1px solid var(--border)' }}
        onClick={() => hasMetadata && setExpanded(e => !e)}
      >
        <td className="px-4 py-3 text-xs font-mono tabular-nums whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>
          {formatTimestamp(entry.created_at)}
        </td>
        <td className="px-4 py-3">
          <ActionBadge action={entry.action} />
        </td>
        <td className="px-4 py-3 text-sm max-w-xs" style={{ color: 'var(--text-primary)' }}>
          {entry.description}
        </td>
        <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>
          {entry.user?.name ?? <span style={{ color: 'var(--text-muted)' }}>—</span>}
        </td>
        <td className="px-4 py-3 text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>
          {entry.ip_address ?? '—'}
        </td>
        <td className="px-4 py-3">
          {hasMetadata && (
            <span style={{ color: 'var(--text-muted)' }}>
              {expanded ? <ChevronDown size={13} /> : <ChevronRight size={13} />}
            </span>
          )}
        </td>
      </tr>
      {expanded && hasMetadata && (
        <tr style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-elevated)' }}>
          <td colSpan={6} className="px-6 py-3">
            <pre className="text-xs leading-relaxed overflow-x-auto" style={{ color: 'var(--text-secondary)' }}>
              {JSON.stringify(entry.metadata, null, 2)}
            </pre>
          </td>
        </tr>
      )}
    </>
  );
}

export default function AuditLogPage() {
  const [search, setSearch]   = useState('');
  const [action, setAction]   = useState('');
  const [page, setPage]       = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-audit-log', search, action, page],
    queryFn: () => {
      const params: Record<string, any> = { page };
      if (search) params.search = search;
      if (action) params.action = action;
      return api.get('/admin/audit-log', { params }).then(r => r.data);
    },
    placeholderData: (prev: any) => prev,
  });

  const entries: any[]  = data?.data ?? [];
  const lastPage: number = data?.last_page ?? 1;
  const total: number    = data?.total ?? 0;

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5 pb-10">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Audit Log</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Immutable record of all key actions across the platform
          {!isLoading && total > 0 && <span className="ml-1">· {total} entries</span>}
        </p>
      </div>

      {/* Toolbar */}
      <div className="flex gap-3 flex-wrap items-center">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => { setSearch(e.target.value); setPage(1); }}
            placeholder="Search descriptions…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }}
          />
        </div>
        <div className="relative">
          <select
            value={action}
            onChange={e => { setAction(e.target.value); setPage(1); }}
            className="appearance-none pl-3 pr-8 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: action ? 'var(--text-primary)' : 'var(--text-muted)', minWidth: '180px' }}
          >
            {ACTION_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
        </div>
        {(search || action) && (
          <button
            onClick={() => { setSearch(''); setAction(''); setPage(1); }}
            className="text-xs px-3 py-2 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}
          >
            Clear
          </button>
        )}
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full min-w-[800px] text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Timestamp', 'Action', 'Description', 'User', 'IP Address', ''].map(h => (
                <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(8)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(6)].map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: j === 2 ? '70%' : '50%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : entries.length === 0 ? (
              <tr>
                <td colSpan={6}>
                  <EmptyState icon={ClipboardList} title="No audit log entries yet." />
                </td>
              </tr>
            ) : (
              entries.map((e: any) => <LogRow key={e.id} entry={e} />)
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {!isLoading && lastPage > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Page {data?.current_page} of {lastPage}</p>
          <div className="flex gap-2">
            <button disabled={page <= 1} onClick={() => setPage(p => p - 1)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-40"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              Previous
            </button>
            <button disabled={page >= lastPage} onClick={() => setPage(p => p + 1)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-40"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
