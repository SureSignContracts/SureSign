'use client';

import { useState, useEffect, useRef } from 'react';
import {
  Bell, FileText, Upload, Trash2, LayoutTemplate,
  Package, FolderKanban, RefreshCw, AlertTriangle, UserPlus, Info, X,
  MoreHorizontal, CheckCheck, ExternalLink, AlertCircle, Clock, Zap,
  type LucideIcon,
} from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import api from '@/lib/api';
import { useUnreadCount, useNotifications, type SuresignNotification } from '@/hooks/useNotifications';
import { useAuthStore } from '@/store/authStore';
import { formatDate } from '@/lib/utils';
import { isToday as isTodayInTimezone, formatDateTime } from '@/lib/dateTime';
import toast from 'react-hot-toast';

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
    <span className="flex items-center justify-center w-7 h-7 rounded-lg flex-shrink-0"
      style={{ backgroundColor: bg, border: '1px solid var(--border)' }}>
      <Icon size={13} style={{ color }} strokeWidth={1.8} />
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
        className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
        style={{ color: 'var(--text-muted)' }} title="More options">
        <MoreHorizontal size={15} />
      </button>
      {open && (
        <div className="absolute right-0 top-full mt-1 w-52 rounded-xl overflow-hidden z-[60]"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 8px 24px rgba(0,0,0,0.14)' }}>
          <button onClick={() => { onMarkAll(); setOpen(false); }} disabled={!hasUnread}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-40 disabled:cursor-not-allowed"
            style={{ color: 'var(--text-secondary)' }}>
            <CheckCheck size={14} /> Mark all as read
          </button>
          <button onClick={() => { onClearRead(); setOpen(false); }} disabled={!hasRead}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-40 disabled:cursor-not-allowed"
            style={{ color: 'var(--text-secondary)' }}>
            <X size={14} /> Clear read notifications
          </button>
          <button onClick={() => { onOpen(); setOpen(false); }}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
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
  const dotColor   = n.priority === 'critical' ? '#f87171' : n.priority === 'warning' ? '#fb923c' : '#3b82f6';
  const categoryLabel = n.category ? CATEGORY_LABELS[n.category] : null;

  return (
    <div
      className="group relative flex items-start gap-3 px-4 py-3 transition-colors hover:bg-[var(--bg-hover)] focus-visible:bg-[var(--bg-hover)] cursor-pointer outline-none"
      style={{ borderBottom: '1px solid var(--border)' }}
      role="button"
      tabIndex={0}
      aria-label={n.title}
      onClick={() => onRead(n)}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onRead(n); } }}
    >
      {/* Unread dot */}
      <div className="flex-shrink-0 pt-1 w-2">
        {isUnread && <span className="block w-1.5 h-1.5 rounded-full" style={{ backgroundColor: dotColor }} />}
      </div>

      <NotifIcon n={n} />

      <div className="flex-1 min-w-0 pr-5">
        <p className="text-xs leading-snug"
          style={{ color: 'var(--text-primary)', fontWeight: isUnread ? 600 : 400 }}>
          {n.title}
        </p>
        <p className="text-xs mt-0.5 line-clamp-2" style={{ color: 'var(--text-muted)' }}>
          {n.message}
        </p>
        {recipientLocalMeetingTime(n) && (
          <p className="text-[10px] mt-0.5 italic" style={{ color: 'var(--text-muted)' }}>
            {recipientLocalMeetingTime(n)}
          </p>
        )}
        <div className="flex items-center gap-2 mt-1 flex-wrap">
          <span className="text-[10px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
            {formatTimeAgo(n.created_at)}
          </span>
          {categoryLabel && (
            <span className="text-[10px] px-1.5 py-0.5 rounded"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
              {categoryLabel}
            </span>
          )}
          {n.action_url && (
            <span className="text-[10px]" style={{ color: 'var(--gold)' }}>→ Open</span>
          )}
        </div>
      </div>

      {/* Dismiss */}
      <button onClick={(e) => onDismiss(n, e)}
        className="absolute right-3 top-3 p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity hover:bg-[var(--bg-hover)]"
        title="Dismiss" style={{ color: 'var(--text-muted)' }}>
        <X size={11} />
      </button>
    </div>
  );
}

// ── Section label ─────────────────────────────────────────────────────────────

function SectionLabel({ label, count, accent }: { label: string; count?: number; accent?: string }) {
  return (
    <div className="flex items-center justify-between px-4 py-1.5"
      style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
      <span className="text-[10px] uppercase tracking-widest font-semibold" style={{ color: accent ?? 'var(--text-muted)' }}>
        {label}
      </span>
      {count !== undefined && count > 0 && (
        <span className="text-[10px] font-bold tabular-nums" style={{ color: accent ?? 'var(--text-muted)' }}>{count}</span>
      )}
    </div>
  );
}

// ── Critical banner (top of bell) ─────────────────────────────────────────────

function CriticalBanner({ notifications }: { notifications: SuresignNotification[] }) {
  if (notifications.length === 0) return null;
  return (
    <div className="px-4 py-2.5 flex items-center gap-2"
      style={{ backgroundColor: 'rgba(239,68,68,0.08)', borderBottom: '1px solid rgba(239,68,68,0.2)' }}>
      <Zap size={13} style={{ color: '#f87171', flexShrink: 0 }} />
      <p className="text-xs font-semibold" style={{ color: '#f87171' }}>
        {notifications.length} critical action{notifications.length > 1 ? 's' : ''} require immediate attention
      </p>
    </div>
  );
}

// ── Main bell ─────────────────────────────────────────────────────────────────

export default function NotificationBell({ basePath = '/admin/notifications' }: { basePath?: string }) {
  const [open, setOpen] = useState(false);
  const [visible, setVisible] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);
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

  const displayCount = count > 99 ? '99+' : count > 0 ? String(count) : null;
  const hasCritical  = critical.length > 0;
  const hasRead      = read.length > 0;
  const isEmpty      = all.length === 0;

  useEffect(() => {
    if (open) requestAnimationFrame(() => setVisible(true));
    else setVisible(false);
  }, [open]);

  useEffect(() => {
    function handleClick(e: MouseEvent) {
      if (panelRef.current && !panelRef.current.contains(e.target as Node)) setOpen(false);
    }
    if (open) document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [open]);

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
    if (n.action_url) { setOpen(false); router.push(n.action_url); }
  }

  async function handleDismiss(n: SuresignNotification, e: React.MouseEvent) {
    e.stopPropagation();
    try {
      await api.patch(`/notifications/${n.id}/dismiss`);
      invalidate();
    } catch {
      toast.error('Failed to dismiss');
    }
  }

  async function handleMarkAll() {
    try {
      await api.post('/notifications/mark-all-read');
      invalidate();
    } catch {
      toast.error('Failed to mark all as read');
    }
  }

  async function handleClearRead() {
    try {
      await api.delete('/notifications/clear-read');
      invalidate();
      toast.success('Read notifications cleared');
    } catch {
      toast.error('Failed to clear notifications');
    }
  }

  function handleOpen() {
    setOpen(false);
    router.push(basePath);
  }

  return (
    <div className="relative" ref={panelRef}>
      {/* Bell button */}
      <button
        onClick={() => setOpen(o => !o)}
        className="relative p-2 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
        style={{ color: 'var(--text-secondary)' }}
        aria-label="Notifications"
      >
        <Bell size={20} />
        {displayCount && (
          <span className={`absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 text-white text-[10px] font-bold tabular-nums rounded-full flex items-center justify-center leading-none ${hasCritical ? 'bg-red-500 animate-pulse' : 'bg-red-500'}`}>
            {displayCount}
          </span>
        )}
      </button>

      {/* Dropdown */}
      {open && (
        <div
          className="absolute right-0 mt-2 w-[380px] max-w-[calc(100vw-2rem)] rounded-xl overflow-hidden z-50"
          style={{
            backgroundColor: 'var(--bg-surface)',
            border: '1px solid var(--border)',
            boxShadow: '0 8px 30px rgba(0,0,0,0.15)',
            transformOrigin: 'top right',
            transform: visible ? 'scale(1) translateY(0)' : 'scale(0.96) translateY(-6px)',
            opacity: visible ? 1 : 0,
            transition: 'transform 180ms cubic-bezier(0.16,1,0.3,1), opacity 150ms ease',
          }}
        >
          {/* Header */}
          <div className="flex items-center justify-between px-4 py-3"
            style={{ borderBottom: '1px solid var(--border)' }}>
            <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              Notifications
            </span>
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
          <div className="max-h-[480px] overflow-y-auto">
            {error ? (
              <div className="py-12 text-center">
                <AlertTriangle size={28} className="mx-auto mb-2" style={{ color: '#f87171' }} />
                <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Couldn&apos;t load notifications. Try again shortly.</p>
              </div>
            ) : isLoading ? (
              <div className="py-12 text-center">
                <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</p>
              </div>
            ) : isEmpty ? (
              <div className="py-12 text-center">
                <Bell size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No notifications.</p>
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
