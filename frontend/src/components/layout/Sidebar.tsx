'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard, FileText, DollarSign,
  HardHat, MessageSquare, Brain, Settings, LogOut,
  Building2, Sun, Moon,
} from 'lucide-react';

const navItems = [
  { href: '/',            label: 'Dashboard',    icon: LayoutDashboard },
  { href: '/companies',   label: 'Companies',    icon: Building2 },
  { href: '/contracts',   label: 'Contracts',    icon: FileText },
  { href: '/commercial',  label: 'Commercial',   icon: DollarSign },
  { href: '/site',        label: 'Site Admin',   icon: HardHat },
  { href: '/documents',   label: 'Documents',    icon: FileText },
  { href: '/ai',          label: 'AI Assistant', icon: Brain },
];

const adminItems = [
  { href: '/admin/users', label: 'Users',        icon: MessageSquare },
  { href: '/settings',    label: 'Settings',     icon: Settings },
];

export default function Sidebar() {
  const pathname = usePathname();
  const { user, logout } = useAuthStore();
  const { theme, toggle } = useTheme();
  const isSuperAdmin = user?.roles?.includes('Super Admin');

  const NavLink = ({ href, label, icon: Icon }: { href: string; label: string; icon: any }) => {
    const active = pathname === href || (href !== '/' && pathname?.startsWith(href));
    return (
      <Link href={href}
        className={cn(
          'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all group',
          active
            ? 'text-black font-medium'
            : 'hover:text-[var(--text-primary)] hover:bg-[var(--bg-hover)]'
        )}
        style={active
          ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
          : { color: 'var(--text-secondary)' }
        }
      >
        <Icon size={16} className={cn('flex-shrink-0', !active && 'group-hover:text-[var(--gold)]')} />
        <span>{label}</span>
      </Link>
    );
  };

  return (
    <aside className="flex flex-col w-[260px] h-screen flex-shrink-0"
           style={{ backgroundColor: 'var(--bg-surface)', borderRight: '1px solid var(--border)' }}>

      {/* Logo */}
      <div className="flex items-center gap-2.5 px-4 py-5" style={{ borderBottom: '1px solid var(--border)' }}>
        <div className="w-8 h-8 rounded-md flex items-center justify-center flex-shrink-0"
             style={{ backgroundColor: 'var(--gold)' }}>
          <span className="font-bold text-sm" style={{ color: 'var(--accent-fg)' }}>S</span>
        </div>
        <div>
          <div className="text-sm font-semibold leading-none" style={{ color: 'var(--text-primary)' }}>SureSign</div>
          <div className="text-xs mt-0.5 truncate max-w-[160px]" style={{ color: 'var(--text-muted)' }}>
            {user?.organization?.name || 'Platform'}
          </div>
        </div>
      </div>

      {/* Navigation */}
      <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-1">
        {navItems.map((item) => <NavLink key={item.href} {...item} />)}

        {isSuperAdmin && (
          <>
            <div className="pt-4 pb-1 px-3">
              <span className="text-xs font-medium uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                Admin
              </span>
            </div>
            {adminItems.map((item) => <NavLink key={item.href} {...item} />)}
          </>
        )}
      </nav>

      {/* User */}
      <div className="px-3 py-4" style={{ borderTop: '1px solid var(--border)' }}>
        <div className="flex items-center gap-3 px-3 py-2 rounded-lg mb-1"
             style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <div className="w-7 h-7 rounded-full flex items-center justify-center flex-shrink-0 text-xs font-semibold"
               style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
            {user?.name?.charAt(0)?.toUpperCase() || '?'}
          </div>
          <div className="flex-1 min-w-0">
            <div className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{user?.name}</div>
            <div className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{user?.roles?.[0]}</div>
          </div>
        </div>
        <button
          onClick={() => logout()}
          className="flex items-center gap-2 w-full px-3 py-1.5 rounded-lg text-xs transition-all hover:text-red-500 hover:bg-red-500/10"
          style={{ color: 'var(--text-muted)' }}
        >
          <LogOut size={14} />
          Sign out
        </button>
        {/* Theme toggle */}
        <button
          onClick={toggle}
          className="flex items-center gap-2 w-full px-3 py-1.5 rounded-lg text-xs transition-all hover:bg-[var(--bg-hover)] hover:text-[var(--text-primary)]"
          style={{ color: 'var(--text-muted)' }}
          title={theme === 'light' ? 'Switch to dark mode' : 'Switch to light mode'}
        >
          {theme === 'light' ? <Moon size={14} /> : <Sun size={14} />}
          {theme === 'light' ? 'Dark mode' : 'Light mode'}
        </button>
      </div>
    </aside>
  );
}
