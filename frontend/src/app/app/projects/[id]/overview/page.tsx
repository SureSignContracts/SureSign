'use client';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import dynamic from 'next/dynamic';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd, parseDateOnly } from '@/lib/dateTime';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import CountUp from '@/components/ui/CountUp';
import { DollarSign, FileText, MessageSquare, GitBranch, AlertCircle, Activity, BarChart2, ChevronRight, ShieldAlert, TrendingUp, Zap, FileCheck, HeartHandshake, Pencil, MapPin, ExternalLink, Copy } from 'lucide-react';
import PageTourButton from '@/components/tours/PageTourButton';
import Link from 'next/link';
import { formatDateTime } from '@/lib/dateTime';
import EditProjectModal from '@/components/projects/EditProjectModal';

// Leaflet reads `window`/`document` at import time — client-only, and only
// loaded once the Site Location section actually renders.
const SiteLocationMap = dynamic(() => import('@/components/projects/SiteLocationMap'), {
  ssr: false,
  loading: () => (
    <div className="w-full h-full flex items-center justify-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
      <MapPin size={20} style={{ color: 'var(--text-muted)', opacity: 0.5 }} />
    </div>
  ),
});

/** Same small local-helper pattern already used elsewhere in the app
 *  (e.g. admin/prompts/page.tsx) — no shared clipboard utility exists to
 *  import instead. */
async function copyToClipboard(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    try {
      const el = document.createElement('textarea');
      el.value = text;
      el.style.position = 'fixed';
      el.style.opacity = '0';
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
      return true;
    } catch {
      return false;
    }
  }
}

/** Google's own officially documented, no-API-key URL mechanism — see
 *  https://developers.google.com/maps/documentation/urls/get-started.
 *  Accepts a raw coordinate pair directly; satellite view cannot be forced
 *  reliably through this documented mechanism, so this deliberately opens
 *  the standard map view and lets the user switch views themselves. */
function googleMapsUrl(lat: number, lng: number): string {
  return `https://www.google.com/maps/search/?api=1&query=${lat},${lng}`;
}

// ── Health ─────────────────────────────────────────────────────────────────

const HEALTH_CONFIG = {
  healthy:            { label: 'Healthy',           color: '#4ade80', bg: 'rgba(34,197,94,0.12)' },
  attention_required: { label: 'Needs Attention',   color: '#facc15', bg: 'rgba(234,179,8,0.12)' },
  critical:           { label: 'Critical',          color: '#f87171', bg: 'rgba(239,68,68,0.12)' },
} as const;

function HealthBar({ score, rating }: { score: number; rating: string }) {
  const cfg = HEALTH_CONFIG[rating as keyof typeof HEALTH_CONFIG] ?? HEALTH_CONFIG.healthy;
  return (
    <div className="flex items-center gap-2">
      <div className="flex-1 h-1.5 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        <div className="h-1.5 rounded-full transition-all" style={{ width: `${score}%`, backgroundColor: cfg.color }} />
      </div>
      <span className="text-xs tabular-nums w-8 text-right" style={{ color: cfg.color }}>{score}</span>
    </div>
  );
}

function ProjectHealthWidget({ health }: { health: any }) {
  if (!health) return null;
  const cfg = HEALTH_CONFIG[health.rating as keyof typeof HEALTH_CONFIG] ?? HEALTH_CONFIG.healthy;
  const domains = health.domains ?? {};
  return (
    <div className="ss-animate-in rounded-2xl p-5 flex flex-col gap-4" data-tour="overview-health" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <TrendingUp size={14} style={{ color: 'var(--text-muted)' }} />
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Project health</h2>
        </div>
        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"
          style={{ backgroundColor: cfg.bg, color: cfg.color }}>
          <span style={{ width: 7, height: 7, borderRadius: '50%', backgroundColor: cfg.color, display: 'inline-block' }} />
          {cfg.label}
        </span>
      </div>

      <div className="text-center py-1">
        <p className="text-4xl font-bold tabular-nums" style={{ color: cfg.color }}>{health.score}</p>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>out of 100</p>
      </div>

      <div className="space-y-3">
        {(['commercial', 'compliance', 'risk'] as const).map((domain) => {
          const d = domains[domain];
          if (!d) return null;
          return (
            <div key={domain}>
              <div className="flex items-center justify-between mb-1">
                <span className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{domain}</span>
              </div>
              <HealthBar score={d.score} rating={d.rating} />
              {d.issues?.length > 0 && (
                <p className="text-xs mt-1" style={{ color: '#f87171' }}>{d.issues[0]}</p>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}

// ── Upcoming Actions ────────────────────────────────────────────────────────

const PRIORITY_COLORS: Record<string, string> = {
  critical: '#f87171',
  high:     '#fb923c',
  medium:   '#facc15',
  low:      '#4ade80',
};

const CATEGORY_LABELS: Record<string, string> = {
  payment:     'Payment',
  compliance:  'Compliance',
  programme:   'Programme',
  contract:    'Contract',
  retention:   'Retention',
  risk:        'Risk',
  deliverables:'Deliverable',
  notices:     'Notice',
};

function ActionRow({ action }: { action: any }) {
  const router = useRouter();
  const color = PRIORITY_COLORS[action.priority] ?? 'var(--text-muted)';
  const isOverdue = action.status === 'overdue';
  const isDueToday = action.status === 'due_today';
  const clickable = Boolean(action.action_url);
  return (
    <div
      className={`flex items-start gap-3 py-2.5 ${clickable ? 'cursor-pointer transition-opacity hover:opacity-80' : ''}`}
      style={{ borderBottom: '1px solid var(--border)' }}
      onClick={clickable ? () => router.push(action.action_url) : undefined}
    >
      <div className="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style={{ backgroundColor: color }} />
      <div className="flex-1 min-w-0">
        <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{action.title}</p>
        <div className="flex items-center gap-2 mt-0.5 flex-wrap">
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {CATEGORY_LABELS[action.category] ?? action.category}
          </span>
          <span className="text-xs font-medium"
            style={{ color: isOverdue ? '#f87171' : isDueToday ? '#facc15' : 'var(--text-muted)' }}>
            {isOverdue
              ? `Overdue by ${Math.abs(action.days_remaining)}d`
              : isDueToday
              ? 'Due today'
              : action.due_date ? parseDateOnly(action.due_date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : ''}
          </span>
        </div>
      </div>
    </div>
  );
}

function UpcomingActionsWidget({ actions }: { actions: any }) {
  if (!actions) return null;
  const overdue: any[]   = actions.overdue    ?? [];
  const dueToday: any[]  = actions.due_today  ?? [];
  const upcoming7: any[] = actions.upcoming_7 ?? [];
  const counts           = actions.counts     ?? {};

  const allVisible = [...overdue, ...dueToday, ...upcoming7].slice(0, 8);

  return (
    <div className="ss-animate-in rounded-2xl p-5 flex flex-col gap-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Zap size={14} style={{ color: 'var(--text-muted)' }} />
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Upcoming actions</h2>
        </div>
        <div className="flex items-center gap-2">
          {counts.overdue > 0 && (
            <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
              {counts.overdue} overdue
            </span>
          )}
          {counts.due_today > 0 && (
            <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'rgba(234,179,8,0.1)', color: '#facc15' }}>
              {counts.due_today} today
            </span>
          )}
        </div>
      </div>

      {allVisible.length === 0 ? (
        <p className="text-xs py-4 text-center" style={{ color: 'var(--text-muted)' }}>No actions due in the next 7 days.</p>
      ) : (
        <div>
          {allVisible.map((action: any, i: number) => (
            <ActionRow key={`${action.source_type}-${action.source_id}-${i}`} action={action} />
          ))}
        </div>
      )}
    </div>
  );
}

// ── Risk Summary ────────────────────────────────────────────────────────────

function RiskSummaryWidget({ riskSummary }: { riskSummary: any }) {
  if (!riskSummary || (riskSummary.critical === 0 && riskSummary.high === 0 && riskSummary.non_standard_amendments === 0)) return null;
  const topRisks: any[] = riskSummary.top_risks ?? [];
  return (
    <div className="ss-animate-in rounded-2xl p-5 flex flex-col gap-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <ShieldAlert size={14} style={{ color: 'var(--text-muted)' }} />
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Contract risks</h2>
        </div>
        <div className="flex items-center gap-2">
          {riskSummary.critical > 0 && (
            <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
              {riskSummary.critical} critical
            </span>
          )}
          {riskSummary.high > 0 && (
            <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'rgba(249,115,22,0.1)', color: '#fb923c' }}>
              {riskSummary.high} high
            </span>
          )}
        </div>
      </div>

      {riskSummary.non_standard_amendments > 0 && (
        <div className="flex items-center gap-2 p-2.5 rounded-xl" style={{ backgroundColor: 'rgba(239,68,68,0.06)', border: '1px solid rgba(239,68,68,0.15)' }}>
          <AlertCircle size={13} style={{ color: '#f87171', flexShrink: 0 }} />
          <p className="text-xs" style={{ color: '#f87171' }}>
            {riskSummary.non_standard_amendments} non-standard amendment{riskSummary.non_standard_amendments > 1 ? 's' : ''} identified
          </p>
        </div>
      )}

      {topRisks.length > 0 && (
        <div>
          {topRisks.slice(0, 3).map((risk: any) => (
            <div key={risk.id} className="flex items-start gap-2.5 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
              <div className="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0"
                style={{ backgroundColor: risk.severity === 'critical' ? '#f87171' : risk.severity === 'high' ? '#fb923c' : '#facc15' }} />
              <div className="flex-1 min-w-0">
                <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{risk.title}</p>
                {risk.clause_reference && (
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{risk.clause_reference}</p>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ── Final Accounts ──────────────────────────────────────────────────────────

const FA_STATUS_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  draft:                    { label: 'Draft',              bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  submitted:                { label: 'Submitted',          bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  under_review:             { label: 'Under Review',       bg: 'rgba(251,146,60,0.12)', text: '#fb923c' },
  agreed:                   { label: 'Agreed',             bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  signed:                   { label: 'Signed',             bg: 'rgba(167,139,250,0.12)',text: '#a78bfa' },
  final_certificate_issued: { label: 'Final Certificate',  bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  commercially_closed:      { label: 'Commercially Closed',bg: 'rgba(34,197,94,0.2)',   text: '#4ade80' },
};

function FinalAccountRow({ fa, formatCurrency, projectId }: { fa: any; formatCurrency: (v: number) => string; projectId: string }) {
  const router = useRouter();
  const cfg = FA_STATUS_CONFIG[fa.status] ?? FA_STATUS_CONFIG.draft;
  const progress: any[] = fa.close_out_progress ?? [];
  const completedSteps = progress.filter((s) => s.completed).length;

  return (
    <div
      className="py-2.5 cursor-pointer transition-opacity hover:opacity-80"
      style={{ borderBottom: '1px solid var(--border)' }}
      onClick={() => router.push(fa.action_url ?? `/app/projects/${projectId}/commercial?tab=final-account&fa=${fa.id}`)}
    >
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>
            {fa.source_name ?? fa.reference} {fa.reference && fa.source_name ? `· ${fa.reference}` : ''}
          </p>
          <div className="flex items-center gap-2 mt-1 flex-wrap">
            <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: cfg.bg, color: cfg.text }}>
              {cfg.label}
            </span>
            {progress.length > 0 && (
              <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Close-Out {completedSteps}/{progress.length}
              </span>
            )}
            {fa.dispute_window_remaining_days !== null && fa.dispute_window_remaining_days !== undefined && fa.status === 'final_certificate_issued' && (
              <span className="text-xs font-medium" style={{ color: fa.dispute_window_remaining_days < 0 ? '#f87171' : fa.dispute_window_remaining_days <= 7 ? '#facc15' : 'var(--text-muted)' }}>
                {fa.dispute_window_remaining_days < 0
                  ? 'Dispute window closed'
                  : fa.dispute_window_remaining_days === 0
                  ? 'Dispute window expires today'
                  : `Dispute window: ${fa.dispute_window_remaining_days}d left`}
              </span>
            )}
          </div>
        </div>
        {fa.final_balance_due !== null && fa.final_balance_due !== undefined && (
          <div className="text-right shrink-0">
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Final balance due</p>
            <p className="text-sm font-bold tabular-nums" style={{ color: 'var(--gold)' }}>{formatCurrency(fa.final_balance_due)}</p>
          </div>
        )}
      </div>
    </div>
  );
}

function FinalAccountsWidget({ finalAccounts, projectId, formatCurrency }: { finalAccounts: any[]; projectId: string; formatCurrency: (v: number) => string }) {
  const router = useRouter();

  return (
    <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <FileCheck size={14} style={{ color: 'var(--text-muted)' }} />
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Final Account</h2>
        </div>
        <button onClick={() => router.push(`/app/projects/${projectId}/commercial?tab=final-account`)}
          className="flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--gold)' }}>
          View <ChevronRight size={11} />
        </button>
      </div>

      {finalAccounts.length === 0 ? (
        <p className="text-xs py-3 text-center" style={{ color: 'var(--text-muted)' }}>
          No Final Account has been started for this project yet.
        </p>
      ) : (
        <div>
          {finalAccounts.map((fa: any) => (
            <FinalAccountRow key={fa.id} fa={fa} formatCurrency={formatCurrency} projectId={projectId} />
          ))}
        </div>
      )}
    </div>
  );
}

// ── Consultancy ──────────────────────────────────────────────────────────────

const ENGAGEMENT_LABELS: Record<string, string> = {
  awaiting_consultant: 'Awaiting Consultant',
  awaiting_customer:   'Awaiting Customer',
  completed:           'Completed',
  cancelled:           'Cancelled',
};

interface LinkedConsultation {
  id: number;
  reference: string;
  consultancy_service: { code: string; display_name: string } | null;
  engagement_status: string | null;
  appointment_status: string;
  assigned_consultant: { id: number; name: string } | null;
  created_at: string;
  starts_at: string;
}

/**
 * Read-only, Consultancy-owned summary of consultations linked to this
 * project (Phase C2, Batch 5) — visible only to Super Admin/Admin, since
 * GET /admin/consultancy/projects/{id}/consultations is gated to those
 * roles. A Client sees nothing here; project linkage is operator-managed
 * only in this phase (see internal-docs/commercial/
 * suresign-consultancy-phase-c2-specification-v1.md §6).
 */
function ConsultancyWidget({ projectId }: { projectId: string }) {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['project-consultancy-consultations', projectId],
    queryFn: () => api.get(`/admin/consultancy/projects/${projectId}/consultations`, { params: { per_page: 5 } })
      .then(r => r.data.data as LinkedConsultation[]),
  });

  // A skeleton (rather than returning null) avoids this card popping in
  // and shifting layout after every other widget on the page has already
  // settled — this widget fetches independently of the page's own
  // top-level query. On error, stay silent rather than claim "no
  // consultations linked" — that would misrepresent a failed fetch as a
  // genuine empty state; this is a secondary, supplementary card, not
  // worth a full error+retry treatment of its own.
  if (isLoading) {
    return (
      <div className="rounded-2xl p-5 h-24 animate-pulse" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />
    );
  }
  if (isError) return null;
  if (!data || data.length === 0) {
    return (
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center gap-2 mb-1">
          <HeartHandshake size={14} style={{ color: 'var(--text-muted)' }} />
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Consultancy</h2>
        </div>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No consultations linked to this project yet.</p>
      </div>
    );
  }

  return (
    <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 mb-3">
        <HeartHandshake size={14} style={{ color: 'var(--text-muted)' }} />
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Consultancy</h2>
      </div>
      <div>
        {data.map((c, i) => (
          <Link
            key={c.id}
            href={`/admin/consultancy/queue/${c.id}`}
            className="flex items-center justify-between gap-3 py-2.5 hover:opacity-80 transition-opacity"
            style={{ borderBottom: i < data.length - 1 ? '1px solid var(--border)' : undefined }}
          >
            <div className="min-w-0">
              <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                {c.consultancy_service?.display_name ?? 'Consultation'} · {c.reference}
              </p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {c.assigned_consultant?.name ?? 'Unassigned'} · {formatDateTime(c.starts_at)}
              </p>
            </div>
            <span className="text-xs px-2 py-0.5 rounded-full font-medium whitespace-nowrap" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
              {ENGAGEMENT_LABELS[c.engagement_status ?? ''] ?? c.engagement_status ?? c.appointment_status}
            </span>
          </Link>
        ))}
      </div>
    </div>
  );
}

// ── Shared ──────────────────────────────────────────────────────────────────

function InfoRow({ label, value }: { label: string; value?: string | null }) {
  return (
    <div className="flex items-start gap-3 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
      <span className="text-xs min-w-[140px] pt-0.5" style={{ color: 'var(--text-muted)' }}>{label}</span>
      <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{value || '—'}</span>
    </div>
  );
}

function StatCard({
  label, value, icon: Icon, href, index = 0,
}: {
  label: string; value: number | string; icon: React.ElementType; href?: string; index?: number;
}) {
  const router = useRouter();
  const delay = index * 70;
  return (
    <div
      onClick={() => href && router.push(href)}
      className="ss-animate-in group rounded-xl px-4 py-3 flex items-center gap-3 transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] hover:-translate-y-0.5"
      style={{
        backgroundColor: 'var(--bg-surface)',
        cursor: href ? 'pointer' : 'default',
        border: '1px solid var(--border)',
        animationDelay: `${delay}ms`,
      }}
    >
      <div className="w-9 h-9 rounded-lg flex items-center justify-center transition-transform duration-200 group-hover:scale-110" style={{ backgroundColor: 'var(--gold-15)' }}>
        <Icon size={16} style={{ color: 'var(--gold)' }} />
      </div>
      <div>
        <div className="text-lg font-bold leading-none tabular-nums" style={{ color: 'var(--text-primary)' }}>
          {typeof value === 'number' ? <CountUp value={value} delay={delay} /> : value}
        </div>
        <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{label}</div>
      </div>
    </div>
  );
}

const ACTIVITY_ICONS: Record<string, React.ElementType> = {
  project_created:                  Activity,
  contract_added:                   FileText,
  contract_updated:                 FileText,
  payment_application_created:      DollarSign,
  payment_application_submitted:    DollarSign,
  payment_application_certified:    DollarSign,
  payment_application_paid:         DollarSign,
  pdf_generated:                    FileText,
  rfi_created:                      MessageSquare,
  variation_created:                GitBranch,
  variation_updated:                GitBranch,
  variation_submitted:              GitBranch,
  variation_instructed:             GitBranch,
  variation_quoted:                 GitBranch,
  variation_assessed:               GitBranch,
  variation_approved:               GitBranch,
  variation_rejected:               GitBranch,
  variation_resubmitted:            GitBranch,
};

// ─── Programme helpers ────────────────────────────────────────────────────────

/**
 * Whole-calendar-day difference between two dates, safe whether each side is
 * a bare "YYYY-MM-DD" string or a full ISO datetime — `ContractProgrammeMilestone`'s
 * `planned_date`/`forecast_date` are Eloquent `date` casts, which serialize
 * to JSON as e.g. `"2024-04-29T00:00:00.000000Z"`, not a bare date. The
 * previous `new Date(dateStr + 'T00:00:00')` pattern silently assumed the
 * latter and, given the former, built an invalid string
 * ("...000000ZT00:00:00") whose `getTime()` is `NaN` — the direct cause of
 * this widget's "NaNd remaining" and a second, silent bug in the "Due Soon"
 * health check below (`NaN >= 0` is always `false`). `.slice(0, 10)` takes
 * only the calendar-date portion before reparsing, exactly as
 * `DeadlineClassifier` does server-side — never diff `Date` instances
 * directly, always reparse from date-only strings first.
 */
function daysBetweenDateOnly(from: string, to: string): number {
  return Math.round((parseDateOnly(to.slice(0, 10)).getTime() - parseDateOnly(from.slice(0, 10)).getTime()) / 86_400_000);
}

function calcProgrammeHealth(milestones: any[], today: string): string {
  if (milestones.length === 0) return 'no_data';
  const active = milestones.filter((m: any) => m.status !== 'complete');
  if (active.some((m: any) => m.planned_date && m.planned_date < today)) return 'at_risk';
  if (milestones.some((m: any) => m.status === 'delayed' || m.status === 'at_risk')) return 'delayed';
  if (active.some((m: any) => {
    if (!m.planned_date) return false;
    const diff = daysBetweenDateOnly(today, m.planned_date);
    return diff >= 0 && diff <= 14;
  })) return 'due_soon';
  return 'on_track';
}

const PROG_HEALTH_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  on_track: { label: 'On Track',      bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  due_soon: { label: 'Due Soon',      bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  at_risk:  { label: 'At Risk',       bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  delayed:  { label: 'Delayed',       bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  no_data:  { label: 'No Milestones', bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

function ProgrammeOverviewWidget({ milestones, projectId }: { milestones: any[]; projectId: string }) {
  const router = useRouter();
  // Must agree with the backend's own organisation-timezone-aware "today"
  // (TimezoneResolver), not the UTC calendar day.
  const today = effectiveTodayYmd();
  const health = calcProgrammeHealth(milestones, today);
  const cfg = PROG_HEALTH_CONFIG[health];
  const overdue = milestones.filter((m: any) => m.status !== 'complete' && m.planned_date && m.planned_date < today).length;
  const next = milestones
    .filter((m: any) => m.status !== 'complete' && (m.planned_date || m.forecast_date))
    .sort((a: any, b: any) => (a.planned_date || a.forecast_date || '').localeCompare(b.planned_date || b.forecast_date || ''))[0] ?? null;
  const nextDate = next?.planned_date || next?.forecast_date;
  const daysToNext = nextDate ? daysBetweenDateOnly(today, nextDate) : null;

  return (
    <div className="ss-animate-in rounded-2xl p-5 flex flex-col gap-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <BarChart2 size={14} style={{ color: 'var(--text-muted)' }} />
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Programme</h2>
        </div>
        <button onClick={() => router.push(`/app/projects/${projectId}/programme`)}
          className="flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--gold)' }}>
          View <ChevronRight size={11} />
        </button>
      </div>

      <div className="flex items-center gap-3">
        <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold"
          style={{ backgroundColor: cfg.bg, color: cfg.text }}>
          <span style={{ width: 7, height: 7, borderRadius: '50%', backgroundColor: cfg.text, display: 'inline-block' }} />
          {cfg.label}
        </span>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{milestones.length} milestone{milestones.length !== 1 ? 's' : ''}</span>
        {overdue > 0 && (
          <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}>
            {overdue} overdue
          </span>
        )}
      </div>

      {next ? (
        <div className="rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <p className="text-xs mb-0.5" style={{ color: 'var(--text-muted)' }}>Next milestone</p>
          <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{next.name}</p>
          {nextDate && (
            <p className="text-xs mt-0.5" style={{ color: daysToNext !== null && daysToNext < 0 ? '#f87171' : daysToNext !== null && daysToNext <= 7 ? '#facc15' : 'var(--text-muted)' }}>
              {daysToNext === null
                ? formatDate(nextDate)
                : daysToNext < 0
                ? `Overdue by ${Math.abs(daysToNext)}d`
                : daysToNext === 0
                ? 'Due today'
                : `${formatDate(nextDate)} · ${daysToNext}d remaining`}
            </p>
          )}
        </div>
      ) : milestones.length === 0 ? (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No milestones. Seed from AI analysis on the Contracts page.</p>
      ) : (
        <p className="text-xs" style={{ color: '#4ade80' }}>All milestones complete.</p>
      )}
    </div>
  );
}

function ActivityFeed({ activities }: { activities: any[] }) {
  if (activities.length === 0) {
    return (
      <div className="text-center py-8">
        <Activity size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No activity yet</p>
      </div>
    );
  }
  return (
    <div className="space-y-0">
      {activities.slice(0, 8).map((a: any, i: number) => {
        const Icon = ACTIVITY_ICONS[a.activity_type] ?? Activity;
        return (
          <div key={a.id} className="flex gap-3 py-2.5" style={{ borderBottom: i < activities.length - 1 ? '1px solid var(--border)' : 'none' }}>
            <div className="w-7 h-7 rounded-lg flex-shrink-0 flex items-center justify-center mt-0.5" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <Icon size={13} style={{ color: 'var(--text-secondary)' }} />
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{a.title}</p>
              {a.description && <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{a.description}</p>}
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {a.user?.name} · {a.created_at ? formatDate(a.created_at) : ''}
              </p>
            </div>
          </div>
        );
      })}
    </div>
  );
}

export default function ProjectOverviewPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { isPlatformOperator, canOperate } = useProjectPermissions();
  const [editOpen, setEditOpen] = useState(false);

  const { data: project, isLoading } = useQuery({
    queryKey: ['project', id],
    queryFn: () => api.get(`/projects/${id}`).then(r => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000,
  });

  const { data: statsData } = useQuery({
    queryKey: ['project-stats', id],
    queryFn: () => api.get(`/projects/${id}/stats`).then(r => r.data).catch(() => null),
    enabled: !!id,
    staleTime: 2 * 60 * 1000,
  });

  const { data: activitiesData } = useQuery({
    queryKey: ['project-activities', id],
    queryFn: () => api.get(`/projects/${id}/activities`).then(r => r.data).catch(() => ({ data: [] })),
    enabled: !!id,
    staleTime: 1 * 60 * 1000,
  });

  const { data: programmeData } = useQuery<any[]>({
    queryKey: ['project-programme', id],
    queryFn: () => api.get(`/projects/${id}/programme`).then(r => r.data).catch(() => []),
    enabled: !!id,
    staleTime: 2 * 60 * 1000,
  });
  const milestones: any[] = programmeData ?? [];

  const { data: intelligence } = useQuery({
    queryKey: ['project-intelligence', id],
    queryFn: () => api.get(`/projects/${id}/dashboard-intelligence`).then(r => r.data).catch(() => null),
    enabled: !!id,
    staleTime: 2 * 60 * 1000,
  });

  const projectHealth   = intelligence?.project_health ?? null;
  const upcomingActions = intelligence?.upcoming_actions ?? null;
  const riskSummary     = intelligence?.risk_summary ?? null;
  const finalAccounts   = intelligence?.final_accounts ?? [];
  const approvedNotIncluded = intelligence?.commercial?.approved_not_included_count ?? 0;
  const approvedNotIncludedValue = intelligence?.commercial?.approved_not_included_value ?? 0;

  const activities: any[] = activitiesData?.data ?? [];

  if (isLoading) {
    return (
      <div className="p-6 max-w-6xl mx-auto space-y-6">
        <div className="flex items-start justify-between">
          <div className="space-y-2">
            <div className="h-7 w-64 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
            <div className="h-4 w-40 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          </div>
          <div className="h-6 w-20 rounded-full animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        </div>
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
        <div className="grid lg:grid-cols-2 gap-5">
          {[...Array(2)].map((_, i) => (
            <div key={i} className="rounded-2xl p-5 space-y-3 h-64 animate-pulse" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }} />
          ))}
        </div>
      </div>
    );
  }

  const statusColors: Record<string, { bg: string; text: string }> = {
    active:    { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
    on_hold:   { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
    completed: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
    cancelled: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  };
  const badge = statusColors[project?.status] ?? { bg: 'rgba(90,86,82,0.2)', text: '#9a9490' };

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>
              {project?.name}
            </h1>
            <PageTourButton tourKey="page-project-overview" label="Take a tour of this page" />
          </div>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            {project?.code} {project?.type ? `· ${project.type}` : ''}
          </p>
          {(project?.organization?.name || project?.client?.name) && (
            <p className="mt-1 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
              Company: {project?.organization?.name ?? project?.client?.name}
            </p>
          )}
        </div>
        <div className="flex items-center gap-2 flex-shrink-0">
          <span
            className="px-3 py-1 rounded-full text-xs font-medium capitalize"
            style={{ backgroundColor: badge.bg, color: badge.text }}
          >
            {project?.status?.replace(/_/g, ' ')}
          </span>
          {canOperate && (
            <button
              onClick={() => setEditOpen(true)}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors hover:bg-[var(--bg-hover)]"
              style={{ borderColor: 'var(--border)', color: 'var(--text-secondary)' }}
            >
              <Pencil size={12} /> Edit Project
            </button>
          )}
        </div>
      </div>

      {editOpen && project && (
        <EditProjectModal project={project} projectId={id} onClose={() => setEditOpen(false)} />
      )}

      {/* Clickable stat cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3" data-tour="overview-stats">
        <StatCard label="Open RFIs"          value={statsData?.open_rfis ?? 0}          icon={MessageSquare} href={`/app/projects/${id}/rfis?status=open`} index={0} />
        <StatCard label="Pending Variations"  value={statsData?.pending_variations ?? 0}  icon={GitBranch}     href={`/app/projects/${id}/variations?status=pending`} index={1} />
        <StatCard label="Payment Apps"        value={statsData?.payment_apps ?? 0}        icon={DollarSign}    href={`/app/projects/${id}/commercial`} index={2} />
        <StatCard label="Open Snagging"       value={statsData?.open_snagging ?? 0}       icon={AlertCircle}   href={`/app/projects/${id}/snagging`} index={3} />
      </div>

      {/* Operational intelligence — health + actions (most urgent first) */}
      <div className="grid lg:grid-cols-2 gap-5">
        <ProjectHealthWidget health={projectHealth} />
        <UpcomingActionsWidget actions={upcomingActions} />
      </div>

      {/* Approved-not-included variation alert */}
      {approvedNotIncluded > 0 && (
        <div className="flex items-center gap-3 p-3.5 rounded-xl"
          style={{ backgroundColor: 'rgba(249,115,22,0.06)', border: '1px solid rgba(249,115,22,0.2)' }}>
          <AlertCircle size={15} style={{ color: '#fb923c', flexShrink: 0 }} />
          <p className="text-sm" style={{ color: '#fb923c' }}>
            <span className="font-semibold">{approvedNotIncluded} approved variation{approvedNotIncluded > 1 ? 's' : ''}</span>
            {approvedNotIncludedValue > 0 && ` (${formatCurrency(approvedNotIncludedValue)})`} not yet included in a payment application.
          </p>
        </div>
      )}

      {/* Programme & Variation overview */}
      <div className="grid lg:grid-cols-2 gap-5">
        <div data-tour="overview-programme">
          <ProgrammeOverviewWidget milestones={milestones} projectId={id!} />
        </div>
        <div className="rounded-2xl p-5" data-tour="overview-variations" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="flex items-center justify-between mb-4">
            <div className="flex items-center gap-2">
              <GitBranch size={14} style={{ color: 'var(--text-muted)' }} />
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Variations</h2>
            </div>
            <button onClick={() => router.push(`/app/projects/${id}/variations`)}
              className="flex items-center gap-1 text-xs px-2.5 py-1 rounded-lg hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--gold)' }}>
              View <ChevronRight size={11} />
            </button>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div className="rounded-xl p-3 text-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <p className="text-xl font-bold tabular-nums" style={{ color: 'var(--gold)' }}>{statsData?.pending_variations ?? 0}</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Pending</p>
            </div>
            <div className="rounded-xl p-3 text-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <p className="text-xl font-bold tabular-nums" style={{ color: '#4ade80' }}>{statsData?.approved_variations ?? 0}</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Approved</p>
            </div>
            <div className="rounded-xl p-3 text-center col-span-2" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <p className="text-xl font-bold tabular-nums" style={{ color: '#fb923c' }}>{statsData?.variation_programme_days ?? 0}d</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Programme impact</p>
            </div>
          </div>
        </div>
      </div>

      {/* Final Account */}
      <FinalAccountsWidget finalAccounts={finalAccounts} projectId={id!} formatCurrency={formatCurrency} />

      {/* Contract value + certified summary */}
      {(statsData?.contract_value || statsData?.total_certified) && (
        <div className="grid grid-cols-2 gap-4">
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Contract value</p>
            <p className="text-2xl font-bold mt-1 tabular-nums" style={{ color: 'var(--gold)' }}>{formatCurrency(statsData?.contract_value ?? 0)}</p>
          </div>
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total certified</p>
            <p className="text-2xl font-bold mt-1 tabular-nums" style={{ color: '#4ade80' }}>{formatCurrency(statsData?.total_certified ?? 0)}</p>
          </div>
        </div>
      )}

      <div className="grid lg:grid-cols-2 gap-5">
        {/* Project details */}
        <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Project details</h2>
          <InfoRow label="Project Name"    value={project?.name} />
          <InfoRow label="Project Number"  value={project?.code} />
          <InfoRow label="Type of Work"    value={project?.type} />
          <InfoRow label="Contract Type"   value={project?.contract_type} />
          <InfoRow label="Contract Value"  value={project?.contract_value ? formatCurrency(project.contract_value) : null} />
          <InfoRow label="Start Date"      value={project?.start_date ? formatDate(project.start_date) : null} />
          <InfoRow label="Completion"      value={project?.end_date ? formatDate(project.end_date) : null} />
          <InfoRow label="Address"         value={[project?.address, project?.city, project?.state, project?.postcode].filter(Boolean).join(', ')} />
        </div>

        {/* Client info */}
        <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Client information</h2>
          {project?.client ? (
            <>
              <InfoRow label="Client Name"    value={project.client.name} />
              <InfoRow label="Contact Name"   value={project.client.contact_name} />
              <InfoRow label="Email"          value={project.client.email} />
              <InfoRow label="Phone"          value={project.client.phone} />
              <InfoRow label="Address"        value={project.client.address} />
            </>
          ) : (
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No client linked to this project.</p>
          )}
        </div>
      </div>

      {/* Site Location — reuses the project data already loaded above; no
          extra request. Coordinates power the embedded map/external link;
          address is displayed as recorded, never geocoded from/into either. */}
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Site Location</h2>
          {canOperate && (
            <button
              onClick={() => setEditOpen(true)}
              className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium border transition-colors hover:bg-[var(--bg-hover)]"
              style={{ borderColor: 'var(--border)', color: 'var(--text-secondary)' }}
            >
              <Pencil size={11} /> Edit Project
            </button>
          )}
        </div>

        {project?.latitude != null && project?.longitude != null ? (
          <div className="grid md:grid-cols-2 gap-5">
            <div className="rounded-xl overflow-hidden" style={{ height: 260, border: '1px solid var(--border)' }}>
              <SiteLocationMap latitude={Number(project.latitude)} longitude={Number(project.longitude)} />
            </div>
            <div className="flex flex-col justify-between gap-4 min-w-0">
              <div className="space-y-3 min-w-0">
                {(project?.address || project?.city || project?.country) && (
                  <div className="min-w-0">
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Project address</p>
                    <p className="text-sm mt-0.5 break-words" style={{ color: 'var(--text-primary)' }}>
                      {project?.address && <span>{project.address}<br /></span>}
                      {[project?.city, project?.state, project?.postcode, project?.country].filter(Boolean).join(', ')}
                    </p>
                  </div>
                )}
                <div className="min-w-0">
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Coordinates</p>
                  <p className="text-sm mt-0.5 tabular-nums break-all" style={{ color: 'var(--text-primary)' }}>
                    {Number(project.latitude).toFixed(7)}, {Number(project.longitude).toFixed(7)}
                  </p>
                </div>
              </div>

              <div className="flex flex-wrap gap-2">
                <a
                  href={googleMapsUrl(Number(project.latitude), Number(project.longitude))}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:bg-[var(--bg-hover)]"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                >
                  Open in Google Maps <ExternalLink size={11} />
                </a>
                <button
                  onClick={async () => {
                    const ok = await copyToClipboard(`${Number(project.latitude).toFixed(7)}, ${Number(project.longitude).toFixed(7)}`);
                    toast[ok ? 'success' : 'error'](ok ? 'Coordinates copied' : 'Could not copy coordinates');
                  }}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:bg-[var(--bg-hover)]"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                >
                  <Copy size={11} /> Copy coordinates
                </button>
              </div>
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center justify-center text-center px-6 py-10 gap-3">
            <div className="w-11 h-11 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <MapPin size={20} style={{ color: 'var(--text-muted)' }} />
            </div>
            <div className="space-y-1">
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>No project location has been added yet</p>
              <p className="text-xs max-w-xs" style={{ color: 'var(--text-muted)' }}>Add geographic coordinates to view this project on the map.</p>
            </div>
            {canOperate && (
              <button
                onClick={() => setEditOpen(true)}
                className="mt-1 flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:bg-[var(--bg-hover)]"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              >
                <Pencil size={11} /> Edit Project
              </button>
            )}
          </div>
        )}
      </div>

      {/* Consultancy — Super Admin/Admin only (see ConsultancyWidget docblock) */}
      {isPlatformOperator && <ConsultancyWidget projectId={id!} />}

      {/* Risk summary (only shown when risks exist) */}
      <RiskSummaryWidget riskSummary={riskSummary} />

      {/* Activity timeline */}
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Recent activity</h2>
          <Activity size={14} style={{ color: 'var(--text-muted)' }} />
        </div>
        <ActivityFeed activities={activities} />
      </div>

      {/* Description */}
      {project?.description && (
        <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Description</h2>
          <p className="text-sm leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
            {project.description}
          </p>
        </div>
      )}
    </div>
  );
}

