'use client';

import { useState, useEffect, useCallback } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard, FileText, DollarSign, MessageSquare, GitBranch,
  ClipboardList, Users2, Bell, CheckSquare, FolderOpen, Package, Archive,
  ArrowLeft, FolderKanban, Scale, CalendarDays, ShieldAlert, BarChart2,
  ChevronRight, Briefcase, HardHat, Clock, FileStack,
} from 'lucide-react';

interface ProjectSidebarProps {
  projectId: string;
  projectName?: string;
  projectCode?: string;
  companyName?: string;
  organizationId?: number | string;
  isLoading?: boolean;
  mobileOpen?: boolean;
  onMobileClose?: () => void;
}

interface NavItem {
  href: string;
  label: string;
  icon: React.ElementType;
}

interface NavGroup {
  label: string;
  icon: React.ElementType;
  items: NavItem[];
}

// ── Navigation structure ───────────────────────────────────────────────────

const standalone = (id: string): NavItem[] => [
  { href: `/app/projects/${id}/overview`, label: 'Overview', icon: LayoutDashboard },
];

const groups = (id: string): NavGroup[] => [
  {
    label: 'Contract',
    icon: Briefcase,
    items: [
      { href: `/app/projects/${id}/contracts`,  label: 'Contracts',     icon: FileText },
      { href: `/app/projects/${id}/commercial`, label: 'Commercial',    icon: DollarSign },
      { href: `/app/projects/${id}/variations`, label: 'Variations',    icon: GitBranch },
      { href: `/app/projects/${id}/notices`,    label: 'Notices',       icon: Bell },
      { href: `/app/projects/${id}/programme`,  label: 'Programme',     icon: BarChart2 },
      { href: `/app/projects/${id}/delay-eot`,  label: 'Delay & EOT',   icon: Clock },
      { href: `/app/projects/${id}/risks`,      label: 'Risk Register', icon: ShieldAlert },
    ],
  },
  {
    label: 'Communications',
    icon: MessageSquare,
    items: [
      { href: `/app/projects/${id}/rfis`,     label: 'RFIs',     icon: MessageSquare },
      { href: `/app/projects/${id}/meetings`, label: 'Meetings', icon: Users2 },
    ],
  },
  {
    label: 'Delivery',
    icon: HardHat,
    items: [
      { href: `/app/projects/${id}/qa`,                    label: 'QA Reports',         icon: CheckSquare },
      { href: `/app/projects/${id}/snagging`,              label: 'Snagging',           icon: Package },
      { href: `/app/projects/${id}/site-reports`,          label: 'Site Reports',       icon: ClipboardList },
      { href: `/app/projects/${id}/delivery-documents`,    label: 'Delivery Documents', icon: FileStack },
      { href: `/app/projects/${id}/closeout`,              label: 'Closeout',           icon: Archive },
    ],
  },
  {
    label: 'Disputes',
    icon: Scale,
    items: [
      { href: `/app/projects/${id}/adjudication`, label: 'Adjudication', icon: Scale },
    ],
  },
];

const utility = (id: string): NavItem[] => [
  { href: `/app/projects/${id}/documents`, label: 'Documents', icon: FolderOpen },
  { href: `/app/projects/${id}/calendar`,  label: 'Calendar',  icon: CalendarDays },
];

// ── State persistence ──────────────────────────────────────────────────────

const STORAGE_KEY = 'suresign_project_nav';

function readStorage(): Record<string, boolean> {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : {};
  } catch { return {}; }
}

function writeStorage(state: Record<string, boolean>) {
  try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch {}
}

function isActive(pathname: string, href: string) {
  return pathname === href || pathname?.startsWith(href + '/');
}

// ── Top-level nav link (matches AppSidebar NavItem style) ──────────────────

function NavLink({ href, label, icon: Icon, pathname }: NavItem & { pathname: string }) {
  const active = isActive(pathname, href);
  return (
    <Link
      href={href}
      className={cn(
        'group relative flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm',
        'transition-all duration-150 ease-out',
        active
          ? 'font-semibold shadow-sm'
          : 'hover:bg-[var(--bg-surface)] hover:translate-x-0.5',
      )}
      style={active
        ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
        : { color: 'var(--text-secondary)' }
      }
    >
      <Icon size={16} className={cn('flex-shrink-0 transition-all duration-150', !active && 'group-hover:scale-110')} />
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

// ── Child nav link inside branch ───────────────────────────────────────────

function ChildNavLink({ href, label, icon: Icon, pathname }: NavItem & { pathname: string }) {
  const active = isActive(pathname, href);
  return (
    <Link
      href={href}
      className={cn(
        'group flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-[13px]',
        'transition-all duration-150 ease-out',
        active
          ? 'font-medium shadow-sm'
          : 'hover:bg-[var(--bg-surface)] hover:translate-x-0.5',
      )}
      style={active
        ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
        : { color: 'var(--text-secondary)' }
      }
    >
      <Icon size={13} className={cn('flex-shrink-0 transition-all duration-150', !active && 'group-hover:scale-110')} />
      <span className="truncate">{label}</span>
    </Link>
  );
}

// ── Expandable group ───────────────────────────────────────────────────────

function NavGroupSection({
  group,
  pathname,
  open,
  onToggle,
}: {
  group: NavGroup;
  pathname: string;
  open: boolean;
  onToggle: () => void;
}) {
  const hasActive = group.items.some(item => isActive(pathname, item.href));
  const GroupIcon = group.icon;

  return (
    <div>
      {/* Group header — AppSidebar NavItem style */}
      <button
        onClick={onToggle}
        className={cn(
          'group relative w-full flex items-center gap-2.5 px-3 py-2 rounded-xl text-sm',
          'transition-all duration-150 ease-out',
          'hover:bg-[var(--bg-surface)] hover:translate-x-0.5',
        )}
        style={{
          color: hasActive ? 'var(--text-primary)' : 'var(--text-secondary)',
          fontWeight: hasActive ? 600 : 400,
        }}
      >
        {/* Active accent bar (shown when group has active child) */}
        <span
          className="absolute left-0 top-1/2 -translate-y-1/2 w-[3px] rounded-full transition-all duration-200"
          style={{
            backgroundColor: 'var(--gold)',
            height: hasActive ? '18px' : '0px',
            opacity: hasActive ? 1 : 0,
          }}
        />
        <GroupIcon
          size={16}
          className={cn('flex-shrink-0 transition-all duration-150', 'group-hover:scale-110')}
        />
        <span className="flex-1 text-left truncate">{group.label}</span>
        <ChevronRight
          size={12}
          className="flex-shrink-0 transition-transform duration-200 ease-in-out opacity-40"
          style={{ transform: open ? 'rotate(90deg)' : 'rotate(0deg)' }}
        />
      </button>

      {/* Animated branch children */}
      <div
        className="grid"
        style={{
          gridTemplateRows: open ? '1fr' : '0fr',
          transition: 'grid-template-rows 200ms ease-in-out',
        }}
      >
        <div className="overflow-hidden min-h-0">
          {/* Branch container: curved tree connectors aligned to group icon */}
          <div className="ml-[22px] py-1 space-y-0.5">
            {group.items.map((item, i) => {
              const isLast = i === group.items.length - 1;
              return (
                <div key={item.href} className="relative pl-4">
                  {/* Curved elbow: vertical drop from above + rounded turn into the item */}
                  <span
                    className="absolute left-0 top-0 pointer-events-none"
                    style={{
                      width: '12px',
                      height: 'calc(50% + 1px)',
                      borderLeft: '1.5px solid var(--border)',
                      borderBottom: '1.5px solid var(--border)',
                      borderBottomLeftRadius: '8px',
                    }}
                  />
                  {/* Continue the vertical trunk past this item (all but the last) */}
                  {!isLast && (
                    <span
                      className="absolute left-0 bottom-0 pointer-events-none"
                      style={{
                        width: '1.5px',
                        top: '50%',
                        backgroundColor: 'var(--border)',
                      }}
                    />
                  )}
                  <ChildNavLink {...item} pathname={pathname} />
                </div>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
}

// ── Main component ─────────────────────────────────────────────────────────

export default function ProjectSidebar({
  projectId,
  projectName,
  projectCode,
  companyName,
  organizationId,
  isLoading,
  mobileOpen = false,
  onMobileClose,
}: ProjectSidebarProps) {
  const pathname = usePathname();
  const { user } = useAuthStore();

  const [groupOpen, setGroupOpen] = useState<Record<string, boolean>>(() => {
    const initial: Record<string, boolean> = {};
    groups(projectId).forEach(g => {
      initial[g.label] = g.items.some(item => isActive(pathname, item.href));
    });
    return initial;
  });

  // Hydrate from localStorage, keep active group open
  useEffect(() => {
    const stored = readStorage();
    setGroupOpen(prev => {
      const merged = { ...stored };
      groups(projectId).forEach(g => {
        if (prev[g.label]) merged[g.label] = true;
      });
      return merged;
    });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Auto-expand group on navigation to a child
  useEffect(() => {
    groups(projectId).forEach(g => {
      if (g.items.some(item => isActive(pathname, item.href))) {
        setGroupOpen(prev => {
          if (prev[g.label]) return prev;
          const next = { ...prev, [g.label]: true };
          writeStorage(next);
          return next;
        });
      }
    });
  }, [pathname, projectId]);

  // Close mobile drawer on route change
  useEffect(() => {
    onMobileClose?.();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);

  const toggleGroup = useCallback((label: string) => {
    setGroupOpen(prev => {
      const next = { ...prev, [label]: !prev[label] };
      writeStorage(next);
      return next;
    });
  }, []);

  const isSystemUser = user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin');
  const backHref = isSystemUser && organizationId
    ? `/admin/companies/${organizationId}`
    : isSystemUser ? '/admin/projects' : '/app/projects';
  const backLabel = isSystemUser && organizationId ? 'All Companies' : 'All Projects';

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
          'flex flex-col w-[240px] h-screen z-50',
          'fixed inset-y-0 left-0 transition-transform duration-300 ease-out',
          'lg:static lg:z-auto lg:flex-shrink-0 lg:transition-none',
          mobileOpen ? 'translate-x-0 shadow-2xl' : '-translate-x-full lg:translate-x-0',
        )}
        style={{ backgroundColor: 'var(--bg-base)' }}
      >
        {/* Project identity block */}
        <div className="px-3 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <Link
            href={backHref}
            className="inline-flex items-center gap-1.5 text-xs font-medium mb-3 rounded-lg transition-all hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-muted)', padding: '5px 8px' }}
          >
            <ArrowLeft size={12} />
            {backLabel}
          </Link>

          <div
            className="flex items-start gap-2.5 px-3 py-2.5 rounded-xl"
            style={{ backgroundColor: 'var(--bg-elevated)' }}
          >
            <div
              className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
              style={{ backgroundColor: 'var(--gold-15)' }}
            >
              <FolderKanban size={14} style={{ color: 'var(--gold)' }} />
            </div>
            <div className="flex-1 min-w-0">
              {isLoading ? (
                <>
                  <div className="h-3.5 w-28 rounded-md animate-pulse mb-1.5" style={{ backgroundColor: 'var(--border)' }} />
                  <div className="h-2.5 w-16 rounded-md animate-pulse" style={{ backgroundColor: 'var(--border)' }} />
                </>
              ) : (
                <>
                  <div className="text-xs font-semibold leading-snug truncate" style={{ color: 'var(--text-primary)' }} title={projectName}>
                    {projectName || '—'}
                  </div>
                  {projectCode && (
                    <div className="text-xs mt-0.5 font-mono" style={{ color: 'var(--text-muted)' }}>{projectCode}</div>
                  )}
                  {companyName && (
                    <div className="text-xs mt-1 truncate" style={{ color: 'var(--text-muted)' }} title={companyName}>{companyName}</div>
                  )}
                </>
              )}
            </div>
          </div>
        </div>

        {/* Scrollable nav — space-y-4 between sections matches AppSidebar */}
        <nav className="flex-1 overflow-y-auto py-3 space-y-4" style={{ overflowX: 'visible' }}>
          {/* Standalone: Overview */}
          <div className="px-3 space-y-0.5">
            {standalone(projectId).map(item => (
              <NavLink key={item.href} {...item} pathname={pathname} />
            ))}
          </div>

          {/* Grouped sections */}
          <div className="px-3 space-y-1">
            {groups(projectId).map(group => (
              <NavGroupSection
                key={group.label}
                group={group}
                pathname={pathname}
                open={!!groupOpen[group.label]}
                onToggle={() => toggleGroup(group.label)}
              />
            ))}
          </div>

          {/* Utility: Documents + Calendar */}
          <div className="px-3 space-y-0.5 pt-0" style={{ borderTop: '1px solid var(--border)', paddingTop: '12px', marginTop: '0' }}>
            {utility(projectId).map(item => (
              <NavLink key={item.href} {...item} pathname={pathname} />
            ))}
          </div>
        </nav>
      </aside>
    </>
  );
}
