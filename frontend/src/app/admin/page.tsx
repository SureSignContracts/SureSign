'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import { APP_VERSION_LABEL } from '@/config/app-version';
import CountUp from '@/components/ui/CountUp';
import {
  Building2, Users, FolderKanban, FileText,
  Plus, LayoutTemplate, HardDrive, Cpu,
  ArrowRight, ChevronRight,
} from 'lucide-react';


function greeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

function timeAgo(dateStr: string) {
  const diff = Date.now() - new Date(dateStr).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1)  return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  return `${Math.floor(h / 24)}d ago`;
}

function statusColor(status: string) {
  if (status === 'active')    return { bg: 'rgba(34,197,94,0.1)',  fg: '#22c55e' };
  if (status === 'tender')    return { bg: 'rgba(59,130,246,0.1)', fg: '#3b82f6' };
  if (status === 'completed') return { bg: 'rgba(100,116,139,0.1)',fg: '#64748b' };
  return { bg: 'rgba(148,163,184,0.1)', fg: '#94a3b8' };
}

export default function AdminDashboardPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['admin-dashboard'],
    queryFn: () => api.get('/admin/dashboard').then(r => r.data),
  });

  const user      = useAuthStore(s => s.user);
  const firstName = user?.first_name || user?.name?.split(' ')[0] || 'Admin';
  const s         = data?.stats ?? {};

  return (
    <div className="p-8 max-w-6xl mx-auto space-y-10">

      {/* ── Header ─────────────────────────────────────────── */}
      <div className="flex items-end justify-between">
        <div>
          <h1 className="text-3xl font-bold tracking-tight" style={{ color: 'var(--text-primary)' }}>
            {greeting()}, {firstName}
          </h1>
          <p className="mt-2 text-sm" style={{ color: 'var(--text-muted)' }}>
            Platform overview for SureSign Contracts.
          </p>
        </div>
        <span
          className="px-3 py-1.5 rounded-full text-xs font-medium"
          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
        >
          {APP_VERSION_LABEL}
        </span>
      </div>

      {/* ── Stats ──────────────────────────────────────────── */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: 'Companies',  value: s.total_companies ?? 0, sub: 'on the platform',           icon: Building2,    href: '/admin/companies' },
          { label: 'Projects',   value: s.total_projects   ?? 0, sub: `${s.active_projects ?? 0} active`, icon: FolderKanban, href: '/admin/projects'  },
          { label: 'Users',      value: s.total_users      ?? 0, sub: 'registered accounts',      icon: Users,        href: '/admin/users'     },
          { label: 'Documents',  value: s.total_documents  ?? 0, sub: 'uploaded files',           icon: FileText,     href: '/admin/documents' },
        ].map((item, i) => (
          <Link
            key={item.label}
            href={item.href}
            className="group ss-animate-in rounded-2xl p-5 transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] hover:-translate-y-0.5"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${i * 70}ms` }}
          >
            <div className="flex items-center justify-between mb-4">
              <div className="w-9 h-9 rounded-xl flex items-center justify-center transition-transform duration-200 group-hover:scale-110" style={{ backgroundColor: 'var(--gold-15)' }}>
                <item.icon size={16} style={{ color: 'var(--gold)' }} />
              </div>
              <ChevronRight size={14} className="opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all" style={{ color: 'var(--text-muted)' }} />
            </div>
            <p className="text-2xl font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>
              {isLoading ? '–' : <CountUp value={item.value} delay={i * 70} />}
            </p>
            <p className="text-sm mt-1 font-medium" style={{ color: 'var(--text-secondary)' }}>{item.label}</p>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{item.sub}</p>
          </Link>
        ))}
      </div>

      {/* ── Quick Actions ──────────────────────────────────── */}
      <div>
        <h2 className="text-xs font-semibold uppercase tracking-widest mb-4" style={{ color: 'var(--text-muted)' }}>
          Quick actions
        </h2>
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
          {[
            { label: 'New company',  icon: Plus,           href: '/admin/companies' },
            { label: 'New project',  icon: FolderKanban,   href: '/admin/projects'  },
            { label: 'Templates',    icon: LayoutTemplate, href: '/admin/templates' },
            { label: 'Users',        icon: Users,          href: '/admin/users'     },
            { label: 'Storage',      icon: HardDrive,      href: '/admin/storage'   },
            { label: 'AI / Prompts', icon: Cpu,            href: '/admin/prompts'   },
          ].map((item, i) => (
            <Link
              key={item.label}
              href={item.href}
              className="group ss-animate-in flex flex-col items-center gap-2 p-4 rounded-2xl text-center transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] hover:-translate-y-0.5"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${280 + i * 50}ms` }}
            >
              <div className="w-9 h-9 rounded-xl flex items-center justify-center transition-transform duration-200 group-hover:scale-110" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <item.icon size={16} style={{ color: 'var(--text-secondary)' }} />
              </div>
              <span className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>{item.label}</span>
            </Link>
          ))}
        </div>
      </div>

      {/* ── Recent Companies + Projects ────────────────────── */}
      <div className="grid lg:grid-cols-2 gap-5">

        {/* Companies */}
        <div className="ss-animate-in rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '600ms' }}>
          <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent companies</h3>
            <Link href="/admin/companies" className="flex items-center gap-1 text-xs hover:opacity-75 transition-opacity" style={{ color: 'var(--gold)' }}>
              View all <ArrowRight size={11} />
            </Link>
          </div>
          <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {isLoading
              ? [...Array(5)].map((_, i) => <div key={i} className="h-14 animate-pulse mx-5 my-2 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }} />)
              : (data?.recent_companies ?? []).length === 0
                ? <p className="px-5 py-10 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No companies yet.</p>
                : (data.recent_companies as any[]).slice(0, 5).map((c: any) => (
                  <Link
                    key={c.id}
                    href={`/admin/companies/${c.id}`}
                    className="flex items-center justify-between px-5 py-3.5 hover:bg-[var(--bg-hover)] transition-colors"
                  >
                    <div>
                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{c.name}</p>
                      <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                        {c.projects_count} {c.projects_count === 1 ? 'project' : 'projects'} · {c.users_count} {c.users_count === 1 ? 'user' : 'users'}
                      </p>
                    </div>
                    <ChevronRight size={14} style={{ color: 'var(--text-muted)' }} />
                  </Link>
                ))
            }
          </div>
        </div>

        {/* Projects */}
        <div className="ss-animate-in rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '680ms' }}>
          <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent projects</h3>
            <Link href="/admin/projects" className="flex items-center gap-1 text-xs hover:opacity-75 transition-opacity" style={{ color: 'var(--gold)' }}>
              View all <ArrowRight size={11} />
            </Link>
          </div>
          <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {isLoading
              ? [...Array(5)].map((_, i) => <div key={i} className="h-14 animate-pulse mx-5 my-2 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }} />)
              : (data?.recent_projects ?? []).length === 0
                ? <p className="px-5 py-10 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No projects yet.</p>
                : (data.recent_projects as any[]).slice(0, 5).map((p: any) => {
                  const st = statusColor(p.status);
                  return (
                    <Link
                      key={p.id}
                      href={`/admin/companies/${p.organization_id}`}
                      className="flex items-center justify-between px-5 py-3.5 hover:bg-[var(--bg-hover)] transition-colors"
                    >
                      <div>
                        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{p.name}</p>
                        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                          {p.organization?.name ?? '—'}
                          {p.code ? ` · ${p.code}` : ''}
                        </p>
                      </div>
                      <span className="text-xs px-2 py-0.5 rounded-full capitalize ml-3 flex-shrink-0" style={{ backgroundColor: st.bg, color: st.fg }}>
                        {p.status?.replace(/_/g, ' ') ?? '—'}
                      </span>
                    </Link>
                  );
                })
            }
          </div>
        </div>
      </div>

      {/* ── Recent Activity ────────────────────────────────── */}
      <div className="ss-animate-in rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '760ms' }}>
        <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent activity</h3>
        </div>
        {isLoading ? (
          <div className="p-5 space-y-3">
            {[...Array(4)].map((_, i) => <div key={i} className="h-10 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
          </div>
        ) : (data?.recent_activity ?? []).length === 0 ? (
          <p className="px-5 py-12 text-sm text-center" style={{ color: 'var(--text-muted)' }}>
            No activity yet. Actions across the platform will appear here.
          </p>
        ) : (
          <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {(data.recent_activity as any[]).slice(0, 8).map((item: any) => (
              <div key={item.id} className="flex items-center justify-between px-5 py-3.5">
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{item.title}</p>
                  {item.description && (
                    <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{item.description}</p>
                  )}
                </div>
                <p className="text-xs ml-4 flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
                  {timeAgo(item.created_at)}
                </p>
              </div>
            ))}
          </div>
        )}
      </div>

    </div>
  );
}
