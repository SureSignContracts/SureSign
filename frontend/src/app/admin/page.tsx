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
    <div className="mx-auto max-w-7xl space-y-6 p-4 pb-12 sm:p-6 lg:p-8">

      {/* ── Header ─────────────────────────────────────────── */}
      <section className="ss-animate-in relative overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_60px_rgba(24,33,29,0.16)]">
        <div className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
        <div className="relative flex flex-wrap items-start justify-between gap-6 px-6 pb-7 pt-6 sm:px-8 sm:pt-8">
          <div>
            <p className="mb-4 text-xs font-semibold uppercase tracking-[0.14em] text-[#9ee5b5]">Platform control</p>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">
            {greeting()}, {firstName}
            </h1>
            <p className="mt-3 text-sm text-white/55">Operational position across the SureSign platform.</p>
          </div>
          <span className="rounded-lg border border-white/10 px-3 py-1.5 text-xs font-medium text-white/45">{APP_VERSION_LABEL}</span>
        </div>
        <div className="relative grid grid-cols-2 border-t border-white/10 lg:grid-cols-4">
        {[
          { label: 'Companies',  value: s.total_companies ?? 0, sub: 'on the platform',           icon: Building2,    href: '/admin/companies' },
          { label: 'Projects',   value: s.total_projects   ?? 0, sub: `${s.active_projects ?? 0} active`, icon: FolderKanban, href: '/admin/projects'  },
          { label: 'Users',      value: s.total_users      ?? 0, sub: 'registered accounts',      icon: Users,        href: '/admin/users'     },
          { label: 'Documents',  value: s.total_documents  ?? 0, sub: 'uploaded files',           icon: FileText,     href: '/admin/documents' },
        ].map((item, i) => (
          <Link
            key={item.label}
            href={item.href}
            className="group ss-animate-in min-h-[116px] border-r border-white/10 px-5 py-4 transition-colors duration-200 hover:bg-white/[0.055]"
            style={{ animationDelay: `${i * 70}ms` }}
          >
            <div className="flex items-center justify-between">
              <item.icon size={15} className="text-white/30" />
              <ChevronRight size={14} className="-translate-x-1 text-white/25 opacity-0 transition-all group-hover:translate-x-0 group-hover:opacity-100" />
            </div>
            <p className="mt-3 text-2xl font-semibold tabular-nums tracking-[-0.04em] text-[#9ee5b5]">
              {isLoading ? '–' : <CountUp value={item.value} delay={i * 70} />}
            </p>
            <p className="mt-1 text-xs font-medium text-white/70">{item.label}</p>
            <p className="mt-0.5 text-[11px] text-white/35">{item.sub}</p>
          </Link>
        ))}
        </div>
      </section>

      {/* ── Quick Actions ──────────────────────────────────── */}
      <nav className="ss-animate-in flex flex-wrap items-center gap-2 rounded-2xl bg-[var(--bg-surface)] p-2 shadow-[0_10px_30px_rgba(24,33,29,0.06)]" style={{ animationDelay: '280ms' }} aria-label="Quick actions">
        <span className="px-3 text-[10px] font-semibold uppercase tracking-[0.14em]" style={{ color: 'var(--text-muted)' }}>Shortcuts</span>
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
              className="group ss-animate-in flex items-center gap-2 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200 hover:-translate-y-px hover:bg-[var(--bg-elevated)] hover:text-[var(--gold)]"
              style={{ color: 'var(--text-secondary)', animationDelay: `${320 + i * 50}ms` }}
            >
              <item.icon size={14} className="opacity-55 transition-transform duration-200 group-hover:-translate-y-px" />
              <span>{item.label}</span>
            </Link>
          ))}
      </nav>

      {/* ── Recent Companies + Projects ────────────────────── */}
      <section className="grid gap-5 lg:grid-cols-[0.9fr_1.1fr]">

        {/* Companies */}
        <div className="ss-animate-in rounded-2xl bg-[var(--bg-surface)] p-5 shadow-[0_14px_38px_rgba(24,33,29,0.07)]" style={{ animationDelay: '600ms' }}>
          <div className="flex items-start justify-between pb-4">
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-[0.14em]" style={{ color: 'var(--text-muted)' }}>Directory</p>
              <h3 className="mt-1 text-lg font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>Recent companies</h3>
            </div>
            <Link href="/admin/companies" className="flex items-center gap-1 text-xs hover:opacity-75 transition-opacity" style={{ color: 'var(--gold)' }}>
              View all <ArrowRight size={11} />
            </Link>
          </div>
          <div className="space-y-1">
            {isLoading
              ? [...Array(5)].map((_, i) => <div key={i} className="h-14 animate-pulse mx-5 my-2 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }} />)
              : (data?.recent_companies ?? []).length === 0
                ? <p className="px-5 py-10 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No companies yet.</p>
                : (data.recent_companies as any[]).slice(0, 5).map((c: any) => (
                  <Link
                    key={c.id}
                    href={`/admin/companies/${c.id}`}
                    className="group flex items-center justify-between rounded-xl px-3 py-3 transition-all hover:bg-[var(--bg-elevated)]"
                  >
                    <div className="flex items-center gap-3">
                      <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-[#18211d] text-xs font-semibold text-[#9ee5b5]">{c.name?.slice(0, 1).toUpperCase()}</span>
                      <div>
                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{c.name}</p>
                      <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                        {c.projects_count} {c.projects_count === 1 ? 'project' : 'projects'} · {c.users_count} {c.users_count === 1 ? 'user' : 'users'}
                      </p>
                      </div>
                    </div>
                    <ChevronRight size={14} className="transition-transform group-hover:translate-x-0.5" style={{ color: 'var(--text-muted)' }} />
                  </Link>
                ))
            }
          </div>
        </div>

        {/* Projects */}
        <div className="ss-animate-in rounded-2xl bg-[var(--bg-surface)] p-5 shadow-[0_14px_38px_rgba(24,33,29,0.07)]" style={{ animationDelay: '680ms' }}>
          <div className="flex items-start justify-between pb-4">
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-[0.14em]" style={{ color: 'var(--text-muted)' }}>Portfolio</p>
              <h3 className="mt-1 text-lg font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>Recent projects</h3>
            </div>
            <Link href="/admin/projects" className="flex items-center gap-1 text-xs hover:opacity-75 transition-opacity" style={{ color: 'var(--gold)' }}>
              View all <ArrowRight size={11} />
            </Link>
          </div>
          <div className="space-y-1">
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
                      className="group flex items-center justify-between rounded-xl px-3 py-3 transition-all hover:bg-[var(--bg-elevated)]"
                    >
                      <div className="flex items-center gap-3">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-[#eaf7ee] text-[#247044]"><FolderKanban size={15} /></span>
                        <div>
                        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{p.name}</p>
                        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                          {p.organization?.name ?? '—'}
                          {p.code ? ` · ${p.code}` : ''}
                        </p>
                        </div>
                      </div>
                      <span className="ml-3 inline-flex flex-shrink-0 items-center gap-1.5 text-xs font-medium capitalize" style={{ color: st.fg }}>
                        <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: st.fg }} />
                        {p.status?.replace(/_/g, ' ') ?? '—'}
                      </span>
                    </Link>
                  );
                })
            }
          </div>
        </div>
      </section>

      {/* ── Recent Activity ────────────────────────────────── */}
      <section className="ss-animate-in overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_20px_50px_rgba(24,33,29,0.14)]" style={{ animationDelay: '760ms' }}>
        <div className="flex items-end justify-between border-b border-white/10 px-6 py-5">
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-[0.14em] text-[#9ee5b5]">Change ledger</p>
            <h3 className="mt-1 text-lg font-semibold tracking-[-0.02em]">Recent activity</h3>
          </div>
          <p className="text-[11px] text-white/35">Latest platform changes</p>
        </div>
        {isLoading ? (
          <div className="space-y-3 p-5">
            {[...Array(4)].map((_, i) => <div key={i} className="h-10 animate-pulse rounded-lg bg-white/5" />)}
          </div>
        ) : (data?.recent_activity ?? []).length === 0 ? (
          <p className="px-5 py-12 text-center text-sm text-white/45">
            No activity yet. Actions across the platform will appear here.
          </p>
        ) : (
          <div className="grid md:grid-cols-2">
            {(data.recent_activity as any[]).slice(0, 8).map((item: any, i: number) => (
              <div key={item.id} className="grid grid-cols-[8px_minmax(0,1fr)] gap-4 border-b border-white/10 px-6 py-4 md:border-r">
                <span className="mt-1.5 h-2 w-2 rounded-full" style={{ backgroundColor: i === 0 ? '#9ee5b5' : 'rgba(255,255,255,0.18)' }} />
                <div className="min-w-0">
                  <p className="text-[10px] font-medium uppercase tracking-[0.12em] text-white/30">{timeAgo(item.created_at)}</p>
                  <p className="mt-1 text-sm font-medium text-white/85">{item.title}</p>
                  {item.description && (
                    <p className="mt-0.5 truncate text-xs text-white/40">{item.description}</p>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

    </div>
  );
}
