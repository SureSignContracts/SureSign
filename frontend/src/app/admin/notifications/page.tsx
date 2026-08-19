'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import {
  Bell, CheckCheck, Circle, X, Trash2, AlertTriangle,
  ExternalLink, AlertCircle, Clock, Info, Inbox, SlidersHorizontal,
} from 'lucide-react';
import api from '@/lib/api';
import toast from '@/lib/toast';
import PaginationBar from '@/components/ui/PaginationBar';
import { type SuresignNotification, type NotificationFilter } from '@/hooks/useNotifications';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import Select from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/getErrorMessage';

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

const PRIORITY_CONFIG: Record<string, { color: string; bg: string; label: string; border: string }> = {
  critical: { color: '#b42318', bg: '#fff1f0', border: '#ffd7d2', label: 'Critical' },
  warning:  { color: '#b54708', bg: '#fff7e8', border: '#fde4b5', label: 'Warning' },
  reminder: { color: '#7a5d00', bg: '#fff9db', border: '#f4e79d', label: 'Reminder' },
  info:     { color: '#53645b', bg: '#f1f5f2', border: '#e2e9e4', label: 'Info' },
};

const STATUS_CONFIG: Record<string, { color: string; bg: string }> = {
  unread:    { color: '#60a5fa', bg: 'rgba(59,130,246,0.15)' },
  read:      { color: 'var(--text-muted)', bg: 'var(--bg-elevated)' },
  dismissed: { color: '#9a9490', bg: 'rgba(90,86,82,0.15)' },
  resolved:  { color: '#4ade80', bg: 'rgba(34,197,94,0.12)' },
  expired:   { color: '#9a9490', bg: 'rgba(90,86,82,0.15)' },
};

// ── Helpers ───────────────────────────────────────────────────────────────────

// created_at is a genuine DATETIME instant — resolved to the viewer's
// effective SureSign timezone, not the browser's own local OS timezone.
function formatDate(dateString: string): string {
  return formatDateTime(dateString, {
    timeZone: useAuthStore.getState().user?.effective_timezone,
    locale: 'en-GB',
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
    <span className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.08em]"
      style={{ backgroundColor: cfg.bg, color: cfg.color, borderColor: cfg.border }}>
      <Icon size={10} />
      {cfg.label}
    </span>
  );
}

function StatusBadge({ status }: { status: string }) {
  const cfg = STATUS_CONFIG[status] ?? STATUS_CONFIG.read;
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold capitalize"
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
    <div className="ss-notifications-modal-overlay fixed inset-0 z-[100] flex items-center justify-center bg-[#101713]/70 p-4 backdrop-blur-[6px]" onClick={onClose}>
      <div className="ss-notifications-modal-panel w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-[0_30px_90px_rgba(5,14,10,0.34)]"
        onClick={e => e.stopPropagation()}>
        <div className="bg-[#18211d] px-6 py-5 text-white">
          <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-xl bg-white/10">
            <Trash2 size={16} />
          </div>
          <h2 className="text-xl font-semibold tracking-[-0.02em]">{title}</h2>
        </div>
        <div className="px-6 py-5">
          <p className="text-sm leading-6 text-gray-600">{message}</p>
          <div className="mt-6 flex justify-end gap-3">
          <button onClick={onClose} disabled={loading}
            className="rounded-lg px-4 py-2.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 disabled:opacity-50">
            Cancel
          </button>
          <button onClick={onConfirm} disabled={loading}
            className="rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-red-700 disabled:opacity-60">
            {loading ? 'Clearing…' : confirmLabel}
          </button>
          </div>
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

  const { data, isLoading, error } = useQuery<NotificationsResponse>({
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
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to mark as read')),
  });

  const dismissMutation = useMutation({
    mutationFn: (id: number) => api.patch(`/notifications/${id}/dismiss`),
    onSuccess: () => invalidate(),
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to dismiss')),
  });

  const markAllReadMutation = useMutation({
    mutationFn: () => api.post('/notifications/mark-all-read'),
    onSuccess: () => { invalidate(); toast.success('All notifications marked as read'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to mark all as read')),
  });

  const clearReadMutation = useMutation({
    mutationFn: () => api.delete('/notifications/clear-read'),
    onSuccess: () => { invalidate(); setSelected(new Set()); setConfirmClearRead(false); toast.success('Read notifications cleared'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to clear read notifications')),
  });

  const clearSelectedMutation = useMutation({
    mutationFn: () => api.delete('/notifications/clear-selected', { data: { ids: Array.from(selected) } }),
    onSuccess: () => { invalidate(); setSelected(new Set()); setConfirmClearSelected(false); toast.success('Selected notifications cleared'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to clear selected notifications')),
  });

  const handleFilterChange = (filter: NotificationFilter) => {
    setActiveFilter(filter);
    setPriorityFilter('');
    setCategoryFilter('');
    setPage(1);
    setSelected(new Set());
  };

  const toggleSelect = (id: number) => {
    setSelected(prev => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
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
    <div className="ss-notifications-page mx-auto max-w-[1520px] p-5 lg:p-8">
      <section className="ss-notifications-hero relative overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_60px_rgba(18,33,26,0.14)]">
        <div className="pointer-events-none absolute -right-24 -top-40 h-80 w-80 rounded-full border border-white/[0.06]" />
        <div className="pointer-events-none absolute -right-8 -top-24 h-56 w-56 rounded-full border border-white/[0.06]" />
        <div className="relative flex flex-col gap-8 px-7 py-7 lg:flex-row lg:items-end lg:justify-between lg:px-10 lg:py-9">
          <div>
            <div className="mb-5 flex items-center gap-3 text-[11px] font-semibold uppercase tracking-[0.18em] text-[#9ee5b5]">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-[#9ee5b5] text-[#18211d]"><Bell size={17} /></span>
              Platform signal desk
            </div>
            <h1 className="text-3xl font-semibold tracking-[-0.035em] sm:text-4xl">Notifications</h1>
            <p className="mt-3 max-w-2xl text-sm leading-6 text-white/60 sm:text-base">Review operational activity, customer responses and platform events from one focused register.</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {selected.size > 0 ? (
              <button onClick={() => setConfirmClearSelected(true)} className="inline-flex items-center gap-2 rounded-xl border border-red-300/20 bg-red-400/10 px-4 py-3 text-sm font-medium text-red-200 transition-colors hover:bg-red-400/15"><Trash2 size={15} /> Clear {selected.size} selected</button>
            ) : hasReadRows ? (
              <button onClick={() => setConfirmClearRead(true)} className="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm font-medium text-white/75 transition-all hover:border-white/20 hover:bg-white/[0.08] hover:text-white"><X size={15} /> Clear read</button>
            ) : null}
            <button onClick={() => markAllReadMutation.mutate()} disabled={unreadCount === 0 || markAllReadMutation.isPending} className="inline-flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-3 text-sm font-semibold text-[#18211d] transition-all hover:-translate-y-0.5 hover:bg-[#afecc1] disabled:cursor-not-allowed disabled:opacity-35 disabled:hover:translate-y-0"><CheckCheck size={16} />{markAllReadMutation.isPending ? 'Marking…' : 'Mark all read'}</button>
          </div>
        </div>
        <div className="relative grid border-t border-white/10 sm:grid-cols-2">
          <div className="flex items-center gap-4 px-7 py-5 lg:px-10"><Inbox size={18} className="text-white/35" /><div><strong className="text-2xl text-[#9ee5b5]">{unreadCount}</strong><span className="ml-2 text-sm text-white/55">unread across the platform</span></div></div>
          <div className="flex items-center gap-4 border-t border-white/10 px-7 py-5 sm:border-l sm:border-t-0 lg:px-10"><SlidersHorizontal size={18} className="text-white/35" /><div><strong className="text-2xl text-[#9ee5b5]">{total}</strong><span className="ml-2 text-sm text-white/55">records in this view</span></div></div>
        </div>
      </section>

      <section className="ss-notifications-content mt-7">
        <div className="flex flex-col gap-4 border-b border-gray-200 pb-5 xl:flex-row xl:items-center xl:justify-between">
          <div className="flex max-w-full items-center gap-1 overflow-x-auto rounded-xl bg-white p-1 shadow-[0_8px_24px_rgba(24,33,29,0.06)]">
            {STATUS_TABS.map(tab => (
              <button key={tab.value} onClick={() => handleFilterChange(tab.value)} className={`shrink-0 rounded-lg px-4 py-2.5 text-sm font-medium transition-all ${activeFilter === tab.value && !priorityFilter && !categoryFilter ? 'bg-[#18211d] text-white shadow-sm' : 'text-gray-500 hover:bg-gray-50 hover:text-gray-900'}`}>{tab.label}</button>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Select value={priorityFilter} onChange={e => { setPriorityFilter(e.target.value); setCategoryFilter(''); setPage(1); }} size="sm">{PRIORITY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
            <Select value={categoryFilter} onChange={e => { setCategoryFilter(e.target.value); setPriorityFilter(''); setPage(1); }} size="sm">{CATEGORY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</Select>
          </div>
        </div>

        <div className="ss-notifications-register mt-5 overflow-hidden rounded-2xl bg-white shadow-[0_12px_35px_rgba(24,33,29,0.07)]">
          <div className="flex items-center justify-between border-b border-gray-100 px-5 py-4 sm:px-6">
            <div className="flex items-center gap-3"><input type="checkbox" checked={allSelected} onChange={toggleSelectAll} aria-label="Select all notifications" className="h-4 w-4 rounded border-gray-300 accent-[#18211d]" /><div><h2 className="text-sm font-semibold text-gray-900">Notification register</h2><p className="mt-0.5 text-xs text-gray-400">Newest platform activity first</p></div></div>
            <span className="text-xs font-medium text-gray-400">{rows.length} shown</span>
          </div>
          {error ? (
            <div className="flex flex-col items-center px-6 py-16 text-center"><AlertTriangle size={24} className="text-red-500" /><p className="mt-3 text-sm font-medium text-gray-900">Notifications could not be loaded</p><p className="mt-1 text-sm text-gray-500">Please try again shortly.</p></div>
          ) : isLoading ? (
            <div className="divide-y divide-gray-100">{[0, 1, 2, 3].map(item => <div key={item} className="flex gap-4 px-6 py-5"><div className="h-11 w-11 animate-pulse rounded-xl bg-gray-100" /><div className="flex-1"><div className="h-3 w-1/3 animate-pulse rounded bg-gray-100" /><div className="mt-3 h-3 w-2/3 animate-pulse rounded bg-gray-100" /></div></div>)}</div>
          ) : rows.length === 0 ? (
            <div className="flex flex-col items-center px-6 py-16 text-center"><span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#edf8f1] text-[#2f7c4d]"><Bell size={21} /></span><p className="mt-4 text-sm font-semibold text-gray-900">Nothing in this view</p><p className="mt-1 text-sm text-gray-500">Choose another status or adjust the filters.</p></div>
          ) : (
            <div key={`${activeFilter}-${priorityFilter}-${categoryFilter}-${page}`} className="ss-notifications-list divide-y divide-gray-100">
              {rows.map((n, index) => {
                const isUnread = n.status === 'unread';
                const cfg = PRIORITY_CONFIG[n.priority ?? 'info'] ?? PRIORITY_CONFIG.info;
                const Icon = n.priority === 'critical' ? AlertTriangle : n.priority === 'warning' ? AlertCircle : n.priority === 'reminder' ? Clock : Info;
                return (
                  <article key={n.id} className={`ss-notifications-row group grid grid-cols-[auto_2.75rem_minmax(0,1fr)] gap-x-3 px-5 py-5 transition-[background-color,transform] duration-200 hover:bg-[#fafcfb] sm:px-6 lg:grid-cols-[auto_2.75rem_minmax(0,1fr)_13rem_auto] ${isUnread ? 'bg-[#f7fcf9]' : ''}`} style={{ animationDelay: `${Math.min(index, 8) * 45}ms` }}>
                    <input type="checkbox" checked={selected.has(n.id)} onChange={() => toggleSelect(n.id)} aria-label={`Select ${n.title}`} className="mt-3 h-4 w-4 rounded border-gray-300 accent-[#18211d]" />
                    <span className="ss-notifications-priority-icon flex h-11 w-11 items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-105" style={{ backgroundColor: cfg.bg, color: cfg.color }}><Icon size={17} /></span>
                    <button type="button" onClick={() => handleOpen(n)} className="min-w-0 text-left">
                      <div className="flex flex-wrap items-center gap-2">{isUnread ? <span className="h-1.5 w-1.5 rounded-full bg-[#2f9e5a]" /> : null}<h3 className={`truncate text-sm text-gray-900 ${isUnread ? 'font-semibold' : 'font-medium'}`}>{n.title}</h3></div>
                      <p className="mt-1 max-w-3xl text-sm leading-5 text-gray-500">{truncate(n.message, 110)}</p>
                      <div className="mt-3 flex flex-wrap items-center gap-2"><PriorityBadge priority={n.priority} />{n.category ? <span className="rounded-md bg-gray-100 px-2 py-1 text-[11px] font-medium capitalize text-gray-500">{n.category}</span> : null}<span className="lg:hidden"><StatusBadge status={n.status} /></span></div>
                    </button>
                    <div className="hidden self-center lg:block"><p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-gray-400">Received</p><p className="mt-1 text-sm text-gray-600">{formatDate(n.created_at)}</p></div>
                    <div className="col-span-2 col-start-2 mt-3 flex items-center justify-between gap-3 lg:col-span-1 lg:col-start-auto lg:mt-0 lg:justify-end"><span className="hidden lg:block"><StatusBadge status={n.status} /></span><div className="flex items-center gap-1 opacity-100 transition-opacity lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">{n.action_url ? <button onClick={() => handleOpen(n)} className="flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-[#eaf8ef] hover:text-[#24643e]" title="Open"><ExternalLink size={15} /></button> : null}{isUnread ? <button onClick={() => dismissMutation.mutate(n.id)} className="flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-800" title="Dismiss"><X size={15} /></button> : null}</div></div>
                  </article>
                );
              })}
            </div>
          )}
        </div>
      </section>

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
