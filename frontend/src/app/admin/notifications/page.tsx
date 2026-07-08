'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import {
  Bell, CheckCheck, Circle, X, Trash2, AlertTriangle,
  ExternalLink, AlertCircle, Clock, Info, ChevronDown,
} from 'lucide-react';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import PaginationBar from '@/components/ui/PaginationBar';
import { type SuresignNotification, type NotificationFilter } from '@/hooks/useNotifications';

// ── Types ─────────────────────────────────────────────────────────────────────

interface NotificationsResponse {
  data: SuresignNotification[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
  from: number | null;
  to: number | null;
  unread_count: number;
}

// ── Filter tabs ───────────────────────────────────────────────────────────────

const STATUS_TABS: { label: string; value: NotificationFilter }[] = [
  { label: 'Active',    value: 'active' },
  { label: 'Unread',   value: 'unread' },
  { label: 'Read',     value: 'read' },
  { label: 'Dismissed',value: 'dismissed' },
  { label: 'Resolved', value: 'resolved' },
  { label: 'All',      value: 'all' },
];

const PRIORITY_OPTIONS: { label: string; value: string }[] = [
  { label: 'All priorities', value: '' },
  { label: 'Critical',       value: 'critical' },
  { label: 'Warning',        value: 'warning' },
  { label: 'Reminder',       value: 'reminder' },
  { label: 'Info',           value: 'info' },
];

const CATEGORY_OPTIONS: { label: string; value: string }[] = [
  { label: 'All categories', value: '' },
  { label: 'Payment',        value: 'payment' },
  { label: 'Commercial',     value: 'commercial' },
  { label: 'Contract',       value: 'contract' },
  { label: 'Compliance',     value: 'compliance' },
  { label: 'Programme',      value: 'programme' },
  { label: 'Variation',      value: 'variation' },
  { label: 'Risk',           value: 'risk' },
  { label: 'Retention',      value: 'retention' },
  { label: 'Deliverable',    value: 'deliverable' },
  { label: 'Notice',         value: 'notice' },
  { label: 'Communication',  value: 'communication' },
  { label: 'General',        value: 'general' },
];

// ── Priority config ───────────────────────────────────────────────────────────

const PRIORITY_CONFIG: Record<string, { color: string; bg: string; label: string }> = {
  critical: { color: '#f87171', bg: 'rgba(239,68,68,0.12)',  label: 'Critical' },
  warning:  { color: '#fb923c', bg: 'rgba(249,115,22,0.12)', label: 'Warning' },
  reminder: { color: '#facc15', bg: 'rgba(234,179,8,0.12)',  label: 'Reminder' },
  info:     { color: 'var(--text-muted)', bg: 'var(--bg-elevated)', label: 'Info' },
};

const STATUS_CONFIG: Record<string, { color: string; bg: string }> = {
  unread:    { color: '#60a5fa', bg: 'rgba(59,130,246,0.15)' },
  read:      { color: 'var(--text-muted)', bg: 'var(--bg-elevated)' },
  dismissed: { color: '#9a9490', bg: 'rgba(90,86,82,0.15)' },
  resolved:  { color: '#4ade80', bg: 'rgba(34,197,94,0.12)' },
  expired:   { color: '#9a9490', bg: 'rgba(90,86,82,0.15)' },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatDate(dateString: string): string {
  return new Date(dateString).toLocaleString('en-GB', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function truncate(text: string, length: number): string {
  return text.length <= length ? text : text.slice(0, length) + '…';
}

function PriorityBadge({ priority }: { priority: string | null }) {
  if (!priority) return null;
  const cfg = PRIORITY_CONFIG[priority] ?? PRIORITY_CONFIG.info;
  const Icon = priority === 'critical' ? AlertTriangle
    : priority === 'warning'  ? AlertCircle
    : priority === 'reminder' ? Clock
    : Info;
  return (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
      style={{ backgroundColor: cfg.bg, color: cfg.color }}>
      <Icon size={10} />
      {cfg.label}
    </span>
  );
}

function StatusBadge({ status }: { status: string }) {
  const cfg = STATUS_CONFIG[status] ?? STATUS_CONFIG.read;
  return (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium capitalize"
      style={{ backgroundColor: cfg.bg, color: cfg.color }}>
      {status === 'unread' && <Circle size={6} fill="currentColor" />}
      {status}
    </span>
  );
}

// ── Confirm modal ─────────────────────────────────────────────────────────────

function ConfirmModal({
  title, message, confirmLabel, onConfirm, onClose, loading,
}: {
  title: string; message: string; confirmLabel: string;
  onConfirm: () => void; onClose: () => void; loading: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm"
      style={{ backgroundColor: 'rgba(0,0,0,0.6)' }} onClick={onClose}>
      <div className="w-full max-w-sm rounded-2xl p-6 shadow-2xl ss-animate-in"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}>
        <div className="flex items-center gap-3 mb-3">
          <div className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
            style={{ backgroundColor: 'rgba(239,68,68,0.12)' }}>
            <AlertTriangle size={16} style={{ color: '#ef4444' }} />
          </div>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h2>
        </div>
        <p className="text-sm mb-5" style={{ color: 'var(--text-secondary)' }}>{message}</p>
        <div className="flex gap-3">
          <button onClick={onClose} disabled={loading}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button onClick={onConfirm} disabled={loading}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-60"
            style={{ backgroundColor: '#ef4444', color: '#fff' }}>
            {loading ? 'Clearing…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function NotificationsPage() {
  const router = useRouter();
  const [activeFilter, setActiveFilter] = useState<NotificationFilter>('active');
  const [priorityFilter, setPriorityFilter] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [confirmClearRead, setConfirmClearRead] = useState(false);
  const [confirmClearSelected, setConfirmClearSelected] = useState(false);
  const queryClient = useQueryClient();

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['notifications'] });
    queryClient.invalidateQueries({ queryKey: ['notifications-count'] });
  }

  const buildQueryParams = () => {
    const params: Record<string, string> = { page: String(page), per_page: String(perPage) };
    // If a priority or category filter is active, it overrides the status filter
    if (priorityFilter)  { params.filter = priorityFilter; return params; }
    if (categoryFilter)  { params.filter = categoryFilter; return params; }
    if (activeFilter !== 'active') params.filter = activeFilter;
    return params;
  };

  const effectiveQueryKey = [
    'notifications', activeFilter, priorityFilter, categoryFilter, page, perPage,
  ];

  const { data, isLoading } = useQuery<NotificationsResponse>({
    queryKey: effectiveQueryKey,
    queryFn: async () => {
      const params = new URLSearchParams(buildQueryParams());
      const response = await api.get(`/notifications?${params.toString()}`);
      return response.data;
    },
  });

  const markReadMutation = useMutation({
    mutationFn: (id: number) => api.patch(`/notifications/${id}/read`),
    onSuccess: () => invalidate(),
    onError: () => toast.error('Failed to mark as read'),
  });

  const dismissMutation = useMutation({
    mutationFn: (id: number) => api.patch(`/notifications/${id}/dismiss`),
    onSuccess: () => invalidate(),
    onError: () => toast.error('Failed to dismiss'),
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => api.post('/notifications/mark-all-read'),
    onSuccess: () => { invalidate(); toast.success('All notifications marked as read'); },
    onError: () => toast.error('Failed to mark all as read'),
  });

  const clearReadMutation = useMutation({
    mutationFn: () => api.delete('/notifications/clear-read'),
    onSuccess: () => { invalidate(); setSelected(new Set()); setConfirmClearRead(false); toast.success('Read notifications cleared'); },
    onError: () => toast.error('Failed to clear read notifications'),
  });

  const clearSelectedMutation = useMutation({
    mutationFn: () => api.delete('/notifications/clear-selected', { data: { ids: Array.from(selected) } }),
    onSuccess: () => { invalidate(); setSelected(new Set()); setConfirmClearSelected(false); toast.success('Selected notifications cleared'); },
    onError: () => toast.error('Failed to clear selected notifications'),
  });

  const handleFilterChange = (filter: NotificationFilter) => {
    setActiveFilter(filter);
    setPriorityFilter('');
    setCategoryFilter('');
    setPage(1);
    setSelected(new Set());
  };

  const toggleSelect = (id: number) => {
    setSelected(prev => { const next = new Set(prev); next.has(id) ? next.delete(id) : next.add(id); return next; });
  };

  const toggleSelectAll = () => {
    const rows = data?.data ?? [];
    setSelected(rows.length > 0 && selected.size === rows.length ? new Set() : new Set(rows.map(n => n.id)));
  };

  function handleOpen(n: SuresignNotification) {
    if (n.status === 'unread') markReadMutation.mutate(n.id);
    if (n.action_url) router.push(n.action_url);
  }

  const rows = data?.data ?? [];
  const unreadCount = data?.unread_count ?? 0;
  const hasReadRows = rows.some(n => n.status === 'read');
  const allSelected = rows.length > 0 && selected.size === rows.length;
  const currentPage = data?.current_page ?? 1;
  const lastPage    = data?.last_page ?? 1;
  const total       = data?.total ?? 0;
  const dataPerPage = data?.per_page ?? perPage;

  return (
    <div className="p-6 max-w-6xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Notifications</h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-secondary)' }}>
            Activity, operational reminders, and system alerts
          </p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {selected.size > 0 && (
            <button onClick={() => setConfirmClearSelected(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.25)' }}>
              <Trash2 size={14} /> Clear {selected.size} selected
            </button>
          )}
          {hasReadRows && selected.size === 0 && (
            <button onClick={() => setConfirmClearRead(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}>
              <X size={14} /> Clear read
            </button>
          )}
          <button onClick={() => markAllReadMutation.mutate()} disabled={unreadCount === 0 || markAllReadMutation.isPending}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-50 active:scale-[0.98]"
            style={{ backgroundColor: 'var(--gold)', color: '#000' }}>
            <CheckCheck size={16} />
            {markAllReadMutation.isPending ? 'Marking…' : 'Mark All Read'}
          </button>
        </div>
      </div>

      {/* Filters row */}
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        {/* Status tabs */}
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          {STATUS_TABS.map(tab => (
            <button key={tab.value} onClick={() => handleFilterChange(tab.value)}
              className="px-3 py-1.5 rounded-full text-sm font-medium transition-all active:scale-[0.97]"
              style={{
                backgroundColor: activeFilter === tab.value && !priorityFilter && !categoryFilter ? 'var(--gold)' : 'transparent',
                color: activeFilter === tab.value && !priorityFilter && !categoryFilter ? 'var(--accent-fg)' : 'var(--text-muted)',
              }}>
              {tab.label}
            </button>
          ))}
        </div>

        {/* Priority dropdown */}
        <div className="relative">
          <select value={priorityFilter}
            onChange={e => { setPriorityFilter(e.target.value); setCategoryFilter(''); setPage(1); }}
            className="appearance-none pl-3 pr-8 py-1.5 rounded-lg text-sm cursor-pointer"
            style={{ backgroundColor: priorityFilter ? 'var(--gold)' : 'var(--bg-surface)', color: priorityFilter ? '#000' : 'var(--text-secondary)', border: '1px solid var(--border)' }}>
            {PRIORITY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          <ChevronDown size={13} className="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: priorityFilter ? '#000' : 'var(--text-muted)' }} />
        </div>

        {/* Category dropdown */}
        <div className="relative">
          <select value={categoryFilter}
            onChange={e => { setCategoryFilter(e.target.value); setPriorityFilter(''); setPage(1); }}
            className="appearance-none pl-3 pr-8 py-1.5 rounded-lg text-sm cursor-pointer"
            style={{ backgroundColor: categoryFilter ? 'var(--gold)' : 'var(--bg-surface)', color: categoryFilter ? '#000' : 'var(--text-secondary)', border: '1px solid var(--border)' }}>
            {CATEGORY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
          <ChevronDown size={13} className="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: categoryFilter ? '#000' : 'var(--text-muted)' }} />
        </div>
      </div>

      {/* Table */}
      <div className="rounded-xl overflow-x-auto"
        style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)', boxShadow: 'var(--shadow-card)' }}>
        {isLoading ? (
          <div className="p-12 text-center" style={{ color: 'var(--text-muted)' }}>Loading notifications…</div>
        ) : rows.length === 0 ? (
          <div className="p-12 text-center flex flex-col items-center gap-3">
            <Bell size={40} style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No notifications</p>
          </div>
        ) : (
          <table className="w-full min-w-[720px]">
            <thead>
              <tr className="text-xs uppercase tracking-wide border-b"
                style={{ borderColor: 'var(--border)', color: 'var(--text-muted)', backgroundColor: 'var(--bg-base)' }}>
                <th className="px-4 py-3 text-left w-8">
                  <input type="checkbox" checked={allSelected} onChange={toggleSelectAll} className="rounded" style={{ accentColor: 'var(--gold)' }} />
                </th>
                <th className="px-4 py-3 text-left font-medium w-32">Priority</th>
                <th className="px-4 py-3 text-left font-medium w-28">Category</th>
                <th className="px-4 py-3 text-left font-medium">Title / Message</th>
                <th className="px-4 py-3 text-left font-medium w-40">Date</th>
                <th className="px-4 py-3 text-left font-medium w-28">Status</th>
                <th className="px-4 py-3 w-20" />
              </tr>
            </thead>
            <tbody>
              {rows.map((n, index) => {
                const isUnread  = n.status === 'unread';
                const isChecked = selected.has(n.id);
                return (
                  <tr key={n.id} className="border-b group transition-colors"
                    style={{
                      borderColor: 'var(--border)',
                      backgroundColor: isChecked
                        ? 'var(--gold-8)'
                        : isUnread
                          ? 'color-mix(in srgb, var(--gold) 3%, var(--bg-surface))'
                          : index % 2 === 0 ? 'var(--bg-surface)' : 'var(--bg-base)',
                    }}>
                    <td className="px-4 py-3">
                      <input type="checkbox" checked={isChecked} onChange={() => toggleSelect(n.id)} style={{ accentColor: 'var(--gold)' }} />
                    </td>
                    <td className="px-4 py-3">
                      <PriorityBadge priority={n.priority} />
                    </td>
                    <td className="px-4 py-3">
                      {n.category && (
                        <span className="text-xs capitalize px-2 py-0.5 rounded"
                          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                          {n.category}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 cursor-pointer" onClick={() => isUnread && markReadMutation.mutate(n.id)}>
                      <div className="flex items-center gap-2 mb-0.5">
                        {isUnread && <span className="w-1.5 h-1.5 rounded-full flex-shrink-0 bg-blue-500" />}
                        <span className="text-sm" style={{ color: 'var(--text-primary)', fontWeight: isUnread ? 600 : 400 }}>
                          {n.title}
                        </span>
                      </div>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{truncate(n.message, 80)}</p>
                    </td>
                    <td className="px-4 py-3">
                      <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(n.created_at)}</span>
                    </td>
                    <td className="px-4 py-3">
                      <StatusBadge status={n.status} />
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        {n.action_url && (
                          <button onClick={() => handleOpen(n)}
                            className="p-1 rounded-md hover:bg-[var(--bg-hover)]" title="Open"
                            style={{ color: 'var(--gold)' }}>
                            <ExternalLink size={13} />
                          </button>
                        )}
                        {n.status === 'unread' && (
                          <button onClick={() => dismissMutation.mutate(n.id)}
                            className="p-1 rounded-md hover:bg-[var(--bg-hover)]" title="Dismiss"
                            style={{ color: 'var(--text-muted)' }}>
                            <X size={13} />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>

      <PaginationBar
        page={currentPage} lastPage={lastPage} total={total} perPage={dataPerPage}
        onPage={p => { setPage(p); setSelected(new Set()); }}
        onPerPage={n => { setPerPage(n); setPage(1); setSelected(new Set()); }}
      />

      {confirmClearRead && (
        <ConfirmModal title="Clear read notifications"
          message="This will permanently delete all read notifications."
          confirmLabel="Clear all read"
          onConfirm={() => clearReadMutation.mutate()}
          onClose={() => setConfirmClearRead(false)}
          loading={clearReadMutation.isPending} />
      )}
      {confirmClearSelected && (
        <ConfirmModal title={`Clear ${selected.size} notification${selected.size !== 1 ? 's' : ''}`}
          message="Selected notifications will be permanently deleted."
          confirmLabel={`Clear ${selected.size} selected`}
          onConfirm={() => clearSelectedMutation.mutate()}
          onClose={() => setConfirmClearSelected(false)}
          loading={clearSelectedMutation.isPending} />
      )}
    </div>
  );
}
