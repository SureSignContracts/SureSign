'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Bell, CheckCheck, Circle, X, Trash2, AlertTriangle } from 'lucide-react';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import PaginationBar from '@/components/ui/PaginationBar';

type NotificationType =
  | 'document_generated'
  | 'file_uploaded'
  | 'file_deleted'
  | 'template_uploaded'
  | 'template_updated'
  | 'template_deleted'
  | 'trade_package_generated'
  | 'trade_package_created'
  | 'system';

interface Notification {
  id: number;
  type: NotificationType;
  title: string;
  message: string;
  is_read: boolean;
  read_at: string | null;
  created_at: string;
}

interface NotificationsResponse {
  data: Notification[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
  from: number | null;
  to: number | null;
  unread_count: number;
}

type FilterTab = 'all' | 'unread' | 'documents' | 'templates' | 'trade_packages' | 'system';

const FILTER_TABS: { label: string; value: FilterTab }[] = [
  { label: 'All', value: 'all' },
  { label: 'Unread', value: 'unread' },
  { label: 'Documents', value: 'documents' },
  { label: 'Templates', value: 'templates' },
  { label: 'Trade Packages', value: 'trade_packages' },
  { label: 'System', value: 'system' },
];

const FILTER_TYPE_MAP: Record<FilterTab, NotificationType[] | null> = {
  all: null,
  unread: null,
  documents: ['document_generated', 'file_uploaded', 'file_deleted'],
  templates: ['template_uploaded', 'template_updated', 'template_deleted'],
  trade_packages: ['trade_package_generated', 'trade_package_created'],
  system: ['system'],
};

const TYPE_LABELS: Record<NotificationType, string> = {
  document_generated: 'Document Generated',
  file_uploaded: 'File Uploaded',
  file_deleted: 'File Deleted',
  template_uploaded: 'Template Uploaded',
  template_updated: 'Template Updated',
  template_deleted: 'Template Deleted',
  trade_package_generated: 'Trade Package Generated',
  trade_package_created: 'Trade Package Created',
  system: 'System',
};

const TYPE_ICONS: Record<NotificationType, string> = {
  document_generated: '📄',
  file_uploaded: '📁',
  file_deleted: '🗑️',
  template_uploaded: '📋',
  template_updated: '✏️',
  template_deleted: '🗑️',
  trade_package_generated: '📦',
  trade_package_created: '📦',
  system: '⚙️',
};

function formatDate(dateString: string): string {
  return new Date(dateString).toLocaleString('en-GB', {
    day: 'numeric', month: 'short', year: 'numeric',
    hour: '2-digit', minute: '2-digit',
  });
}

function truncate(text: string, length: number): string {
  return text.length <= length ? text : text.slice(0, length) + '…';
}

// ── Confirm modal ──────────────────────────────────────────────────────────────

function ConfirmModal({
  title,
  message,
  confirmLabel,
  onConfirm,
  onClose,
  loading,
}: {
  title: string;
  message: string;
  confirmLabel: string;
  onConfirm: () => void;
  onClose: () => void;
  loading: boolean;
}) {
  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center"
      style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}
      onClick={onClose}
    >
      <div
        className="w-full max-w-sm rounded-2xl p-6 shadow-2xl"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-center gap-3 mb-3">
          <div className="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0"
            style={{ backgroundColor: 'rgba(239,68,68,0.12)' }}>
            <AlertTriangle size={16} style={{ color: '#ef4444' }} />
          </div>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h2>
        </div>
        <p className="text-sm mb-5" style={{ color: 'var(--text-secondary)' }}>{message}</p>
        <div className="flex gap-3">
          <button
            onClick={onClose}
            disabled={loading}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
          >
            Cancel
          </button>
          <button
            onClick={onConfirm}
            disabled={loading}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-60"
            style={{ backgroundColor: '#ef4444', color: '#fff' }}
          >
            {loading ? 'Clearing…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Page ───────────────────────────────────────────────────────────────────────

export default function NotificationsPage() {
  const [activeFilter, setActiveFilter] = useState<FilterTab>('all');
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
    if (activeFilter === 'unread') params.filter = 'unread';
    const types = FILTER_TYPE_MAP[activeFilter];
    if (types) params.type = types.join(',');
    return params;
  };

  const { data, isLoading } = useQuery<NotificationsResponse>({
    queryKey: ['notifications', activeFilter, page, perPage],
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

  const markAllReadMutation = useMutation({
    mutationFn: () => api.post('/notifications/mark-all-read'),
    onSuccess: () => { invalidate(); toast.success('All notifications marked as read'); },
    onError: () => toast.error('Failed to mark all as read'),
  });

  const clearReadMutation = useMutation({
    mutationFn: () => api.delete('/notifications/clear-read'),
    onSuccess: () => {
      invalidate();
      setSelected(new Set());
      setConfirmClearRead(false);
      toast.success('Read notifications cleared');
    },
    onError: () => toast.error('Failed to clear read notifications'),
  });

  const clearSelectedMutation = useMutation({
    mutationFn: () => api.delete('/notifications/clear-selected', { data: { ids: Array.from(selected) } }),
    onSuccess: () => {
      invalidate();
      setSelected(new Set());
      setConfirmClearSelected(false);
      toast.success('Selected notifications cleared');
    },
    onError: () => toast.error('Failed to clear selected notifications'),
  });

  const clearOneMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/notifications/${id}`),
    onSuccess: () => invalidate(),
    onError: () => toast.error('Failed to dismiss notification'),
  });

  const handleFilterChange = (filter: FilterTab) => {
    setActiveFilter(filter);
    setPage(1);
    setSelected(new Set());
  };

  const toggleSelect = (id: number) => {
    setSelected(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const toggleSelectAll = () => {
    const rows = data?.data ?? [];
    if (selected.size === rows.length) {
      setSelected(new Set());
    } else {
      setSelected(new Set(rows.map(n => n.id)));
    }
  };

  const rows = data?.data ?? [];
  const hasUnread = (data?.unread_count ?? 0) > 0;
  const hasReadRows = rows.some(n => n.is_read);
  const allSelected = rows.length > 0 && selected.size === rows.length;
  const currentPage = data?.current_page ?? 1;
  const lastPage = data?.last_page ?? 1;
  const total = data?.total ?? 0;
  const dataPerPage = data?.per_page ?? perPage;

  return (
    <div className="p-6 max-w-6xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between mb-6 flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>
            Notifications
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-secondary)' }}>
            Your activity and system notifications
          </p>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {selected.size > 0 && (
            <button
              onClick={() => setConfirmClearSelected(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-80"
              style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.25)' }}
            >
              <Trash2 size={14} />
              Clear {selected.size} selected
            </button>
          )}
          {hasReadRows && selected.size === 0 && (
            <button
              onClick={() => setConfirmClearRead(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-80"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
            >
              <X size={14} />
              Clear read
            </button>
          )}
          <button
            onClick={() => markAllReadMutation.mutate()}
            disabled={markAllReadMutation.isPending}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity disabled:opacity-50 disabled:cursor-not-allowed hover:opacity-90"
            style={{ backgroundColor: 'var(--gold)', color: '#000' }}
          >
            <CheckCheck size={16} />
            {markAllReadMutation.isPending ? 'Marking…' : 'Mark All Read'}
          </button>
        </div>
      </div>

      {/* Filter Tabs */}
      <div className="flex gap-1 mb-4 p-1 rounded-lg w-fit" style={{ backgroundColor: 'var(--bg-surface)' }}>
        {FILTER_TABS.map((tab) => (
          <button
            key={tab.value}
            onClick={() => handleFilterChange(tab.value)}
            className="px-3 py-1.5 rounded-md text-sm font-medium transition-all"
            style={{
              backgroundColor: activeFilter === tab.value ? 'var(--bg-base)' : 'transparent',
              color: activeFilter === tab.value ? 'var(--text-primary)' : 'var(--text-muted)',
              boxShadow: activeFilter === tab.value ? '0 1px 3px rgba(0,0,0,0.1)' : 'none',
            }}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Table */}
      <div
        className="rounded-xl overflow-hidden"
        style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}
      >
        {isLoading ? (
          <div className="p-12 text-center" style={{ color: 'var(--text-muted)' }}>
            Loading notifications…
          </div>
        ) : rows.length === 0 ? (
          <div className="p-12 text-center flex flex-col items-center gap-3">
            <Bell size={40} style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No notifications</p>
          </div>
        ) : (
          <table className="w-full">
            <thead>
              <tr
                className="text-xs uppercase tracking-wide border-b"
                style={{ borderColor: 'var(--border)', color: 'var(--text-muted)', backgroundColor: 'var(--bg-base)' }}
              >
                <th className="px-4 py-3 text-left w-8">
                  <input
                    type="checkbox"
                    checked={allSelected}
                    onChange={toggleSelectAll}
                    className="rounded"
                    style={{ accentColor: 'var(--gold)' }}
                  />
                </th>
                <th className="px-4 py-3 text-left font-medium w-48">Type</th>
                <th className="px-4 py-3 text-left font-medium">Title</th>
                <th className="px-4 py-3 text-left font-medium">Message</th>
                <th className="px-4 py-3 text-left font-medium w-44">Date</th>
                <th className="px-4 py-3 text-left font-medium w-24">Status</th>
                <th className="px-4 py-3 w-8" />
              </tr>
            </thead>
            <tbody>
              {rows.map((notification, index) => {
                const isUnread = !notification.is_read;
                const isChecked = selected.has(notification.id);
                return (
                  <tr
                    key={notification.id}
                    className="border-b group transition-colors"
                    style={{
                      borderColor: 'var(--border)',
                      backgroundColor: isChecked
                        ? 'rgba(185,149,102,0.06)'
                        : isUnread
                          ? 'color-mix(in srgb, var(--gold) 3%, var(--bg-surface))'
                          : index % 2 === 0
                            ? 'var(--bg-surface)'
                            : 'var(--bg-base)',
                    }}
                  >
                    <td className="px-4 py-3">
                      <input
                        type="checkbox"
                        checked={isChecked}
                        onChange={() => toggleSelect(notification.id)}
                        style={{ accentColor: 'var(--gold)' }}
                      />
                    </td>
                    <td
                      className="px-4 py-3 cursor-pointer"
                      onClick={() => { if (isUnread) markReadMutation.mutate(notification.id); }}
                    >
                      <div className="flex items-center gap-2">
                        <span className="text-base">{TYPE_ICONS[notification.type] ?? '🔔'}</span>
                        <span className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>
                          {TYPE_LABELS[notification.type] ?? notification.type}
                        </span>
                      </div>
                    </td>
                    <td
                      className="px-4 py-3 cursor-pointer"
                      onClick={() => { if (isUnread) markReadMutation.mutate(notification.id); }}
                    >
                      <div className="flex items-center gap-2">
                        {isUnread && <span className="w-1.5 h-1.5 rounded-full flex-shrink-0 bg-blue-500" />}
                        <span
                          className="text-sm"
                          style={{ color: 'var(--text-primary)', fontWeight: isUnread ? 600 : 400 }}
                        >
                          {notification.title}
                        </span>
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                        {truncate(notification.message, 80)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {formatDate(notification.created_at)}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {isUnread ? (
                        <span
                          className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                          style={{ backgroundColor: 'rgba(59,130,246,0.15)', color: 'rgb(59,130,246)' }}
                        >
                          <Circle size={6} fill="currentColor" />
                          Unread
                        </span>
                      ) : (
                        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Read</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => clearOneMutation.mutate(notification.id)}
                        className="p-1 rounded-md opacity-0 group-hover:opacity-100 transition-opacity hover:bg-[var(--bg-elevated)]"
                        title="Dismiss"
                        style={{ color: 'var(--text-muted)' }}
                      >
                        <X size={13} />
                      </button>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </div>

      <PaginationBar
        page={currentPage}
        lastPage={lastPage}
        total={total}
        perPage={dataPerPage}
        onPage={p => { setPage(p); setSelected(new Set()); }}
        onPerPage={n => { setPerPage(n); setPage(1); setSelected(new Set()); }}
      />

      {/* Confirm modals */}
      {confirmClearRead && (
        <ConfirmModal
          title="Clear read notifications"
          message="This will permanently delete all read notifications. Audit logs will not be affected."
          confirmLabel="Clear all read"
          onConfirm={() => clearReadMutation.mutate()}
          onClose={() => setConfirmClearRead(false)}
          loading={clearReadMutation.isPending}
        />
      )}
      {confirmClearSelected && (
        <ConfirmModal
          title={`Clear ${selected.size} notification${selected.size !== 1 ? 's' : ''}`}
          message="Selected notifications will be permanently deleted. Audit logs will not be affected."
          confirmLabel={`Clear ${selected.size} selected`}
          onConfirm={() => clearSelectedMutation.mutate()}
          onClose={() => setConfirmClearSelected(false)}
          loading={clearSelectedMutation.isPending}
        />
      )}
    </div>
  );
}
