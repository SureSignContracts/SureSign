'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import CountUp from '@/components/ui/CountUp';
import {
  CalendarDays, Plus, Sparkles, X, ChevronDown, Check,
  AlertTriangle, Clock, BarChart2, List, Info,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PageTourButton from '@/components/tours/PageTourButton';

// ─── Constants ───────────────────────────────────────────────────────────────

const MILESTONE_TYPES: Record<string, string> = {
  commencement:         'Commencement',
  sectional_completion: 'Sectional Completion',
  completion:           'Completion',
  handover:             'Handover',
  obligation:           'Obligation',
  milestone:            'Milestone',
  other:                'Other',
};

const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  not_started: { label: 'Not started', bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  in_progress: { label: 'In progress', bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  complete:    { label: 'Complete',    bg: 'rgba(34,197,94,0.12)',   text: '#4ade80' },
  delayed:     { label: 'Delayed',     bg: 'rgba(239,68,68,0.12)',   text: '#f87171' },
  at_risk:     { label: 'At risk',     bg: 'rgba(249,115,22,0.12)',  text: '#fb923c' },
};

const HEALTH_CONFIG: Record<string, { label: string; bg: string; text: string; dot: string }> = {
  on_track: { label: 'On Track',          bg: 'rgba(34,197,94,0.12)',  text: '#4ade80',  dot: '#4ade80' },
  due_soon: { label: 'Due soon',          bg: 'rgba(234,179,8,0.12)',  text: '#facc15',  dot: '#facc15' },
  at_risk:  { label: 'At risk',           bg: 'rgba(239,68,68,0.12)',  text: '#f87171',  dot: '#f87171' },
  delayed:  { label: 'Delayed',           bg: 'rgba(249,115,22,0.12)', text: '#fb923c',  dot: '#fb923c' },
  no_data:  { label: 'No Programme Data', bg: 'rgba(90,86,82,0.2)',    text: '#9a9490',  dot: '#9a9490' },
};

// ─── Types ────────────────────────────────────────────────────────────────────

type Milestone = {
  id: number;
  name: string;
  milestone_type: string;
  responsible_party: string;
  status: string;

  // ── Current fields (milestones) ───────────────────────────────────────────
  planned_date:  string | null;
  forecast_date: string | null;
  actual_date:   string | null;

  // ── Activity-level programme fields (Sprint 5 Programme Foundation) ───────
  // Live on the backend now — see contract_programme_milestones migration.
  // Still optional here: existing plain-milestone rows simply don't populate
  // them, and the timeline renderer already tolerates null/undefined.
  planned_start?:   string | null; // planned activity start date
  planned_finish?:  string | null; // alias for planned_date when activities have duration
  forecast_start?:  string | null; // revised start forecast
  forecast_finish?: string | null; // revised finish forecast
  actual_start?:    string | null; // recorded start
  actual_finish?:   string | null; // recorded finish (= actual_date for milestones)
  duration_days?:   number | null; // planned duration in days
  progress_pct?:    number | null; // 0–100
  depends_on?:      number[] | null; // IDs of predecessor milestones/activities
  group_name?:      string | null; // "Block A" / "Block B" etc. for section grouping

  source_text: string | null;
  notes: string | null;
  is_ai_generated: boolean;
  contract?: { id: number; title: string; reference_number?: string | null };
  trade_package?: { id: number; name: string } | null;
};

type ContractOption = { id: number; title: string; reference_number?: string | null };

// ─── Helpers ──────────────────────────────────────────────────────────────────

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * Parse a date string that may be a bare date ("2026-04-30") OR a full ISO
 * timestamp ("2026-04-30T00:00:00.000000Z") returned by the Laravel API.
 * Always interpret as local midnight so all comparisons are consistent.
 */
function dateMs(d: string): number {
  return new Date(d.slice(0, 10) + 'T00:00:00').getTime();
}

function daysDiff(from: string, to: string): number {
  const ms = dateMs(to) - dateMs(from);
  if (isNaN(ms)) return 0;
  return Math.round(ms / 86_400_000);
}

function calcVariance(m: Milestone): number | null {
  const base = m.planned_date;
  if (!base) return null;
  const compare = m.actual_date ?? m.forecast_date;
  if (!compare) return null;
  return daysDiff(base, compare);
}

function calcHealth(milestones: Milestone[]): string {
  if (milestones.length === 0) return 'no_data';
  const now = today();
  const active = milestones.filter(m => m.status !== 'complete');
  if (active.some(m => m.planned_date && m.planned_date < now)) return 'at_risk';
  if (milestones.some(m => m.status === 'delayed' || m.status === 'at_risk')) return 'delayed';
  if (active.some(m => {
    if (!m.planned_date) return false;
    const d = daysDiff(now, m.planned_date);
    return d >= 0 && d <= 14;
  })) return 'due_soon';
  return 'on_track';
}

function nextCritical(milestones: Milestone[]): Milestone | null {
  return milestones
    .filter(m => m.status !== 'complete' && (m.planned_date || m.forecast_date))
    .sort((a, b) =>
      (a.planned_date || a.forecast_date || '').localeCompare(b.planned_date || b.forecast_date || ''),
    )[0] ?? null;
}

// ─── VarianceCell ─────────────────────────────────────────────────────────────

function VarianceCell({ m }: { m: Milestone }) {
  const v = calcVariance(m);
  if (v === null) return <span style={{ color: 'var(--text-muted)' }}>—</span>;
  if (v === 0) return <span style={{ color: '#4ade80' }}>On time</span>;
  const isDelay = v > 0;
  return (
    <span style={{ color: isDelay ? '#f87171' : '#4ade80' }}>
      {isDelay ? '+' : ''}{v}d
    </span>
  );
}

// ─── Next Critical Milestone card ─────────────────────────────────────────────

function NextMilestoneCard({ milestones }: { milestones: Milestone[] }) {
  const next = nextCritical(milestones);
  const now = today();

  if (!next) {
    if (milestones.length > 0) {
      return (
        <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Next critical milestone</p>
          <p className="text-sm" style={{ color: '#4ade80' }}>All milestones complete.</p>
        </div>
      );
    }
    return null;
  }

  const targetDate = next.planned_date || next.forecast_date;
  const daysRemaining = targetDate ? daysDiff(now, targetDate) : null;
  const isOverdue = daysRemaining !== null && daysRemaining < 0;

  return (
    <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 mb-3">
        <Clock size={14} style={{ color: 'var(--text-muted)' }} />
        <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Next critical milestone</p>
      </div>
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="font-semibold text-sm truncate" style={{ color: 'var(--text-primary)' }}>{next.name}</p>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-secondary)' }}>
            {MILESTONE_TYPES[next.milestone_type] ?? next.milestone_type}
            {next.contract && <> · {next.contract.title}</>}
          </p>
          {targetDate && (
            <p className="text-xs mt-1">
              <span style={{ color: 'var(--text-muted)' }}>Target: </span>
              <span style={{ color: isOverdue ? '#f87171' : 'var(--text-secondary)' }}>
                {formatDate(targetDate)}
              </span>
            </p>
          )}
        </div>
        <div className="flex-shrink-0 text-right">
          {daysRemaining !== null && (
            <div
              className="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold"
              style={
                isOverdue
                  ? { backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' }
                  : daysRemaining <= 14
                  ? { backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15' }
                  : { backgroundColor: 'rgba(34,197,94,0.12)', color: '#4ade80' }
              }
            >
              {isOverdue
                ? `Overdue by ${Math.abs(daysRemaining)}d`
                : daysRemaining === 0
                ? 'Due today'
                : `${daysRemaining}d remaining`}
            </div>
          )}
          <div className="mt-1">
            <span
              className="text-xs px-2 py-0.5 rounded-full"
              style={{
                backgroundColor: STATUS_CONFIG[next.status]?.bg ?? 'var(--bg-elevated)',
                color: STATUS_CONFIG[next.status]?.text ?? 'var(--text-muted)',
              }}
            >
              {STATUS_CONFIG[next.status]?.label ?? next.status}
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}

// ─── Programme Health Summary ─────────────────────────────────────────────────

function HealthSummary({ milestones }: { milestones: Milestone[] }) {
  const now = today();
  const health = calcHealth(milestones);
  const cfg = HEALTH_CONFIG[health];

  const completed  = milestones.filter(m => m.status === 'complete').length;
  const overdue    = milestones.filter(m => m.status !== 'complete' && m.planned_date && m.planned_date < now).length;
  const dueSoon    = milestones.filter(m => {
    if (m.status === 'complete' || !m.planned_date) return false;
    const d = daysDiff(now, m.planned_date);
    return d >= 0 && d <= 14;
  }).length;
  const atRisk     = milestones.filter(m => m.status === 'delayed' || m.status === 'at_risk').length;
  const aiCount    = milestones.filter(m => m.is_ai_generated).length;

  return (
    <div className="ss-animate-in rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center justify-between mb-4">
        <div className="flex items-center gap-2">
          <BarChart2 size={14} style={{ color: 'var(--text-muted)' }} />
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Programme health</h2>
        </div>
        <span
          className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold"
          style={{ backgroundColor: cfg.bg, color: cfg.text }}
        >
          <span style={{ width: 7, height: 7, borderRadius: '50%', backgroundColor: cfg.dot, display: 'inline-block' }} />
          {cfg.label}
        </span>
      </div>
      <div className="grid grid-cols-3 sm:grid-cols-6 gap-3">
        {[
          { label: 'Total',     value: milestones.length, color: 'var(--gold)' },
          { label: 'Complete',  value: completed,         color: '#4ade80' },
          { label: 'Overdue',   value: overdue,           color: '#f87171' },
          { label: 'Due soon',  value: dueSoon,           color: '#facc15' },
          { label: 'At risk',   value: atRisk,            color: '#fb923c' },
          { label: 'AI generated', value: aiCount,        color: 'var(--text-secondary)' },
        ].map((s, i) => (
          <div key={s.label} className="ss-animate-in text-center rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)', animationDelay: `${i * 60}ms` }}>
            <p className="text-lg font-bold leading-none tabular-nums" style={{ color: s.color }}>
              <CountUp value={s.value} delay={i * 60} />
            </p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{s.label}</p>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Programme Timeline / Gantt-Style View ───────────────────────────────────

const LABEL_W = 260; // px — sticky left column (fixed pixels, not %)

function rowAccentColor(m: Milestone, now: string): string {
  if (m.status === 'complete') return '#4ade80';
  if (m.planned_date && m.planned_date < now) return '#f87171';
  const target = m.planned_date || m.forecast_date;
  if (target) {
    const d = daysDiff(now, target);
    if (d >= 0 && d <= 14) return '#facc15'; // due soon
  }
  return STATUS_CONFIG[m.status]?.text ?? '#9a9490';
}

function TimelineView({ milestones }: { milestones: Milestone[] }) {
  const withDates = milestones.filter(m => m.planned_date || m.forecast_date || m.actual_date);
  const noDates   = milestones.filter(m => !m.planned_date && !m.forecast_date && !m.actual_date);
  const now = today();

  if (withDates.length === 0) {
    return (
      <div className="rounded-2xl text-center py-12"
        style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
        <BarChart2 size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No milestones with dates to display.</p>
        {noDates.length > 0 && (
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {noDates.length} milestone{noDates.length > 1 ? 's' : ''} have no dates set.
          </p>
        )}
      </div>
    );
  }

  // ── Date range: collect all milestone dates, add 14-day padding ─────────────
  const allDates = withDates.flatMap(m =>
    [m.planned_date, m.forecast_date, m.actual_date].filter(Boolean) as string[],
  );
  const rawMin = allDates.reduce((a, b) => (a < b ? a : b));
  const rawMax = allDates.reduce((a, b) => (a > b ? a : b));

  // Use dateMs() everywhere — handles bare "YYYY-MM-DD" and full ISO timestamps
  // Asymmetric padding: 14-day lead-in so the first milestone isn't flush to the left,
  // but only 5-day tail so there's no wasted space after the last milestone.
  const PAD_START_MS = 14 * 86_400_000;
  const PAD_END_MS   =  5 * 86_400_000;
  const startMs     = dateMs(rawMin) - PAD_START_MS;
  const endMs       = dateMs(rawMax) + PAD_END_MS;
  const spanMs      = endMs - startMs; // always > 0
  const rawSpanDays = Math.round((dateMs(rawMax) - dateMs(rawMin)) / 86_400_000);

  // ── CHART_W: explicit pixel width.  Markers use left: Xpx (never %) ─────────
  const CHART_W = Math.max(800, Math.min(2400, rawSpanDays * 8));
  const TOTAL_W = LABEL_W + CHART_W;
  const HEADER_H = 44;
  const ROW_H    = 72;

  // Convert a date string → pixel offset within the CHART_W area
  const toX = (d: string): number => {
    const ratio = (dateMs(d) - startMs) / spanMs;
    return Math.max(0, Math.min(CHART_W, Math.round(ratio * CHART_W)));
  };

  // ── Month ticks ─────────────────────────────────────────────────────────────
  type MonthTick = { label: string; x: number };
  const monthTicks: MonthTick[] = [];
  {
    const mc = new Date(startMs);
    mc.setDate(1); mc.setHours(0, 0, 0, 0);
    // step forward to include the month containing startMs
    while (mc.getTime() <= endMs) {
      const x = Math.round(((mc.getTime() - startMs) / spanMs) * CHART_W);
      monthTicks.push({
        label: mc.toLocaleString('default', { month: 'short', year: '2-digit' }),
        x: Math.max(0, Math.min(CHART_W, x)),
      });
      mc.setMonth(mc.getMonth() + 1);
    }
  }

  // ── Weekly ticks for short programmes ──────────────────────────────────────
  const weekTicksX: number[] = [];
  if (rawSpanDays <= 120) {
    const wc = new Date(startMs);
    const wd = wc.getDay();
    // align to Monday
    wc.setDate(wc.getDate() + (wd === 1 ? 0 : wd === 0 ? 1 : 8 - wd));
    wc.setHours(0, 0, 0, 0);
    while (wc.getTime() <= endMs) {
      const x = Math.round(((wc.getTime() - startMs) / spanMs) * CHART_W);
      if (x >= 0 && x <= CHART_W) weekTicksX.push(x);
      wc.setDate(wc.getDate() + 7);
    }
  }

  // ── Today pixel position ────────────────────────────────────────────────────
  const todayX = toX(now);
  const todayVisible = todayX >= 0 && todayX <= CHART_W;

  const hasForecasts = withDates.some(m => m.forecast_date);
  const hasActuals   = withDates.some(m => m.actual_date);
  const hasOverdue   = withDates.some(m => m.status !== 'complete' && !!m.planned_date && m.planned_date < now);

  return (
    <div className="rounded-2xl overflow-hidden"
      style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>

      {/* ── Title bar ──────────────────────────────────────────────────────── */}
      <div style={{
        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        flexWrap: 'wrap', gap: '4px 16px',
        padding: '10px 20px', borderBottom: '1px solid var(--border)',
        backgroundColor: 'var(--bg-elevated)',
      }}>
        <div>
          <span style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-primary)' }}>
            Programme Milestone Timeline
          </span>
          <span style={{ fontSize: 11, fontWeight: 400, marginLeft: 10, color: 'var(--text-muted)' }}>
            {formatDate(rawMin)} — {formatDate(rawMax)}
            <span style={{ marginLeft: 6 }}>· {rawSpanDays}d</span>
          </span>
        </div>
      </div>

      {/* ── Legend bar ─────────────────────────────────────────────────────── */}
      {/*   Always visible; shows only the symbols that are present in this data */}
      <div style={{
        display: 'flex', alignItems: 'center', flexWrap: 'wrap',
        gap: '6px 20px', padding: '7px 20px',
        borderBottom: '2px solid var(--border)',
        backgroundColor: 'var(--bg-surface)',
      }}>

        {/* ○ Planned Date */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
          <svg width="14" height="14" viewBox="0 0 14 14" style={{ flexShrink: 0 }}>
            <circle cx="7" cy="7" r="5.5" fill="none" stroke="#9a9490" strokeWidth="2" />
          </svg>
          <span style={{ fontSize: 11, color: 'var(--text-secondary)', fontWeight: 500 }}>Planned date</span>
        </div>

        {/* ◆ Forecast Date */}
        {hasForecasts && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
            <svg width="12" height="12" viewBox="0 0 12 12" style={{ flexShrink: 0 }}>
              <rect x="1.5" y="1.5" width="9" height="9" fill="#facc15" transform="rotate(45 6 6)" rx="1" />
            </svg>
            <span style={{ fontSize: 11, color: 'var(--text-secondary)', fontWeight: 500 }}>Forecast date</span>
          </div>
        )}

        {/* ● Actual Date */}
        {hasActuals && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
            <svg width="14" height="14" viewBox="0 0 14 14" style={{ flexShrink: 0 }}>
              <circle cx="7" cy="7" r="5.5" fill="#4ade80" />
            </svg>
            <span style={{ fontSize: 11, color: 'var(--text-secondary)', fontWeight: 500 }}>Actual date</span>
          </div>
        )}

        {/* -- Date slip connector */}
        {hasForecasts && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
            <svg width="22" height="4" viewBox="0 0 22 4" style={{ flexShrink: 0 }}>
              <line x1="0" y1="2" x2="22" y2="2" stroke="rgba(250,204,21,0.6)" strokeWidth="2" strokeDasharray="4 3" />
            </svg>
            <span style={{ fontSize: 11, color: 'var(--text-secondary)', fontWeight: 500 }}>Date Slip</span>
          </div>
        )}

        {/* │ Today line */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
          <svg width="4" height="14" viewBox="0 0 4 14" style={{ flexShrink: 0 }}>
            <rect x="1" y="0" width="2" height="14" fill="rgba(250,204,21,0.75)" rx="1" />
          </svg>
          <span style={{ fontSize: 11, color: 'var(--text-secondary)', fontWeight: 500 }}>Today</span>
        </div>

        {/* ⚠ Overdue — only shown when at least one overdue milestone exists */}
        {hasOverdue && (
          <div style={{ display: 'flex', alignItems: 'center', gap: 7 }}>
            <svg width="14" height="14" viewBox="0 0 14 14" style={{ flexShrink: 0 }}>
              <circle cx="7" cy="7" r="5.5" fill="none" stroke="#f87171" strokeWidth="2" />
            </svg>
            <span style={{ fontSize: 11, color: '#f87171', fontWeight: 500 }}>Overdue (planned passed)</span>
          </div>
        )}

        {/* Future: when duration_days is present, a bar legend will appear here */}
      </div>

      {/* ── Scrollable chart area ─────────────────────────────────────────── */}
      {/*   overflow-x: auto on this element; content has explicit pixel width  */}
      <div style={{ overflowX: 'auto', overflowY: 'visible' }}>
        {/* Inner container: exactly TOTAL_W px wide. */}
        <div style={{ width: TOTAL_W, minWidth: TOTAL_W }}>

          {/* ── Month header row ─────────────────────────────────────────── */}
          <div style={{
            display: 'flex', height: HEADER_H,
            borderBottom: '2px solid var(--border)',
            backgroundColor: 'var(--bg-elevated)',
          }}>
            {/* Sticky label header */}
            <div style={{
              width: LABEL_W, flexShrink: 0,
              display: 'flex', alignItems: 'center', paddingLeft: 16,
              position: 'sticky', left: 0, zIndex: 20,
              backgroundColor: 'var(--bg-elevated)',
              borderRight: '2px solid var(--border)',
            }}>
              <span style={{
                fontSize: 10, fontWeight: 700, textTransform: 'uppercase',
                letterSpacing: '0.06em', color: 'var(--text-muted)',
              }}>
                Milestone
              </span>
            </div>

            {/* Month ruler — fixed pixel width, absolute-positioned labels */}
            <div style={{ width: CHART_W, flexShrink: 0, position: 'relative', overflow: 'hidden' }}>
              {monthTicks.map((mt, i) => (
                <div key={i} style={{
                  position: 'absolute', left: mt.x, top: 0, bottom: 0,
                  display: 'flex', alignItems: 'center', paddingLeft: 6,
                  borderLeft: i > 0 ? '1px solid var(--border)' : 'none',
                }}>
                  <span style={{
                    fontSize: 11, fontWeight: 700,
                    color: 'var(--text-secondary)', whiteSpace: 'nowrap',
                    userSelect: 'none',
                  }}>
                    {mt.label}
                  </span>
                </div>
              ))}

              {/* TODAY chip in header */}
              {todayVisible && (
                <div style={{
                  position: 'absolute', bottom: 5,
                  left: todayX, transform: 'translateX(-50%)',
                  zIndex: 10, pointerEvents: 'none',
                }}>
                  <span style={{
                    display: 'inline-block',
                    fontSize: 9, fontWeight: 800, letterSpacing: '0.04em',
                    color: '#facc15',
                    backgroundColor: 'rgba(250,204,21,0.12)',
                    padding: '1px 5px', borderRadius: 4,
                    whiteSpace: 'nowrap',
                  }}>
                    TODAY
                  </span>
                </div>
              )}
            </div>
          </div>

          {/* ── Milestone rows ────────────────────────────────────────────── */}
          {/* TODO (activity-level programme): group rows by m.group_name so
              "Block A", "Block B", "Block C" render as collapsible section headers
              above their activity rows. Add a `group_name` string column to the
              contract_programme_milestones table when that feature is built. */}
          {withDates.map((m, rowIdx) => {
            const accent     = rowAccentColor(m, now);
            const plannedX   = m.planned_date  ? toX(m.planned_date)  : null;
            const forecastX  = m.forecast_date ? toX(m.forecast_date) : null;
            const actualX    = m.actual_date   ? toX(m.actual_date)   : null;
            const primaryX   = actualX ?? forecastX ?? plannedX;
            const targetDate = m.actual_date ?? m.forecast_date ?? m.planned_date;
            const variance   = calcVariance(m);
            const isOverdue  = m.status !== 'complete' && !!m.planned_date && m.planned_date < now;
            const isDueSoon  = !isOverdue && (() => {
              const t = m.planned_date || m.forecast_date;
              if (!t || m.status === 'complete') return false;
              return daysDiff(now, t) >= 0 && daysDiff(now, t) <= 14;
            })();

            // Alternate row backgrounds — applied to BOTH label and chart cells
            const rowBg = rowIdx % 2 === 0
              ? 'var(--bg-surface)'
              : 'rgba(255,255,255,0.018)';

            return (
              <div key={m.id} style={{ display: 'flex', height: ROW_H, borderBottom: '1px solid var(--border)' }}>

                {/* ── Sticky label cell ─────────────────────────────────── */}
                <div style={{
                  width: LABEL_W, flexShrink: 0,
                  display: 'flex', flexDirection: 'column', justifyContent: 'center',
                  paddingLeft: 18, paddingRight: 10,
                  position: 'sticky', left: 0, zIndex: 10,
                  backgroundColor: rowBg,
                  borderRight: '2px solid var(--border)',
                }}>
                  {/* Urgency accent bar */}
                  <div style={{
                    position: 'absolute', left: 0, top: 10, bottom: 10,
                    width: 3, borderRadius: 2, backgroundColor: accent,
                  }} />

                  {/* Name */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 4, marginBottom: 2 }}>
                    {m.is_ai_generated && <Sparkles size={9} style={{ color: 'var(--gold)', flexShrink: 0 }} />}
                    <span
                      title={m.name}
                      style={{
                        fontSize: 12, fontWeight: 600, lineHeight: 1.2,
                        color: isOverdue ? '#f87171' : 'var(--text-primary)',
                        overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
                        maxWidth: LABEL_W - 38,
                      }}>
                      {m.name}
                    </span>
                  </div>

                  {/* Type · contract */}
                  <span
                    title={m.contract?.title}
                    style={{
                      fontSize: 10, color: 'var(--text-muted)', lineHeight: 1.3,
                      overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap',
                      maxWidth: LABEL_W - 22, marginBottom: 4,
                    }}>
                    {MILESTONE_TYPES[m.milestone_type] ?? m.milestone_type}
                    {m.contract ? ` · ${m.contract.title}` : ''}
                  </span>

                  {/* Status + variance flags */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: 5, flexWrap: 'wrap' }}>
                    <span style={{
                      fontSize: 10, padding: '1px 5px', borderRadius: 9999,
                      backgroundColor: STATUS_CONFIG[m.status]?.bg ?? 'var(--bg-elevated)',
                      color: STATUS_CONFIG[m.status]?.text ?? '#9a9490',
                    }}>
                      {STATUS_CONFIG[m.status]?.label ?? m.status}
                    </span>
                    {variance !== null && variance !== 0 && (
                      <span style={{ fontSize: 10, fontWeight: 700, color: variance > 0 ? '#f87171' : '#4ade80' }}>
                        {variance > 0 ? '+' : ''}{variance}d
                      </span>
                    )}
                    {isOverdue && (
                      <span style={{ fontSize: 10, color: '#f87171', display: 'flex', alignItems: 'center', gap: 2 }}>
                        <AlertTriangle size={9} style={{ flexShrink: 0 }} />Overdue
                      </span>
                    )}
                    {isDueSoon && (
                      <span style={{ fontSize: 10, color: '#facc15' }}>Due soon</span>
                    )}
                  </div>
                </div>

                {/* ── Chart track cell ──────────────────────────────────── */}
                {/* Explicit pixel width = CHART_W; absolute children use left: Xpx */}
                <div style={{
                  width: CHART_W, flexShrink: 0,
                  position: 'relative',
                  backgroundColor: rowBg,
                }}>

                  {/* Week guide ticks */}
                  {weekTicksX.map((x, i) => (
                    <div key={i} style={{
                      position: 'absolute', left: x, top: 0, bottom: 0, width: 1,
                      backgroundColor: 'rgba(255,255,255,0.03)', pointerEvents: 'none',
                    }} />
                  ))}

                  {/* Month grid lines */}
                  {monthTicks.map((mt, i) => (
                    <div key={i} style={{
                      position: 'absolute', left: mt.x, top: 0, bottom: 0, width: 1,
                      backgroundColor: 'var(--border)', pointerEvents: 'none',
                    }} />
                  ))}

                  {/* Today vertical line */}
                  {todayVisible && (
                    <div style={{
                      position: 'absolute', left: todayX, top: 0, bottom: 0, width: 2,
                      backgroundColor: 'rgba(250,204,21,0.55)',
                      zIndex: 5, pointerEvents: 'none',
                    }} />
                  )}

                  {/* Horizontal centre guide */}
                  <div style={{
                    position: 'absolute', left: 0, right: 0, top: '50%',
                    height: 1, backgroundColor: 'rgba(255,255,255,0.05)',
                    pointerEvents: 'none',
                  }} />

                  {/* Planned → Forecast slip connector */}
                  {plannedX !== null && forecastX !== null && Math.abs(forecastX - plannedX) > 3 && (
                    <div style={{
                      position: 'absolute',
                      left: Math.min(plannedX, forecastX),
                      top: '50%', transform: 'translateY(-50%)',
                      width: Math.abs(forecastX - plannedX), height: 2,
                      borderTop: '2px dashed rgba(250,204,21,0.45)',
                      pointerEvents: 'none', zIndex: 2,
                    }} />
                  )}

                  {/* ── Future: duration bar placeholder ──────────────────
                      When m.planned_start + m.duration_days arrive from the
                      API, render a rounded rect here between plannedStartX and
                      plannedFinishX with m.progress_pct fill. Leave zIndex < 6
                      so markers sit on top. */}

                  {/* ○ Planned marker — hollow circle.
                      Overdue = red ring, not grey, so it matches the legend. */}
                  {plannedX !== null && (
                    <div title={`Planned: ${formatDate(m.planned_date!)}`} style={{
                      position: 'absolute', left: plannedX, top: '50%',
                      transform: 'translate(-50%, -50%)', zIndex: 6,
                    }}>
                      <svg width="16" height="16" viewBox="0 0 16 16" style={{ overflow: 'visible', display: 'block' }}>
                        <circle cx="8" cy="8" r="6" fill="transparent"
                          stroke={isOverdue ? '#f87171' : accent} strokeWidth="2.5" />
                      </svg>
                    </div>
                  )}

                  {/* ◆ Forecast marker — amber diamond */}
                  {forecastX !== null && (
                    <div title={`Forecast: ${formatDate(m.forecast_date!)}`} style={{
                      position: 'absolute', left: forecastX, top: '50%',
                      transform: 'translate(-50%, -50%)', zIndex: 6,
                    }}>
                      <svg width="14" height="14" viewBox="0 0 14 14" style={{ overflow: 'visible', display: 'block' }}>
                        <rect x="2" y="2" width="10" height="10" fill="#facc15" rx="1"
                          transform="rotate(45 7 7)" />
                      </svg>
                    </div>
                  )}

                  {/* ● Actual marker — green filled circle */}
                  {actualX !== null && (
                    <div title={`Actual: ${formatDate(m.actual_date!)}`} style={{
                      position: 'absolute', left: actualX, top: '50%',
                      transform: 'translate(-50%, -50%)', zIndex: 6,
                    }}>
                      <svg width="16" height="16" viewBox="0 0 16 16" style={{ overflow: 'visible', display: 'block' }}>
                        <circle cx="8" cy="8" r="6" fill="#4ade80" />
                      </svg>
                    </div>
                  )}

                  {/* Date label below primary marker */}
                  {primaryX !== null && targetDate && (
                    <div style={{
                      position: 'absolute',
                      left: primaryX, top: 'calc(50% + 10px)',
                      transform: 'translateX(-50%)',
                      fontSize: 9, color: 'var(--text-muted)',
                      whiteSpace: 'nowrap', pointerEvents: 'none', zIndex: 4,
                      userSelect: 'none',
                    }}>
                      {formatDate(targetDate)}
                    </div>
                  )}

                </div>
              </div>
            );
          })}

        </div>
      </div>

      {/* No-date milestones */}
      {noDates.length > 0 && (
        <div style={{ padding: '10px 20px', borderTop: '1px solid var(--border)' }}>
          <p style={{ fontSize: 11, fontWeight: 600, color: 'var(--text-muted)', marginBottom: 6 }}>
            No date set ({noDates.length})
          </p>
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
            {noDates.map(n => (
              <span key={n.id} style={{
                display: 'inline-flex', alignItems: 'center', gap: 4,
                fontSize: 11, padding: '2px 8px', borderRadius: 8,
                backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)',
              }}>
                {n.is_ai_generated && <Sparkles size={9} style={{ color: 'var(--gold)' }} />}
                {n.name}
              </span>
            ))}
          </div>
        </div>
      )}

    </div>
  );
}

// ─── AI Source Panel ──────────────────────────────────────────────────────────

function AiSourcePanel({ milestone }: { milestone: Milestone }) {
  const [open, setOpen] = useState(false);
  if (!milestone.source_text) return null;

  return (
    <div>
      <button
        onClick={() => setOpen(o => !o)}
        className="flex items-center gap-1 text-xs"
        style={{ color: 'var(--gold)' }}
      >
        <Info size={10} />
        Source
        <ChevronDown size={10} style={{ transform: open ? 'rotate(180deg)' : 'none', transition: '0.15s' }} />
      </button>
      {open && (
        <div
          className="mt-1 text-xs rounded-lg p-2 leading-relaxed"
          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', maxWidth: 320 }}
        >
          <p className="font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
            Extracted from AI Contract Analysis
          </p>
          {milestone.source_text}
        </div>
      )}
    </div>
  );
}

// ─── Milestone Modal ──────────────────────────────────────────────────────────

function MilestoneModal({
  milestone,
  projectId,
  onClose,
}: {
  milestone?: Milestone;
  projectId: string;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const isEdit = !!milestone;

  const { data: contractsData } = useQuery<{ data?: ContractOption[] }>({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data),
  });
  const contracts = contractsData?.data ?? [];

  const [form, setForm] = useState({
    contract_id:       '',
    name:              milestone?.name ?? '',
    milestone_type:    milestone?.milestone_type ?? 'milestone',
    responsible_party: milestone?.responsible_party ?? 'contractor',
    status:            milestone?.status ?? 'not_started',
    planned_date:      milestone?.planned_date?.slice(0, 10) ?? '',
    forecast_date:     milestone?.forecast_date?.slice(0, 10) ?? '',
    actual_date:       milestone?.actual_date?.slice(0, 10) ?? '',
    notes:             milestone?.notes ?? '',
  });

  const set = (k: string, v: string) => setForm(p => ({ ...p, [k]: v }));

  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) =>
      isEdit
        ? api.put(`/programme/${milestone!.id}`, data).then(r => r.data)
        : api.post(`/contracts/${data.contract_id}/programme`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-programme', projectId] });
      toast.success(isEdit ? 'Milestone updated' : 'Milestone added');
      onClose();
    },
    onError: () => toast.error('Failed to save milestone'),
  });

  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-lg rounded-2xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {isEdit ? 'Edit Milestone' : 'New Milestone'}
          </h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          {!isEdit && (
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Contract *</label>
              <select value={form.contract_id} onChange={e => set('contract_id', e.target.value)} required
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="">Select contract…</option>
                {contracts.map(c => (
                  <option key={c.id} value={c.id}>{c.title}{c.reference_number ? ` (${c.reference_number})` : ''}</option>
                ))}
              </select>
            </div>
          )}
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Milestone name *</label>
            <input value={form.name} onChange={e => set('name', e.target.value)} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Type</label>
              <select value={form.milestone_type} onChange={e => set('milestone_type', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {Object.entries(MILESTONE_TYPES).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Responsible party</label>
              <select value={form.responsible_party} onChange={e => set('responsible_party', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="contractor">Contractor</option>
                <option value="employer">Employer</option>
                <option value="both">Both</option>
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Status</label>
              <select value={form.status} onChange={e => set('status', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {Object.entries(STATUS_CONFIG).map(([v, c]) => <option key={v} value={v}>{c.label}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Planned date</label>
              <input type="date" value={form.planned_date} onChange={e => set('planned_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Forecast date</label>
              <input type="date" value={form.forecast_date} onChange={e => set('forecast_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Actual date</label>
              <input type="date" value={form.actual_date} onChange={e => set('actual_date', e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Notes</label>
            <textarea value={form.notes} onChange={e => set('notes', e.target.value)} rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending || (!isEdit && !form.contract_id)}
              className="px-4 py-2 rounded-lg text-sm font-medium transition-all active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Milestone'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Seed Button ──────────────────────────────────────────────────────────────

function SeedButton({ projectId, contracts }: { projectId: string; contracts: ContractOption[] }) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);

  const { mutate, isPending } = useMutation({
    mutationFn: (contractId: number) =>
      api.post(`/contracts/${contractId}/programme/seed-from-analysis`).then(r => r.data),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: ['project-programme', projectId] });
      toast.success(data.message ?? 'Milestones seeded from AI analysis');
      setOpen(false);
    },
    onError: (err: unknown) => {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message;
      toast.error(msg ?? 'Failed to seed milestones');
    },
  });

  if (contracts.length === 1) {
    return (
      <button onClick={() => mutate(contracts[0].id)} disabled={isPending}
        className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98] disabled:opacity-50"
        style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}>
        <Sparkles size={14} style={{ color: 'var(--gold)' }} />
        {isPending ? 'Seeding…' : 'Seed from AI'}
      </button>
    );
  }

  return (
    <div className="relative">
      <button onClick={() => setOpen(o => !o)}
        className="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-all active:scale-[0.98]"
        style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}>
        <Sparkles size={14} style={{ color: 'var(--gold)' }} />
        Seed from AI <ChevronDown size={13} />
      </button>
      {open && (
        <div className="absolute right-0 top-full mt-1 rounded-xl shadow-xl z-10 min-w-[240px]"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          {contracts.map(c => (
            <button key={c.id} onClick={() => mutate(c.id)} disabled={isPending}
              className="w-full text-left px-4 py-2.5 text-sm hover:bg-[var(--bg-hover)] first:rounded-t-xl last:rounded-b-xl"
              style={{ color: 'var(--text-primary)' }}>
              {c.title}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function ProjectProgrammePage() {
  const { id } = useParams<{ id: string }>();
  const { canManageProgramme: canWrite } = useProjectPermissions();
  const [showModal, setShowModal] = useState(false);
  const [editMilestone, setEditMilestone] = useState<Milestone | null>(null);
  const [statusFilter, setStatusFilter] = useState('all');
  const [viewMode, setViewMode] = useState<'table' | 'timeline'>('table');
  const queryClient = useQueryClient();

  const { data: milestones = [], isLoading } = useQuery<Milestone[]>({
    queryKey: ['project-programme', id],
    queryFn: () => api.get(`/projects/${id}/programme`).then(r => r.data),
  });

  const { data: contractsData } = useQuery<{ data?: ContractOption[] }>({
    queryKey: ['project-contracts', id],
    queryFn: () => api.get(`/projects/${id}/contracts`).then(r => r.data),
  });
  const contracts = contractsData?.data ?? [];

  const { mutate: deleteMilestone } = useMutation({
    mutationFn: (milestoneId: number) => api.delete(`/programme/${milestoneId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-programme', id] });
      toast.success('Milestone removed');
    },
  });

  const filtered = statusFilter === 'all'
    ? milestones
    : milestones.filter(m => m.status === statusFilter);

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Programme</h1>
            <PageTourButton tourKey="page-programme" label="Take a tour of this page" />
          </div>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Contract milestones and key dates</p>
        </div>
        <div className="flex gap-2 flex-wrap">
          {canWrite && contracts.length > 0 && (
            <div data-tour="programme-seed">
              <SeedButton projectId={id!} contracts={contracts} />
            </div>
          )}
          {canWrite && (
            <button data-tour="programme-new" onClick={() => setShowModal(true)}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all hover:opacity-90 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              <Plus size={15} />
              Add Milestone
            </button>
          )}
        </div>
      </div>

      {/* Health Summary */}
      {!isLoading && <div data-tour="programme-health"><HealthSummary milestones={milestones} /></div>}

      {/* Next Critical Milestone */}
      {!isLoading && milestones.length > 0 && <NextMilestoneCard milestones={milestones} />}

      {/* Filter + View toggle row */}
      <div className="flex items-center justify-between flex-wrap gap-3" data-tour="programme-filters">
        <div className="flex gap-1 p-1 rounded-full w-fit" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {(['all', 'not_started', 'in_progress', 'complete', 'delayed', 'at_risk'] as const).map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
              style={statusFilter === s ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
              {s === 'all' ? 'All' : STATUS_CONFIG[s]?.label ?? s}
            </button>
          ))}
        </div>

        {/* View toggle */}
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {(['table', 'timeline'] as const).map(v => (
            <button key={v} onClick={() => setViewMode(v)}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
              style={viewMode === v ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
              {v === 'table' ? <List size={12} /> : <BarChart2 size={12} />}
              {v === 'table' ? 'Table' : 'Timeline'}
            </button>
          ))}
        </div>
      </div>

      {/* Timeline view */}
      {viewMode === 'timeline' && !isLoading && (
        <TimelineView milestones={filtered} />
      )}

      {/* Table view */}
      {viewMode === 'table' && (
        <div className="rounded-2xl overflow-x-auto" data-tour="programme-table" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <table className="w-full min-w-[780px] text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Milestone', 'Type', 'Responsible', 'Planned', 'Forecast', 'Actual', 'Variance', 'Status', ''].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {isLoading ? (
                [...Array(5)].map((_, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                    {[...Array(9)].map((_, j) => (
                      <td key={j} className="px-4 py-4">
                        <div className="h-3.5 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                      </td>
                    ))}
                  </tr>
                ))
              ) : filtered.length === 0 ? (
                <tr>
                  <td colSpan={9} className="px-5 py-14 text-center">
                    <CalendarDays size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
                    <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>
                      {milestones.length === 0 ? 'No programme milestones yet.' : 'No milestones match this filter.'}
                    </p>
                    {milestones.length === 0 && (
                      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
                        You can add milestones manually or seed a baseline programme from confirmed AI contract analysis.
                      </p>
                    )}
                    {milestones.length === 0 && canWrite && (
                      <div className="flex items-center justify-center gap-3 flex-wrap">
                        {contracts.length > 0 && (
                          <button
                            onClick={() => api.post(`/contracts/${contracts[0].id}/programme/seed-from-analysis`)
                              .then(r => { queryClient.invalidateQueries({ queryKey: ['project-programme', id] }); toast.success(r.data.message); })
                              .catch(() => toast.error('No confirmed AI analysis found'))}
                            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
                            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}>
                            <Sparkles size={13} style={{ color: 'var(--gold)' }} />
                            Seed from AI
                          </button>
                        )}
                        <button onClick={() => setShowModal(true)}
                          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
                          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                          <Plus size={13} />
                          Add Milestone
                        </button>
                      </div>
                    )}
                  </td>
                </tr>
              ) : filtered.map((m, idx) => {
                const badge   = STATUS_CONFIG[m.status] ?? { label: m.status, bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                const now     = today();
                const isLate  = m.planned_date && !m.actual_date && m.planned_date < now && m.status !== 'complete';
                const prevGroup = idx > 0 ? filtered[idx - 1].group_name : undefined;
                const showGroupHeader = !!m.group_name && m.group_name !== prevGroup;
                const sourceLabel = m.trade_package?.name ?? m.contract?.title;

                return (
                  <>
                    {showGroupHeader && (
                      <tr key={`group-${m.group_name}-${m.id}`}>
                        <td colSpan={9} className="px-4 pt-4 pb-1.5">
                          <span className="text-[10px] font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{m.group_name}</span>
                        </td>
                      </tr>
                    )}
                    <tr key={m.id} className="hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-4 py-3 max-w-[220px]">
                        <div className="flex items-center gap-2">
                          {m.is_ai_generated && (
                            <Sparkles size={11} style={{ color: 'var(--gold)', flexShrink: 0 }} />
                          )}
                          <span className="font-medium truncate" style={{ color: 'var(--text-primary)' }}>{m.name}</span>
                        </div>
                        {sourceLabel && (
                          <span className="text-xs block truncate" style={{ color: 'var(--text-muted)' }}>{sourceLabel}</span>
                        )}
                        {m.is_ai_generated && <AiSourcePanel milestone={m} />}
                      </td>
                      <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                        {MILESTONE_TYPES[m.milestone_type] ?? m.milestone_type}
                      </td>
                      <td className="px-4 py-3 text-xs capitalize" style={{ color: 'var(--text-secondary)' }}>
                        {m.responsible_party}
                      </td>
                      <td className="px-4 py-3 text-xs tabular-nums" style={{ color: isLate ? '#f87171' : 'var(--text-secondary)' }}>
                        {m.planned_start && m.planned_date && m.planned_start !== m.planned_date ? (
                          <>{formatDate(m.planned_start)} → {formatDate(m.planned_date)}</>
                        ) : (
                          m.planned_date ? formatDate(m.planned_date) : '—'
                        )}
                        {m.duration_days ? <span className="block" style={{ color: 'var(--text-muted)' }}>{m.duration_days}d</span> : null}
                        {isLate && (
                          <span className="flex items-center gap-1 mt-0.5" style={{ color: '#f87171' }}>
                            <AlertTriangle size={9} />Overdue
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                        {m.forecast_start && m.forecast_date && m.forecast_start !== m.forecast_date ? (
                          <>{formatDate(m.forecast_start)} → {formatDate(m.forecast_date)}</>
                        ) : (
                          m.forecast_date ? formatDate(m.forecast_date) : '—'
                        )}
                      </td>
                      <td className="px-4 py-3 text-xs tabular-nums" style={{ color: m.actual_date ? '#4ade80' : 'var(--text-muted)' }}>
                        {m.actual_date ? (
                          <span className="flex items-center gap-1"><Check size={11} />{formatDate(m.actual_date)}</span>
                        ) : m.actual_start ? (
                          <span>Started {formatDate(m.actual_start)}</span>
                        ) : '—'}
                      </td>
                      <td className="px-4 py-3 text-xs tabular-nums">
                        <VarianceCell m={m} />
                      </td>
                      <td className="px-4 py-3">
                        <span className="px-2 py-0.5 rounded-full text-xs font-medium"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                          {badge.label}
                        </span>
                        {typeof m.progress_pct === 'number' && (
                          <div className="mt-1.5 flex items-center gap-1.5">
                            <div className="flex-1 h-1 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)', minWidth: 40 }}>
                              <div className="h-full rounded-full" style={{ width: `${Math.min(100, Math.max(0, m.progress_pct))}%`, backgroundColor: 'var(--gold)' }} />
                            </div>
                            <span className="text-[10px] tabular-nums" style={{ color: 'var(--text-muted)' }}>{m.progress_pct}%</span>
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        {canWrite && (
                          <div className="flex items-center gap-1">
                            <button onClick={() => setEditMilestone(m)}
                              className="text-xs px-2 py-1 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
                              style={{ color: 'var(--text-muted)' }}>
                              Edit
                            </button>
                            <button onClick={() => { if (confirm('Remove this milestone?')) deleteMilestone(m.id); }}
                              className="text-xs px-2 py-1 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
                              style={{ color: '#f87171' }}>
                              ✕
                            </button>
                          </div>
                        )}
                      </td>
                    </tr>
                  </>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {canWrite && showModal && (
        <MilestoneModal projectId={id!} onClose={() => setShowModal(false)} />
      )}
      {canWrite && editMilestone && (
        <MilestoneModal milestone={editMilestone} projectId={id!} onClose={() => setEditMilestone(null)} />
      )}
    </div>
  );
}
