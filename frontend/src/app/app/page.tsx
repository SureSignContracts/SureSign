'use client';

import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import api from '@/lib/api';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import EmptyState from '@/components/ui/EmptyState';
import Select from '@/components/ui/Select';
import PageTourButton from '@/components/tours/PageTourButton';
import { staggerDelay } from '@/lib/motion';
import ProjectMap, { type ProjectMapData } from '@/components/dashboard/ProjectMap';
import Image from 'next/image';
import {
  AlertTriangle, CheckCircle2, Clock, DollarSign, GitBranch, MessageSquare,
  ShieldAlert, FileText, Milestone, FolderKanban, Activity, ArrowRight, Search,
  ChevronDown, ChevronUp, CalendarClock, ClipboardCheck, Landmark,
} from 'lucide-react';

// ── Types (mirrors OrganisationDashboardService::build()) ────────────────

type ItemStatus = 'overdue' | 'due_today' | 'due_soon' | 'upcoming';

type NeedsAttentionItem = {
  type: string;
  project_id: number;
  project_name: string;
  source_id: number;
  reference: string;
  summary: string;
  due_date: string;
  status: ItemStatus;
  days: number;
  record_status: string;
  amount: number | null;
  currency: string | null;
  action_url: string;
};

type CashBlock = { currency: string; outstanding_total: number; retention_total: number };

type DashboardData = {
  needs_attention: {
    items: NeedsAttentionItem[];
    counts: { overdue: number; due_today: number; due_soon: number; upcoming: number };
  };
  portfolio_health: {
    active_projects: number;
    projects_with_overdue_items: number;
    total_overdue_items: number;
    items_due_soon: number;
  };
  commercial_snapshot: {
    by_currency: CashBlock[];
    awaiting_certification_count: number;
    commercial_deadline_count: number;
    action_url: string;
  };
  project_map: ProjectMapData;
  recent_activity: {
    id: number;
    description: string;
    project_id: number;
    project_name: string | null;
    actor: string;
    timestamp: string;
    action_url: string | null;
  }[];
  meta: { effective_timezone: string; due_soon_threshold_days: number; generated_at: string; has_projects: boolean };
};

const STATUS_STYLE: Record<ItemStatus, { bg: string; text: string; label: string; icon: typeof AlertTriangle }> = {
  overdue:    { bg: 'rgba(239,68,68,0.15)',  text: '#f87171', label: 'Overdue',   icon: AlertTriangle },
  due_today:  { bg: 'rgba(249,115,22,0.15)', text: '#fb923c', label: 'Due Today', icon: Clock },
  due_soon:   { bg: 'rgba(234,179,8,0.15)',  text: '#facc15', label: 'Due Soon',  icon: Clock },
  upcoming:   { bg: 'rgba(90,86,82,0.3)',    text: '#9a9490', label: 'Upcoming',  icon: Clock },
};

const TYPE_META: Record<string, { label: string; icon: typeof AlertTriangle }> = {
  rfi:                  { label: 'RFI', icon: MessageSquare },
  variation:            { label: 'Variation', icon: GitBranch },
  payment_application:  { label: 'Payment Application', icon: DollarSign },
  contract_risk:        { label: 'Risk', icon: ShieldAlert },
  delivery_document:    { label: 'Delivery Document', icon: FileText },
  programme_milestone:  { label: 'Milestone', icon: Milestone },
};

const NEEDS_ATTENTION_PREVIEW_COUNT = 5;
const RECENT_ACTIVITY_PREVIEW_COUNT = 5;

function dayLabel(days: number): string {
  if (days < 0) return `${Math.abs(days)}d overdue`;
  if (days === 0) return 'Due today';
  return `${days}d remaining`;
}

/** Compact attention-summary tile — a real server-computed count, an icon, and an optional click action. */
function SummaryStatCard({
  icon: Icon, value, label, tone, onClick, index,
}: {
  icon: typeof AlertTriangle;
  value: number;
  label: string;
  tone: { bg: string; text: string };
  onClick?: () => void;
  index: number;
}) {
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag
      onClick={onClick}
      className={`ss-animate-in flex items-center gap-3 rounded-xl p-3.5 text-left transition-all duration-200 ${onClick ? 'cursor-pointer hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]' : ''}`}
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: staggerDelay(index) }}
    >
      <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: tone.bg }}>
        <Icon size={18} style={{ color: tone.text }} />
      </div>
      <div className="min-w-0">
        <p className="text-2xl font-bold leading-none tabular-nums" style={{ color: 'var(--text-primary)' }}>{value}</p>
        <p className="text-xs mt-0.5 leading-snug" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>
    </Tag>
  );
}

export default function AppDashboardPage() {
  const user = useAuthStore((s) => s.user);
  const router = useRouter();
  const formatCurrency = useCurrencyFormatter();

  const [urgencyFilter, setUrgencyFilter] = useState<'all' | ItemStatus>('all');
  const [projectFilter, setProjectFilter] = useState<string>('all');
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [attentionExpanded, setAttentionExpanded] = useState(false);

  const { data, isLoading, isError, refetch } = useQuery<DashboardData>({
    queryKey: ['dashboard-action-centre'],
    queryFn: () => api.get('/dashboard/action-centre').then((r) => r.data),
  });

  const items = useMemo(() => data?.needs_attention.items ?? [], [data]);

  const projectOptions = useMemo(
    () => Array.from(new Map(items.map(i => [i.project_id, i.project_name])).entries()),
    [items]
  );
  const typeOptions = useMemo(
    () => Array.from(new Set(items.map(i => i.type))),
    [items]
  );

  const filtersActive = urgencyFilter !== 'all' || projectFilter !== 'all' || typeFilter !== 'all';

  const filteredItems = items.filter(i =>
    (urgencyFilter === 'all' || i.status === urgencyFilter) &&
    (projectFilter === 'all' || String(i.project_id) === projectFilter) &&
    (typeFilter === 'all' || i.type === typeFilter)
  );

  // Filters are the user's own explicit narrowing — always show every match.
  // With no filter active, default to a short preview so Needs Attention no
  // longer dominates the first viewport; "View all" reveals the rest in place.
  const visibleItems = filtersActive || attentionExpanded ? filteredItems : filteredItems.slice(0, NEEDS_ATTENTION_PREVIEW_COUNT);
  const hiddenCount = filtersActive ? 0 : Math.max(filteredItems.length - NEEDS_ATTENTION_PREVIEW_COUNT, 0);

  const firstName = user?.name?.split(' ')[0] ?? 'there';
  const counts = data?.needs_attention.counts;
  const totalUrgent = (counts?.overdue ?? 0) + (counts?.due_today ?? 0);
  const recentActivity = data?.recent_activity ?? [];
  const [activityExpanded, setActivityExpanded] = useState(false);
  const visibleActivity = activityExpanded ? recentActivity : recentActivity.slice(0, RECENT_ACTIVITY_PREVIEW_COUNT);

  function jumpToNeedsAttention(status: ItemStatus | 'all') {
    setUrgencyFilter(status);
    document.getElementById('dashboard-needs-attention')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  if (isLoading) {
    return (
      <div className="p-6 max-w-6xl mx-auto space-y-6">
        <div className="h-28 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
        <div className="space-y-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      </div>
    );
  }

  if (isError) {
    return (
      <div className="p-6 max-w-6xl mx-auto">
        <div className="flex flex-col items-center justify-center py-16 gap-3 rounded-xl" style={{ border: '1px solid var(--border)' }}>
          <AlertTriangle size={28} style={{ color: '#f87171' }} />
          <p className="text-sm font-medium" style={{ color: '#f87171' }}>Could not load the organisation action centre</p>
          <button
            onClick={() => refetch()}
            className="mt-1 px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            Retry
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-8">
      {/* Hero */}
      <div
        className="ss-animate-in relative flex items-stretch gap-6 rounded-2xl p-6 overflow-hidden"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
        data-tour="dashboard-header"
      >
        <div className="flex-1 min-w-0 flex flex-col justify-center">
          <div className="flex items-center gap-1.5">
            <h1 className="text-2xl font-bold tracking-tight" style={{ color: 'var(--text-primary)' }}>
              Good day, {firstName}
            </h1>
            <PageTourButton tourKey="page-dashboard" label="Take a tour of this page" />
          </div>
          <p className="mt-1.5 text-sm max-w-md" style={{ color: 'var(--text-secondary)' }}>
            {totalUrgent > 0
              ? `${totalUrgent} item${totalUrgent === 1 ? '' : 's'} need${totalUrgent === 1 ? 's' : ''} attention across your organisation.`
              : 'What requires attention across your organisation today.'}
          </p>
        </div>

        {/* Hero visual — hidden on small screens rather than left as dead
            decorative space (mobile keeps the first viewport action-focused,
            per the visual direction). Explicit width/height on the source
            asset (matching its real aspect ratio) avoids any layout shift;
            `object-contain` lets it sit inside the fixed-height slot without
            cropping. See docs/dashboard/overview.md and the batch report for
            this asset's source/license. */}
        <div
          className="hidden md:flex w-48 flex-shrink-0 items-center justify-center rounded-xl relative overflow-hidden"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
        >
          <Image
            src="/dashboard/hero-construction.webp"
            alt=""
            width={480}
            height={291}
            className="w-full h-full object-contain p-2"
            priority
          />
        </div>
      </div>

      {!data?.meta.has_projects ? (
        <EmptyState
          surface
          icon={FolderKanban}
          title="No projects yet"
          description="Dashboard intelligence will appear here once projects are created."
        />
      ) : (
        <>
          {/* Attention Summary */}
          <section aria-label="Attention summary">
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <SummaryStatCard index={0} icon={AlertTriangle} value={counts?.overdue ?? 0} label="Overdue"
                tone={{ bg: 'rgba(239,68,68,0.15)', text: '#f87171' }} onClick={() => jumpToNeedsAttention('overdue')} />
              <SummaryStatCard index={1} icon={Clock} value={counts?.due_today ?? 0} label="Due Today"
                tone={{ bg: 'rgba(249,115,22,0.15)', text: '#fb923c' }} onClick={() => jumpToNeedsAttention('due_today')} />
              <SummaryStatCard index={2} icon={Clock} value={counts?.due_soon ?? 0} label="Due Soon"
                tone={{ bg: 'rgba(234,179,8,0.15)', text: '#facc15' }} onClick={() => jumpToNeedsAttention('due_soon')} />
              <SummaryStatCard index={3} icon={CalendarClock} value={data?.commercial_snapshot.commercial_deadline_count ?? 0} label="Commercial Deadlines"
                tone={{ bg: 'var(--gold-15)', text: 'var(--gold)' }} onClick={() => router.push(data?.commercial_snapshot.action_url ?? '/app/commercial')} />
            </div>
          </section>

          {/* Project Map */}
          {data?.project_map && <ProjectMap data={data.project_map} />}

          {/* Portfolio Health + Commercial Position */}
          <div className="grid lg:grid-cols-2 gap-6">
            <section data-tour="dashboard-portfolio-health">
              <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Portfolio Health</h2>
              <div className="grid grid-cols-2 gap-3">
                {[
                  { label: 'Active Projects', value: data?.portfolio_health.active_projects ?? 0, icon: FolderKanban, tone: { bg: 'var(--bg-elevated)', text: 'var(--text-primary)' } },
                  { label: 'Projects With Overdue Items', value: data?.portfolio_health.projects_with_overdue_items ?? 0, icon: AlertTriangle, tone: { bg: 'rgba(239,68,68,0.15)', text: '#f87171' } },
                  { label: 'Total Overdue Items', value: data?.portfolio_health.total_overdue_items ?? 0, icon: ShieldAlert, tone: { bg: 'rgba(239,68,68,0.15)', text: '#f87171' } },
                  { label: 'Items Due Soon', value: data?.portfolio_health.items_due_soon ?? 0, icon: Clock, tone: { bg: 'rgba(234,179,8,0.15)', text: '#facc15' } },
                ].map((stat, i) => (
                  <div
                    key={stat.label}
                    className="ss-animate-in rounded-xl p-3.5 flex items-center gap-3 transition-shadow duration-200 hover:shadow-[var(--shadow-card)]"
                    style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: staggerDelay(i) }}
                  >
                    <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: stat.tone.bg }}>
                      <stat.icon size={16} style={{ color: stat.tone.text }} />
                    </div>
                    <div className="min-w-0">
                      <p className="text-xl font-bold leading-none tabular-nums" style={{ color: 'var(--text-primary)' }}>{stat.value}</p>
                      <p className="text-xs mt-0.5 leading-snug" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
                    </div>
                  </div>
                ))}
              </div>
            </section>

            <section data-tour="dashboard-commercial-snapshot">
              <div className="flex items-center justify-between mb-3">
                <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Commercial Position</h2>
                <a href={data?.commercial_snapshot.action_url ?? '/app/commercial'} className="flex items-center gap-1 text-xs font-medium hover:opacity-80" style={{ color: 'var(--gold)' }}>
                  Open Global Commercial <ArrowRight size={11} />
                </a>
              </div>
              {(data?.commercial_snapshot.by_currency.length ?? 0) === 0 ? (
                <EmptyState surface icon={Landmark} title="No commercial data yet" description="Commercial figures will appear here once payment applications exist." />
              ) : (
                <div
                  className="rounded-xl p-4 space-y-3 ss-animate-in"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
                >
                  {data!.commercial_snapshot.by_currency.map((block) => (
                    <div key={block.currency} className="grid grid-cols-2 gap-3">
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'rgba(249,115,22,0.15)' }}>
                          <DollarSign size={16} style={{ color: '#fb923c' }} />
                        </div>
                        <div className="min-w-0">
                          <p className="text-lg font-bold leading-none tabular-nums truncate" style={{ color: 'var(--text-primary)' }}>{formatCurrency(block.outstanding_total, block.currency)}</p>
                          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Outstanding {data!.commercial_snapshot.by_currency.length > 1 ? `(${block.currency})` : ''}</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'rgba(167,139,250,0.15)' }}>
                          <Landmark size={16} style={{ color: '#a78bfa' }} />
                        </div>
                        <div className="min-w-0">
                          <p className="text-lg font-bold leading-none tabular-nums truncate" style={{ color: 'var(--text-primary)' }}>{formatCurrency(block.retention_total, block.currency)}</p>
                          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Retention Held {data!.commercial_snapshot.by_currency.length > 1 ? `(${block.currency})` : ''}</p>
                        </div>
                      </div>
                    </div>
                  ))}
                  <div className="pt-3 grid grid-cols-2 gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                    <div className="flex items-center gap-2">
                      <ClipboardCheck size={14} style={{ color: 'var(--text-muted)' }} />
                      <div className="min-w-0">
                        <p className="text-sm font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{data?.commercial_snapshot.awaiting_certification_count ?? 0}</p>
                        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Awaiting Certification</p>
                      </div>
                    </div>
                    <div className="flex items-center gap-2">
                      <CalendarClock size={14} style={{ color: 'var(--text-muted)' }} />
                      <div className="min-w-0">
                        <p className="text-sm font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{data?.commercial_snapshot.commercial_deadline_count ?? 0}</p>
                        <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Commercial Deadlines Due</p>
                      </div>
                    </div>
                  </div>
                </div>
              )}
            </section>
          </div>

          {/* Needs Attention */}
          <section id="dashboard-needs-attention" data-tour="dashboard-needs-attention">
            <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Needs Attention</h2>
              {items.length > 0 && (
                <div className="flex gap-2 flex-wrap">
                  <Select aria-label="Filter by urgency" value={urgencyFilter} onChange={e => setUrgencyFilter(e.target.value as 'all' | ItemStatus)}
                    className="text-xs py-1.5" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                    <option value="all">All urgency</option>
                    <option value="overdue">Overdue</option>
                    <option value="due_today">Due Today</option>
                    <option value="due_soon">Due Soon</option>
                    <option value="upcoming">Upcoming</option>
                  </Select>
                  <Select aria-label="Filter by project" value={projectFilter} onChange={e => setProjectFilter(e.target.value)}
                    className="text-xs py-1.5" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                    <option value="all">All projects</option>
                    {projectOptions.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                  </Select>
                  <Select aria-label="Filter by record type" value={typeFilter} onChange={e => setTypeFilter(e.target.value)}
                    className="text-xs py-1.5" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                    <option value="all">All record types</option>
                    {typeOptions.map(t => <option key={t} value={t}>{TYPE_META[t]?.label ?? t}</option>)}
                  </Select>
                </div>
              )}
            </div>

            {items.length === 0 ? (
              <EmptyState surface icon={CheckCircle2} title="All caught up" description="No urgent actions across your projects." />
            ) : filteredItems.length === 0 ? (
              <EmptyState surface icon={Search} title="No items match the current filters" description="Try a different urgency, project, or record type filter." />
            ) : (
              <div className="space-y-2">
                {visibleItems.map((item, i) => {
                  const s = STATUS_STYLE[item.status];
                  const t = TYPE_META[item.type] ?? { label: item.type, icon: FileText };
                  const StatusIcon = s.icon;
                  const TypeIcon = t.icon;
                  return (
                    <div
                      key={`${item.type}-${item.project_id}-${item.source_id}-${i}`}
                      role="button"
                      tabIndex={0}
                      onClick={() => router.push(item.action_url)}
                      onKeyDown={e => e.key === 'Enter' && router.push(item.action_url)}
                      className="ss-animate-in flex items-center gap-3 p-3.5 rounded-xl border cursor-pointer transition-all duration-200 hover:border-[var(--gold)] hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]"
                      style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', animationDelay: staggerDelay(i) }}
                    >
                      <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: s.bg }}>
                        <StatusIcon size={16} style={{ color: s.text }} />
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                          <TypeIcon size={12} style={{ color: 'var(--text-muted)' }} />
                          <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                            {t.label}: {item.reference}
                          </p>
                          <span className="text-xs px-2 py-0.5 rounded-full flex-shrink-0" style={{ backgroundColor: s.bg, color: s.text }}>
                            {s.label}
                          </span>
                        </div>
                        <div className="flex items-center gap-3 mt-0.5 flex-wrap">
                          <span className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{item.project_name}</span>
                          <span className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{item.summary}</span>
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{dayLabel(item.days)}</span>
                          {item.amount !== null && item.currency && (
                            <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatCurrency(item.amount, item.currency)}</span>
                          )}
                        </div>
                      </div>
                    </div>
                  );
                })}

                {hiddenCount > 0 && (
                  <button
                    onClick={() => setAttentionExpanded(true)}
                    className="w-full flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-xs font-medium transition-colors duration-150 hover:bg-[var(--bg-hover)]"
                    style={{ color: 'var(--gold)', border: '1px solid var(--border)' }}
                  >
                    View all {filteredItems.length} items <ChevronDown size={13} />
                  </button>
                )}
                {!filtersActive && attentionExpanded && filteredItems.length > NEEDS_ATTENTION_PREVIEW_COUNT && (
                  <button
                    onClick={() => setAttentionExpanded(false)}
                    className="w-full flex items-center justify-center gap-1.5 py-2.5 rounded-xl text-xs font-medium transition-colors duration-150 hover:bg-[var(--bg-hover)]"
                    style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                  >
                    Show less <ChevronUp size={13} />
                  </button>
                )}
              </div>
            )}
          </section>

          {/* Recent Activity */}
          {recentActivity.length > 0 && (
            <section data-tour="dashboard-recent-activity">
              <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Recent Activity</h2>
              <div className="ss-animate-in space-y-0.5">
                {visibleActivity.map(activity => (
                  <div key={activity.id} className="flex items-center gap-3 px-2 py-2 rounded-lg transition-colors duration-150 hover:bg-[var(--bg-hover)]">
                    <div className="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                      <Activity size={11} style={{ color: 'var(--text-muted)' }} />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm truncate" style={{ color: 'var(--text-primary)' }}>{activity.description}</p>
                      <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>
                        {activity.project_name} · {activity.actor} · {new Date(activity.timestamp).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })}
                      </p>
                    </div>
                  </div>
                ))}
                {recentActivity.length > RECENT_ACTIVITY_PREVIEW_COUNT && (
                  <button
                    onClick={() => setActivityExpanded(v => !v)}
                    className="w-full flex items-center justify-center gap-1.5 py-2 mt-1 rounded-lg text-xs font-medium transition-colors duration-150 hover:bg-[var(--bg-hover)]"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    {activityExpanded ? <>Show less <ChevronUp size={12} /></> : <>Show {recentActivity.length - RECENT_ACTIVITY_PREVIEW_COUNT} more <ChevronDown size={12} /></>}
                  </button>
                )}
              </div>
            </section>
          )}
        </>
      )}
    </div>
  );
}
