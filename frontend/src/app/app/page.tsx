'use client';

import { useQuery } from '@tanstack/react-query';
import { useAuthStore } from '@/store/authStore';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import CountUp from '@/components/ui/CountUp';
import { Card, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PageTourButton from '@/components/tours/PageTourButton';
import {
  FolderKanban, AlertCircle, FileText, DollarSign, TrendingUp, Clock,
  ArrowRight, Activity, Inbox,
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

function StatCard({ label, value, icon: Icon, accent, href, index = 0 }: {
  label: string; value: number | string; icon: any; accent?: boolean; href?: string; index?: number;
}) {
  const delay = index * 70;
  const inner = (
    <div
      className="group relative ss-animate-in rounded-2xl p-5 flex flex-col justify-between overflow-hidden transition-all hover:scale-[1.01]"
      style={{
        backgroundColor: accent ? 'var(--gold-8)' : 'var(--bg-surface)',
        border: `1px solid ${accent ? 'var(--gold-30)' : 'var(--border)'}`,
        boxShadow: 'var(--shadow-card)',
        minHeight: '110px',
        animationDelay: `${delay}ms`,
      }}
    >
      {accent && (
        <div className="absolute inset-0 pointer-events-none"
             style={{ background: 'radial-gradient(ellipse at top right, var(--gold-15) 0%, transparent 70%)' }} />
      )}
      <div className="flex items-start justify-between mb-3">
        <p className="text-xs font-medium uppercase tracking-widest"
           style={{ color: accent ? 'color-mix(in srgb, var(--gold) 80%, transparent)' : 'var(--text-muted)' }}>
          {label}
        </p>
        <div className="w-8 h-8 rounded-xl flex items-center justify-center"
             style={{ backgroundColor: accent ? 'var(--gold-15)' : 'var(--bg-elevated)' }}>
          <Icon size={15} style={{ color: accent ? 'var(--gold)' : 'var(--text-secondary)' }} />
        </div>
      </div>
      <p className="text-3xl font-bold tracking-tight"
         style={{ color: accent ? 'var(--gold)' : 'var(--text-primary)' }}>
        {typeof value === 'number' ? <CountUp value={value} delay={delay} /> : value}
      </p>
    </div>
  );
  return href ? <a href={href}>{inner}</a> : inner;
}

function FeaturedStat({ label, value, sublabel, icon: Icon, href }: {
  label: string; value: number; sublabel: string; icon: any; href: string;
}) {
  return (
    <a
      href={href}
      className="group relative ss-animate-in rounded-2xl p-6 flex flex-col justify-between overflow-hidden transition-all hover:scale-[1.005] sm:col-span-2 sm:row-span-2"
      style={{
        backgroundColor: 'var(--gold-8)',
        border: '1px solid var(--gold-30)',
        boxShadow: 'var(--shadow-card)',
        minHeight: '220px',
      }}
    >
      <div className="absolute inset-0 pointer-events-none"
           style={{ background: 'radial-gradient(ellipse at top right, var(--gold-15) 0%, transparent 70%)' }} />
      <div className="flex items-start justify-between">
        <p className="text-xs font-medium uppercase tracking-widest" style={{ color: 'color-mix(in srgb, var(--gold) 80%, transparent)' }}>
          {label}
        </p>
        <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'var(--gold-15)' }}>
          <Icon size={18} style={{ color: 'var(--gold)' }} />
        </div>
      </div>
      <div>
        <p className="text-6xl font-bold tracking-tight tabular-nums" style={{ color: 'var(--gold)' }}>
          <CountUp value={value} />
        </p>
        <p className="mt-2 text-sm" style={{ color: 'var(--text-secondary)' }}>{sublabel}</p>
      </div>
    </a>
  );
}

function SectionCard({ title, href, children }: { title: string; href: string; children: React.ReactNode }) {
  return (
    <Card className="overflow-hidden flex flex-col">
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <a href={href}
           className="flex items-center gap-1 text-xs font-medium hover:opacity-80 transition-opacity"
           style={{ color: 'var(--gold)' }}>
          View all <ArrowRight size={11} />
        </a>
      </CardHeader>
      <div className="flex-1 divide-y" style={{ '--tw-divide-opacity': 1, borderColor: 'var(--border)' } as any}>
        {children}
      </div>
    </Card>
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
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div className="h-[220px] rounded-2xl animate-pulse sm:col-span-2 sm:row-span-2" style={{ backgroundColor: 'var(--bg-surface)' }} />
          {[...Array(4)].map((_, i) => (
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
      <div className="flex items-start justify-between" data-tour="dashboard-header">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[2rem] font-bold tracking-tight" style={{ color: 'var(--text-primary)' }}>
              Good {getGreeting()}, {firstName}
            </h1>
            <PageTourButton tourKey="page-dashboard" label="Take a tour of this page" />
          </div>
          <p className="mt-1.5 text-sm" style={{ color: 'var(--text-secondary)' }}>
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

      {/* Stats grid — one featured tile carries the visual weight, the rest support it */}
      {stats && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4" data-tour="dashboard-stats">
          <FeaturedStat
            label="Active Projects"
            value={stats.active_projects}
            sublabel={`of ${stats.total_projects} total projects`}
            icon={TrendingUp}
            href="/app/projects"
          />
          <StatCard label="Open RFIs"          value={stats.open_rfis}            icon={AlertCircle}  href="/app/site"       index={0} />
          <StatCard label="Pending Variations" value={stats.pending_variations}   icon={DollarSign}   href="/app/commercial" index={1} />
          <StatCard label="Docs This Month"    value={stats.documents_this_month} icon={FileText}     href="/app/documents"  index={2} />
          <StatCard label="Payment Apps"       value={stats.payment_apps_pending} icon={Clock}        accent href="/app/commercial" index={3} />
        </div>
      )}

      <div className="grid lg:grid-cols-2 gap-5" data-tour="dashboard-recent">
        {/* Recent Projects */}
        <SectionCard title="Recent Projects" href="/app/projects">
          {!data?.recent_projects?.length ? (
            <EmptyState icon={FolderKanban} title="No projects yet" description="Projects you create will show up here." />
          ) : data.recent_projects.map((p: any) => (
            <a
              key={p.id}
              href={`/app/projects/${p.id}/overview`}
              className="flex items-center justify-between px-5 py-3 hover:bg-[var(--bg-hover)] transition-colors"
            >
              <div className="flex items-center gap-3 min-w-0">
                <div
                  className="w-7 h-7 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"
                  style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
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
              <Badge status={p.status} />
            </a>
          ))}
        </SectionCard>

        {/* Recent RFIs */}
        <SectionCard title="Open RFIs" href="/app/site">
          {!data?.recent_rfis?.length ? (
            <EmptyState icon={Inbox} title="No open RFIs" description="Requests for information will show up here once raised." />
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
              <Badge status={r.status} />
            </div>
          ))}
        </SectionCard>
      </div>
    </div>
  );
}
