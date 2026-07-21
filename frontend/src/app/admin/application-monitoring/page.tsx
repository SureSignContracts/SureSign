'use client';

import { useState } from 'react';
import {
  Activity, Users, Building2, Zap, ListChecks, Brain,
  RefreshCw, AlertTriangle, FileText, Bell, Radio, ServerCrash, Info,
} from 'lucide-react';
import { Card, CardHeader, CardTitle, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import CountUp from '@/components/ui/CountUp';
import { useAuthStore } from '@/store/authStore';
import {
  useApplicationMonitoring,
  type ModuleUsageRow,
} from '@/hooks/useApplicationMonitoring';

function formatTime(iso: string | null) {
  if (!iso) return '—';
  const timeZone = useAuthStore.getState().user?.effective_timezone;
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone });
}

function timeAgo(iso: string | null) {
  if (!iso) return '—';
  const diff = Date.now() - new Date(iso).getTime();
  const m = Math.floor(diff / 60000);
  if (m < 1) return 'just now';
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  return `${h}h ago`;
}

function StatCard({
  icon: Icon, label, value, sub, tone, loading,
}: {
  icon: React.ElementType;
  label: string;
  value: number | string | null;
  sub?: string;
  tone?: 'success' | 'warning' | 'danger' | 'neutral';
  loading?: boolean;
}) {
  const toneColor = tone === 'success' ? '#4ade80' : tone === 'warning' ? '#facc15' : tone === 'danger' ? '#f87171' : 'var(--gold)';

  return (
    <Card className="p-5">
      <div className="flex items-center justify-between mb-4">
        <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <Icon size={16} style={{ color: toneColor }} />
        </div>
      </div>
      <p className="text-2xl font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>
        {loading ? '–' : value === null ? '—' : typeof value === 'number' ? <CountUp value={value} /> : value}
      </p>
      <p className="text-sm mt-1 font-medium" style={{ color: 'var(--text-secondary)' }}>{label}</p>
      {sub && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </Card>
  );
}

function ModuleUsageList({ rows }: { rows: ModuleUsageRow[] }) {
  if (rows.length === 0) {
    return <EmptyState icon={ListChecks} title="No module usage recorded for this period yet." />;
  }

  const max = Math.max(...rows.map(r => r.total_visits), 1);

  return (
    <div className="space-y-3">
      {rows.slice(0, 10).map(row => (
        <div key={row.module_key}>
          <div className="flex items-center justify-between text-xs mb-1">
            <span className="font-medium" style={{ color: 'var(--text-primary)' }}>{row.label}</span>
            <span style={{ color: 'var(--text-muted)' }}>
              {row.total_visits} visits · {row.active_user_days} active user-days
            </span>
          </div>
          <div className="h-1.5 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <div
              className="h-full rounded-full"
              style={{ width: `${Math.max(4, (row.total_visits / max) * 100)}%`, backgroundColor: 'var(--gold)' }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

export default function ApplicationMonitoringPage() {
  const [modulePeriod, setModulePeriod] = useState<'today' | 'last_7_days' | 'last_30_days'>('today');
  const { data, isLoading, isError, isFetching, dataUpdatedAt, refetch } = useApplicationMonitoring();

  const warnings = data?.warnings ?? [];
  const queueStatus = data?.queue.status ?? 'unknown';
  const queueTone = queueStatus === 'healthy' ? 'success' : queueStatus === 'attention' ? 'warning' : 'neutral';

  // A hard failure of the endpoint itself (network error, unexpected 4xx/5xx)
  // is distinct from the partial-failure `warnings` a successful 200 response
  // can carry — this is "we have no data at all," not "some sections are
  // unavailable." Only show it when there's nothing already on screen; a
  // background refetch failure with existing data falls through to the
  // stale-but-visible view below instead of blanking the page.
  if (isError && !data) {
    return (
      <div className="p-6 max-w-7xl mx-auto">
        <EmptyState
          icon={ServerCrash}
          title="Couldn't load Application Monitoring"
          description="The monitoring endpoint didn't respond. This does not affect the rest of SureSign — try refreshing."
          surface
          action={
            <button
              onClick={() => refetch()}
              className="flex items-center gap-1.5 text-xs px-3 py-2 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
              style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              <RefreshCw size={12} />
              Try again
            </button>
          }
        />
      </div>
    );
  }

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6 pb-10">

      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <Activity size={22} style={{ color: 'var(--gold)' }} />
            Application Monitoring
          </h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Live platform usage and operational health, refreshed automatically every minute.
          </p>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {dataUpdatedAt ? `Updated ${timeAgo(new Date(dataUpdatedAt).toISOString())}` : 'Not yet loaded'}
          </span>
          <button
            onClick={() => refetch()}
            disabled={isFetching}
            className="flex items-center gap-1.5 text-xs px-3 py-2 rounded-lg hover:bg-[var(--bg-hover)] transition-colors disabled:opacity-50"
            style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
          >
            <RefreshCw size={12} className={isFetching ? 'animate-spin' : ''} />
            Refresh
          </button>
        </div>
      </div>

      {/* Warnings */}
      {warnings.length > 0 && (
        <div className="rounded-xl px-4 py-3 flex items-start gap-2.5" style={{ backgroundColor: 'rgba(234,179,8,0.08)', border: '1px solid rgba(234,179,8,0.25)' }}>
          <AlertTriangle size={15} className="mt-0.5 flex-shrink-0" style={{ color: '#facc15' }} />
          <div className="space-y-1">
            {warnings.map((w, i) => (
              <p key={i} className="text-xs" style={{ color: 'var(--text-secondary)' }}>{w}</p>
            ))}
          </div>
        </div>
      )}

      {/* Live overview cards */}
      <div className="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        <StatCard
          icon={Radio}
          label="Online Users"
          value={data?.presence.available ? data.presence.online_count : '—'}
          sub={data?.presence.available ? 'active in the last 5 min' : 'presence unavailable'}
          loading={isLoading}
        />
        <StatCard
          icon={Building2}
          label="Active Organizations"
          value={data?.presence.available ? data.presence.active_organizations_count : '—'}
          sub="online right now"
          loading={isLoading}
        />
        <StatCard
          icon={Users}
          label="Active Users Today"
          value={data?.active_users.dau ?? null}
          sub={`WAU ${data?.active_users.wau ?? '—'} · MAU ${data?.active_users.mau ?? '—'}`}
          loading={isLoading}
        />
        <StatCard
          icon={Zap}
          label="Application Actions Today"
          value={data?.application_actions.today ?? null}
          sub={`${data?.application_actions.last_15_minutes ?? '—'} in last 15 min`}
          loading={isLoading}
        />
        <StatCard
          icon={ListChecks}
          label="Queue Status"
          value={queueStatus === 'healthy' ? 'Healthy' : queueStatus === 'attention' ? 'Attention' : 'Unknown'}
          sub={`${data?.queue.pending_jobs ?? '—'} pending · ${data?.queue.failed_jobs_24h ?? '—'} failed (24h)`}
          tone={queueTone}
          loading={isLoading}
        />
        <StatCard
          icon={Brain}
          label="AI Operations"
          value={data?.ai.processing ?? null}
          sub={`${data?.ai.pending ?? '—'} pending · ${data?.ai.failed_today ?? '—'} failed today`}
          loading={isLoading}
        />
      </div>

      <div className="grid lg:grid-cols-2 gap-5">

        {/* Active user trend */}
        <Card>
          <CardHeader><CardTitle>Active User Trend</CardTitle></CardHeader>
          <CardBody className="space-y-4">
            <div className="grid grid-cols-3 gap-3 text-center">
              {[
                { label: 'DAU', value: data?.active_users.dau },
                { label: 'WAU', value: data?.active_users.wau },
                { label: 'MAU', value: data?.active_users.mau },
              ].map(item => (
                <div key={item.label} className="rounded-xl py-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                  <p className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>{item.value ?? '—'}</p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{item.label}</p>
                </div>
              ))}
            </div>
            {(data?.active_users.daily_trend?.length ?? 0) === 0 ? (
              <p className="text-xs text-center py-4" style={{ color: 'var(--text-muted)' }}>No trend data for the last 7 days yet.</p>
            ) : (
              <div className="flex items-end gap-1.5 h-20">
                {data!.active_users.daily_trend.map(point => {
                  const max = Math.max(...data!.active_users.daily_trend.map(p => p.active_users), 1);
                  return (
                    <div key={point.date} className="flex-1 flex flex-col items-center gap-1">
                      <div
                        className="w-full rounded-t"
                        style={{ height: `${Math.max(4, (point.active_users / max) * 100)}%`, backgroundColor: 'var(--gold)', minHeight: '4px' }}
                        title={`${point.date}: ${point.active_users} active users`}
                      />
                      <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                        {new Date(point.date).toLocaleDateString('en-GB', { day: '2-digit' })}
                      </span>
                    </div>
                  );
                })}
              </div>
            )}
          </CardBody>
        </Card>

        {/* Module usage */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-1.5">
              <CardTitle>Module Usage</CardTitle>
              <span
                tabIndex={0}
                title={data?.module_usage.active_user_days_definition
                  ?? 'This metric represents the sum of daily distinct users during the selected period. A user active on multiple days contributes once per day.'}
                aria-label="What is an active user-day?"
                className="cursor-help"
              >
                <Info size={13} style={{ color: 'var(--text-muted)' }} />
              </span>
            </div>
            <div className="flex gap-1">
              {([
                { key: 'today', label: 'Today' },
                { key: 'last_7_days', label: '7 Days' },
                { key: 'last_30_days', label: '30 Days' },
              ] as const).map(opt => (
                <button
                  key={opt.key}
                  onClick={() => setModulePeriod(opt.key)}
                  className="text-xs px-2.5 py-1 rounded-lg transition-colors"
                  style={{
                    backgroundColor: modulePeriod === opt.key ? 'var(--gold-15)' : 'transparent',
                    color: modulePeriod === opt.key ? 'var(--gold)' : 'var(--text-muted)',
                  }}
                >
                  {opt.label}
                </button>
              ))}
            </div>
          </CardHeader>
          <CardBody>
            {isLoading ? (
              <div className="space-y-3">
                {[...Array(5)].map((_, i) => <div key={i} className="h-6 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
              </div>
            ) : (
              <ModuleUsageList rows={data?.module_usage[modulePeriod] ?? []} />
            )}
          </CardBody>
        </Card>
      </div>

      {/* Online users table */}
      <Card>
        <CardHeader>
          <CardTitle>Online Users</CardTitle>
          {data?.presence.available && (
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{data.presence.online_count} online</span>
          )}
        </CardHeader>
        <div className="overflow-x-auto">
          {!data?.presence.available ? (
            <EmptyState icon={Radio} title="Presence unavailable" description="Live presence could not be read — this does not mean no one is online." />
          ) : data.presence.online_users.length === 0 ? (
            <EmptyState icon={Users} title="No users online right now." />
          ) : (
            <table className="w-full min-w-[700px] text-sm">
              <thead>
                <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                  {['User', 'Role', 'Organization', 'Module', 'Last Active'].map(h => (
                    <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.presence.online_users.map(u => (
                  <tr key={u.user_id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3">
                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{u.name ?? '—'}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{u.email ?? '—'}</p>
                    </td>
                    <td className="px-4 py-3"><Badge tone="neutral">{u.role ?? '—'}</Badge></td>
                    <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{u.organization_name ?? '—'}</td>
                    <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{u.module_label ?? '—'}</td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{formatTime(u.last_active_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </Card>

      {/* Operational health */}
      <div className="grid lg:grid-cols-3 gap-5">
        <Card>
          <CardHeader><CardTitle>Queue</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm">
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Pending jobs</span><span style={{ color: 'var(--text-primary)' }}>{data?.queue.pending_jobs ?? '—'}</span></div>
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Failed jobs (24h)</span><span style={{ color: 'var(--text-primary)' }}>{data?.queue.failed_jobs_24h ?? '—'}</span></div>
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Failed jobs (total)</span><span style={{ color: 'var(--text-primary)' }}>{data?.queue.failed_jobs_total ?? '—'}</span></div>
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Oldest pending job</span><span style={{ color: 'var(--text-primary)' }}>{data?.queue.oldest_pending_job_age_seconds ? `${Math.round(data.queue.oldest_pending_job_age_seconds / 60)}m` : '—'}</span></div>
          </CardBody>
        </Card>
        <Card>
          <CardHeader><CardTitle>AI Analyses</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm">
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Pending</span><span style={{ color: 'var(--text-primary)' }}>{data?.ai.pending ?? '—'}</span></div>
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Processing</span><span style={{ color: 'var(--text-primary)' }}>{data?.ai.processing ?? '—'}</span></div>
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Completed today</span><span style={{ color: 'var(--text-primary)' }}>{data?.ai.completed_today ?? '—'}</span></div>
            <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Failed today</span><span style={{ color: 'var(--text-primary)' }}>{data?.ai.failed_today ?? '—'}</span></div>
            {(data?.ai.stuck_count ?? 0) > 0 && (
              <p className="text-xs pt-1" style={{ color: '#facc15' }}>{data?.ai.stuck_count} analysis(es) appear stuck</p>
            )}
          </CardBody>
        </Card>
        <Card>
          <CardHeader><CardTitle>Documents & Notifications</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm">
            <div className="flex justify-between"><span className="flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}><FileText size={12} /> Uploaded today</span><span style={{ color: 'var(--text-primary)' }}>{data?.documents.uploaded_today ?? '—'}</span></div>
            <div className="flex justify-between"><span className="flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}><FileText size={12} /> Generated today</span><span style={{ color: 'var(--text-primary)' }}>{data?.documents.generated_today ?? '—'}</span></div>
            <div className="flex justify-between"><span className="flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}><Bell size={12} /> Notifications today</span><span style={{ color: 'var(--text-primary)' }}>{data?.notifications.created_today ?? '—'}</span></div>
            <div className="flex justify-between"><span className="flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}><Bell size={12} /> Unread total</span><span style={{ color: 'var(--text-primary)' }}>{data?.notifications.unread_total ?? '—'}</span></div>
          </CardBody>
        </Card>
      </div>

      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
        {data?.presence_definition}
      </p>
    </div>
  );
}
