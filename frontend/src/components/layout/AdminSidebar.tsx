'use client';

import { useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';
import { useSiteSettings } from '@/hooks/useSiteSettings';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard, Building2, FolderKanban, FileText, LayoutTemplate, Brain, HardDrive,
  CreditCard, Users, LifeBuoy, ScrollText, Settings, LogOut,
  Sun, Moon, ChevronUp, Gem, BookOpen,
} from 'lucide-react';

const adminNavItems = [
  { href: '/admin',                   label: 'Dashboard',      icon: LayoutDashboard, exact: true,  pageKey: null },
  { href: '/admin/companies',         label: 'Companies',      icon: Building2,                     pageKey: 'companies' },
  { href: '/admin/documents',         label: 'Documents',      icon: FileText,                      pageKey: 'documents' },
  { href: '/admin/prompts',           label: 'Prompt Library', icon: BookOpen,                      pageKey: 'prompts' },
  { href: '/admin/projects',          label: 'Projects',       icon: FolderKanban,                  pageKey: 'projects' },
  { href: '/admin/templates',         label: 'Templates',      icon: LayoutTemplate,                pageKey: 'templates' },
  { href: '/admin/billing',           label: 'Billing',        icon: CreditCard,                    pageKey: 'billing' },
  { href: '/admin/users',             label: 'Users',          icon: Users,                         pageKey: 'users' },
  { href: '/admin/suresign',          label: 'SureSign',       icon: Gem,                           pageKey: null },
  // Super Admin only
  { href: '/admin/ai-configurations', label: 'AI Config',      icon: Brain,                         pageKey: null, superAdminOnly: true },
  { href: '/admin/storage',           label: 'Storage',        icon: HardDrive,                     pageKey: null, superAdminOnly: true },
  { href: '/admin/support',           label: 'Support',        icon: LifeBuoy,                      pageKey: null, superAdminOnly: true },
  { href: '/admin/system-logs',       label: 'System Logs',    icon: ScrollText,                    pageKey: null, superAdminOnly: true },
];

export default function AdminSidebar() {
  const pathname = usePathname();
  const { user, logout } = useAuthStore();
  const { theme, toggle } = useTheme();
  const [profileOpen, setProfileOpen] = useState(false);

  const isSuperAdmin = user?.roles?.includes('Super Admin');

  const { data: siteSettings } = useSiteSettings();
  const hiddenPages: string[] = siteSettings?.hidden_pages ?? [];

  const visibleNavItems = adminNavItems.filter(item => {
    if (item.superAdminOnly && !isSuperAdmin) return false;
    if (item.pageKey && hiddenPages.includes(item.pageKey)) return false;
    return true;
  });

  const NavLink = ({ href, label, icon: Icon, exact = false }: {
    href: string; label: string; icon: any; exact?: boolean;
  }) => {
    const active = exact ? pathname === href : (pathname === href || pathname?.startsWith(href + '/'));
    return (
      <Link
        href={href}
        className={cn(
          'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all',
          active ? 'font-medium' : 'hover:bg-[var(--bg-hover)]'
        )}
        style={active
          ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
          : { color: 'var(--text-secondary)' }
        }
      >
        <Icon size={16} className="flex-shrink-0" />
        <span>{label}</span>
      </Link>
    );
  };

  return (
    <aside
      className="flex flex-col w-[240px] h-screen flex-shrink-0"
      style={{ backgroundColor: 'var(--bg-surface)', borderRight: '1px solid var(--border)' }}
    >
      {/* Admin header */}
      <div className="flex items-center gap-3 px-4 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
        <img
          src={theme === 'dark' ? '/logo_white/SureSign_WLOGO.png' : '/logo_black/SureSign_BLOGO.png'}
          alt="SureSign"
          className="w-8 h-8 object-contain flex-shrink-0"
        />
        <div className="min-w-0">
          <div className="text-sm font-semibold leading-none" style={{ color: 'var(--text-primary)' }}>
            SureSign Admin
          </div>
          <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
            {user?.roles?.includes('Super Admin') ? 'Super Administrator' : 'Administrator'}
          </div>
        </div>
      </div>

      {/* Nav */}
      <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
        {visibleNavItems.map((item) => (
          <NavLink key={item.href} href={item.href} label={item.label} icon={item.icon} exact={item.exact} />
        ))}
      </nav>

      {/* Profile footer + slide-up drawer */}
      <div className="relative" style={{ borderTop: '1px solid var(--border)' }}>
        {/* Drawer */}
        <div
          className={`absolute bottom-full left-0 right-0 mx-2 mb-1 rounded-xl overflow-hidden transition-all duration-200 ${
            profileOpen ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2 pointer-events-none'
          }`}
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 -8px 30px rgba(0,0,0,0.12)' }}
        >
          <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
            <div className="text-xs font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{user?.name}</div>
            <div className="text-xs truncate mt-0.5" style={{ color: 'var(--text-muted)' }}>{user?.email}</div>
          </div>
          <Link
            href="/admin/settings"
            onClick={() => setProfileOpen(false)}
            className="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-secondary)' }}
          >
            <Settings size={15} />
            <span>Settings</span>
          </Link>
          <button
            onClick={toggle}
            className="w-full flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-secondary)' }}
          >
            {theme === 'dark' ? <Sun size={15} /> : <Moon size={15} />}
            <span>{theme === 'dark' ? 'Light Mode' : 'Dark Mode'}</span>
          </button>
          <button
            onClick={() => logout().then(() => (window.location.href = '/login'))}
            className="w-full flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: '#ef4444', borderTop: '1px solid var(--border)' }}
          >
            <LogOut size={15} />
            <span>Log out</span>
          </button>
        </div>

        {/* Clickable profile */}
        <button
          onClick={() => setProfileOpen(p => !p)}
          className="w-full px-3 py-3 text-left"
        >
          <div className="flex items-center gap-3 px-3 py-2 rounded-lg transition-colors hover:bg-[var(--bg-hover)]">
            <div
              className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-semibold"
              style={{ backgroundColor: 'rgba(185,149,102,0.2)', color: 'var(--gold)' }}
            >
              {user?.name?.charAt(0)?.toUpperCase() || '?'}
            </div>
            <div className="flex-1 min-w-0">
              <div className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{user?.name}</div>
              <div className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{user?.roles?.[0] ?? 'Admin'}</div>
            </div>
            <ChevronUp
              size={14}
              className={`transition-transform flex-shrink-0 ${profileOpen ? '' : 'rotate-180'}`}
              style={{ color: 'var(--text-muted)' }}
            />
          </div>
        </button>
      </div>
    </aside>
  );
}
