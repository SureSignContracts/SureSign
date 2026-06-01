'use client';

import { useState } from 'react';
import Link from 'next/link';
import Image from 'next/image';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';
import { useQuery } from '@tanstack/react-query';
import { cn } from '@/lib/utils';
import api from '@/lib/api';
import {
  LayoutDashboard, FolderKanban, DollarSign, HardHat,
  FileText, BarChart2, Brain, Users, Settings, LogOut,
  Sun, Moon, ShieldCheck, ChevronUp,
} from 'lucide-react';

const navItems = [
  { href: '/app',           label: 'Dashboard',    icon: LayoutDashboard },
  { href: '/app/projects',  label: 'Projects',     icon: FolderKanban },
  { href: '/app/commercial',label: 'Commercial',   icon: DollarSign },
  { href: '/app/site',      label: 'Site Admin',   icon: HardHat },
  { href: '/app/documents', label: 'Documents',    icon: FileText },
  { href: '/app/reports',   label: 'Reports',      icon: BarChart2 },
  { href: '/app/ai',        label: 'AI Assistant', icon: Brain },
];

const bottomItems = [
  { href: '/app/team',     label: 'Team',     icon: Users },
  { href: '/app/settings', label: 'Settings', icon: Settings },
];

export default function AppSidebar() {
  const pathname = usePathname();
  const { user, logout } = useAuthStore();
  const { theme, toggle } = useTheme();
  const [profileOpen, setProfileOpen] = useState(false);

  const { data: branding } = useQuery({
    queryKey: ['branding'],
    queryFn: () => api.get('/organization/branding').then(r => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000,
  });

  const NavLink = ({ href, label, icon: Icon, exact = false }: {
    href: string; label: string; icon: any; exact?: boolean;
  }) => {
    const active = exact ? pathname === href : (pathname === href || pathname?.startsWith(href + '/'));
    return (
      <Link
        href={href}
        className={cn(
          'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all group',
          active
            ? 'font-medium'
            : 'hover:bg-[var(--bg-hover)]'
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
      {/* Logo / Org */}
      <div className="flex items-center gap-2.5 px-4 py-5" style={{ borderBottom: '1px solid var(--border)' }}>
        <div
          className="w-8 h-8 rounded-md flex items-center justify-center flex-shrink-0 text-sm font-bold overflow-hidden"
          style={{ backgroundColor: branding?.logo_url ? 'transparent' : 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          {branding?.logo_url ? (
            <img src={branding.logo_url} alt="Company logo" className="w-full h-full object-contain" />
          ) : (
            (branding?.company_name?.charAt(0) || user?.organization?.name?.charAt(0) || 'S').toUpperCase()
          )}
        </div>
        <div className="min-w-0">
          <div className="text-sm font-semibold leading-none truncate" style={{ color: 'var(--text-primary)' }}>
            {branding?.company_name || user?.organization?.name || 'Company Portal'}
          </div>
          <div className="text-xs mt-0.5 truncate max-w-[160px]" style={{ color: 'var(--text-muted)' }}>
            SureSign
          </div>
        </div>
      </div>

      {/* Main nav */}
      <nav className="flex-1 overflow-y-auto px-3 py-4 space-y-0.5">
        <NavLink href="/app" label="Dashboard" icon={LayoutDashboard} exact />
        <NavLink href="/app/projects" label="Projects" icon={FolderKanban} />
        <NavLink href="/app/commercial" label="Commercial" icon={DollarSign} />
        <NavLink href="/app/site" label="Site Admin" icon={HardHat} />
        <NavLink href="/app/documents" label="Documents" icon={FileText} />
        <NavLink href="/app/reports" label="Reports" icon={BarChart2} />
        <NavLink href="/app/ai" label="AI Assistant" icon={Brain} />
      </nav>

      {/* Bottom nav */}
      <div className="px-3 pb-2 space-y-0.5" style={{ borderTop: '1px solid var(--border)', paddingTop: '12px' }}>
        <NavLink href="/app/team" label="Team" icon={Users} />
      </div>

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
            href="/app/settings"
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
          {user?.roles?.includes('Super Admin') && (
            <Link
              href="/admin"
              className="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: '#ef4444' }}
            >
              <ShieldCheck size={15} />
              <span>Admin Panel</span>
            </Link>
          )}
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
              <div className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{user?.roles?.[0]}</div>
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
