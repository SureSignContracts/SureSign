'use client';

import { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';
import { useIsMobile } from '@/hooks/useIsMobile';
import { useSiteSettings } from '@/hooks/useSiteSettings';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import {
  LayoutDashboard, Building2, FolderKanban, FileText, LayoutTemplate,
  Brain, HardDrive, CreditCard, Users, LifeBuoy, ScrollText, ClipboardList,
  Settings, LogOut, Sun, Moon, Gem, BookOpen, Search, Megaphone, Activity,
  PanelLeftClose, PanelLeftOpen, ChevronRight,
} from 'lucide-react';
import { APP_VERSION_LABEL } from '@/config/app-version';

const COLLAPSED_KEY = 'suresign_sidebar_collapsed';

const NAV_GROUPS = [
  {
    label: null,
    items: [
      { href: '/admin', label: 'Dashboard', icon: LayoutDashboard, exact: true, pageKey: null },
    ],
  },
  {
    label: 'Platform',
    items: [
      { href: '/admin/companies', label: 'Companies',      icon: Building2,    pageKey: 'companies' },
      { href: '/admin/projects',  label: 'Projects',       icon: FolderKanban, pageKey: 'projects'  },
      { href: '/admin/documents', label: 'Documents',      icon: FileText,     pageKey: 'documents' },
      { href: '/admin/users',     label: 'Users',          icon: Users,        pageKey: 'users',     superAdminOnly: true },
    ],
  },
  {
    label: 'Tools',
    items: [
      { href: '/admin/templates', label: 'Templates',      icon: LayoutTemplate, pageKey: 'templates' },
      { href: '/admin/prompts',   label: 'Prompt Library', icon: BookOpen,       pageKey: 'prompts'   },
      { href: '/admin/find',      label: 'Find Company',   icon: Search,         pageKey: 'find'      },
      { href: '/admin/billing',   label: 'Billing',        icon: CreditCard,     pageKey: 'billing'   },
      { href: '/admin/suresign',  label: 'SureSign',       icon: Gem,            pageKey: 'suresign'  },
    ],
  },
  {
    label: 'System',
    superAdminOnly: true,
    items: [
      { href: '/admin/ai-configurations', label: 'AI Config',   icon: Brain,         pageKey: 'ai-configurations', superAdminOnly: true },
      { href: '/admin/application-monitoring', label: 'Application Monitoring', icon: Activity, pageKey: 'application-monitoring', superAdminOnly: true },
      { href: '/admin/storage',           label: 'Storage',     icon: HardDrive,     pageKey: 'storage',           superAdminOnly: true },
      { href: '/admin/support',           label: 'Support',     icon: LifeBuoy,      pageKey: 'support',           superAdminOnly: true },
      { href: '/admin/announcements',     label: 'Announcements', icon: Megaphone,   pageKey: 'announcements',     superAdminOnly: true },
      { href: '/admin/system-logs',       label: 'System Logs', icon: ScrollText,    pageKey: 'system-logs',       superAdminOnly: true },
      { href: '/admin/audit-log',         label: 'Audit Log',   icon: ClipboardList, pageKey: 'audit-log',         superAdminOnly: true },
    ],
  },
];

// ─── Profile popover ──────────────────────────────────────────────────────────

// ─── Portal tooltip for collapsed sidebar items ───────────────────────────────

function SidebarTooltip({ label, anchorRef }: { label: string; anchorRef: React.RefObject<HTMLElement | null> }) {
  const [pos, setPos] = useState<{ top: number; left: number } | null>(null);

  useEffect(() => {
    const el = anchorRef.current;
    if (!el) return;
    const rect = el.getBoundingClientRect();
    setPos({ top: rect.top + rect.height / 2, left: rect.right + 10 });
  }, [anchorRef]);

  if (!pos) return null;

  return createPortal(
    <span
      style={{
        position: 'fixed',
        top: pos.top,
        left: pos.left,
        transform: 'translateY(-50%)',
        backgroundColor: 'var(--bg-surface)',
        border: '1px solid var(--border)',
        color: 'var(--text-primary)',
        boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
        padding: '4px 10px',
        borderRadius: '8px',
        fontSize: '12px',
        fontWeight: 500,
        whiteSpace: 'nowrap',
        pointerEvents: 'none',
        zIndex: 9999,
      }}
    >
      {label}
    </span>,
    document.body,
  );
}

// ─── Collapsed footer buttons with portal tooltips ────────────────────────────

function CollapsedFooterBtn({
  onClick,
  tooltip,
  className,
  style,
  children,
}: {
  onClick: () => void;
  tooltip: string;
  className?: string;
  style?: React.CSSProperties;
  children: React.ReactNode;
}) {
  const ref = useRef<HTMLButtonElement>(null);
  const [hovered, setHovered] = useState(false);
  return (
    <>
      <button
        ref={ref}
        onClick={onClick}
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
        className={className}
        style={style}
      >
        {children}
      </button>
      {hovered && <SidebarTooltip label={tooltip} anchorRef={ref} />}
    </>
  );
}

function CollapsedFooter({
  theme, displayName, initials, profileOpen,
  onToggleCollapsed, onToggleTheme, onToggleProfile,
}: {
  theme: string; displayName: string; initials: string; profileOpen: boolean;
  onToggleCollapsed: () => void; onToggleTheme: () => void; onToggleProfile: () => void;
}) {
  return (
    <div className="flex flex-col items-center gap-1 py-2.5">
      <CollapsedFooterBtn
        onClick={onToggleCollapsed}
        tooltip="Expand sidebar"
        className="group w-9 h-9 flex items-center justify-center rounded-xl transition-all duration-150 hover:bg-[var(--bg-surface)]"
        style={{ color: 'var(--text-secondary)' }}
      >
        <PanelLeftOpen size={17} className="transition-transform duration-150 group-hover:scale-110" />
      </CollapsedFooterBtn>

      <CollapsedFooterBtn
        onClick={onToggleTheme}
        tooltip={theme === 'dark' ? 'Light mode' : 'Dark mode'}
        className="group w-9 h-9 flex items-center justify-center rounded-xl transition-all duration-150 hover:bg-[var(--bg-surface)]"
        style={{ color: 'var(--text-secondary)' }}
      >
        {theme === 'dark'
          ? <Sun  size={17} className="transition-transform duration-200 group-hover:rotate-12 group-hover:scale-110" />
          : <Moon size={17} className="transition-transform duration-200 group-hover:-rotate-12 group-hover:scale-110" />}
      </CollapsedFooterBtn>

      <CollapsedFooterBtn
        onClick={onToggleProfile}
        tooltip={displayName}
        className={cn(
          'group w-9 h-9 flex items-center justify-center rounded-xl text-[11px] font-bold transition-all duration-150',
          profileOpen ? 'bg-[var(--bg-panel)] ring-2 ring-[var(--border)] scale-105' : 'bg-[var(--bg-elevated)] hover:bg-[var(--bg-panel)] hover:scale-105',
        )}
        style={{ color: 'var(--text-primary)' }}
      >
        {initials}
      </CollapsedFooterBtn>
    </div>
  );
}

function ProfilePopover({
  open,
  collapsed,
  displayName,
  email,
  theme,
  onClose,
  onToggleTheme,
  onLogout,
  portalRef,
}: {
  open: boolean;
  collapsed: boolean;
  displayName: string;
  email?: string;
  theme: string;
  onClose: () => void;
  onToggleTheme: () => void;
  onLogout: () => void;
  portalRef: React.RefObject<HTMLDivElement | null>;
}) {
  const content = (
    <div
      ref={collapsed ? portalRef : undefined}
      className={cn(
        'overflow-hidden rounded-xl',
        'transition-all duration-200 ease-out',
        collapsed ? 'fixed z-[200] w-52' : 'absolute bottom-full left-3 right-3 z-[200] mb-1',
        open
          ? 'opacity-100 scale-100 translate-y-0 pointer-events-auto'
          : 'opacity-0 scale-95 translate-y-2 pointer-events-none',
      )}
      style={
        collapsed
          ? {
              bottom: '12px',
              left: '68px',
              backgroundColor: 'var(--bg-surface)',
              border: '1px solid var(--border)',
              boxShadow: '0 8px 32px rgba(0,0,0,0.18)',
              transformOrigin: 'bottom left',
            }
          : {
              backgroundColor: 'var(--bg-surface)',
              border: '1px solid var(--border)',
              boxShadow: '0 8px 32px rgba(0,0,0,0.18)',
              transformOrigin: 'bottom center',
            }
      }
    >
      <div className="px-3.5 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
        <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{displayName}</p>
        {email && <p className="text-xs truncate mt-0.5" style={{ color: 'var(--text-muted)' }}>{email}</p>}
      </div>

      <Link
        href="/admin/settings"
        onClick={onClose}
        className="group flex items-center gap-2.5 px-3.5 py-2.5 text-xs transition-colors hover:bg-[var(--bg-hover)]"
        style={{ color: 'var(--text-secondary)' }}
      >
        <Settings size={13} className="transition-transform duration-200 group-hover:rotate-45" />
        Settings
      </Link>

      <button
        onClick={onToggleTheme}
        className="group w-full flex items-center gap-2.5 px-3.5 py-2.5 text-xs transition-colors hover:bg-[var(--bg-hover)]"
        style={{ color: 'var(--text-secondary)' }}
      >
        {theme === 'dark'
          ? <Sun  size={13} className="transition-transform duration-200 group-hover:rotate-12 group-hover:scale-110" />
          : <Moon size={13} className="transition-transform duration-200 group-hover:-rotate-12 group-hover:scale-110" />}
        {theme === 'dark' ? 'Light mode' : 'Dark mode'}
      </button>

      <button
        onClick={onLogout}
        className="group w-full flex items-center gap-2.5 px-3.5 py-2.5 text-xs transition-colors hover:bg-red-500/10"
        style={{ color: '#ef4444', borderTop: '1px solid var(--border)' }}
      >
        <LogOut size={13} className="transition-transform duration-150 group-hover:translate-x-0.5" />
        Sign out
      </button>

      <div className="px-3.5 py-2 text-center" style={{ borderTop: '1px solid var(--border)' }}>
        <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{APP_VERSION_LABEL}</span>
      </div>
    </div>
  );

  // Collapsed uses `fixed` positioning meant to float over the main content,
  // outside the narrow icon rail — but the sidebar's own transform (for its
  // mobile slide animation) creates a containing block that traps `fixed`
  // descendants, and its overflow-y-hidden then clips most of the popover.
  // Portal straight to <body> to escape that, matching how SidebarTooltip
  // already does it above. The expanded case stays in place — it's an
  // `absolute` dropdown anchored to its own (non-transformed) footer parent,
  // which portaling would break.
  if (collapsed) {
    return typeof document !== 'undefined' ? createPortal(content, document.body) : null;
  }
  return content;
}

// ─── Nav item ─────────────────────────────────────────────────────────────────

function NavBadge({ count }: { count: number }) {
  return (
    <span
      className="flex-shrink-0 min-w-[16px] h-4 px-1 rounded-full flex items-center justify-center text-[10px] font-bold tabular-nums"
      style={{ backgroundColor: '#ef4444', color: '#fff' }}
    >
      {count > 99 ? '99+' : count}
    </span>
  );
}

function NavItem({
  href,
  label,
  icon: Icon,
  active,
  collapsed,
  badge,
}: {
  href: string;
  label: string;
  icon: React.ElementType;
  active: boolean;
  collapsed: boolean;
  badge?: number;
}) {
  const linkRef = useRef<HTMLAnchorElement>(null);
  const [hovered, setHovered] = useState(false);

  // Collapsed: icon-only button with portal tooltip
  if (collapsed) {
    return (
      <>
        <Link
          ref={linkRef}
          href={href}
          onMouseEnter={() => setHovered(true)}
          onMouseLeave={() => setHovered(false)}
          className={cn(
            'group relative flex items-center justify-center w-9 h-9 mx-auto rounded-xl',
            'transition-all duration-150 ease-out',
            active
              ? 'bg-[var(--bg-surface)] shadow-sm'
              : 'hover:bg-[var(--bg-surface)]',
          )}
          style={{ color: active ? 'var(--text-primary)' : 'var(--text-secondary)' }}
        >
          <Icon
            size={18}
            className={cn(
              'flex-shrink-0 transition-all duration-150',
              !active && 'group-hover:scale-110 group-hover:text-[var(--text-primary)]',
            )}
          />
          {active && (
            <span
              className="absolute right-1 top-1 w-1 h-1 rounded-full"
              style={{ backgroundColor: 'var(--text-primary)' }}
            />
          )}
          {!!badge && (
            <span
              className="absolute -top-1 -right-1 min-w-[14px] h-3.5 px-1 rounded-full flex items-center justify-center text-[9px] font-bold tabular-nums"
              style={{ backgroundColor: '#ef4444', color: '#fff' }}
            >
              {badge > 99 ? '99+' : badge}
            </span>
          )}
        </Link>
        {hovered && <SidebarTooltip label={badge ? `${label} (${badge})` : label} anchorRef={linkRef} />}
      </>
    );
  }

  // Expanded: full row
  return (
    <Link
      href={href}
      className={cn(
        'group relative flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm',
        'transition-all duration-150 ease-out',
        active
          ? 'font-semibold bg-[var(--bg-surface)] shadow-sm'
          : 'hover:bg-[var(--bg-surface)] hover:translate-x-0.5',
      )}
      style={{ color: active ? 'var(--text-primary)' : 'var(--text-secondary)' }}
    >
      {/* Left accent bar */}
      <span
        className="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] rounded-full transition-all duration-200 ease-out"
        style={{
          backgroundColor: 'var(--text-primary)',
          height: active ? '18px' : '0px',
          opacity: active ? 1 : 0,
        }}
      />

      <Icon
        size={17}
        className={cn(
          'flex-shrink-0 transition-all duration-150',
          !active && 'group-hover:scale-110',
        )}
      />
      <span className="truncate">{label}</span>

      {!!badge && <NavBadge count={badge} />}

      {/* Hover right-arrow hint */}
      {!active && (
        <ChevronRight
          size={11}
          className="ml-auto opacity-0 -translate-x-1 transition-all duration-150 group-hover:opacity-40 group-hover:translate-x-0"
        />
      )}
    </Link>
  );
}

// ─── Sidebar ──────────────────────────────────────────────────────────────────

export default function AdminSidebar({
  mobileOpen = false,
  onMobileClose,
}: {
  mobileOpen?: boolean;
  onMobileClose?: () => void;
} = {}) {
  const pathname          = usePathname();
  const { user, logout }  = useAuthStore();
  const { theme, toggle } = useTheme();
  const isMobile = useIsMobile();
  const [profileOpen, setProfileOpen] = useState(false);
  const [collapsed, setCollapsed]     = useState(false);
  const popoverRef = useRef<HTMLDivElement>(null);
  // ProfilePopover is portaled to document.body (see below) so it isn't
  // clipped by the sidebar's own transform/overflow — but that means it's no
  // longer a DOM descendant of popoverRef, so the outside-click handler
  // needs its own ref to still recognise clicks inside the popover.
  const portalPopoverRef = useRef<HTMLDivElement>(null);

  // On mobile the sidebar is a full drawer — never the icon-only collapsed view.
  const showCollapsed = collapsed && !isMobile;

  // Close the drawer whenever the route changes.
  useEffect(() => {
    onMobileClose?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  useEffect(() => {
    try {
      const saved = localStorage.getItem(COLLAPSED_KEY);
      if (saved !== null) setCollapsed(saved === 'true');
    } catch {}
  }, []);

  useEffect(() => {
    if (!profileOpen) return;
    function handle(e: MouseEvent) {
      const target = e.target as Node;
      const insidePopover = popoverRef.current?.contains(target);
      const insidePortal = portalPopoverRef.current?.contains(target);
      if (!insidePopover && !insidePortal) {
        setProfileOpen(false);
      }
    }
    document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, [profileOpen]);

  function toggleCollapsed() {
    setCollapsed(c => {
      const next = !c;
      try { localStorage.setItem(COLLAPSED_KEY, String(next)); } catch {}
      return next;
    });
    setProfileOpen(false);
  }

  const isSuperAdmin  = user?.roles?.includes('Super Admin');
  const { data: siteSettings, isSettingsReady } = useSiteSettings();
  const hiddenPages: string[] = siteSettings?.hidden_pages ?? [];

  // Support inbox badge — "needs attention" count (Waiting for Support,
  // including legacy pre-Batch-5 Open rows folded in by the backend).
  // Lightweight dedicated endpoint (no ticket list), polled every 60s to
  // match the notification bell's own cadence. Super Admin only, since the
  // Support nav item itself is superAdminOnly.
  const { data: supportCounts } = useQuery({
    queryKey: ['admin-support-ticket-counts'],
    queryFn: () => api.get('/admin/support-tickets/counts').then(r => r.data.counts as Record<string, number>),
    enabled: !!isSuperAdmin,
    refetchInterval: 60000,
  });
  const supportBadge = supportCounts?.waiting_for_support ?? 0;

  function isVisible(item: { pageKey: string | null; superAdminOnly?: boolean }) {
    if (item.superAdminOnly && !isSuperAdmin) return false;
    if (item.pageKey && hiddenPages.includes(item.pageKey)) return false;
    return true;
  }

  function isActive(href: string, exact?: boolean) {
    if (exact) return pathname === href;
    return pathname === href || pathname?.startsWith(href + '/');
  }

  const displayName = user?.name ?? '';
  const initials    = displayName.charAt(0).toUpperCase() || '?';
  const role        = user?.roles?.[0] ?? 'Admin';

  return (
    <>
      {/* Mobile backdrop */}
      <div
        onClick={onMobileClose}
        className={cn(
          'fixed inset-0 z-40 lg:hidden transition-opacity duration-300',
          mobileOpen ? 'opacity-100 pointer-events-auto' : 'opacity-0 pointer-events-none',
        )}
        style={{ backgroundColor: 'rgba(0,0,0,0.45)' }}
      />
    <aside
      className={cn(
        'flex flex-col h-screen overflow-y-hidden z-50',
        'fixed inset-y-0 left-0 lg:static lg:z-auto lg:flex-shrink-0',
        mobileOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0',
      )}
      style={{
        width:    showCollapsed ? '64px' : '248px',
        minWidth: showCollapsed ? '64px' : '248px',
        maxWidth: showCollapsed ? '64px' : '248px',
        transition: 'transform 300ms cubic-bezier(0.4,0,0.2,1), width 260ms cubic-bezier(0.4,0,0.2,1), min-width 260ms cubic-bezier(0.4,0,0.2,1), max-width 260ms cubic-bezier(0.4,0,0.2,1)',
        backgroundColor: 'var(--bg-base)',
      }}
    >
      {/* ── Logo ─────────────────────────────────────────── */}
      <div
        className="flex-shrink-0 flex items-center h-14"
        style={{ borderBottom: '1px solid var(--border)' }}
      >
        {showCollapsed ? (
          /* Collapsed: icon perfectly centred */
          <div className="w-full flex items-center justify-center">
            <img
              src={theme === 'dark' ? '/logo_white/SureSign_WLOGO.webp' : '/logo_black/SureSign_BLOGO.webp'}
              alt="SureSign"
              style={{ width: '36px', height: '36px', objectFit: 'contain' }}
            />
          </div>
        ) : (
          /* Expanded: logo + wordmark + collapse button — logo nudged to sit over the nav icon column */
          <div className="w-full flex items-center gap-2.5 pl-[14px] pr-3">
            <img
              src={theme === 'dark' ? '/logo_white/SureSign_WLOGO.webp' : '/logo_black/SureSign_BLOGO.webp'}
              alt="SureSign"
              style={{ width: '36px', height: '36px', objectFit: 'contain', flexShrink: 0 }}
            />
            <span className="flex-1 text-lg font-bold tracking-tight truncate" style={{ color: 'var(--text-primary)' }}>
              SureSign
            </span>
            <button
              onClick={isMobile ? onMobileClose : toggleCollapsed}
              className="group flex-shrink-0 p-1.5 rounded-lg transition-all duration-150 hover:bg-[var(--bg-surface)] active:scale-95"
              style={{ color: 'var(--text-secondary)' }}
              title={isMobile ? 'Close menu' : 'Collapse sidebar'}
            >
              <PanelLeftClose size={17} className="transition-transform duration-150 group-hover:scale-110" />
            </button>
          </div>
        )}
      </div>

      {/* ── Nav ──────────────────────────────────────────── */}
      <nav className="flex-1 overflow-y-auto pt-7 pb-3 space-y-4" style={{ overflowX: 'visible' }}>
        {!isSettingsReady ? (
          <div className={cn('space-y-1', showCollapsed ? 'px-2' : 'px-3')}>
            {[...Array(8)].map((_, i) => (
              <div
                key={i}
                className={cn('h-9 rounded-xl animate-pulse', showCollapsed ? 'w-9 mx-auto' : '')}
                style={{ backgroundColor: 'var(--bg-elevated)', opacity: 1 - i * 0.09 }}
              />
            ))}
          </div>
        ) : (
          NAV_GROUPS.map((group, gi) => {
            const visibleItems = group.items.filter(isVisible);
            if (visibleItems.length === 0) return null;
            return (
              <div key={gi} className={cn(showCollapsed ? 'px-2' : 'px-3')}>
                {/* Section label */}
                {group.label && (
                  <div
                    className="overflow-hidden"
                    style={{
                      maxHeight: showCollapsed ? '0px' : '20px',
                      opacity: showCollapsed ? 0 : 1,
                      marginBottom: showCollapsed ? '0px' : '4px',
                      transition: 'max-height 260ms cubic-bezier(0.4,0,0.2,1), opacity 180ms ease, margin-bottom 260ms ease',
                    }}
                  >
                    <p
                      className="text-[10px] font-semibold uppercase tracking-widest px-3"
                      style={{ color: 'var(--text-muted)' }}
                    >
                      {group.label}
                    </p>
                  </div>
                )}
                {/* Collapsed: thin divider between groups */}
                {group.label && showCollapsed && (
                  <div className="h-px my-2 mx-1" style={{ backgroundColor: 'var(--border)' }} />
                )}

                <div className="space-y-0.5">
                  {visibleItems.map(item => (
                    <NavItem
                      key={item.href}
                      href={item.href}
                      label={item.label}
                      icon={item.icon}
                      active={isActive(item.href, (item as any).exact)}
                      collapsed={showCollapsed}
                      badge={item.pageKey === 'support' ? supportBadge : undefined}
                    />
                  ))}
                </div>
              </div>
            );
          })
        )}
      </nav>

      {/* ── Footer ───────────────────────────────────────── */}
      <div
        ref={popoverRef}
        className="relative flex-shrink-0"
        style={{ borderTop: '1px solid var(--border)' }}
      >
        {/* Animated popover */}
        <ProfilePopover
          open={profileOpen}
          collapsed={showCollapsed}
          displayName={displayName}
          email={user?.email}
          theme={theme}
          onClose={() => setProfileOpen(false)}
          onToggleTheme={toggle}
          onLogout={() => logout().then(() => (window.location.href = '/login'))}
          portalRef={portalPopoverRef}
        />

        {/* Collapsed footer: expand + theme + avatar */}
        {showCollapsed && (
          <CollapsedFooter
            theme={theme}
            displayName={displayName}
            initials={initials}
            profileOpen={profileOpen}
            onToggleCollapsed={toggleCollapsed}
            onToggleTheme={toggle}
            onToggleProfile={() => setProfileOpen(p => !p)}
          />
        )}

        {/* Expanded footer: version + profile row */}
        {!showCollapsed && (
          <div className="p-3 space-y-1">
            {/* Version + theme toggle */}
            <div className="flex items-center justify-between px-3 py-1">
              <span className="text-[10px] font-medium" style={{ color: 'var(--text-muted)' }}>
                {APP_VERSION_LABEL}
              </span>
              <button
                onClick={toggle}
                title={theme === 'dark' ? 'Light mode' : 'Dark mode'}
                className="group p-1 rounded-lg transition-all duration-150 hover:bg-[var(--bg-surface)] hover:scale-110"
                style={{ color: 'var(--text-secondary)' }}
              >
                {theme === 'dark'
                  ? <Sun  size={15} className="transition-transform duration-200 group-hover:rotate-12" />
                  : <Moon size={15} className="transition-transform duration-200 group-hover:-rotate-12" />}
              </button>
            </div>

            {/* Profile button */}
            <button
              onClick={() => setProfileOpen(p => !p)}
              className={cn(
                'group w-full flex items-center gap-2.5 px-3 py-2 rounded-xl',
                'transition-all duration-150',
                profileOpen ? 'bg-[var(--bg-elevated)]' : 'hover:bg-[var(--bg-surface)]',
              )}
            >
              <div
                className="w-7 h-7 rounded-lg flex items-center justify-center text-[11px] font-bold flex-shrink-0 transition-transform duration-150 group-hover:scale-105"
                style={{ backgroundColor: 'var(--bg-panel)', color: 'var(--text-primary)' }}
              >
                {initials}
              </div>
              <div className="flex-1 min-w-0 text-left">
                <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-primary)' }}>
                  {displayName}
                </p>
                <p className="text-[10px] truncate" style={{ color: 'var(--text-muted)' }}>
                  {role}
                </p>
              </div>
              <ChevronRight
                size={12}
                className={cn(
                  'flex-shrink-0 transition-all duration-200',
                  profileOpen ? 'rotate-90 opacity-60' : 'opacity-30 group-hover:opacity-60 group-hover:translate-x-0.5',
                )}
                style={{ color: 'var(--text-primary)' }}
              />
            </button>
          </div>
        )}
      </div>
    </aside>
    </>
  );
}
