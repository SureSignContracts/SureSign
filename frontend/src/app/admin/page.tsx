'use client';

import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import { Building2, Users, FolderKanban, HardDrive, Cpu, Activity, TrendingUp, AlertCircle, FileText } from 'lucide-react';

function MetricCard({ label, value, sub, icon: Icon, color = 'var(--gold)', href }: {
  label: string; value: string | number; sub?: string; icon: any; color?: string; href?: string;
}) {
  const inner = (
    <div
      className="rounded-2xl p-5 flex flex-col gap-3 transition-all hover:scale-[1.01]"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
    >
      <div className="flex items-center justify-between">
        <p className="text-xs font-medium uppercase tracking-widest" style={{ color: 'var(--text-secondary)' }}>
          {label}
        </p>
        <div className="w-8 h-8 rounded-xl flex items-center justify-center" style={{ backgroundColor: color + '18' }}>
          <Icon size={15} style={{ color }} />
        </div>
      </div>
      <p className="text-3xl font-bold" style={{ color: 'var(--text-primary)' }}>{value}</p>
      {sub && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </div>
  );
  return href ? <Link href={href}>{inner}</Link> : inner;
}

export default function AdminDashboardPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['admin-dashboard'],
    queryFn: () => api.get('/admin/dashboard').then(r => r.data),
  });
  const user = useAuthStore(s => s.user);
  const firstName = user?.first_name || user?.name?.split(' ')[0] || 'Admin';

  const s = data?.stats;

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-7">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>
            {firstName}'s Admin Dashboard
          </h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Platform-wide overview — all tenants, usage and activity
          </p>
        </div>
        <div
          className="flex items-center gap-2 px-3 py-1.5 rounded-full text-xs"
          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
        >
          <Activity size={11} />
          Admin Mode
        </div>
      </div>

      {/* Metrics row 1 */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <MetricCard
          label="Total Companies"
          value={isLoading ? '–' : (s?.total_companies ?? 0)}
          icon={Building2}
          color="#3b82f6"
          href="/admin/companies"
        />
        <MetricCard
          label="Total Projects"
          value={isLoading ? '–' : (s?.total_projects ?? 0)}
          sub={`${s?.active_projects ?? 0} active`}
          icon={FolderKanban}
          color="#10b981"
          href="/admin/projects"
        />
        <MetricCard
          label="Total Users"
          value={isLoading ? '–' : (s?.total_users ?? 0)}
          icon={Users}
          color="#8b5cf6"
          href="/admin/users"
        />
        <MetricCard
          label="Storage Used"
          value={isLoading ? '–' : (s?.storage_used ?? '0 GB')}
          icon={HardDrive}
          color="#f59e0b"
          href="/admin/storage"
        />
      </div>

      {/* Metrics row 2 */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <MetricCard
          label="Total Documents"
          value={isLoading ? '–' : (s?.total_documents ?? 0)}
          icon={FileText}
          color="#ec4899"
          href="/admin/documents"
        />
        <MetricCard
          label="Monthly AI Usage"
          value={isLoading ? '–' : (s?.monthly_ai_usage ?? 0)}
          sub="conversations this month"
          icon={Cpu}
          color="#a855f7"
        />
        <MetricCard
          label="Active Projects"
          value={isLoading ? '–' : (s?.active_projects ?? 0)}
          icon={TrendingUp}
          color="#22c55e"
          href="/admin/projects"
        />
        <MetricCard
          label="Docs Uploaded Today"
          value={isLoading ? '–' : (data?.activity?.docs_today ?? 0)}
          icon={AlertCircle}
          color="#ef4444"
        />
      </div>

      <div className="grid lg:grid-cols-2 gap-5">
        {/* Recent Companies */}
        <div
          className="rounded-2xl overflow-hidden"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
        >
          <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent Companies</h3>
            <Link href="/admin/companies" className="text-xs hover:opacity-80" style={{ color: 'var(--gold)' }}>
              View all
            </Link>
          </div>
          <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <div key={i} className="px-5 py-3 h-14 animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
              ))
            ) : (data?.recent_companies ?? []).length === 0 ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No companies yet</p>
            ) : (data?.recent_companies ?? []).map((c: any) => (
              <Link
                key={c.id}
                href={`/admin/companies/${c.id}`}
                className="flex items-center justify-between px-5 py-3 hover:bg-[var(--bg-elevated)] transition-colors"
              >
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{c.name}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    {c.users_count ?? 0} users · {c.projects_count ?? 0} projects
                  </p>
                </div>
                <span
                  className="text-xs px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: 'rgba(34,197,94,0.1)', color: '#4ade80' }}
                >
                  Active
                </span>
              </Link>
            ))}
          </div>
        </div>

        {/* Recent Projects */}
        <div
          className="rounded-2xl overflow-hidden"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
        >
          <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent Projects</h3>
            <Link href="/admin/projects" className="text-xs hover:opacity-80" style={{ color: 'var(--gold)' }}>
              View all
            </Link>
          </div>
          <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <div key={i} className="px-5 py-3 h-14 animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
              ))
            ) : (data?.recent_projects ?? []).length === 0 ? (
              <p className="px-5 py-8 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No projects yet</p>
            ) : (data?.recent_projects ?? []).map((p: any) => (
              <Link
                key={p.id}
                href={`/admin/companies/${p.organization_id}`}
                className="flex items-center justify-between px-5 py-3 hover:bg-[var(--bg-elevated)] transition-colors"
              >
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{p.name}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    {p.code ? `${p.code} · ` : ''}{p.organization?.name ?? ''}
                  </p>
                </div>
                <span
                  className="text-xs px-2 py-0.5 rounded-full capitalize"
                  style={{
                    backgroundColor: p.status === 'active' ? 'rgba(34,197,94,0.1)' : 'rgba(148,163,184,0.1)',
                    color: p.status === 'active' ? '#4ade80' : '#94a3b8',
                  }}
                >
                  {p.status?.replace(/_/g, ' ') ?? '—'}
                </span>
              </Link>
            ))}
          </div>
        </div>
      </div>

      {/* Platform Activity */}
      <div
        className="rounded-2xl overflow-hidden"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
      >
        <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Platform Activity</h3>
        </div>
        <div className="p-5 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {[
            { label: 'Documents uploaded today', value: data?.activity?.docs_today ?? 0,      icon: FileText,    color: '#10b981' },
            { label: 'AI conversations (month)', value: s?.monthly_ai_usage ?? 0,             icon: Cpu,         color: '#8b5cf6' },
            { label: 'Active sessions',          value: data?.activity?.active_sessions ?? 0, icon: Activity,    color: '#3b82f6' },
            { label: 'Support tickets',          value: data?.activity?.support_tickets ?? 0, icon: AlertCircle, color: '#ef4444' },
          ].map((item) => (
            <div key={item.label} className="flex items-center gap-3 p-3 rounded-xl" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <div className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: item.color + '18' }}>
                <item.icon size={15} style={{ color: item.color }} />
              </div>
              <div>
                <p className="text-sm font-bold" style={{ color: 'var(--text-primary)' }}>{item.value}</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{item.label}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
