'use client';

import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import {
  HeartHandshake, Settings2, ListChecks, Clock, UserX, Hourglass,
  CheckCircle2, XCircle, PlusCircle, AlertTriangle, CalendarClock, Timer,
} from 'lucide-react';
import api from '@/lib/api';
import Button from '@/components/ui/Button';

interface DashboardSummary {
  totals: {
    all: number;
    awaiting_consultant: number;
    awaiting_customer: number;
    completed: number;
    cancelled: number;
    unassigned: number;
  };
  attention: {
    awaiting_customer_under_3_days: number;
    awaiting_customer_3_to_7_days: number;
    awaiting_customer_over_7_days: number;
    awaiting_customer_unknown_age: number;
  };
  recent: {
    created_last_7_days: number;
    completed_last_7_days: number;
  };
}

function StatCard({
  label, value, icon: Icon, href, tone = 'default',
}: {
  label: string; value: number; icon: React.ElementType; href?: string; tone?: 'default' | 'warning' | 'danger';
}) {
  const toneColor = tone === 'danger' ? '#f87171' : tone === 'warning' ? '#facc15' : 'var(--gold)';
  const content = (
    <div
      className="rounded-xl px-4 py-3 flex items-center gap-3 transition-all duration-200 ease-[cubic-bezier(0.32,0.72,0,1)]"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
    >
      <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--gold-15)' }}>
        <Icon size={16} style={{ color: toneColor }} />
      </div>
      <div className="min-w-0">
        <div className="text-lg font-bold leading-none tabular-nums" style={{ color: 'var(--text-primary)' }}>{value}</div>
        <div className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{label}</div>
      </div>
    </div>
  );

  if (!href) return content;

  return (
    <Link
      href={href}
      className="block hover:-translate-y-0.5 transition-transform duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 rounded-xl"
    >
      {content}
    </Link>
  );
}

function AttentionRow({ label, value, tone, href }: { label: string; value: number; tone: 'ok' | 'warning' | 'danger' | 'neutral'; href: string }) {
  const toneColor = tone === 'danger' ? '#f87171' : tone === 'warning' ? '#facc15' : tone === 'ok' ? '#4ade80' : 'var(--text-muted)';
  return (
    <Link
      href={href}
      className="flex items-center justify-between gap-3 py-2.5 hover:opacity-80 transition-opacity focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-[-2px] rounded-lg px-1"
      style={{ borderBottom: '1px solid var(--border)' }}
    >
      <div className="flex items-center gap-2">
        <span style={{ width: 7, height: 7, borderRadius: '50%', backgroundColor: toneColor, display: 'inline-block' }} />
        <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>{label}</span>
      </div>
      <span className="text-sm font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{value}</span>
    </Link>
  );
}

function SkeletonCard() {
  return <div className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />;
}

export default function ConsultancyDashboardPage() {
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin-consultancy-dashboard'],
    queryFn: () => api.get('/admin/consultancy/dashboard').then(r => r.data as DashboardSummary),
  });

  const totals = data?.totals;
  const attention = data?.attention;
  const recent = data?.recent;

  const awaitingCustomerAttentionCount =
    (attention?.awaiting_customer_3_to_7_days ?? 0) +
    (attention?.awaiting_customer_over_7_days ?? 0) +
    (attention?.awaiting_customer_unknown_age ?? 0);

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3 ss-animate-in">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <HeartHandshake size={20} /> Consultancy Dashboard
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
            What needs attention, and where to click next.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link href="/admin/consultancy/queue">
            <Button variant="secondary" size="md" className="rounded-full"><ListChecks size={14} /> Full Queue</Button>
          </Link>
          <Link href="/admin/consultancy/services">
            <Button variant="secondary" size="md" className="rounded-full"><Settings2 size={14} /> Consultancy Services</Button>
          </Link>
          <Link href="/admin/consultancy/availability">
            <Button variant="secondary" size="md" className="rounded-full"><CalendarClock size={14} /> Availability</Button>
          </Link>
          <Link href="/admin/consultancy/settings">
            <Button variant="secondary" size="md" className="rounded-full"><Settings2 size={14} /> Settings</Button>
          </Link>
          <Link href="/admin/consultancy/reservations">
            <Button variant="secondary" size="md" className="rounded-full"><Timer size={14} /> Reservations</Button>
          </Link>
        </div>
      </div>

      {isError ? (
        <div className="rounded-2xl p-8 text-center ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <p className="text-sm" style={{ color: '#f87171' }}>We couldn&apos;t load the dashboard summary.</p>
          <Button variant="secondary" size="sm" className="mt-4" onClick={() => refetch()}>Retry</Button>
        </div>
      ) : (
        <>
          {/* Totals */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 ss-animate-in" style={{ animationDelay: '50ms' }}>
            {isLoading ? (
              [...Array(4)].map((_, i) => <SkeletonCard key={i} />)
            ) : (
              <>
                <StatCard label="All Consultations" value={totals?.all ?? 0} icon={ListChecks} href="/admin/consultancy/queue" />
                <StatCard label="Awaiting Consultant" value={totals?.awaiting_consultant ?? 0} icon={Clock} tone="warning" href="/admin/consultancy/queue?engagement_status=awaiting_consultant" />
                <StatCard label="Awaiting Customer" value={totals?.awaiting_customer ?? 0} icon={Hourglass} tone="warning" href="/admin/consultancy/queue?engagement_status=awaiting_customer" />
                <StatCard label="Unassigned" value={totals?.unassigned ?? 0} icon={UserX} tone={totals && totals.unassigned > 0 ? 'danger' : 'default'} href="/admin/consultancy/queue?unassigned=1" />
              </>
            )}
          </div>

          {/* Attention panel */}
          <div className="rounded-2xl p-5 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '100ms' }}>
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                <AlertTriangle size={14} style={{ color: 'var(--text-muted)' }} />
                <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Awaiting Customer — Ageing</h2>
              </div>
              {awaitingCustomerAttentionCount > 0 && (
                <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
                  {awaitingCustomerAttentionCount} may need a nudge
                </span>
              )}
            </div>

            {isLoading ? (
              <div className="space-y-2">
                {[...Array(4)].map((_, i) => <div key={i} className="h-8 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
              </div>
            ) : !totals || totals.awaiting_customer === 0 ? (
              <p className="text-sm py-2" style={{ color: 'var(--text-muted)' }}>No consultations are currently awaiting a customer response.</p>
            ) : (
              <div>
                <AttentionRow
                  label="Under 3 days"
                  value={attention?.awaiting_customer_under_3_days ?? 0}
                  tone="ok"
                  href="/admin/consultancy/queue?engagement_status=awaiting_customer"
                />
                <AttentionRow
                  label="3–7 days"
                  value={attention?.awaiting_customer_3_to_7_days ?? 0}
                  tone="warning"
                  href="/admin/consultancy/queue?engagement_status=awaiting_customer"
                />
                <AttentionRow
                  label="Over 7 days"
                  value={attention?.awaiting_customer_over_7_days ?? 0}
                  tone="danger"
                  href="/admin/consultancy/queue?overdue_awaiting_customer=1"
                />
                <AttentionRow
                  label="Unknown age (no recorded transition)"
                  value={attention?.awaiting_customer_unknown_age ?? 0}
                  tone="neutral"
                  href="/admin/consultancy/queue?engagement_status=awaiting_customer"
                />
              </div>
            )}
            <p className="mt-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
              Ageing is measured from the most recent recorded transition into &quot;Awaiting Customer&quot; — not from when the
              record was last edited. A consultation with no recorded transition (older data) is shown as unknown age rather
              than guessed.
            </p>
          </div>

          {/* Recent activity + completed/cancelled */}
          <div className="grid sm:grid-cols-2 gap-5 ss-animate-in" style={{ animationDelay: '150ms' }}>
            <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
              <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Last 7 Days</h2>
              <div className="grid grid-cols-2 gap-3">
                {isLoading ? (
                  <>
                    <SkeletonCard />
                    <SkeletonCard />
                  </>
                ) : (
                  <>
                    <StatCard label="New Bookings" value={recent?.created_last_7_days ?? 0} icon={PlusCircle} />
                    <StatCard label="Completed" value={recent?.completed_last_7_days ?? 0} icon={CheckCircle2} />
                  </>
                )}
              </div>
            </div>
            <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
              <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>All Time</h2>
              <div className="grid grid-cols-2 gap-3">
                {isLoading ? (
                  <>
                    <SkeletonCard />
                    <SkeletonCard />
                  </>
                ) : (
                  <>
                    <StatCard label="Completed" value={totals?.completed ?? 0} icon={CheckCircle2} href="/admin/consultancy/queue?engagement_status=completed" />
                    <StatCard label="Cancelled" value={totals?.cancelled ?? 0} icon={XCircle} href="/admin/consultancy/queue?engagement_status=cancelled" />
                  </>
                )}
              </div>
            </div>
          </div>
        </>
      )}
    </div>
  );
}
