'use client';

import { useState, useEffect, useRef, useCallback } from 'react';
import {
  Bell, FileText, Upload, Trash2, LayoutTemplate,
  Package, FolderKanban, RefreshCw, AlertTriangle, UserPlus, Info, X,
  MoreHorizontal, CheckCheck, ExternalLink, AlertCircle, Clock, Zap,
  ChevronRight, Inbox, type LucideIcon,
} from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import api from '@/lib/api';
import { useUnreadCount, useNotifications, type SuresignNotification } from '@/hooks/useNotifications';
import { useAuthStore } from '@/store/authStore';
import { formatDate } from '@/lib/utils';
import { isToday as isTodayInTimezone, formatDateTime } from '@/lib/dateTime';
import toast from 'react-hot-toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { SidebarCountBadge } from '@/components/ui/Badge';

/**
 * For a timed-meeting notification, the shared `message` text can only ever
 * show ONE timezone's rendering (the meeting's scheduling timezone — always
 * explicitly labelled, so never ambiguous, just not personalised). This
 * renders an additional line in the VIEWER's own effective timezone, using
 * the raw UTC instant carried in `data` — but only when it would actually
 * show something different from the scheduling timezone already in the
 * message, to avoid redundant noise for the common case where they match.
 */
function recipientLocalMeetingTime(n: SuresignNotification): string | null {
  const data = n.data as { is_timed?: boolean; starts_at?: string; scheduled_timezone?: string } | null | undefined;
  if (!data?.is_timed || !data.starts_at) return null;

  const viewerTz = useAuthStore.getState().user?.effective_timezone;
  if (!viewerTz || viewerTz === data.scheduled_timezone) return null;

  return `Your time: ${formatDateTime(data.starts_at, { timeZone: viewerTz })} (${viewerTz})`;
}

// ── Helpers ───────────────────────────────────────────────────────────────────

// Threshold-based ("Xm ago" -> "Xh ago" -> "Xd ago" -> short date after a
// week) rather than lib/dateTime.ts's always-relative formatRelativeTime() —
// this is an intentional, pre-existing UX choice for the bell dropdown, not
// a duplicate to remove. The only fix here is the >1-week fallback, which
// now resolves to the viewer's effective timezone via formatDate() instead
// of the browser's own local one.
function formatTimeAgo(dateStr: string): string {
  const date = new Date(dateStr);
  const diff = Math.floor((Date.now() - date.getTime()) / 1000);
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
  return formatDate(dateStr);
}

// ── Type → icon mapping (existing notification types) ────────────────────────

const TYPE_ICON: Record<string, LucideIcon> = {
  document_generated:      FileText,
  file_uploaded:           Upload,
  file_deleted:            Trash2,
  template_uploaded:       LayoutTemplate,
  template_updated:        LayoutTemplate,
  template_deleted:        LayoutTemplate,
  trade_package_generated: Package,
  trade_package_created:   Package,
  project_created:         FolderKanban,
  project_updated:         FolderKanban,
  sync_completed:          RefreshCw,
  sync_failed:             AlertTriangle,
  user_invited:            UserPlus,
};

// ── Priority → colour + icon ──────────────────────────────────────────────────

const PRIORITY_CONFIG: Record<string, { color: string; bg: string; Icon: LucideIcon }> = {
  critical: { color: '#f87171', bg: 'rgba(239,68,68,0.12)',    Icon: AlertTriangle },
  warning:  { color: '#fb923c', bg: 'rgba(249,115,22,0.12)',   Icon: AlertCircle },
  reminder: { color: '#facc15', bg: 'rgba(234,179,8,0.12)',    Icon: Clock },
  info:     { color: 'var(--text-muted)', bg: 'var(--bg-elevated)', Icon: Info },
};

const CATEGORY_LABELS: Record<string, string> = {
  commercial: 'Commercial', contract: 'Contract', programme: 'Programme',
  compliance: 'Compliance', payment: 'Payment', variation: 'Variation',
  retention: 'Retention', deliverable: 'Deliverable', notice: 'Notice',
  risk: 'Risk', communication: 'Communication', general: 'General',
};

// ── NotifIcon ─────────────────────────────────────────────────────────────────

function NotifIcon({ n }: { n: SuresignNotification }) {
  const pCfg = n.priority ? PRIORITY_CONFIG[n.priority] : null;
  const Icon = pCfg?.Icon ?? TYPE_ICON[n.type] ?? Info;
  const color = pCfg?.color ?? 'var(--text-muted)';
  const bg    = pCfg?.bg    ?? 'var(--bg-elevated)';

  return (
    <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl"
      style={{ backgroundColor: bg, border: '1px solid', borderColor: pCfg ? `${color}33` : 'var(--border)' }}>
      <Icon size={15} style={{ color }} strokeWidth={1.8} />
    </span>
  );
}

// ── Actions menu ──────────────────────────────────────────────────────────────

function ActionsMenu({
  hasUnread, hasRead,
  onMarkAll, onClearRead, onOpen,
}: {
  hasUnread: boolean; hasRead: boolean;
  onMarkAll: () => void; onClearRead: () => void; onOpen: () => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handle(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    if (open) document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button onClick={() => setOpen(o => !o)}
        className="rounded-lg p-2 transition-all duration-200 hover:bg-[var(--bg-hover)] active:scale-95"
        style={{ color: 'var(--text-muted)' }} title="More options">
        <MoreHorizontal size={15} />
      </button>
      {open && (
        <div className="ss-menu-pop-in absolute right-0 top-full z-[60] mt-1 w-56 overflow-hidden rounded-xl"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 8px 24px rgba(0,0,0,0.14)' }}>
          <button onClick={() => { onMarkAll(); setOpen(false); }} disabled={!hasUnread}
            className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)] disabled:cursor-not-allowed disabled:opacity-40"
            style={{ color: 'var(--text-secondary)' }}>
            <CheckCheck size={14} /> Mark all as read
          </button>
          <button onClick={() => { onClearRead(); setOpen(false); }} disabled={!hasRead}
            className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)] disabled:cursor-not-allowed disabled:opacity-40"
            style={{ color: 'var(--text-secondary)' }}>
            <X size={14} /> Clear read notifications
          </button>
          <button onClick={() => { onOpen(); setOpen(false); }}
            className="flex w-full items-center gap-2.5 px-3.5 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-secondary)', borderTop: '1px solid var(--border)' }}>
            <ExternalLink size={14} /> Open notifications
          </button>
        </div>
      )}
    </div>
  );
}

// ── Single row ────────────────────────────────────────────────────────────────

function NotifRow({
  n,
  onRead,
  onDismiss,
}: {
  n: SuresignNotification;
  onRead: (n: SuresignNotification) => void;
  onDismiss: (n: SuresignNotification, e: React.MouseEvent) => void;
}) {
  const isUnread   = n.status === 'unread';
  const categoryLabel = n.category ? CATEGORY_LABELS[n.category] : null;

  return (
    <div
      className="group relative flex cursor-pointer items-start gap-3 px-4 py-4 outline-none transition-colors hover:bg-[var(--bg-hover)] focus-visible:bg-[var(--bg-hover)]"
      style={{ borderBottom: '1px solid var(--border)', backgroundColor: isUnread ? 'var(--bg-surface)' : 'var(--bg-base)' }}
      role="button"
      tabIndex={0}
      aria-label={n.title}
      onClick={() => onRead(n)}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onRead(n); } }}
    >
      <NotifIcon n={n} />

      <div className="min-w-0 flex-1 pr-6">
        <p className="text-[13px] leading-[1.35]"
          style={{ color: 'var(--text-primary)', fontWeight: isUnread ? 600 : 400 }}>
          {n.title}
        </p>
        <p className="mt-1 line-clamp-2 text-xs leading-[1.45]" style={{ color: 'var(--text-muted)' }}>
          {n.message}
        </p>
        {recipientLocalMeetingTime(n) && (
          <p className="mt-1 text-[10px]" style={{ color: 'var(--text-muted)' }}>
            {recipientLocalMeetingTime(n)}
          </p>
        )}
        <div className="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1">
          <span className="text-[10px] font-medium tabular-nums" style={{ color: 'var(--text-muted)' }}>
            {formatTimeAgo(n.created_at)}
          </span>
          {categoryLabel && (
            <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
              {categoryLabel}
            </span>
          )}
          {n.action_url && (
            <span className="flex items-center gap-0.5 text-[10px] font-semibold" style={{ color: 'var(--gold)' }}>
              Open <ChevronRight size={10} />
            </span>
          )}
        </div>
      </div>

      {/* Dismiss */}
      <button onClick={(e) => onDismiss(n, e)}
        className="absolute right-3 top-3 rounded-lg p-1.5 opacity-0 transition-all duration-200 hover:bg-[var(--bg-hover)] group-hover:opacity-100 focus-visible:opacity-100"
        title="Dismiss" style={{ color: 'var(--text-muted)' }}>
        <X size={11} />
      </button>
    </div>
  );
}

// ── Section label ─────────────────────────────────────────────────────────────

function SectionLabel({ label, count, accent }: { label: string; count?: number; accent?: string }) {
  return (
    <div className="flex items-center justify-between px-4 py-2.5"
      style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
      <span className="text-[11px] font-semibold" style={{ color: accent ?? 'var(--text-secondary)' }}>
        {label}
      </span>
      {count !== undefined && count > 0 && (
        <span className="flex h-5 min-w-5 items-center justify-center rounded-md px-1.5 text-[10px] font-semibold tabular-nums"
          style={{ color: accent ?? 'var(--text-muted)', backgroundColor: 'var(--bg-surface)' }}>{count}</span>
      )}
    </div>
  );
}

// ── Critical banner (top of bell) ─────────────────────────────────────────────

function CriticalBanner({ notifications }: { notifications: SuresignNotification[] }) {
  if (notifications.length === 0) return null;
  return (
    <div className="flex items-center gap-2.5 px-4 py-3"
      style={{ backgroundColor: 'rgba(239,68,68,0.08)', borderBottom: '1px solid rgba(239,68,68,0.2)' }}>
      <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-red-500/10"><Zap size={13} style={{ color: '#f87171' }} /></span>
      <p className="text-xs font-medium" style={{ color: '#f87171' }}>
        {notifications.length} critical action{notifications.length > 1 ? 's' : ''} need attention
      </p>
    </div>
  );
}

// ── Main bell ─────────────────────────────────────────────────────────────────

export default function NotificationBell({ basePath = '/admin/notifications' }: { basePath?: string }) {
  const [open, setOpen] = useState(false);
  const [closing, setClosing] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);
  const closeTimerRef = useRef<number | null>(null);
  const queryClient = useQueryClient();
  const router = useRouter();

  const { count } = useUnreadCount();
  // Fetch active notifications only — server excludes resolved/expired by default
  const { notifications, isLoading, error } = useNotifications('active');
  const effectiveTimezone = useAuthStore(s => s.user?.effective_timezone);

  const all = notifications ?? [];

  // Group client-side within the active set (server already filtered resolved/expired/dismissed)
  // Critical: priority=critical, unread
  const critical = all.filter(n => n.priority === 'critical' && n.status === 'unread');
  // Today: created today (in the viewer's effective timezone), not critical, unread
  const todayUnread = all.filter(n => n.priority !== 'critical' && n.status === 'unread' && isTodayInTimezone(n.created_at, effectiveTimezone));
  // Earlier: unread from before today (non-critical)
  const earlierUnread = all.filter(n => n.priority !== 'critical' && n.status === 'unread' && !isTodayInTimezone(n.created_at, effectiveTimezone));
  // Read: recently read ones for context
  const read = all.filter(n => n.status === 'read').slice(0, 5);

  const hasRead      = read.length > 0;
  const isEmpty      = all.length === 0;

  const closePanel = useCallback((afterClose?: () => void) => {
    if (!open || closing) return;
    setClosing(true);
    closeTimerRef.current = window.setTimeout(() => {
      setOpen(false);
      setClosing(false);
      closeTimerRef.current = null;
      afterClose?.();
    }, 180);
  }, [open, closing]);

  function togglePanel() {
    if (open) {
      closePanel();
    } else {
      setClosing(false);
      setOpen(true);
    }
  }

  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) closePanel();
    }
    function handleKey(e: KeyboardEvent) {
      if (e.key === 'Escape') closePanel();
    }
    if (open && !closing) {
      document.addEventListener('mousedown', handleClick);
      window.addEventListener('keydown', handleKey);
    }
    return () => {
      document.removeEventListener('mousedown', handleClick);
      window.removeEventListener('keydown', handleKey);
    };
  }, [open, closing, closePanel]);

  useEffect(() => () => {
    if (closeTimerRef.current !== null) window.clearTimeout(closeTimerRef.current);
  }, []);

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['notifications'] });
    queryClient.invalidateQueries({ queryKey: ['notifications-count'] });
  }

  async function handleRead(n: SuresignNotification) {
    if (n.status === 'unread') {
      try {
        await api.patch(`/notifications/${n.id}/read`);
        invalidate();
      } catch {
        // Navigation must not be blocked by a failed read-state update — the
        // next poll/refetch will reconcile the status once the API recovers.
      }
    }
    if (n.action_url) closePanel(() => router.push(n.action_url!));
  }

  async function handleDismiss(n: SuresignNotification, e: React.MouseEvent) {
    e.stopPropagation();
    try {
      await api.patch(`/notifications/${n.id}/dismiss`);
      invalidate();
    } catch (err) {
      toast.error(getErrorMessage(err, 'Failed to dismiss'));
    }
  }

  async function handleMarkAll() {
    try {
      await api.post('/notifications/mark-all-read');
      invalidate();
    } catch (err) {
      toast.error(getErrorMessage(err, 'Failed to mark all as read'));
    }
  }

  async function handleClearRead() {
    try {
      await api.delete('/notifications/clear-read');
      invalidate();
      toast.success('Read notifications cleared');
    } catch (err) {
      toast.error(getErrorMessage(err, 'Failed to clear notifications'));
    }
  }

  function handleOpen() {
    closePanel(() => router.push(basePath));
  }

  return (
    <div className="relative" ref={panelRef}>
      {/* Bell button */}
      <button
        onClick={togglePanel}
        className="relative rounded-lg p-2 transition-all duration-200 hover:-translate-y-0.5 hover:bg-[var(--bg-hover)] active:scale-95"
        style={{ color: 'var(--text-secondary)' }}
        aria-label="Notifications"
        aria-expanded={open && !closing}
        aria-haspopup="dialog"
      >
        <Bell size={20} />
        <SidebarCountBadge count={count} className="absolute -right-2 -top-1" />
      </button>

      {/* Dropdown */}
      {open && (
        <div
          className={`${closing ? 'ss-notification-panel-out' : 'ss-notification-panel-in'} absolute right-0 z-50 mt-2 w-[420px] max-w-[calc(100vw-1.5rem)] overflow-hidden rounded-2xl`}
          style={{
            backgroundColor: 'var(--bg-surface)',
            border: '1px solid var(--border)',
            boxShadow: '0 18px 55px rgba(18,33,25,0.18)',
            transformOrigin: 'top right',
          }}
        >
          {/* Header */}
          <div className="flex items-center justify-between px-4 py-4"
            style={{ borderBottom: '1px solid var(--border)' }}>
            <div className="flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-xl" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}><Inbox size={16} /></span>
              <div>
                <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Notifications</p>
                <p className="mt-0.5 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                  {count > 0 ? `${count} unread item${count === 1 ? '' : 's'}` : 'You are all caught up'}
                </p>
              </div>
            </div>
            <ActionsMenu
              hasUnread={count > 0}
              hasRead={hasRead}
              onMarkAll={handleMarkAll}
              onClearRead={handleClearRead}
              onOpen={handleOpen}
            />
          </div>

          {/* Critical banner */}
          <CriticalBanner notifications={critical} />

          {/* Body */}
          <div className="max-h-[520px] overflow-y-auto overscroll-contain">
            {error ? (
              <div className="px-8 py-14 text-center">
                <span className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-red-500/10"><AlertTriangle size={20} style={{ color: '#f87171' }} /></span>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Notifications unavailable</p>
                <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>Try again shortly.</p>
              </div>
            ) : isLoading ? (
              <div className="space-y-3 p-4" aria-busy="true" aria-live="polite">
                <span className="sr-only">Loading notifications…</span>
                {[...Array(4)].map((_, index) => <div key={index} className="h-20 animate-pulse rounded-xl" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
              </div>
            ) : isEmpty ? (
              <div className="px-8 py-14 text-center">
                <span className="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}><CheckCheck size={20} /></span>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>You&rsquo;re all caught up</p>
                <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>New activity will appear here.</p>
              </div>
            ) : (
              <>
                {/* Critical */}
                {critical.length > 0 && (
                  <>
                    <SectionLabel label="Critical" count={critical.length} accent="#f87171" />
                    {critical.map(n => (
                      <NotifRow key={n.id} n={n} onRead={handleRead} onDismiss={handleDismiss} />
                    ))}
                  </>
                )}

                {/* Today */}
                {todayUnread.length > 0 && (
                  <>
                    <SectionLabel label="Today" count={todayUnread.length} />
                    {todayUnread.map(n => (
                      <NotifRow key={n.id} n={n} onRead={handleRead} onDismiss={handleDismiss} />
                    ))}
                  </>
                )}

                {/* Earlier (unread) */}
                {earlierUnread.length > 0 && (
                  <>
                    <SectionLabel label="Earlier" />
                    {earlierUnread.slice(0, 8).map(n => (
                      <NotifRow key={n.id} n={n} onRead={handleRead} onDismiss={handleDismiss} />
                    ))}
                  </>
                )}

                {/* No new */}
                {critical.length === 0 && todayUnread.length === 0 && earlierUnread.length === 0 && (
                  <div className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                    No new notifications.
                  </div>
                )}

                {/* Read */}
                {read.length > 0 && (
                  <>
                    <SectionLabel label="Read" />
                    {read.map(n => (
                      <NotifRow key={n.id} n={n} onRead={handleRead} onDismiss={handleDismiss} />
                    ))}
                  </>
                )}
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
