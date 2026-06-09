'use client';

import { useState, useEffect, useRef } from 'react';
import {
  Bell, FileText, Upload, Trash2, LayoutTemplate,
  Package, FolderKanban, RefreshCw, AlertTriangle, UserPlus, Info, X,
  MoreHorizontal, CheckCheck, ExternalLink,
  type LucideIcon,
} from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import api from '@/lib/api';
import { useUnreadCount, useNotifications, type SuresignNotification } from '@/hooks/useNotifications';
import toast from 'react-hot-toast';

function formatTimeAgo(dateStr: string): string {
  const date = new Date(dateStr);
  const diff = Math.floor((Date.now() - date.getTime()) / 1000);
  if (diff < 60) return 'just now';
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
  if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

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

function NotifIcon({ type }: { type: string }) {
  const Icon = TYPE_ICON[type] ?? Info;
  return (
    <span className="flex items-center justify-center w-7 h-7 rounded-lg flex-shrink-0"
      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
      <Icon size={13} style={{ color: 'var(--text-muted)' }} strokeWidth={1.8} />
    </span>
  );
}

// ── Three-dot actions menu ────────────────────────────────────────────────

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
      <button
        onClick={() => setOpen(o => !o)}
        className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
        style={{ color: 'var(--text-muted)' }}
        title="More options"
      >
        <MoreHorizontal size={15} />
      </button>

      {open && (
        <div className="absolute right-0 top-full mt-1 w-52 rounded-xl overflow-hidden z-[60]"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 8px 24px rgba(0,0,0,0.14)' }}>
          <button
            onClick={() => { onMarkAll(); setOpen(false); }}
            disabled={!hasUnread}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-40 disabled:cursor-not-allowed"
            style={{ color: 'var(--text-secondary)' }}>
            <CheckCheck size={14} />
            Mark all as read
          </button>
          <button
            onClick={() => { onClearRead(); setOpen(false); }}
            disabled={!hasRead}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-40 disabled:cursor-not-allowed"
            style={{ color: 'var(--text-secondary)' }}>
            <X size={14} />
            Clear read notifications
          </button>
          <button
            onClick={() => { onOpen(); setOpen(false); }}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-secondary)', borderTop: '1px solid var(--border)' }}>
            <ExternalLink size={14} />
            Open notifications
          </button>
        </div>
      )}
    </div>
  );
}

// ── Single notification row ───────────────────────────────────────────────

function NotifRow({
  n,
  onRead,
  onDismiss,
}: {
  n: SuresignNotification;
  onRead: (n: SuresignNotification) => void;
  onDismiss: (n: SuresignNotification, e: React.MouseEvent) => void;
}) {
  return (
    <div
      className="group relative flex items-start gap-3 px-4 py-3 transition-colors hover:bg-[var(--bg-hover)] cursor-pointer"
      style={{ borderBottom: '1px solid var(--border)' }}
      onClick={() => onRead(n)}
    >
      {/* Unread dot */}
      <div className="flex-shrink-0 pt-1 w-2">
        {!n.is_read && <span className="block w-1.5 h-1.5 rounded-full bg-blue-500" />}
      </div>

      <NotifIcon type={n.type} />

      <div className="flex-1 min-w-0 pr-5">
        <p className="text-xs leading-snug"
          style={{ color: 'var(--text-primary)', fontWeight: n.is_read ? 400 : 600 }}>
          {n.title}
        </p>
        <p className="text-xs mt-0.5 line-clamp-2" style={{ color: 'var(--text-muted)' }}>
          {n.message}
        </p>
        <p className="text-[10px] mt-1" style={{ color: 'var(--text-muted)' }}>
          {formatTimeAgo(n.created_at)}
        </p>
      </div>

      {/* Dismiss button */}
      <button
        onClick={(e) => onDismiss(n, e)}
        className="absolute right-3 top-3 p-1 rounded opacity-0 group-hover:opacity-100 transition-opacity hover:bg-[var(--bg-elevated)]"
        title="Dismiss"
        style={{ color: 'var(--text-muted)' }}
      >
        <X size={11} />
      </button>
    </div>
  );
}

// ── Section divider ───────────────────────────────────────────────────────

function SectionLabel({ label }: { label: string }) {
  return (
    <div className="px-4 py-1.5" style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
      <span className="text-[10px] uppercase tracking-widest font-semibold" style={{ color: 'var(--text-muted)' }}>{label}</span>
    </div>
  );
}

// ── Main bell component ───────────────────────────────────────────────────

export default function NotificationBell() {
  const [open, setOpen] = useState(false);
  const [visible, setVisible] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);
  const queryClient = useQueryClient();
  const router = useRouter();

  const { count } = useUnreadCount();
  const { notifications } = useNotifications('all');

  const all = notifications ?? [];
  const unread = all.filter(n => !n.is_read).slice(0, 15);
  const read   = all.filter(n => n.is_read).slice(0, 8);

  const displayCount = count > 99 ? '99+' : count > 0 ? String(count) : null;

  // Animate open/close
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

  async function handleMarkRead(n: SuresignNotification) {
    if (n.is_read) return;
    try {
      await api.patch(`/notifications/${n.id}/read`);
      invalidate();
    } catch {}
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

  async function handleClearOne(n: SuresignNotification, e: React.MouseEvent) {
    e.stopPropagation();
    try {
      await api.delete(`/notifications/${n.id}`);
      invalidate();
    } catch {
      toast.error('Failed to dismiss notification');
    }
  }

  function handleOpen() {
    setOpen(false);
    router.push('/admin/notifications');
  }

  const isEmpty = unread.length === 0 && read.length === 0;

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
          <span className="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] px-1 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center leading-none">
            {displayCount}
          </span>
        )}
      </button>

      {/* Dropdown */}
      {open && (
        <div
          className="absolute right-0 mt-2 w-[380px] rounded-xl overflow-hidden z-50"
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
              hasUnread={unread.length > 0}
              hasRead={read.length > 0}
              onMarkAll={handleMarkAll}
              onClearRead={handleClearRead}
              onOpen={handleOpen}
            />
          </div>

          {/* Body */}
          <div className="max-h-[480px] overflow-y-auto">
            {isEmpty ? (
              <div className="py-12 text-center">
                <Bell size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No notifications yet.</p>
              </div>
            ) : (
              <>
                {/* New (unread) */}
                {unread.length > 0 && (
                  <>
                    <SectionLabel label="New" />
                    {unread.map(n => (
                      <NotifRow key={n.id} n={n} onRead={handleMarkRead} onDismiss={handleClearOne} />
                    ))}
                  </>
                )}

                {unread.length === 0 && (
                  <div className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                    No new notifications.
                  </div>
                )}

                {/* Old (read) */}
                {read.length > 0 && (
                  <>
                    <SectionLabel label="Earlier" />
                    {read.map(n => (
                      <NotifRow key={n.id} n={n} onRead={handleMarkRead} onDismiss={handleClearOne} />
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
