'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import {
  FolderKanban, AlertCircle, FileText, DollarSign, TrendingUp, Clock,
  ArrowRight, Activity,
} from 'lucide-react';

interface DashboardStats {
  stats: {
    total_projects: number;
    active_projects: number;
    open_rfis: number;
    pending_variations: number;
    documents_this_month: number;
    payment_apps_pending: number;
  };
  recent_projects: any[];
  recent_rfis: any[];
  recent_documents: any[];
}

function StatCard({ label, value, icon: Icon, accent, href }: {
  label: string; value: number | string; icon: any; accent?: boolean; href?: string;
}) {
  const inner = (
    <div
      className="group relative rounded-2xl p-5 flex flex-col justify-between overflow-hidden transition-all hover:scale-[1.01]"
      style={{
        backgroundColor: accent ? 'rgba(185,149,102,0.08)' : 'var(--bg-surface)',
        border: `1px solid ${accent ? 'rgba(185,149,102,0.3)' : 'var(--border)'}`,
        minHeight: '110px',
      }}
    >
      {accent && (
        <div className="absolute inset-0 pointer-events-none"
             style={{ background: 'radial-gradient(ellipse at top right, rgba(185,149,102,0.12) 0%, transparent 70%)' }} />
      )}
      <div className="flex items-start justify-between mb-3">
        <p className="text-xs font-medium uppercase tracking-widest"
           style={{ color: accent ? 'rgba(185,149,102,0.8)' : 'var(--text-muted)' }}>
          {label}
        </p>
        <div className="w-8 h-8 rounded-xl flex items-center justify-center"
             style={{ backgroundColor: accent ? 'rgba(185,149,102,0.15)' : 'var(--bg-elevated)' }}>
          <Icon size={15} style={{ color: accent ? 'var(--gold)' : 'var(--text-secondary)' }} />
        </div>
      </div>
      <p className="text-3xl font-bold tracking-tight"
         style={{ color: accent ? 'var(--gold)' : 'var(--text-primary)' }}>
        {value}
      </p>
    </div>
  );
  return href ? <a href={href}>{inner}</a> : inner;
}

function StatusChip({ status }: { status: string }) {
  const map: Record<string, { bg: string; text: string }> = {
    active:    { bg: 'rgba(34,197,94,0.12)',   text: '#4ade80' },
    open:      { bg: 'rgba(234,179,8,0.12)',   text: '#facc15' },
    pending:   { bg: 'rgba(234,179,8,0.12)',   text: '#facc15' },
    draft:     { bg: 'rgba(148,163,184,0.12)', text: '#94a3b8' },
    completed: { bg: 'rgba(34,197,94,0.12)',   text: '#4ade80' },
    submitted: { bg: 'rgba(59,130,246,0.12)',  text: '#60a5fa' },
  };
  const style = map[status] || { bg: 'rgba(148,163,184,0.12)', text: '#94a3b8' };
  return (
    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize"
          style={{ backgroundColor: style.bg, color: style.text }}>
      {status.replace(/_/g, ' ')}
    </span>
  );
}

function SectionCard({ title, href, children }: { title: string; href: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl overflow-hidden flex flex-col"
         style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="px-5 py-4 flex items-center justify-between flex-shrink-0"
           style={{ borderBottom: '1px solid var(--border)' }}>
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h3>
        <a href={href}
           className="flex items-center gap-1 text-xs font-medium hover:opacity-80 transition-opacity"
           style={{ color: 'var(--gold)' }}>
          View all <ArrowRight size={11} />
        </a>
      </div>
      <div className="flex-1 divide-y" style={{ '--tw-divide-opacity': 1, borderColor: 'var(--border)' } as any}>
        {children}
      </div>
    </div>
  );
}

function getGreeting() {
  const h = new Date().getHours();
  if (h < 12) return 'morning';
  if (h < 17) return 'afternoon';
  return 'evening';
}

export default function AppDashboardPage() {
  const user = useAuthStore((s) => s.user);

  const { data, isLoading } = useQuery<DashboardStats>({
    queryKey: ['dashboard'],
    queryFn: () => api.get('/dashboard').then((r) => r.data),
  });

  if (isLoading) {
    return (
      <div className="p-6 max-w-7xl mx-auto space-y-6">
        <div className="h-8 w-64 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="h-28 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      </div>
    );
  }

  const stats = data?.stats;
  const firstName = user?.name?.split(' ')[0] ?? 'there';

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-7">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>
            Good {getGreeting()}, {firstName}
          </h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-secondary)' }}>
            Here's what needs attention across your projects today.
          </p>
        </div>
        <div
          className="flex items-center gap-2 px-3 py-1.5 rounded-full text-xs"
          style={{ backgroundColor: 'rgba(34,197,94,0.1)', color: '#4ade80', border: '1px solid rgba(34,197,94,0.2)' }}
        >
          <Activity size={11} />
          Live
        </div>
      </div>

      {/* Stats grid */}
      {stats && (
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
          <StatCard label="Total Projects"     value={stats.total_projects}       icon={FolderKanban} href="/app/projects" />
          <StatCard label="Active Projects"    value={stats.active_projects}      icon={TrendingUp}   accent href="/app/projects" />
          <StatCard label="Open RFIs"          value={stats.open_rfis}            icon={AlertCircle}  href="/app/site" />
          <StatCard label="Pending Variations" value={stats.pending_variations}   icon={DollarSign}   href="/app/commercial" />
          <StatCard label="Docs This Month"    value={stats.documents_this_month} icon={FileText}     href="/app/documents" />
          <StatCard label="Payment Apps"       value={stats.payment_apps_pending} icon={Clock}        accent href="/app/commercial" />
        </div>
      )}

      <div className="grid lg:grid-cols-2 gap-5">
        {/* Recent Projects */}
        <SectionCard title="Recent Projects" href="/app/projects">
          {!data?.recent_projects?.length ? (
            <p className="px-5 py-8 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No projects yet</p>
          ) : data.recent_projects.map((p: any) => (
            <a
              key={p.id}
              href={`/app/projects/${p.id}/overview`}
              className="flex items-center justify-between px-5 py-3 hover:bg-[var(--bg-elevated)] transition-colors"
            >
              <div className="flex items-center gap-3 min-w-0">
                <div
                  className="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"
                  style={{ backgroundColor: 'rgba(185,149,102,0.15)', color: 'var(--gold)' }}
                >
                  {p.name?.charAt(0).toUpperCase()}
                </div>
                <div className="min-w-0">
                  <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{p.name}</p>
                  <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>
                    {p.code || 'No code'} · {formatDate(p.created_at)}
                  </p>
                </div>
              </div>
              <StatusChip status={p.status} />
            </a>
          ))}
        </SectionCard>

        {/* Recent RFIs */}
        <SectionCard title="Open RFIs" href="/app/site">
          {!data?.recent_rfis?.length ? (
            <p className="px-5 py-8 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No RFIs</p>
          ) : data.recent_rfis.map((r: any) => (
            <div key={r.id} className="flex items-center justify-between px-5 py-3">
              <div className="min-w-0">
                <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                  RFI #{r.rfi_number} – {r.subject}
                </p>
                <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>
                  {r.project?.name} · {formatDate(r.date_raised)}
                </p>
              </div>
              <StatusChip status={r.status} />
            </div>
          ))}
        </SectionCard>
      </div>
    </div>
  );
}
