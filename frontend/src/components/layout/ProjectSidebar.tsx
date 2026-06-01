'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { cn } from '@/lib/utils';
import {
  LayoutDashboard, FileText, DollarSign, MessageSquare, GitBranch,
  ClipboardList, Users2, Bell, CheckSquare, FolderOpen, Package, Archive,
  ArrowLeft, FolderKanban, Scale,
} from 'lucide-react';

interface ProjectSidebarProps {
  projectId: string;
  projectName?: string;
  projectCode?: string;
  companyName?: string;
  organizationId?: number | string;
  isLoading?: boolean;
}

const projectNavItems = (id: string) => [
  { href: `/app/projects/${id}/overview`,      label: 'Overview',       icon: LayoutDashboard },
  { href: `/app/projects/${id}/contracts`,     label: 'Contracts',      icon: FileText },
  { href: `/app/projects/${id}/commercial`,    label: 'Commercial',     icon: DollarSign },
  { href: `/app/projects/${id}/variations`,    label: 'Variations',     icon: GitBranch },
  { href: `/app/projects/${id}/notices`,       label: 'Notices',        icon: Bell },
  { href: `/app/projects/${id}/adjudication`,  label: 'Adjudication',   icon: Scale },
  { href: `/app/projects/${id}/rfis`,          label: 'RFIs',           icon: MessageSquare },
  { href: `/app/projects/${id}/meetings`,      label: 'Meetings',       icon: Users2 },
  { href: `/app/projects/${id}/qa`,            label: 'QA Reports',     icon: CheckSquare },
  { href: `/app/projects/${id}/snagging`,      label: 'Snagging',       icon: Package },
  { href: `/app/projects/${id}/closeout`,      label: 'Closeout',       icon: Archive },
  { href: `/app/projects/${id}/documents`,     label: 'Documents',      icon: FolderOpen },
  { href: `/app/projects/${id}/site-reports`,  label: 'Site Reports',   icon: ClipboardList },
];

export default function ProjectSidebar({ projectId, projectName, projectCode, companyName, organizationId, isLoading }: ProjectSidebarProps) {
  const pathname = usePathname();
  const { user } = useAuthStore();

  const isSystemUser = user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin');
  const backHref = isSystemUser && organizationId
    ? `/admin/companies/${organizationId}`
    : isSystemUser
    ? '/admin/projects'
    : '/app/projects';
  const backLabel = isSystemUser && organizationId ? 'All Companies' : 'All Projects';

  const NavLink = ({ href, label, icon: Icon }: { href: string; label: string; icon: any }) => {
    const active = pathname === href || pathname?.startsWith(href + '/');
    return (
      <Link
        href={href}
        className={cn(
          'flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-all duration-150 group',
          active ? 'font-medium' : 'hover:bg-[var(--bg-hover)]'
        )}
        style={active
          ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
          : { color: 'var(--text-secondary)' }
        }
      >
        <Icon size={15} className="flex-shrink-0" />
        <span>{label}</span>
      </Link>
    );
  };

  return (
    <aside
      className="flex flex-col w-[240px] h-screen flex-shrink-0"
      style={{ backgroundColor: 'var(--bg-surface)', borderRight: '1px solid var(--border)' }}
    >
      {/* Project identity block */}
      <div className="px-3 pt-4 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
        {/* Back link */}
        <Link
          href={backHref}
          className="inline-flex items-center gap-1.5 text-xs font-medium mb-3 rounded-lg transition-all hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--text-muted)', padding: '5px 8px' }}
        >
          <ArrowLeft size={12} />
          {backLabel}
        </Link>

        {/* Project name + code */}
        <div
          className="flex items-start gap-2.5 px-3 py-2.5 rounded-xl"
          style={{ backgroundColor: 'var(--bg-elevated)' }}
        >
          <div
            className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5"
            style={{ backgroundColor: 'rgba(185,149,102,0.15)' }}
          >
            <FolderKanban size={14} style={{ color: 'var(--gold)' }} />
          </div>
          <div className="flex-1 min-w-0">
            {isLoading ? (
              <>
                <div
                  className="h-3.5 w-28 rounded-md animate-pulse mb-1.5"
                  style={{ backgroundColor: 'var(--border)' }}
                />
                <div
                  className="h-2.5 w-16 rounded-md animate-pulse"
                  style={{ backgroundColor: 'var(--border)' }}
                />
              </>
            ) : (
              <>
                <div
                  className="text-xs font-semibold leading-snug truncate"
                  style={{ color: 'var(--text-primary)' }}
                  title={projectName}
                >
                  {projectName || '—'}
                </div>
                {projectCode && (
                  <div className="text-xs mt-0.5 font-mono" style={{ color: 'var(--text-muted)' }}>
                    {projectCode}
                  </div>
                )}
                {companyName && (
                  <div className="text-xs mt-1 truncate" style={{ color: 'var(--text-muted)' }} title={companyName}>
                    {companyName}
                  </div>
                )}
              </>
            )}
          </div>
        </div>
      </div>

      {/* Project nav */}
      <nav className="flex-1 overflow-y-auto px-3 py-3 space-y-0.5">
        {projectNavItems(projectId).map((item) => (
          <NavLink key={item.href} {...item} />
        ))}
      </nav>
    </aside>
  );
}
