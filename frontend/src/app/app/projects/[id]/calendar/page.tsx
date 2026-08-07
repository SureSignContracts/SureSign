'use client';

import { useState, useMemo, useEffect } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import {
  ChevronLeft, ChevronRight, CalendarDays, X, CalendarClock, CalendarRange, Layers,
  ArrowUpRight, AlertTriangle,
} from 'lucide-react';
import PageTourButton from '@/components/tours/PageTourButton';
import Select from '@/components/ui/Select';

// ─── Types ────────────────────────────────────────────────────────────────────

interface CalendarEvent {
  id: string;
  title: string;
  date: string;
  type: string;
  color: string;
  description?: string | null;
  contract_id?: number | null;
  contract_title?: string | null;
  category?: string | null;
  priority?: string | null;
  status?: string | null;
  action_url?: string | null;
  meta?: Record<string, unknown>;
}

type ViewMode = 'month' | 'week' | 'agenda';
type QuickFilter = 'all' | 'overdue' | 'today' | 'week' | 'upcoming';

// ─── Constants ────────────────────────────────────────────────────────────────

const EVENT_TYPE_LABELS: Record<string, string> = {
  commencement:    'Commencement',
  completion:      'Completion',
  key_date:        'Key Date',
  obligation:      'Obligation',
  payment_due:     'Payment Due',
  final_payment:   'Final Payment',
  pay_less_notice: 'Pay Less Notice',
  payment_notice:  'Payment Notice',
  milestone:       'Milestone',
  final_account:   'Final Account',
};

const LEGEND_TYPES = [
  { type: 'commencement',    color: '#a78bfa' },
  { type: 'completion',      color: '#f87171' },
  { type: 'key_date',        color: '#34d399' },
  { type: 'obligation',      color: '#e879f9' },
  { type: 'payment_due',     color: '#facc15' },
  { type: 'final_payment',   color: '#4ade80' },
  { type: 'pay_less_notice', color: '#fb923c' },
  { type: 'payment_notice',  color: '#60a5fa' },
  { type: 'milestone',       color: '#94a3b8' },
  { type: 'final_account',   color: '#facc15' },
];

// Same vocabulary/colours as CalendarEvent::PRIORITY_* — matches the palette
// already used on the Project Overview page's health widget.
const PRIORITY_CONFIG: Record<string, { label: string; color: string }> = {
  critical: { label: 'Critical', color: '#f87171' },
  high:     { label: 'High',     color: '#fb923c' },
  medium:   { label: 'Medium',   color: '#facc15' },
  low:      { label: 'Low',      color: '#4ade80' },
};

// Same status vocabulary as CalendarEvent::computeStatusFromDays() / the
// overdue/due_today colouring already used across dashboard widgets.
const STATUS_CONFIG: Record<string, { label: string; color: string }> = {
  overdue:      { label: 'Overdue',    color: '#f87171' },
  due_today:    { label: 'Due Today',  color: '#facc15' },
  upcoming:     { label: 'Upcoming',   color: '#60a5fa' },
  pending:      { label: 'Pending',    color: 'var(--text-muted)' },
  unscheduled:  { label: 'Unscheduled',color: 'var(--text-muted)' },
  completed:    { label: 'Completed',  color: '#4ade80' },
};

// Same category labels/vocabulary as CalendarEvent::CATEGORY_* — mirrors
// Project Overview's Upcoming Actions widget category labels.
const CATEGORY_LABELS: Record<string, string> = {
  commercial:   'Commercial',
  payment:      'Payment',
  compliance:   'Compliance',
  programme:    'Programme',
  contract:     'Contract',
  retention:    'Retention',
  risk:         'Risk',
  deliverables: 'Deliverables',
  notices:      'Notices',
  general:      'General',
};

const QUICK_FILTERS: { key: QuickFilter; label: string }[] = [
  { key: 'all',      label: 'All' },
  { key: 'overdue',  label: 'Overdue' },
  { key: 'today',    label: 'Today' },
  { key: 'week',     label: 'This Week' },
  { key: 'upcoming', label: 'Upcoming' },
];

const VIEW_STORAGE_KEY = 'suresign-calendar-view';
const DAY_HEADERS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

// ─── Helpers ──────────────────────────────────────────────────────────────────

function toYMD(d: Date): string {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
}

function todayYMD(): string {
  return toYMD(new Date());
}

/** Append an 8-bit alpha channel to a 6-digit hex colour (cheap, no parsing). */
function tint(hex: string, alpha: string): string {
  return hex.length === 7 ? `${hex}${alpha}` : hex;
}

/**
 * Build a 6-row (42-cell) grid for the given year/month (0-indexed month).
 * Grid starts on Monday.
 */
function buildCalendarGrid(year: number, month: number): Date[] {
  const firstDay = new Date(year, month, 1);
  const startDow = (firstDay.getDay() + 6) % 7; // Mon=0 … Sun=6
  const gridStart = new Date(year, month, 1 - startDow);

  const cells: Date[] = [];
  for (let i = 0; i < 42; i++) {
    cells.push(new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i));
  }
  return cells;
}

/** Build a 7-cell Monday-start week grid containing the given date. */
function buildWeekGrid(anchor: Date): Date[] {
  const startDow = (anchor.getDay() + 6) % 7;
  const weekStart = new Date(anchor.getFullYear(), anchor.getMonth(), anchor.getDate() - startDow);
  return Array.from({ length: 7 }, (_, i) =>
    new Date(weekStart.getFullYear(), weekStart.getMonth(), weekStart.getDate() + i)
  );
}

function monthName(month: number): string {
  return new Date(2000, month, 1).toLocaleString('en-GB', { month: 'long' });
}

function relativeLabel(dateStr: string, todayStr: string): string {
  if (dateStr === todayStr) return 'Today';
  const a = new Date(dateStr + 'T00:00:00').getTime();
  const b = new Date(todayStr + 'T00:00:00').getTime();
  const days = Math.round((a - b) / 86_400_000);
  if (days === 1) return 'Tomorrow';
  if (days > 1 && days < 7) return `In ${days} days`;
  if (days >= 7 && days < 14) return 'Next week';
  return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
}

// ─── Badges ───────────────────────────────────────────────────────────────────

function PriorityBadge({ priority }: { priority?: string | null }) {
  if (!priority) return null;
  const cfg = PRIORITY_CONFIG[priority];
  if (!cfg) return null;
  return (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-medium"
      style={{ backgroundColor: tint(cfg.color === 'var(--text-muted)' ? '#9a9490' : cfg.color, '24'), color: cfg.color, fontSize: '10px' }}>
      <span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: cfg.color }} />
      {cfg.label}
    </span>
  );
}

function StatusBadge({ status }: { status?: string | null }) {
  if (!status) return null;
  const cfg = STATUS_CONFIG[status];
  if (!cfg) return null;
  return (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-medium"
      style={{ backgroundColor: tint(cfg.color === 'var(--text-muted)' ? '#9a9490' : cfg.color, '20'), color: cfg.color, fontSize: '10px' }}>
      {cfg.label}
    </span>
  );
}

function CategoryBadge({ category }: { category?: string | null }) {
  if (!category) return null;
  const label = CATEGORY_LABELS[category] ?? category;
  return (
    <span className="inline-flex items-center px-2 py-0.5 rounded-full font-medium"
      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', fontSize: '10px' }}>
      {label}
    </span>
  );
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function EventPill({ event, onClick }: { event: CalendarEvent; onClick: (e: CalendarEvent) => void }) {
  return (
    <div
      className="truncate rounded-md pl-1.5 pr-1 py-0.5 leading-tight cursor-pointer font-medium hover:opacity-80"
      style={{
        backgroundColor: tint(event.color, '24'),
        borderLeft: `2px solid ${event.color}`,
        color: 'var(--text-primary)',
        fontSize: '10px',
      }}
      title={`${event.title} · ${EVENT_TYPE_LABELS[event.type] ?? event.type}`}
      onClick={(e) => { e.stopPropagation(); onClick(event); }}
    >
      {event.title}
    </div>
  );
}

function TypeBadge({ type, color }: { type: string; color: string }) {
  return (
    <span
      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full font-medium"
      style={{ backgroundColor: tint(color, '24'), color, fontSize: '10px' }}
    >
      <span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: color }} />
      {EVENT_TYPE_LABELS[type] ?? type}
    </span>
  );
}

function StatChip({ icon: Icon, label, value }: { icon: typeof Layers; label: string; value: number }) {
  return (
    <div
      className="flex items-center gap-2.5 px-3.5 py-2 rounded-xl"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
    >
      <div className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ backgroundColor: tint('#b99566', '26') }}>
        <Icon size={14} style={{ color: 'var(--gold)' }} />
      </div>
      <div className="leading-tight">
        <p className="text-sm font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{value}</p>
        <p style={{ fontSize: '10px', color: 'var(--text-muted)' }}>{label}</p>
      </div>
    </div>
  );
}

function EventRow({ event, dateLabel, onClick }: { event: CalendarEvent; dateLabel?: string; onClick: (e: CalendarEvent) => void }) {
  return (
    <div
      className="rounded-xl p-3 cursor-pointer transition-opacity hover:opacity-80"
      style={{ backgroundColor: 'var(--bg-elevated)', borderLeft: `3px solid ${event.color}` }}
      onClick={() => onClick(event)}
    >
      <div className="flex items-start justify-between gap-2">
        <p className="text-xs font-semibold leading-snug" style={{ color: 'var(--text-primary)' }}>{event.title}</p>
        {dateLabel && (
          <span className="text-[10px] font-medium whitespace-nowrap mt-0.5" style={{ color: 'var(--gold)' }}>{dateLabel}</span>
        )}
      </div>
      {event.description && (
        <p className="text-xs mt-1 leading-relaxed" style={{ color: 'var(--text-muted)' }}>{event.description}</p>
      )}
      {event.contract_title && (
        <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Contract: {event.contract_title}</p>
      )}
      <div className="mt-2 flex flex-wrap gap-1.5">
        <TypeBadge type={event.type} color={event.color} />
        <StatusBadge status={event.status} />
        <PriorityBadge priority={event.priority} />
      </div>
    </div>
  );
}

// ─── Event detail modal (Phase 3) ──────────────────────────────────────────────

function EventDetailModal({ event, onClose }: { event: CalendarEvent; onClose: () => void }) {
  const router = useRouter();

  const moduleLabel = event.type === 'final_account'
    ? 'Final Account'
    : ['payment_due', 'final_payment', 'pay_less_notice', 'payment_notice'].includes(event.type)
    ? 'Commercial'
    : event.type === 'milestone'
    ? 'Programme'
    : 'Contract';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}
      onClick={onClose}>
      <div
        className="w-full max-w-md rounded-2xl max-h-[88vh] overflow-y-auto ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="min-w-0 pr-3">
            <p className="text-[10px] font-semibold uppercase tracking-wide mb-1" style={{ color: 'var(--text-muted)' }}>
              {moduleLabel}
            </p>
            <h2 className="text-sm font-semibold leading-snug" style={{ color: 'var(--text-primary)' }}>{event.title}</h2>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)] flex-shrink-0">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="p-5 space-y-4">
          <div className="flex flex-wrap gap-1.5">
            <CategoryBadge category={event.category} />
            <PriorityBadge priority={event.priority} />
            <StatusBadge status={event.status} />
          </div>

          <div className="space-y-2.5">
            <div>
              <p className="text-[10px] font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Due Date</p>
              <p className="text-sm mt-0.5 tabular-nums" style={{ color: 'var(--text-primary)' }}>{formatDate(event.date)}</p>
            </div>

            {event.contract_title && (
              <div>
                <p className="text-[10px] font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Contract / Trade Package</p>
                <p className="text-sm mt-0.5" style={{ color: 'var(--text-primary)' }}>{event.contract_title}</p>
              </div>
            )}

            {event.description && (
              <div>
                <p className="text-[10px] font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Description</p>
                <p className="text-sm mt-0.5 leading-relaxed" style={{ color: 'var(--text-secondary)' }}>{event.description}</p>
              </div>
            )}
          </div>

          {event.action_url && (
            <button
              onClick={() => router.push(event.action_url!)}
              className="w-full flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              View in {moduleLabel} <ArrowUpRight size={14} />
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

// ─── Day detail panel (click a day cell) ───────────────────────────────────────

function DayDetailPanel({
  date,
  events,
  onClose,
  onEventClick,
}: {
  date: string;
  events: CalendarEvent[];
  onClose: () => void;
  onEventClick: (e: CalendarEvent) => void;
}) {
  const parsed = new Date(date + 'T00:00:00');
  const weekday = parsed.toLocaleDateString('en-GB', { weekday: 'long' });
  const rest = parsed.toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });

  return (
    <div
      className="rounded-2xl p-5 flex flex-col gap-4"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
    >
      <div className="flex items-center justify-between">
        <div>
          <p className="text-xs font-semibold" style={{ color: 'var(--gold)' }}>{weekday}</p>
          <p className="text-sm font-semibold mt-0.5" style={{ color: 'var(--text-primary)' }}>{rest}</p>
        </div>
        <button
          onClick={onClose}
          className="w-7 h-7 rounded-lg flex items-center justify-center transition-colors hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--text-muted)' }}
        >
          <X size={14} />
        </button>
      </div>

      {events.length === 0 ? (
        <div className="py-8 text-center">
          <CalendarDays size={22} className="mx-auto mb-2" style={{ color: 'var(--text-muted)', opacity: 0.5 }} />
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No events on this day</p>
        </div>
      ) : (
        <div className="space-y-3">
          {events.map(ev => <EventRow key={ev.id} event={ev} onClick={onEventClick} />)}
        </div>
      )}
    </div>
  );
}

// ─── Operational sidebar (Phase 7) ─────────────────────────────────────────────

function SidebarSection({ title, count, accent, events, todayStr, onEventClick, emptyLabel }: {
  title: string;
  count: number;
  accent?: string;
  events: CalendarEvent[];
  todayStr: string;
  onEventClick: (e: CalendarEvent) => void;
  emptyLabel: string;
}) {
  const [expanded, setExpanded] = useState(false);
  const visible = expanded ? events : events.slice(0, 3);
  const overflow = events.length - visible.length;

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <p className="text-xs font-semibold" style={{ color: accent ?? 'var(--text-secondary)' }}>{title}</p>
        {count > 0 && (
          <span className="text-[10px] font-bold tabular-nums px-1.5 py-0.5 rounded-full"
            style={{ backgroundColor: accent ? tint(accent, '20') : 'var(--bg-elevated)', color: accent ?? 'var(--text-muted)' }}>
            {count}
          </span>
        )}
      </div>
      {events.length === 0 ? (
        <p className="text-xs pb-1" style={{ color: 'var(--text-muted)' }}>{emptyLabel}</p>
      ) : (
        <div className="space-y-2">
          {visible.map(ev => (
            <EventRow key={ev.id} event={ev} dateLabel={relativeLabel(ev.date, todayStr)} onClick={onEventClick} />
          ))}
          {overflow > 0 && (
            <button
              onClick={() => setExpanded(true)}
              className="text-[10px] font-medium w-full text-center py-1 rounded-lg hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--gold)' }}
            >
              +{overflow} more
            </button>
          )}
        </div>
      )}
    </div>
  );
}

function OperationalSidebar({ events, todayStr, onEventClick }: {
  events: CalendarEvent[];
  todayStr: string;
  onEventClick: (e: CalendarEvent) => void;
}) {
  const in7 = new Date(todayStr + 'T00:00:00'); in7.setDate(in7.getDate() + 7);
  const in7Str = toYMD(in7);
  const in30 = new Date(todayStr + 'T00:00:00'); in30.setDate(in30.getDate() + 30);
  const in30Str = toYMD(in30);

  const overdue  = events.filter(e => e.date && e.date < todayStr).sort((a, b) => a.date.localeCompare(b.date));
  const today_   = events.filter(e => e.date === todayStr);
  const week     = events.filter(e => e.date > todayStr && e.date <= in7Str).sort((a, b) => a.date.localeCompare(b.date));
  const month_   = events.filter(e => e.date > in7Str && e.date <= in30Str).sort((a, b) => a.date.localeCompare(b.date));

  return (
    <div
      className="rounded-2xl p-5 flex flex-col gap-5"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
    >
      <div className="flex items-center gap-2">
        <CalendarClock size={15} style={{ color: 'var(--gold)' }} />
        <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Operational Summary</p>
      </div>

      <SidebarSection title="Overdue" count={overdue.length} accent="#f87171" events={overdue} todayStr={todayStr} onEventClick={onEventClick} emptyLabel="Nothing overdue." />
      <SidebarSection title="Today" count={today_.length} accent="#facc15" events={today_} todayStr={todayStr} onEventClick={onEventClick} emptyLabel="Nothing due today." />
      <SidebarSection title="Next 7 Days" count={week.length} events={week} todayStr={todayStr} onEventClick={onEventClick} emptyLabel="Nothing in the next 7 days." />
      <SidebarSection title="Next 30 Days" count={month_.length} events={month_} todayStr={todayStr} onEventClick={onEventClick} emptyLabel="Nothing in the next 30 days." />
    </div>
  );
}

// ─── Agenda view ────────────────────────────────────────────────────────────────

function AgendaView({ events, todayStr, onEventClick }: { events: CalendarEvent[]; todayStr: string; onEventClick: (e: CalendarEvent) => void }) {
  const groups = useMemo(() => {
    const upcoming = events
      .filter(e => e.date && e.date >= todayStr)
      .sort((a, b) => a.date.localeCompare(b.date));
    const map = new Map<string, CalendarEvent[]>();
    for (const e of upcoming) {
      const list = map.get(e.date) ?? [];
      list.push(e);
      map.set(e.date, list);
    }
    return Array.from(map.entries());
  }, [events, todayStr]);

  if (groups.length === 0) {
    return (
      <div
        className="rounded-2xl p-8 text-center"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        <CalendarClock size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)', opacity: 0.5 }} />
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No upcoming events</p>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {groups.map(([date, evs]) => {
        const d = new Date(date + 'T00:00:00');
        return (
          <div key={date}>
            <div className="flex items-center justify-between mb-2 px-0.5">
              <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                {d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}
              </p>
              <span className="text-[10px] font-medium" style={{ color: 'var(--gold)' }}>
                {relativeLabel(date, todayStr)}
              </span>
            </div>
            <div className="space-y-2">
              {evs.map(ev => <EventRow key={ev.id} event={ev} onClick={onEventClick} />)}
            </div>
          </div>
        );
      })}
    </div>
  );
}

// ─── Week view (Phase 1) ────────────────────────────────────────────────────────

function WeekView({
  weekCells, eventsByDate, todayStr, selectedDate, onSelectDate, onEventClick,
}: {
  weekCells: Date[];
  eventsByDate: Map<string, CalendarEvent[]>;
  todayStr: string;
  selectedDate: string | null;
  onSelectDate: (d: string) => void;
  onEventClick: (e: CalendarEvent) => void;
}) {
  return (
    <div className="grid grid-cols-1 sm:grid-cols-7 gap-3">
      {weekCells.map((cell, i) => {
        const dateStr = toYMD(cell);
        const isToday = dateStr === todayStr;
        const dayEvents = eventsByDate.get(dateStr) ?? [];
        const isWeekend = i >= 5;

        return (
          <div
            key={dateStr}
            className="rounded-xl p-3 flex flex-col gap-2 cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
            style={{
              backgroundColor: dateStr === selectedDate ? 'var(--bg-elevated)' : isWeekend ? 'rgba(127,127,127,0.03)' : 'var(--bg-surface)',
              border: `1px solid ${isToday ? 'var(--gold)' : 'var(--border)'}`,
              minHeight: '160px',
            }}
            onClick={() => onSelectDate(dateStr)}
          >
            <div className="flex items-center justify-between">
              <span className="text-[10px] font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
                {DAY_HEADERS[i]}
              </span>
              <span
                className="w-6 h-6 flex items-center justify-center rounded-full text-xs font-semibold tabular-nums"
                style={{
                  backgroundColor: isToday ? 'var(--gold)' : 'transparent',
                  color: isToday ? 'var(--accent-fg)' : 'var(--text-primary)',
                }}
              >
                {cell.getDate()}
              </span>
            </div>
            <div className="space-y-1 overflow-y-auto flex-1">
              {dayEvents.length === 0 ? (
                <p className="text-[10px]" style={{ color: 'var(--text-muted)', opacity: 0.6 }}>—</p>
              ) : (
                dayEvents.map(ev => <EventPill key={ev.id} event={ev} onClick={onEventClick} />)
              )}
            </div>
          </div>
        );
      })}
    </div>
  );
}

// ─── Filter bar (Phase 5) ───────────────────────────────────────────────────────

function FilterSelect({ label, value, options, onChange }: {
  label: string;
  value: string;
  options: { value: string; label: string }[];
  onChange: (v: string) => void;
}) {
  return (
    <Select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      size="sm"
      aria-label={label}
    >
      <option value="all">{label}: All</option>
      {options.map(o => (
        <option key={o.value} value={o.value}>{o.label}</option>
      ))}
    </Select>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────

export default function ProjectCalendarPage() {
  const params = useParams();
  const projectId = params?.id as string;

  const today = new Date();
  const [year, setYear] = useState(today.getFullYear());
  const [month, setMonth] = useState(today.getMonth());
  const [weekAnchor, setWeekAnchor] = useState(today);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [selectedEvent, setSelectedEvent] = useState<CalendarEvent | null>(null);

  const [view, setView] = useState<ViewMode>('month');
  // Restore the last-used view once on mount (avoids SSR/hydration mismatch
  // from reading localStorage during initial render).
  useEffect(() => {
    const stored = window.localStorage.getItem(VIEW_STORAGE_KEY) as ViewMode | null;
    if (stored === 'month' || stored === 'week' || stored === 'agenda') setView(stored);
  }, []);
  useEffect(() => {
    window.localStorage.setItem(VIEW_STORAGE_KEY, view);
  }, [view]);

  const [quickFilter, setQuickFilter] = useState<QuickFilter>('all');
  const [categoryFilter, setCategoryFilter] = useState('all');
  const [priorityFilter, setPriorityFilter] = useState('all');
  const [statusFilter, setStatusFilter] = useState('all');

  const { data, isLoading } = useQuery({
    queryKey: ['calendar-events', projectId],
    queryFn: async () => {
      const res = await api.get(`/projects/${projectId}/calendar-events`);
      return (res.data?.data as CalendarEvent[] | undefined) ?? [];
    },
    enabled: !!projectId,
  });

  const allEvents = data ?? [];
  const todayStr = todayYMD();

  // Filter options derived from the actual dataset — future-proof against new
  // categories without needing a hardcoded list.
  const categoryOptions = useMemo(() => {
    const seen = new Set<string>();
    allEvents.forEach(e => { if (e.category) seen.add(e.category); });
    return Array.from(seen).map(c => ({ value: c, label: CATEGORY_LABELS[c] ?? c }));
  }, [allEvents]);

  const priorityOptions = useMemo(() => {
    const seen = new Set<string>();
    allEvents.forEach(e => { if (e.priority) seen.add(e.priority); });
    return Array.from(seen).map(p => ({ value: p, label: PRIORITY_CONFIG[p]?.label ?? p }));
  }, [allEvents]);

  const statusOptions = useMemo(() => {
    const seen = new Set<string>();
    allEvents.forEach(e => { if (e.status) seen.add(e.status); });
    return Array.from(seen).map(s => ({ value: s, label: STATUS_CONFIG[s]?.label ?? s }));
  }, [allEvents]);

  // All filtering happens client-side against the single fetched dataset —
  // no extra API calls (Sprint 4B Phase 9).
  const events = useMemo(() => {
    let list = allEvents;

    if (categoryFilter !== 'all') list = list.filter(e => e.category === categoryFilter);
    if (priorityFilter !== 'all') list = list.filter(e => e.priority === priorityFilter);
    if (statusFilter !== 'all') list = list.filter(e => e.status === statusFilter);

    if (quickFilter === 'overdue') {
      list = list.filter(e => e.date && e.date < todayStr);
    } else if (quickFilter === 'today') {
      list = list.filter(e => e.date === todayStr);
    } else if (quickFilter === 'week') {
      const in7 = new Date(todayStr + 'T00:00:00'); in7.setDate(in7.getDate() + 7);
      const in7Str = toYMD(in7);
      list = list.filter(e => e.date && e.date >= todayStr && e.date <= in7Str);
    } else if (quickFilter === 'upcoming') {
      list = list.filter(e => e.date && e.date >= todayStr);
    }

    return list;
  }, [allEvents, categoryFilter, priorityFilter, statusFilter, quickFilter, todayStr]);

  // Build a Map<dateString, CalendarEvent[]> for O(1) lookup
  const eventsByDate = useMemo(() => {
    const map = new Map<string, CalendarEvent[]>();
    for (const ev of events) {
      if (!ev.date) continue;
      const list = map.get(ev.date) ?? [];
      list.push(ev);
      map.set(ev.date, list);
    }
    return map;
  }, [events]);

  const cells = useMemo(() => buildCalendarGrid(year, month), [year, month]);
  const weekCells = useMemo(() => buildWeekGrid(weekAnchor), [weekAnchor]);

  const stats = useMemo(() => {
    const monthPrefix = `${year}-${String(month + 1).padStart(2, '0')}`;
    let thisMonth = 0, upcoming = 0;
    for (const e of events) {
      if (!e.date) continue;
      if (e.date.startsWith(monthPrefix)) thisMonth++;
      if (e.date >= todayStr) upcoming++;
    }
    return { total: events.length, thisMonth, upcoming };
  }, [events, year, month, todayStr]);

  const isViewingToday = year === today.getFullYear() && month === today.getMonth();
  const isViewingThisWeek = toYMD(buildWeekGrid(weekAnchor)[0]) === toYMD(buildWeekGrid(today)[0]);

  function prevMonth() {
    if (month === 0) { setYear(y => y - 1); setMonth(11); }
    else setMonth(m => m - 1);
  }
  function nextMonth() {
    if (month === 11) { setYear(y => y + 1); setMonth(0); }
    else setMonth(m => m + 1);
  }
  function goToday() {
    setYear(today.getFullYear());
    setMonth(today.getMonth());
    setWeekAnchor(today);
  }
  function prevWeek() {
    setWeekAnchor(w => { const d = new Date(w); d.setDate(d.getDate() - 7); return d; });
  }
  function nextWeek() {
    setWeekAnchor(w => { const d = new Date(w); d.setDate(d.getDate() + 7); return d; });
  }

  const selectedEvents = selectedDate ? (eventsByDate.get(selectedDate) ?? []) : [];
  const hasActiveFilters = categoryFilter !== 'all' || priorityFilter !== 'all' || statusFilter !== 'all' || quickFilter !== 'all';

  return (
    <div className="p-4 sm:p-6 space-y-5 sm:space-y-6">
      {/* Header */}
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <CalendarDays size={18} style={{ color: 'var(--gold)' }} />
            <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>Project Calendar</h1>
            <PageTourButton tourKey="page-calendar" label="Take a tour of this page" />
          </div>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Key dates, deadlines and obligations</p>
        </div>
        <div className="flex flex-wrap gap-2.5" data-tour="calendar-summary">
          <StatChip icon={Layers}        label={hasActiveFilters ? 'Matching events' : 'Total events'} value={stats.total} />
          <StatChip icon={CalendarRange} label="This month"    value={stats.thisMonth} />
          <StatChip icon={CalendarClock} label="Upcoming"      value={stats.upcoming} />
        </div>
      </div>

      {/* View switcher + Filters */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex rounded-xl overflow-hidden" data-tour="calendar-view-switcher" style={{ border: '1px solid var(--border)' }}>
          {(['month', 'week', 'agenda'] as ViewMode[]).map(v => (
            <button
              key={v}
              onClick={() => setView(v)}
              className="px-3.5 py-1.5 text-xs font-medium capitalize transition-colors"
              style={{
                backgroundColor: view === v ? 'var(--gold)' : 'transparent',
                color: view === v ? 'var(--accent-fg)' : 'var(--text-secondary)',
              }}
            >
              {v}
            </button>
          ))}
        </div>

        <div className="flex flex-wrap items-center gap-2" data-tour="calendar-filters">
          {QUICK_FILTERS.map(qf => (
            <button
              key={qf.key}
              onClick={() => setQuickFilter(qf.key)}
              className="px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors"
              style={{
                backgroundColor: quickFilter === qf.key ? 'var(--gold-15)' : 'var(--bg-elevated)',
                color: quickFilter === qf.key ? 'var(--gold)' : 'var(--text-muted)',
              }}
            >
              {qf.label}
            </button>
          ))}
          <FilterSelect label="Category" value={categoryFilter} options={categoryOptions} onChange={setCategoryFilter} />
          <FilterSelect label="Priority" value={priorityFilter} options={priorityOptions} onChange={setPriorityFilter} />
          <FilterSelect label="Status" value={statusFilter} options={statusOptions} onChange={setStatusFilter} />
        </div>
      </div>

      {/* Legend */}
      <div
        className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 rounded-xl"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        {LEGEND_TYPES.map(({ type, color }) => (
          <div key={type} className="flex items-center gap-1.5">
            <div className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ backgroundColor: color }} />
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{EVENT_TYPE_LABELS[type]}</span>
          </div>
        ))}
        <div className="w-px h-4" style={{ backgroundColor: 'var(--border)' }} />
        {Object.entries(PRIORITY_CONFIG).map(([key, cfg]) => (
          <div key={key} className="flex items-center gap-1.5">
            <div className="w-2.5 h-2.5 rounded-full flex-shrink-0" style={{ backgroundColor: cfg.color }} />
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{cfg.label} priority</span>
          </div>
        ))}
      </div>

      {/* Agenda view — full width */}
      {view === 'agenda' && (
        <AgendaView events={events} todayStr={todayStr} onEventClick={setSelectedEvent} />
      )}

      {/* Mobile fallback: always agenda below md, regardless of selected view */}
      {view !== 'agenda' && (
        <div className="md:hidden">
          <AgendaView events={events} todayStr={todayStr} onEventClick={setSelectedEvent} />
        </div>
      )}

      {/* Month / Week views — desktop */}
      {view !== 'agenda' && (
        <div className="hidden md:flex md:flex-col lg:flex-row gap-5" data-tour="calendar-main">
          <div className="flex-1 min-w-0">
            <div
              className="rounded-2xl overflow-hidden"
              style={{ backgroundColor: 'var(--bg-surface)', border: view === 'month' ? '1px solid var(--border)' : 'none', boxShadow: view === 'month' ? 'var(--shadow-card)' : 'none' }}
            >
              {view === 'month' && (
                <>
                  {/* Navigation */}
                  <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
                    <button onClick={prevMonth} aria-label="Previous month"
                      className="w-8 h-8 rounded-lg flex items-center justify-center transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}>
                      <ChevronLeft size={16} />
                    </button>
                    <div className="flex items-center gap-3">
                      <h2 className="text-sm font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>
                        {monthName(month)} {year}
                      </h2>
                      <button onClick={goToday} disabled={isViewingToday}
                        className="text-xs px-2.5 py-1 rounded-lg transition-colors enabled:hover:bg-[var(--bg-hover)] disabled:opacity-40 disabled:cursor-default"
                        style={{ color: 'var(--gold)', border: '1px solid var(--border)' }}>
                        Today
                      </button>
                    </div>
                    <button onClick={nextMonth} aria-label="Next month"
                      className="w-8 h-8 rounded-lg flex items-center justify-center transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}>
                      <ChevronRight size={16} />
                    </button>
                  </div>

                  {/* Day headers */}
                  <div className="grid grid-cols-7" style={{ borderBottom: '1px solid var(--border)' }}>
                    {DAY_HEADERS.map((d, i) => (
                      <div key={d} className="py-2 text-center text-xs font-semibold uppercase tracking-wide"
                        style={{ color: i >= 5 ? 'var(--gold)' : 'var(--text-muted)', opacity: i >= 5 ? 0.7 : 1 }}>
                        {d}
                      </div>
                    ))}
                  </div>

                  {/* Grid */}
                  {isLoading ? (
                    <div className="grid grid-cols-7">
                      {Array.from({ length: 42 }).map((_, i) => (
                        <div key={i} style={{
                          minHeight: '92px',
                          borderRight: (i + 1) % 7 === 0 ? 'none' : '1px solid var(--border)',
                          borderBottom: i < 35 ? '1px solid var(--border)' : 'none',
                        }} className="p-2">
                          <div className="ml-auto w-6 h-6 rounded-full animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="grid grid-cols-7">
                      {cells.map((cell, idx) => {
                        const dateStr = toYMD(cell);
                        const isCurrentMonth = cell.getMonth() === month;
                        const isToday = dateStr === todayStr;
                        const isSelected = dateStr === selectedDate;
                        const isPast = cell < new Date(todayStr + 'T00:00:00');
                        const dow = idx % 7;
                        const isWeekend = dow >= 5;
                        const dayEvents = eventsByDate.get(dateStr) ?? [];
                        const visibleEvents = dayEvents.slice(0, 3);
                        const overflow = dayEvents.length - visibleEvents.length;

                        const bg = isSelected
                          ? 'var(--bg-elevated)'
                          : isToday
                          ? tint('#b99566', '14')
                          : isWeekend && isCurrentMonth
                          ? 'rgba(127,127,127,0.03)'
                          : 'transparent';

                        return (
                          <div
                            key={idx}
                            onClick={() => setSelectedDate(dateStr === selectedDate ? null : dateStr)}
                            className="relative p-2 cursor-pointer transition-colors hover:bg-[var(--bg-hover)] group"
                            style={{
                              minHeight: '92px',
                              borderRight: (idx + 1) % 7 === 0 ? 'none' : '1px solid var(--border)',
                              borderBottom: idx < 35 ? '1px solid var(--border)' : 'none',
                              backgroundColor: bg,
                              boxShadow: isSelected ? 'inset 0 0 0 1.5px var(--gold)' : 'none',
                            }}
                          >
                            <div className="flex justify-end mb-1">
                              <span
                                className="w-6 h-6 flex items-center justify-center rounded-full text-xs font-semibold transition-colors tabular-nums"
                                style={{
                                  backgroundColor: isToday ? 'var(--gold)' : 'transparent',
                                  color: isToday
                                    ? 'var(--accent-fg)'
                                    : isCurrentMonth
                                    ? isPast ? 'var(--text-muted)' : 'var(--text-primary)'
                                    : 'var(--text-muted)',
                                  opacity: isCurrentMonth ? 1 : 0.35,
                                }}
                              >
                                {cell.getDate()}
                              </span>
                            </div>
                            <div className="space-y-0.5">
                              {visibleEvents.map(ev => (
                                <EventPill key={ev.id} event={ev} onClick={setSelectedEvent} />
                              ))}
                              {overflow > 0 && (
                                <p className="text-center font-medium" style={{ fontSize: '10px', color: 'var(--text-muted)' }}>
                                  +{overflow} more
                                </p>
                              )}
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </>
              )}

              {view === 'week' && (
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <button onClick={prevWeek} aria-label="Previous week"
                      className="w-8 h-8 rounded-lg flex items-center justify-center transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}>
                      <ChevronLeft size={16} />
                    </button>
                    <div className="flex items-center gap-3">
                      <h2 className="text-sm font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>
                        {formatDate(toYMD(weekCells[0]))} – {formatDate(toYMD(weekCells[6]))}
                      </h2>
                      <button onClick={goToday} disabled={isViewingThisWeek}
                        className="text-xs px-2.5 py-1 rounded-lg transition-colors enabled:hover:bg-[var(--bg-hover)] disabled:opacity-40 disabled:cursor-default"
                        style={{ color: 'var(--gold)', border: '1px solid var(--border)' }}>
                        Today
                      </button>
                    </div>
                    <button onClick={nextWeek} aria-label="Next week"
                      className="w-8 h-8 rounded-lg flex items-center justify-center transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)' }}>
                      <ChevronRight size={16} />
                    </button>
                  </div>
                  <WeekView
                    weekCells={weekCells}
                    eventsByDate={eventsByDate}
                    todayStr={todayStr}
                    selectedDate={selectedDate}
                    onSelectDate={(d) => setSelectedDate(d === selectedDate ? null : d)}
                    onEventClick={setSelectedEvent}
                  />
                </div>
              )}
            </div>
          </div>

          {/* Right rail — day detail when a day is selected, otherwise operational sidebar */}
          <div className="lg:w-80 flex-shrink-0">
            {selectedDate ? (
              <DayDetailPanel
                date={selectedDate}
                events={selectedEvents}
                onClose={() => setSelectedDate(null)}
                onEventClick={setSelectedEvent}
              />
            ) : (
              <OperationalSidebar events={events} todayStr={todayStr} onEventClick={setSelectedEvent} />
            )}
          </div>
        </div>
      )}

      {/* No results (filters active, nothing matches) */}
      {!isLoading && events.length === 0 && allEvents.length > 0 && (
        <div className="flex items-center gap-2.5 px-4 py-3 rounded-xl"
          style={{ backgroundColor: 'rgba(249,115,22,0.06)', border: '1px solid rgba(249,115,22,0.2)' }}>
          <AlertTriangle size={14} style={{ color: '#fb923c', flexShrink: 0 }} />
          <p className="text-xs" style={{ color: '#fb923c' }}>No events match the current filters.</p>
        </div>
      )}

      {selectedEvent && (
        <EventDetailModal event={selectedEvent} onClose={() => setSelectedEvent(null)} />
      )}
    </div>
  );
}
