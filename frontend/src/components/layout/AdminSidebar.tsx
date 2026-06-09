'use client';

import { useState, useEffect } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';
import { useSiteSettings } from '@/hooks/useSiteSettings';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard, Building2, FolderKanban, FileText, LayoutTemplate, Brain, HardDrive,
  CreditCard, Users, LifeBuoy, ScrollText, Settings, LogOut,
  Sun, Moon, ChevronUp, Gem, BookOpen, Search,
  PanelLeftClose, PanelLeftOpen,
} from 'lucide-react';
import { APP_VERSION_LABEL } from '@/config/app-version';
// Note: Notifications intentionally excluded from sidebar nav.
// Access via the bell icon in the top-right header.

const COLLAPSED_KEY = 'suresign_sidebar_collapsed';

const adminNavItems = [
  { href: '/admin',                   label: 'Dashboard',      icon: LayoutDashboard, exact: true,  pageKey: null },
  { href: '/admin/companies',         label: 'Companies',      icon: Building2,                     pageKey: 'companies' },
  { href: '/admin/documents',         label: 'Documents',      icon: FileText,                      pageKey: 'documents' },
  { href: '/admin/prompts',           label: 'Prompt Library', icon: BookOpen,                      pageKey: 'prompts' },
  { href: '/admin/projects',          label: 'Projects',       icon: FolderKanban,                  pageKey: 'projects' },
  { href: '/admin/templates',         label: 'Templates',      icon: LayoutTemplate,                pageKey: 'templates' },
  { href: '/admin/find',              label: 'Find Company',   icon: Search,                        pageKey: 'find' },
  { href: '/admin/billing',           label: 'Billing',        icon: CreditCard,                    pageKey: 'billing' },
  { href: '/admin/users',             label: 'Users',          icon: Users,                         pageKey: 'users' },
  { href: '/admin/suresign',          label: 'SureSign',       icon: Gem,                           pageKey: 'suresign' },
  { href: '/admin/ai-configurations', label: 'AI Config',      icon: Brain,                         pageKey: 'ai-configurations', superAdminOnly: true },
  { href: '/admin/storage',           label: 'Storage',        icon: HardDrive,                     pageKey: 'storage',           superAdminOnly: true },
  { href: '/admin/support',           label: 'Support',        icon: LifeBuoy,                      pageKey: 'support',           superAdminOnly: true },
  { href: '/admin/system-logs',       label: 'System Logs',    icon: ScrollText,                    pageKey: 'system-logs',       superAdminOnly: true },
];

function NavSkeleton({ collapsed }: { collapsed: boolean }) {
  return (
    <div className="px-3 py-4 space-y-1">
      {[...Array(8)].map((_, i) => (
        <div key={i} className={cn('h-9 rounded-lg animate-pulse', collapsed ? 'w-9 mx-auto' : '')}
          style={{ backgroundColor: 'var(--bg-elevated)', opacity: 1 - i * 0.08 }} />
      ))}
    </div>
  );
}

export default function AdminSidebar() {
  const pathname = usePathname();
  const { user, logout } = useAuthStore();
  const { theme, toggle } = useTheme();
  const [profileOpen, setProfileOpen] = useState(false);
  const [collapsed, setCollapsed] = useState(false);

  // Restore persisted collapsed state after mount
  useEffect(() => {
    try {
      const saved = localStorage.getItem(COLLAPSED_KEY);
      if (saved !== null) setCollapsed(saved === 'true');
    } catch {}
  }, []);

  function toggleCollapsed() {
    setCollapsed(c => {
      const next = !c;
      try { localStorage.setItem(COLLAPSED_KEY, String(next)); } catch {}
      return next;
    });
    setProfileOpen(false);
  }

  const isSuperAdmin = user?.roles?.includes('Super Admin');
  const { data: siteSettings, isSettingsReady } = useSiteSettings();
  const hiddenPages: string[] = siteSettings?.hidden_pages ?? [];

  const visibleNavItems = adminNavItems.filter(item => {
    if (item.superAdminOnly && !isSuperAdmin) return false;
    if (item.pageKey && hiddenPages.includes(item.pageKey)) return false;
    return true;
  });

  const initials = user?.name?.charAt(0)?.toUpperCase() || '?';

  return (
    <aside
      className="flex flex-col h-screen flex-shrink-0 overflow-x-hidden overflow-y-hidden transition-all duration-200"
      style={{
        width: collapsed ? '56px' : '240px',
        minWidth: collapsed ? '56px' : '240px',
        maxWidth: collapsed ? '56px' : '240px',
        backgroundColor: 'var(--bg-surface)',
        borderRight: '1px solid var(--border)',
      }}
    >
      {/* Logo + collapse toggle */}
      <div className="flex items-center px-3 flex-shrink-0" style={{ borderBottom: '1px solid var(--border)', height: 60 }}>
        <div className={cn('flex items-center gap-3 flex-1', collapsed ? 'justify-center' : '')}>
          <img
            src={theme === 'dark' ? '/logo_white/SureSign_WLOGO.png' : '/logo_black/SureSign_BLOGO.png'}
            alt="SureSign"
            className="w-7 h-7 object-contain flex-shrink-0"
          />
          {!collapsed && (
            <div className="flex-1 min-w-0">
              <div className="text-sm font-semibold leading-none whitespace-nowrap" style={{ color: 'var(--text-primary)' }}>SureSign Admin</div>
              <div className="text-xs mt-0.5 whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>
                {isSuperAdmin ? 'Super Administrator' : 'Administrator'}
              </div>
            </div>
          )}
        </div>
        {!collapsed && (
          <button onClick={toggleCollapsed} title="Collapse sidebar"
            className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)] flex-shrink-0"
            style={{ color: 'var(--text-muted)' }}>
            <PanelLeftClose size={15} />
          </button>
        )}
      </div>

      {/* Nav */}
      {!isSettingsReady ? (
        <NavSkeleton collapsed={collapsed} />
      ) : (
        <nav
          className={cn(
            'flex-1 overflow-y-auto overflow-x-hidden py-3 space-y-0.5',
            collapsed ? 'px-0' : 'px-2'
          )}
        >
          {visibleNavItems.map(item => {
            const active = item.exact
              ? pathname === item.href
              : pathname === item.href || pathname?.startsWith(item.href + '/');
            const Icon = item.icon;

            if (collapsed) {
              return (
                <Link key={item.href} href={item.href} title={item.label}
                  className="flex items-center justify-center w-9 h-9 mx-auto rounded-lg transition-colors"
                  style={active
                    ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                    : { color: 'var(--text-secondary)' }}
                  onMouseEnter={e => { if (!active) (e.currentTarget as HTMLElement).style.backgroundColor = 'var(--bg-hover)'; }}
                  onMouseLeave={e => { if (!active) (e.currentTarget as HTMLElement).style.backgroundColor = 'transparent'; }}
                >
                  <Icon size={16} />
                </Link>
              );
            }

            return (
              <Link key={item.href} href={item.href}
                className={cn('flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all', active ? 'font-medium' : 'hover:bg-[var(--bg-hover)]')}
                style={active ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
                <Icon size={16} className="flex-shrink-0" />
                <span>{item.label}</span>
              </Link>
            );
          })}
        </nav>
      )}

      {/* Profile footer */}
      <div className="relative flex-shrink-0" style={{ borderTop: '1px solid var(--border)' }}>

        {/* Profile slide-up drawer (expanded only) */}
        {!collapsed && (
          <div
            className={cn(
              'absolute bottom-full left-0 right-0 mx-2 mb-1 rounded-xl overflow-hidden transition-all duration-200',
              profileOpen ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2 pointer-events-none'
            )}
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 -8px 30px rgba(0,0,0,0.12)' }}
          >
            <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
              <div className="text-xs font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{user?.name}</div>
              <div className="text-xs truncate mt-0.5" style={{ color: 'var(--text-muted)' }}>{user?.email}</div>
            </div>
            <Link href="/admin/settings" onClick={() => setProfileOpen(false)}
              className="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-secondary)' }}>
              <Settings size={15} /><span>Settings</span>
            </Link>
            <button onClick={toggle}
              className="w-full flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-secondary)' }}>
              {theme === 'dark' ? <Sun size={15} /> : <Moon size={15} />}
              <span>{theme === 'dark' ? 'Light Mode' : 'Dark Mode'}</span>
            </button>
            <button onClick={() => logout().then(() => (window.location.href = '/login'))}
              className="w-full flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: '#ef4444', borderTop: '1px solid var(--border)' }}>
              <LogOut size={15} /><span>Log out</span>
            </button>
            <div className="px-4 py-2 text-center" style={{ borderTop: '1px solid var(--border)' }}>
              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{APP_VERSION_LABEL}</span>
            </div>
          </div>
        )}

        {/* Collapsed footer: expand + avatar */}
        {collapsed ? (
          <div className="flex flex-col items-center gap-1 py-2">
            <button onClick={toggleCollapsed} title="Expand sidebar"
              className="w-9 h-9 flex items-center justify-center rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-muted)' }}>
              <PanelLeftOpen size={15} />
            </button>
            <button onClick={toggle} title={theme === 'dark' ? 'Light Mode' : 'Dark Mode'}
              className="w-9 h-9 flex items-center justify-center rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-muted)' }}>
              {theme === 'dark' ? <Sun size={14} /> : <Moon size={14} />}
            </button>
            {/* Avatar — clickable, opens popover */}
            <div className="relative">
              {/* Fixed popover so overflow-x-hidden on the aside doesn't clip it */}
              {profileOpen && (
                <div
                  className="fixed bottom-4 left-14 ml-2 w-48 rounded-xl overflow-hidden z-[200]"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 4px 24px rgba(0,0,0,0.22)' }}
                >
                  <div className="px-3 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
                    <div className="text-xs font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{user?.name}</div>
                    <div className="text-xs truncate mt-0.5" style={{ color: 'var(--text-muted)' }}>{user?.email}</div>
                  </div>
                  <Link href="/admin/settings" onClick={() => setProfileOpen(false)}
                    className="flex items-center gap-2.5 px-3 py-2 text-xs transition-colors hover:bg-[var(--bg-hover)]"
                    style={{ color: 'var(--text-secondary)' }}>
                    <Settings size={13} /><span>Settings</span>
                  </Link>
                  <button onClick={() => logout().then(() => (window.location.href = '/login'))}
                    className="w-full flex items-center gap-2.5 px-3 py-2 text-xs transition-colors hover:bg-[var(--bg-hover)]"
                    style={{ color: '#ef4444', borderTop: '1px solid var(--border)' }}>
                    <LogOut size={13} /><span>Log out</span>
                  </button>
                  <div className="px-3 py-1.5 text-center" style={{ borderTop: '1px solid var(--border)' }}>
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{APP_VERSION_LABEL}</span>
                  </div>
                </div>
              )}
              <button
                onClick={() => setProfileOpen(p => !p)}
                title={user?.name ?? 'Profile'}
                className="w-9 h-9 flex items-center justify-center rounded-full transition-all hover:ring-2"
                style={{ backgroundColor: 'rgba(185,149,102,0.2)', color: 'var(--gold)', outlineColor: 'var(--gold)' }}
              >
                <span className="text-xs font-bold">{initials}</span>
              </button>
            </div>
          </div>
        ) : (
          /* Expanded footer: full profile button */
          <button onClick={() => setProfileOpen(p => !p)} className="w-full px-3 py-3">
            <div className="flex items-center gap-3 px-3 py-2 rounded-lg transition-colors hover:bg-[var(--bg-hover)]">
              <div className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-semibold"
                style={{ backgroundColor: 'rgba(185,149,102,0.2)', color: 'var(--gold)' }}>
                {initials}
              </div>
              <div className="flex-1 min-w-0">
                <div className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{user?.name}</div>
                <div className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{user?.roles?.[0] ?? 'Admin'}</div>
              </div>
              <ChevronUp size={14} className={cn('transition-transform flex-shrink-0', profileOpen ? '' : 'rotate-180')}
                style={{ color: 'var(--text-muted)' }} />
            </div>
          </button>
        )}
      </div>
    </aside>
  );
}
