'use client';

import { useState, useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';
import { useIsMobile } from '@/hooks/useIsMobile';
import { useQuery } from '@tanstack/react-query';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import {
  LayoutDashboard, FolderKanban, DollarSign, HardHat,
  FileText, BarChart2, Brain, Users, Settings, LogOut,
  Sun, Moon, ShieldCheck, ChevronRight, HelpCircle,
  PanelLeftClose, PanelLeftOpen,
} from 'lucide-react';
import { APP_VERSION_LABEL } from '@/config/app-version';

const COLLAPSED_KEY = 'suresign_app_sidebar_collapsed';

const NAV_GROUPS = [
  {
    label: null,
    items: [
      { href: '/app', label: 'Dashboard', icon: LayoutDashboard, exact: true, tour: 'sidebar-dashboard' },
    ],
  },
  {
    label: 'Workspace',
    items: [
      { href: '/app/projects',   label: 'Projects',     icon: FolderKanban, tour: 'sidebar-projects'   },
      { href: '/app/commercial', label: 'Commercial',   icon: DollarSign,   tour: 'sidebar-commercial' },
      { href: '/app/site',       label: 'Site Admin',   icon: HardHat       },
      { href: '/app/documents',  label: 'Documents',    icon: FileText,     tour: 'sidebar-documents'  },
    ],
  },
  {
    label: 'Tools',
    items: [
      { href: '/app/reports', label: 'Reports',      icon: BarChart2   },
      { href: '/app/ai',      label: 'AI Assistant', icon: Brain       },
      { href: '/app/team',    label: 'Team',         icon: Users       },
      { href: '/app/help',    label: 'Help',         icon: HelpCircle, tour: 'sidebar-help' },
    ],
  },
];

// ─── Portal tooltip ────────────────────────────────────────────────────────────

function SidebarTooltip({
  label,
  anchorRef,
  icon: Icon,
}: {
  label: string;
  anchorRef: React.RefObject<HTMLElement | null>;
  icon?: React.ElementType;
}) {
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
        boxShadow: '0 8px 20px rgba(0,0,0,0.14)',
        padding: '5px 12px 5px 8px',
        borderRadius: '10px',
        fontSize: '12px',
        fontWeight: 500,
        whiteSpace: 'nowrap',
        pointerEvents: 'none',
        zIndex: 9999,
        display: 'flex',
        alignItems: 'center',
        gap: '7px',
      }}
    >
      {Icon && (
        <span
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            width: '22px',
            height: '22px',
            borderRadius: '6px',
            backgroundColor: 'var(--bg-elevated)',
            flexShrink: 0,
          }}
        >
          <Icon size={13} style={{ color: 'var(--text-secondary)' }} />
        </span>
      )}
      {label}
    </span>,
    document.body,
  );
}

// ─── Collapsed footer button with tooltip ─────────────────────────────────────

function CollapsedBtn({
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

// ─── Nav item ─────────────────────────────────────────────────────────────────

function NavItem({
  href,
  label,
  icon: Icon,
  active,
  collapsed,
  exact,
  tour,
}: {
  href: string;
  label: string;
  icon: React.ElementType;
  active: boolean;
  collapsed: boolean;
  exact?: boolean;
  tour?: string;
}) {
  const linkRef = useRef<HTMLAnchorElement>(null);
  const [hovered, setHovered] = useState(false);

  if (collapsed) {
    return (
      <>
        <Link
          ref={linkRef}
          href={href}
          data-tour={tour}
          onMouseEnter={() => setHovered(true)}
          onMouseLeave={() => setHovered(false)}
          className={cn(
            'group relative flex items-center justify-center w-9 h-9 mx-auto rounded-xl',
            'transition-all duration-150 ease-out',
            active ? 'bg-[var(--bg-surface)] shadow-sm' : 'hover:bg-[var(--bg-surface)]',
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
        </Link>
        {hovered && <SidebarTooltip label={label} anchorRef={linkRef} icon={Icon} />}
      </>
    );
  }

  return (
    <Link
      href={href}
      data-tour={tour}
      className={cn(
        'group relative flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm',
        'transition-all duration-150 ease-out',
        active
          ? 'font-semibold bg-[var(--bg-surface)] shadow-sm'
          : 'hover:bg-[var(--bg-surface)] hover:translate-x-0.5',
      )}
      style={{ color: active ? 'var(--text-primary)' : 'var(--text-secondary)' }}
    >
      <span
        className="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] rounded-full transition-all duration-200"
        style={{
          backgroundColor: 'var(--text-primary)',
          height: active ? '18px' : '0px',
          opacity: active ? 1 : 0,
        }}
      />
      <Icon
        size={17}
        className={cn('flex-shrink-0 transition-all duration-150', !active && 'group-hover:scale-110')}
      />
      <span className="truncate">{label}</span>
      {!active && (
        <ChevronRight
          size={11}
          className="ml-auto opacity-0 -translate-x-1 transition-all duration-150 group-hover:opacity-40 group-hover:translate-x-0"
        />
      )}
    </Link>
  );
}

// ─── Profile popover ──────────────────────────────────────────────────────────

function ProfilePopover({
  open,
  collapsed,
  displayName,
  email,
  theme,
  isSuperAdmin,
  onClose,
  onToggleTheme,
  onLogout,
}: {
  open: boolean;
  collapsed: boolean;
  displayName: string;
  email?: string;
  theme: string;
  isSuperAdmin: boolean;
  onClose: () => void;
  onToggleTheme: () => void;
  onLogout: () => void;
}) {
  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl transition-all duration-200 ease-out',
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
        href="/app/settings"
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

      {isSuperAdmin && (
        <Link
          href="/admin"
          onClick={onClose}
          className="group flex items-center gap-2.5 px-3.5 py-2.5 text-xs transition-colors hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--text-secondary)' }}
        >
          <ShieldCheck size={13} />
          Admin Panel
        </Link>
      )}

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
}

// ─── Collapsed footer ─────────────────────────────────────────────────────────

function CollapsedFooter({
  theme, displayName, initials, profileOpen,
  onToggleCollapsed, onToggleTheme, onToggleProfile,
}: {
  theme: string; displayName: string; initials: string; profileOpen: boolean;
  onToggleCollapsed: () => void; onToggleTheme: () => void; onToggleProfile: () => void;
}) {
  return (
    <div className="flex flex-col items-center gap-1 py-2.5">
      <CollapsedBtn
        onClick={onToggleCollapsed}
        tooltip="Expand sidebar"
        className="group w-9 h-9 flex items-center justify-center rounded-xl transition-all duration-150 hover:bg-[var(--bg-surface)]"
        style={{ color: 'var(--text-secondary)' }}
      >
        <PanelLeftOpen size={17} className="transition-transform duration-150 group-hover:scale-110" />
      </CollapsedBtn>

      <CollapsedBtn
        onClick={onToggleTheme}
        tooltip={theme === 'dark' ? 'Light mode' : 'Dark mode'}
        className="group w-9 h-9 flex items-center justify-center rounded-xl transition-all duration-150 hover:bg-[var(--bg-surface)]"
        style={{ color: 'var(--text-secondary)' }}
      >
        {theme === 'dark'
          ? <Sun  size={17} className="transition-transform duration-200 group-hover:rotate-12 group-hover:scale-110" />
          : <Moon size={17} className="transition-transform duration-200 group-hover:-rotate-12 group-hover:scale-110" />}
      </CollapsedBtn>

      <CollapsedBtn
        onClick={onToggleProfile}
        tooltip={displayName}
        className={cn(
          'group w-9 h-9 flex items-center justify-center rounded-xl text-[11px] font-bold transition-all duration-150',
          profileOpen
            ? 'bg-[var(--bg-panel)] ring-2 ring-[var(--border)] scale-105'
            : 'bg-[var(--bg-elevated)] hover:bg-[var(--bg-panel)] hover:scale-105',
        )}
        style={{ color: 'var(--text-primary)' }}
      >
        {initials}
      </CollapsedBtn>
    </div>
  );
}

// ─── Main sidebar ─────────────────────────────────────────────────────────────

export default function AppSidebar({
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

  // On mobile the sidebar is a full drawer — never the icon-only collapsed view.
  const showCollapsed = collapsed && !isMobile;

  // Close the drawer whenever the route changes.
  useEffect(() => {
    onMobileClose?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  const { data: branding } = useQuery({
    queryKey: ['branding'],
    queryFn: () => api.get('/organization/branding').then(r => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000,
  });

  useEffect(() => {
    try {
      const saved = localStorage.getItem(COLLAPSED_KEY);
      if (saved !== null) setCollapsed(saved === 'true');
    } catch {}
  }, []);

  useEffect(() => {
    if (!profileOpen) return;
    function handle(e: MouseEvent) {
      if (popoverRef.current && !popoverRef.current.contains(e.target as Node)) {
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

  function isActive(href: string, exact?: boolean) {
    if (exact) return pathname === href;
    return pathname === href || pathname?.startsWith(href + '/');
  }

  const isSuperAdmin  = user?.roles?.includes('Super Admin') ?? false;
  const displayName   = user?.name ?? '';
  const initials      = displayName.charAt(0).toUpperCase() || '?';
  const role          = user?.roles?.[0] ?? '';
  const fullOrgName   = branding?.company_name || user?.organization?.name || 'Company Portal';
  // Cut at a word boundary rather than mid-word ("Star Affinity" vs "Star Affinity L...").
  const orgWords      = fullOrgName.trim().split(/\s+/);
  const orgName       = orgWords.length > 2 ? orgWords.slice(0, 2).join(' ') : fullOrgName;
  const logoUrl       = branding?.logo_url ?? null;

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
      {/* ── Header ───────────────────────────────────────── */}
      <div
        className="flex-shrink-0 flex items-center h-14"
        style={{ borderBottom: '1px solid var(--border)' }}
      >
        {showCollapsed ? (
          <div className="w-full flex items-center justify-center">
            {logoUrl ? (
              <img src={logoUrl} alt={orgName} style={{ width: '26px', height: '26px', objectFit: 'contain', borderRadius: '4px' }} />
            ) : (
              <div
                className="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                {orgName.charAt(0).toUpperCase()}
              </div>
            )}
          </div>
        ) : (
          <div className="w-full flex items-center gap-2.5 pl-[21px] pr-3">
            {logoUrl ? (
              <img src={logoUrl} alt={orgName} style={{ width: '22px', height: '22px', objectFit: 'contain', borderRadius: '4px', flexShrink: 0 }} />
            ) : (
              <div
                className="w-6 h-6 rounded-md flex items-center justify-center text-[11px] font-bold flex-shrink-0"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                {orgName.charAt(0).toUpperCase()}
              </div>
            )}
            <span className="flex-1 text-sm font-bold tracking-tight truncate" style={{ color: 'var(--text-primary)' }} title={fullOrgName}>
              {orgName}
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
      <nav className="flex-1 overflow-y-auto py-3 space-y-4" style={{ overflowX: 'visible' }}>
        {NAV_GROUPS.map((group, gi) => (
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
                <p className="text-[10px] font-semibold uppercase tracking-widest px-3" style={{ color: 'var(--text-muted)' }}>
                  {group.label}
                </p>
              </div>
            )}
            {group.label && showCollapsed && (
              <div className="h-px my-2 mx-1" style={{ backgroundColor: 'var(--border)' }} />
            )}

            <div className="space-y-0.5">
              {group.items.map(item => (
                <NavItem
                  key={item.href}
                  href={item.href}
                  label={item.label}
                  icon={item.icon}
                  active={isActive(item.href, (item as any).exact)}
                  collapsed={showCollapsed}
                  tour={(item as any).tour}
                />
              ))}
            </div>
          </div>
        ))}
      </nav>

      {/* ── Footer ───────────────────────────────────────── */}
      <div
        ref={popoverRef}
        className="relative flex-shrink-0"
        style={{ borderTop: '1px solid var(--border)' }}
      >
        <ProfilePopover
          open={profileOpen}
          collapsed={showCollapsed}
          displayName={displayName}
          email={user?.email}
          theme={theme}
          isSuperAdmin={isSuperAdmin}
          onClose={() => setProfileOpen(false)}
          onToggleTheme={toggle}
          onLogout={() => logout().then(() => (window.location.href = '/login'))}
        />

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

        {!showCollapsed && (
          <div className="p-3 space-y-1">
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

            <button
              onClick={() => setProfileOpen(p => !p)}
              className={cn(
                'group w-full flex items-center gap-2.5 px-3 py-2 rounded-xl transition-all duration-150',
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
                <p className="text-xs font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{displayName}</p>
                <p className="text-[10px] truncate" style={{ color: 'var(--text-muted)' }}>{role}</p>
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
