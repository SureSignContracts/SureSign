'use client';

import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useRouter, usePathname, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd, parseDateOnly } from '@/lib/dateTime';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { EASE, staggerDelay } from '@/lib/motion';
import Select from '@/components/ui/Select';
import { PROJECT_ORGANIZATION_ROLE_OPTIONS } from '@/lib/projectOrganizationRole';
import {
  Plus, Search, FolderKanban, ChevronRight, X, AlertTriangle, CheckCircle2,
  ChevronLeft, ArrowRight, LayoutGrid, LayoutList, Activity, Archive, RefreshCw,
  FolderPlus, Check, ArrowUpRight,
} from 'lucide-react';
import Button from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PageTourButton from '@/components/tours/PageTourButton';
import { normalizeApiError } from '@/lib/normalizeApiError';

const STATUS_TONE: Record<string, 'success' | 'warning' | 'info' | 'danger'> = {
  active: 'success',
  on_hold: 'warning',
  completed: 'info',
  cancelled: 'danger',
};

const WORK_TYPES = ['New Build', 'Refurbishment', 'Fitout', 'Infrastructure', 'Maintenance', 'Other'];

const INPUT_CLS = 'w-full rounded-lg px-3 py-2 text-sm outline-none border border-[var(--border)] focus:border-[var(--gold)] transition-colors duration-200';

/** Fraction of the programme elapsed between start and completion, for the card timeline. */
function progressPct(start?: string | null, end?: string | null): number | null {
  if (!start || !end) return null;
  const s = parseDateOnly(start).getTime();
  const e = parseDateOnly(end).getTime();
  if (isNaN(s) || isNaN(e) || e <= s) return null;
  // start/end are DATE-only fields — "now" here is "today" in the viewer's
  // effective SureSign timezone, not the raw device clock instant.
  const now = parseDateOnly(effectiveTodayYmd()).getTime();
  return Math.min(100, Math.max(0, ((now - s) / (e - s)) * 100));
}

// ── Portfolio types (mirrors ProjectPortfolioService::build()) ────────────

type PortfolioRow = {
  id: number;
  name: string;
  reference: string | null;
  status: string;
  location: string | null;
  contract_type: string | null;
  start_date: string | null;
  completion_date: string | null;
  attention: { requires_attention: boolean; overdue_count: number; due_today_count: number; nearest_deadline: string | null };
  commercial: {
    currency: string; contract_value: number; certified: number; paid: number;
    outstanding: number; retention_held: number; approved_variations: number; pending_variations: number;
  };
  last_activity: { description: string; actor: string; timestamp: string } | null;
  urls: { workspace: string; commercial: string; documents: string; programme: string };
};

type PortfolioData = {
  summary: { total_projects: number; active_projects: number; projects_requiring_attention: number; completed_projects: number };
  projects: { data: PortfolioRow[]; pagination: { current_page: number; last_page: number; per_page: number; total: number } };
  filters: { statuses: string[]; currencies: string[]; attention_options: string[] };
  meta: { effective_timezone: string; generated_at: string };
};

/**
 * Project Basics — Phase B. Deliberately short: only what's genuinely
 * needed before a Project can exist. Everything else the platform already
 * supports (description, status, contract type/value, currency, dates,
 * address/location) is created with a safe null/default and completed
 * afterwards via Edit Project — see EditProjectModal, which gained those
 * fields in this same phase specifically so nothing here is lost, only
 * deferred. Phase D: on success, the customer self-create path now routes
 * straight into the dedicated Contract-Assisted Setup route
 * (/app/projects/{id}/setup) rather than only closing this modal — the
 * created Project's own id comes directly from this endpoint's response
 * body (ProjectController::store() returns the Project itself, not a
 * {data: ...} envelope). Navigation only ever happens from a genuine
 * `onSuccess` — a failed create leaves the user in this modal exactly as
 * before, never routing to Setup with an undefined Project id.
 */
function CreateProjectModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();
  const router = useRouter();
  const [form, setForm] = useState({
    name: '', code: '', type: '', organization_role: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const mutation = useMutation({
    mutationFn: (data: typeof form) => api.post('/projects', {
      ...data,
      organization_role: data.organization_role || null,
    }).then(r => r.data),
    onSuccess: (project: { id: number }) => {
      queryClient.invalidateQueries({ queryKey: ['projects-portfolio'] });
      onClose();
      router.push(`/app/projects/${project.id}/setup`);
    },
    onError: (e: unknown) => {
      const normalized = normalizeApiError(e, 'Failed to create project. Please check all required fields.');
      setFieldErrors(normalized.fieldErrors ?? {});
      // Field-specific errors already render inline next to each field below
      // — the banner only needs a short summary in that case, not the same
      // text twice. Non-validation failures (network/server/permission)
      // still show their own specific message in the banner, since there's
      // no field to attach them to.
      setError(normalized.type === 'validation' ? 'Check the highlighted information.' : normalized.message);
    },
  });

  const set = (k: string, v: string) => setForm(f => ({ ...f, [k]: v }));

  // Border/focus/radius live in INPUT_CLS so :focus styles can win — inline borders would override them.
  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    color: 'var(--text-primary)',
  };

  const labelStyle = { color: 'var(--text-muted)', fontSize: '0.75rem', marginBottom: '4px', display: 'block' };

  return (
    <div
      className="ss-projects-page fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-3 backdrop-blur-md sm:p-6"
      style={{ backgroundColor: 'rgba(10, 15, 12, 0.72)' }}
      role="dialog"
      aria-modal="true"
      aria-labelledby="create-project-title"
    >
      <div
        className="ss-animate-in relative grid w-full max-w-4xl overflow-hidden rounded-2xl lg:grid-cols-[0.8fr_1.35fr]"
        style={{ backgroundColor: 'var(--bg-surface)', boxShadow: '0 32px 90px rgba(5, 12, 8, 0.38)' }}
      >
        <button
          onClick={onClose}
          className="absolute right-4 top-4 z-10 flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 bg-black/10 text-white/65 transition-all duration-200 hover:rotate-90 hover:bg-black/20 hover:text-white active:scale-95 lg:border-[var(--border)] lg:bg-[var(--bg-elevated)] lg:text-[var(--text-muted)] lg:hover:bg-[var(--bg-hover)] lg:hover:text-[var(--text-primary)]"
          aria-label="Close new project dialog"
        >
          <X size={17} />
        </button>

        <aside className="relative flex min-h-[230px] flex-col justify-between overflow-hidden bg-[#18211d] p-6 text-white sm:p-8 lg:min-h-[610px]">
          <div className="pointer-events-none absolute -right-20 -top-16 h-56 w-56 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
          <div className="relative">
            <div className="ss-animate-in flex h-12 w-12 items-center justify-center rounded-2xl border border-white/10 bg-[#9ee5b5] text-[#18211d] shadow-[0_12px_30px_rgba(158,229,181,0.18)]">
              <FolderPlus size={22} strokeWidth={1.8} />
            </div>
            <div className="ss-animate-in mt-7" style={{ animationDelay: '70ms' }}>
              <p className="text-xs font-semibold uppercase tracking-[0.16em] text-[#9ee5b5]">Project workspace</p>
              <h2 id="create-project-title" className="mt-3 max-w-xs text-3xl font-semibold leading-tight tracking-[-0.035em]">
                Start with the essentials.
              </h2>
              <p className="mt-4 max-w-sm text-sm leading-6 text-white/62">
                Create the workspace now, then add the contract, commercial details and programme in context.
              </p>
            </div>
          </div>

          <div className="relative mt-8 space-y-3 lg:mt-0">
            {[
              'A dedicated contract record',
              'Document and notice workflows',
              'Commercial and programme tracking',
            ].map((item, index) => (
              <div
                key={item}
                className="ss-animate-in flex items-center gap-3 text-sm text-white/78"
                style={{ animationDelay: `${140 + index * 55}ms` }}
              >
                <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-white/8 text-[#9ee5b5]">
                  <Check size={13} strokeWidth={2.2} />
                </span>
                {item}
              </div>
            ))}
          </div>
        </aside>

        <div className="flex max-h-[calc(100dvh-1.5rem)] min-h-0 flex-col sm:max-h-[calc(100dvh-3rem)] lg:max-h-[610px]">
          <div className="px-6 pb-5 pt-7 sm:px-8 sm:pt-8">
            <p className="text-sm font-semibold text-[var(--gold)]">Project details</p>
            <h3 className="mt-1 text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>
              Name the job and your role
            </h3>
            <p className="mt-2 text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
              Only the project name is required. You can complete everything else later.
            </p>
          </div>

          {/* Form: project basics only. The full contract setup follows creation. */}
          <div className="min-h-0 flex-1 overflow-y-auto px-6 pb-6 sm:px-8">
            {error && (
              <div className="ss-animate-in mb-5 flex items-start gap-3 rounded-xl border border-red-400/20 bg-red-500/10 px-4 py-3 text-sm text-red-500">
                <AlertTriangle size={17} className="mt-0.5 shrink-0" />
                {error}
              </div>
            )}

            <div className="space-y-5">
              <div className="grid gap-5 sm:grid-cols-2">
                <div className="ss-animate-in" style={{ animationDelay: '90ms' }}>
                  <label htmlFor="new-project-name" style={labelStyle}>Project name *</label>
                  <input
                    id="new-project-name"
                    autoFocus
                    className={`${INPUT_CLS} h-12 rounded-xl`}
                    style={inputStyle}
                    value={form.name}
                    onChange={e => set('name', e.target.value)}
                    placeholder="High Street Development"
                    aria-invalid={fieldErrors.name ? true : undefined}
                    aria-describedby={fieldErrors.name ? 'project-name-error' : undefined}
                  />
                  {fieldErrors.name && (
                    <p id="project-name-error" className="mt-1.5 text-xs text-red-500">{fieldErrors.name[0]}</p>
                  )}
                </div>
                <div className="ss-animate-in" style={{ animationDelay: '130ms' }}>
                  <label htmlFor="new-project-code" style={labelStyle}>Project number / code</label>
                  <input
                    id="new-project-code"
                    className={`${INPUT_CLS} h-12 rounded-xl`}
                    style={inputStyle}
                    value={form.code}
                    onChange={e => set('code', e.target.value)}
                    placeholder="PRJ-2026-001"
                  />
                </div>
              </div>

              <div className="ss-animate-in" style={{ animationDelay: '170ms' }}>
                <label htmlFor="new-project-type" style={labelStyle}>Project type</label>
                <Select id="new-project-type" className="w-full" value={form.type} onChange={e => set('type', e.target.value)}>
                  <option value="">Select type of work…</option>
                  {WORK_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
                </Select>
              </div>

              <div className="ss-animate-in" style={{ animationDelay: '210ms' }}>
                <label htmlFor="new-project-role" style={labelStyle}>Your organization&rsquo;s role on this project</label>
                <Select id="new-project-role" className="w-full" value={form.organization_role} onChange={e => set('organization_role', e.target.value)}>
                  <option value="">Role not set</option>
                  {PROJECT_ORGANIZATION_ROLE_OPTIONS.map(({ value, label }) => (
                    <option key={value} value={value}>{label}</option>
                  ))}
                </Select>
                <p className="mt-2 text-xs leading-5" style={{ color: 'var(--text-muted)' }}>
                  This sets how your organization participates in this project and can vary between jobs.
                </p>
              </div>

              <div className="ss-animate-in flex items-start gap-3 rounded-xl border border-[var(--border)] bg-[var(--bg-elevated)] p-4" style={{ animationDelay: '250ms' }}>
                <ArrowUpRight size={17} className="mt-0.5 shrink-0 text-[var(--gold)]" />
                <p className="text-xs leading-5" style={{ color: 'var(--text-muted)' }}>
                  After creation, you will continue to guided setup for the agreement and core project information.
                </p>
              </div>
            </div>
          </div>

          <div className="flex items-center justify-end gap-3 border-t border-[var(--border)] px-6 py-4 sm:px-8">
            <Button variant="ghost" onClick={onClose}>
              Cancel
            </Button>
            <Button
              onClick={() => { setError(null); setFieldErrors({}); mutation.mutate(form); }}
              disabled={!form.name || mutation.isPending}
              className="group min-w-[150px] transition-transform duration-200 active:scale-[0.98]"
            >
              {mutation.isPending ? 'Creating…' : (
                <span className="inline-flex items-center gap-2">
                  Create project
                  <ArrowRight size={15} className="transition-transform duration-200 group-hover:translate-x-0.5" />
                </span>
              )}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

function AttentionBadge({ attention }: { attention: PortfolioRow['attention'] }) {
  if (attention.requires_attention) {
    const count = attention.overdue_count + attention.due_today_count;
    return (
      <span className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[11px] font-semibold" style={{ backgroundColor: 'rgba(239,68,68,0.11)', color: '#f87171' }}>
        <AlertTriangle size={12} /> Requires attention{count > 0 ? ` (${count})` : ''}
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[11px] font-semibold" style={{ backgroundColor: 'rgba(34,197,94,0.10)', color: '#4ade80' }}>
      <CheckCircle2 size={12} /> On track
    </span>
  );
}

function ProjectCard({ row, index, formatCurrency }: {
  row: PortfolioRow;
  index: number;
  formatCurrency: (value: number, currency?: string) => string;
}) {
  const pct = progressPct(row.start_date, row.completion_date);

  return (
    <Link
      href={row.urls.workspace}
      className={`group ss-animate-in relative flex min-h-64 flex-col overflow-hidden rounded-2xl transition-all duration-300 ${EASE} hover:-translate-y-1 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)]`}
      style={{
        backgroundColor: 'var(--bg-surface)',
        border: '1px solid var(--border)',
        animationDelay: staggerDelay(index),
      }}
    >
      <div className="h-1 w-full origin-left transition-transform duration-500 group-hover:scale-x-[1.02]" style={{ backgroundColor: row.attention.requires_attention ? '#f87171' : 'var(--gold)' }} />

      <div className="flex items-start justify-between gap-3 px-5 pt-5">
        <div className="flex items-start gap-3 min-w-0">
          <div
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-sm font-bold transition-transform duration-300 group-hover:rotate-[-3deg] group-hover:scale-105"
            style={{ backgroundColor: row.attention.requires_attention ? 'rgba(239,68,68,0.10)' : 'var(--gold-15)', color: row.attention.requires_attention ? '#f87171' : 'var(--gold)', border: '1px solid var(--border)' }}
          >
            {row.name?.charAt(0)?.toUpperCase()}
          </div>
          <div className="min-w-0">
            <p className="truncate text-base font-semibold leading-tight tracking-[-0.015em]" style={{ color: 'var(--text-primary)' }}>
              {row.name}
            </p>
            <p className="mt-1 truncate font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>
              {row.reference ?? 'No reference'}{row.location ? ` / ${row.location}` : ''}
            </p>
          </div>
        </div>
        <Badge tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status.replace(/_/g, ' ')}</Badge>
      </div>

      <div className="mt-5 px-5"><AttentionBadge attention={row.attention} /></div>

      {pct !== null && (
        <div className="px-5">
        <div
          className="h-1 overflow-hidden rounded-full"
          style={{ backgroundColor: 'var(--bg-elevated)' }}
          title={`${Math.round(pct)}% of programme elapsed`}
        >
          <div className="ss-project-progress h-full rounded-full" style={{ '--project-progress': `${pct}%`, backgroundColor: 'var(--gold)' } as React.CSSProperties} />
        </div>
        </div>
      )}

      <div className="mx-5 mt-4 grid grid-cols-2 gap-4 border-t pt-4" style={{ borderColor: 'var(--border)' }}>
        <div>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Outstanding</p>
          <p
            className="text-sm font-semibold mt-0.5 tabular-nums"
            style={{ color: row.commercial.outstanding > 0 ? '#fb923c' : 'var(--text-primary)' }}
          >
            {formatCurrency(row.commercial.outstanding, row.commercial.currency)}
          </p>
        </div>
        <div>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Completion</p>
          <p className="text-sm font-semibold mt-0.5 tabular-nums" style={{ color: 'var(--text-primary)' }}>
            {row.completion_date ? formatDate(row.completion_date) : 'Not set'}
          </p>
        </div>
      </div>

      <div className="mt-auto flex items-center justify-between border-t px-5 py-3.5" style={{ borderColor: 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}>
        <span className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Project workspace</span>
        <div className={`flex items-center gap-1 text-xs font-semibold transition-transform duration-300 group-hover:translate-x-1 ${EASE}`} style={{ color: 'var(--gold)' }}>
          Open <ChevronRight size={13} />
        </div>
      </div>
    </Link>
  );
}

const SORT_OPTIONS = [
  { key: 'attention', label: 'Requires Attention' },
  { key: 'name', label: 'Project Name' },
  { key: 'activity', label: 'Most Recent Activity' },
  { key: 'completion_date', label: 'Completion Date' },
  { key: 'outstanding', label: 'Outstanding Amount' },
];

export default function AppProjectsPage() {
  const formatCurrency = useCurrencyFormatter();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();
  const [showCreate, setShowCreate] = useState(false);

  const search    = searchParams.get('search') ?? '';
  const status    = searchParams.get('status') ?? 'all';
  const attention = searchParams.get('attention') ?? 'all';
  const currency  = searchParams.get('currency') ?? '';
  const sort      = searchParams.get('sort') ?? 'attention';
  const page      = parseInt(searchParams.get('page') ?? '1', 10) || 1;
  const view      = (searchParams.get('view') === 'table' ? 'table' : 'cards') as 'cards' | 'table';

  const setParam = (updates: Record<string, string | null>) => {
    const params = new URLSearchParams(searchParams.toString());
    for (const [key, value] of Object.entries(updates)) {
      if (value === null || value === '' || value === 'all') {
        params.delete(key);
      } else {
        params.set(key, value);
      }
    }
    // Any filter/sort change resets to page 1, unless the page itself changed.
    if (!('page' in updates)) params.delete('page');
    router.replace(`${pathname}?${params.toString()}`, { scroll: false });
  };

  const hasActiveFilters = !!(search || status !== 'all' || attention !== 'all' || currency);

  const queryParams = useMemo(() => ({
    search: search || undefined,
    status: status !== 'all' ? status : undefined,
    attention: attention !== 'all' ? attention : undefined,
    currency: currency || undefined,
    sort,
    page,
  }), [search, status, attention, currency, sort, page]);

  const { data, isLoading, isError, refetch, isFetching } = useQuery<PortfolioData>({
    queryKey: ['projects-portfolio', queryParams],
    queryFn: () => api.get('/projects/portfolio', { params: queryParams }).then(r => r.data),
    placeholderData: (prev) => prev,
  });

  const rows = data?.projects.data ?? [];
  const pagination = data?.projects.pagination;
  const showMultiCurrency = (data?.filters.currencies.length ?? 0) > 1;

  return (
    <>
      {showCreate && <CreateProjectModal onClose={() => setShowCreate(false)} />}
      <div className="ss-projects-page ss-workspace-page-in mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:py-9">
        {/* Portfolio header */}
        <section className="ss-workspace-hero-in grid overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5] lg:grid-cols-[1.05fr_0.95fr]" data-tour="projects-summary">
          <div className="ss-workspace-left-in relative overflow-hidden p-7 sm:p-9 lg:p-11">
            <div className="absolute -left-28 -top-32 h-80 w-80 rounded-full border border-[#a5d6b5]/10 transition-transform duration-700 ease-out hover:scale-105" />
            <div className="relative">
              <div className="mb-8 flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
                <FolderKanban size={20} />
              </div>
              <div className="flex items-start gap-2">
                <h1 className="flex-1 text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Your project portfolio, clearly in view.</h1>
                <span style={{ '--text-muted': '#b9c5bf' } as React.CSSProperties}>
                  <PageTourButton tourKey="page-projects" label="Take a tour of this page" />
                </span>
              </div>
              <p className="mt-4 max-w-xl text-sm leading-6 text-[#b9c5bf] sm:text-base">
                Track delivery, commercial position and the projects that need attention across your organisation.
              </p>
              <Button data-tour="projects-new" size="lg" className="mt-7 gap-2" onClick={() => setShowCreate(true)}>
                <Plus size={16} /> New project
              </Button>
            </div>
          </div>

          <div className="ss-workspace-right-in grid grid-cols-2 border-t border-[#a5d6b5]/10 bg-[#202c26] lg:border-l lg:border-t-0">
            {[
              { label: 'Total projects', value: data?.summary.total_projects, color: '#f4f7f5', icon: FolderKanban, emphasise: false },
              { label: 'Active', value: data?.summary.active_projects, color: '#9ee5b5', icon: Activity, emphasise: false },
              { label: 'Need attention', value: data?.summary.projects_requiring_attention, color: '#fda4a4', icon: AlertTriangle, emphasise: (data?.summary.projects_requiring_attention ?? 0) > 0 },
              { label: 'Completed', value: data?.summary.completed_projects, color: '#b9c5bf', icon: Archive, emphasise: false },
            ].map((stat, index) => (
              <div
                key={stat.label}
                className="ss-animate-in group/stat relative flex min-h-32 flex-col justify-between border-[#a5d6b5]/10 p-5 transition-colors duration-300 hover:bg-[#26342d] sm:p-6 [&:nth-child(odd)]:border-r [&:nth-child(-n+2)]:border-b"
                style={{ animationDelay: `${140 + (index * 65)}ms` }}
              >
                <div className="flex items-center justify-between gap-2">
                  <p className="text-xs font-medium text-[#91a099]">{stat.label}</p>
                  <stat.icon size={14} className="transition-transform duration-300 group-hover/stat:scale-110" style={{ color: stat.emphasise ? '#fda4a4' : '#91a099' }} />
                </div>
                <p className="mt-5 text-3xl font-semibold tracking-[-0.04em] tabular-nums" style={{ color: stat.color }}>
                  {isLoading ? '...' : stat.value}
                </p>
              </div>
            ))}
          </div>
        </section>

        {isError ? (
          <div className="flex flex-col items-center justify-center py-16 gap-3 rounded-xl" style={{ border: '1px solid var(--border)' }}>
            <AlertTriangle size={28} style={{ color: '#f87171' }} />
            <p className="text-sm font-medium" style={{ color: '#f87171' }}>Could not load the project portfolio</p>
            <button
              onClick={() => refetch()}
              className="mt-1 px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              Retry
            </button>
          </div>
        ) : (
          <>
            {/* Filters */}
            <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border p-3" data-tour="projects-filters" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '220ms' }}>
              <div className="relative min-w-0 flex-1 sm:min-w-64">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
                <input
                  value={search}
                  onChange={e => setParam({ search: e.target.value })}
                  placeholder="Search name, reference, location..."
                  aria-label="Search projects"
                  className="w-full rounded-xl border border-[var(--border)] py-2 pl-9 pr-4 text-sm outline-none transition-all duration-200 focus:border-[var(--gold)] focus:ring-2 focus:ring-[var(--gold)]/10"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' }}
                />
              </div>

              <Select
                value={status}
                onChange={e => setParam({ status: e.target.value })}
                aria-label="Filter by status"
                className="capitalize"
              >
                <option value="all">All statuses</option>
                {(data?.filters.statuses ?? []).map(s => (
                  <option key={s} value={s} className="capitalize">{s.replace(/_/g, ' ')}</option>
                ))}
              </Select>

              <Select
                value={attention}
                onChange={e => setParam({ attention: e.target.value })}
                aria-label="Filter by attention"
              >
                <option value="all">Any attention state</option>
                <option value="requires_attention">Requires Attention</option>
                <option value="on_track">On Track</option>
              </Select>

              {showMultiCurrency && (
                <Select
                  value={currency}
                  onChange={e => setParam({ currency: e.target.value })}
                  aria-label="Filter by currency"
                >
                  <option value="">All currencies</option>
                  {(data?.filters.currencies ?? []).map(c => <option key={c} value={c}>{c}</option>)}
                </Select>
              )}

              <Select
                value={sort}
                onChange={e => setParam({ sort: e.target.value })}
                aria-label="Sort projects"
              >
                {SORT_OPTIONS.map(o => <option key={o.key} value={o.key}>Sort: {o.label}</option>)}
              </Select>

              {hasActiveFilters && (
                <button
                  onClick={() => router.replace(pathname, { scroll: false })}
                  className="flex items-center gap-1 rounded-xl px-3 py-2 text-sm font-medium transition-all duration-200 hover:bg-[var(--bg-hover)] active:scale-[0.97]"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <X size={13} /> Clear filters
                </button>
              )}

              {/* View toggle — cards for browsing, table for analysing */}
              <div className="flex gap-1 p-1 rounded-full ml-auto" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                {(['cards', 'table'] as const).map(mode => (
                  <button
                    key={mode}
                    onClick={() => setParam({ view: mode === 'cards' ? null : mode })}
                    aria-label={mode === 'cards' ? 'Card view' : 'Table view'}
                    aria-pressed={view === mode}
                    className={`flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-200 ${EASE} active:scale-[0.97]`}
                    style={view === mode
                      ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', boxShadow: 'var(--shadow-card)' }
                      : { color: 'var(--text-secondary)' }
                    }
                  >
                    {mode === 'cards' ? <LayoutGrid size={13} /> : <LayoutList size={13} />}
                    {mode === 'cards' ? 'Cards' : 'Table'}
                  </button>
                ))}
              </div>
            </div>

            {/* Portfolio list */}
            <div className="ss-animate-in" data-tour="projects-grid" style={{ animationDelay: '280ms' }}>
              <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                  <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Projects</h2>
                  <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
                    {isLoading ? 'Loading your portfolio…' : `${pagination?.total ?? rows.length} project${(pagination?.total ?? rows.length) === 1 ? '' : 's'} shown in this portfolio.`}
                  </p>
                </div>
                {isFetching && !isLoading && (
                  <span className="ss-animate-in flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                    <RefreshCw size={12} className="animate-spin" /> Updating view
                  </span>
                )}
              </div>
              {isLoading ? (
                view === 'cards' ? (
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {[...Array(6)].map((_, i) => (
                      <div key={i} className="h-64 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)' }} />
                    ))}
                  </div>
                ) : (
                  <div className="space-y-2">
                    {[...Array(6)].map((_, i) => (
                      <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
                    ))}
                  </div>
                )
              ) : (data?.summary.total_projects ?? 0) === 0 ? (
                <EmptyState
                  surface
                  icon={FolderKanban}
                  title="No projects yet"
                  description="Portfolio information will appear here once your first project is created."
                  action={<Button onClick={() => setShowCreate(true)}><Plus size={14} /> New project</Button>}
                />
              ) : rows.length === 0 ? (
                <EmptyState
                  surface
                  icon={Search}
                  title="No projects match the current filters"
                  description="Try a different search term or clear your filters."
                  action={<button onClick={() => router.replace(pathname, { scroll: false })} className="text-sm font-medium" style={{ color: 'var(--gold)' }}>Clear filters</button>}
                />
              ) : view === 'cards' ? (
                <div className="grid grid-cols-1 gap-4 transition-opacity duration-200 sm:grid-cols-2 xl:grid-cols-3" style={{ opacity: isFetching ? 0.55 : 1 }}>
                  {rows.map((row, i) => (
                    <ProjectCard key={row.id} row={row} index={i} formatCurrency={formatCurrency} />
                  ))}
                </div>
              ) : (
                <>
                  {/* Desktop table */}
                  <div className="hidden overflow-x-auto rounded-2xl transition-opacity duration-200 md:block" style={{ border: '1px solid var(--border)', opacity: isFetching ? 0.55 : 1, boxShadow: 'var(--shadow-card)' }}>
                    <table className="w-full text-sm">
                      <thead>
                        <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                          {['Project', 'Status', 'Location', 'Contract Type', 'Completion', 'Attention', 'Outstanding', 'Retention', 'Last Activity', ''].map(h => (
                            <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{h}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {rows.map((row, index) => (
                          <tr key={row.id} style={{ borderBottom: '1px solid var(--border)', animationDelay: staggerDelay(index) }} className="ss-animate-in transition-colors hover:bg-[var(--bg-hover)]">
                            <td className="px-3 py-3">
                              <Link href={row.urls.workspace} className="block">
                                <p className="font-medium truncate max-w-[220px]" style={{ color: 'var(--text-primary)' }}>{row.name}</p>
                                <p className="font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>{row.reference ?? 'No reference'}</p>
                              </Link>
                            </td>
                            <td className="px-3 py-3"><Badge tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status.replace(/_/g, ' ')}</Badge></td>
                            <td className="px-3 py-3 whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{row.location ?? 'Not set'}</td>
                            <td className="px-3 py-3 whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{row.contract_type ?? 'Not set'}</td>
                            <td className="px-3 py-3 whitespace-nowrap tabular-nums" style={{ color: 'var(--text-secondary)' }}>{row.completion_date ?? 'Not set'}</td>
                            <td className="px-3 py-3 whitespace-nowrap"><AttentionBadge attention={row.attention} /></td>
                            <td className="px-3 py-3 whitespace-nowrap tabular-nums font-semibold" style={{ color: row.commercial.outstanding > 0 ? '#fb923c' : 'var(--text-secondary)' }}>
                              {formatCurrency(row.commercial.outstanding, row.commercial.currency)}
                            </td>
                            <td className="px-3 py-3 whitespace-nowrap tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                              {formatCurrency(row.commercial.retention_held, row.commercial.currency)}
                            </td>
                            <td className="px-3 py-3 whitespace-nowrap text-xs" style={{ color: 'var(--text-muted)' }}>
                              {row.last_activity ? row.last_activity.description : 'No recent activity'}
                            </td>
                            <td className="px-3 py-3 text-right whitespace-nowrap">
                              <Link href={row.urls.workspace} className="text-xs font-medium inline-flex items-center gap-1" style={{ color: 'var(--gold)' }}>
                                Open <ArrowRight size={11} />
                              </Link>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {/* Mobile cards */}
                  <div className="space-y-3 transition-opacity duration-200 md:hidden" style={{ opacity: isFetching ? 0.55 : 1 }}>
                    {rows.map((row, index) => (
                      <Link
                        key={row.id}
                        href={row.urls.workspace}
                        className="ss-animate-in block space-y-2 rounded-2xl p-4 transition-all duration-300 hover:-translate-y-0.5"
                        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: staggerDelay(index), boxShadow: 'var(--shadow-card)' }}
                      >
                        <div className="flex items-start justify-between gap-2">
                          <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{row.name}</p>
                          <Badge tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status.replace(/_/g, ' ')}</Badge>
                        </div>
                        <AttentionBadge attention={row.attention} />
                        <div className="flex items-center justify-between pt-2" style={{ borderTop: '1px solid var(--border)' }}>
                          <div>
                            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Outstanding</p>
                            <p className="text-sm font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>
                              {formatCurrency(row.commercial.outstanding, row.commercial.currency)}
                            </p>
                          </div>
                          <ChevronRight size={16} style={{ color: 'var(--gold)' }} />
                        </div>
                      </Link>
                    ))}
                  </div>
                </>
              )}

              {/* Pagination — shared by both card and table views */}
              {!isLoading && rows.length > 0 && pagination && pagination.last_page > 1 && (
                <div className="flex items-center justify-between pt-2">
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    Page {pagination.current_page} of {pagination.last_page} ({pagination.total} projects)
                  </p>
                  <div className="flex gap-2">
                    <button
                      disabled={pagination.current_page <= 1}
                      onClick={() => setParam({ page: String(pagination.current_page - 1) })}
                      className="p-2 rounded-lg disabled:opacity-40 transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ border: '1px solid var(--border)' }}
                      aria-label="Previous page"
                    >
                      <ChevronLeft size={14} />
                    </button>
                    <button
                      disabled={pagination.current_page >= pagination.last_page}
                      onClick={() => setParam({ page: String(pagination.current_page + 1) })}
                      className="p-2 rounded-lg disabled:opacity-40 transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ border: '1px solid var(--border)' }}
                      aria-label="Next page"
                    >
                      <ChevronRight size={14} />
                    </button>
                  </div>
                </div>
              )}
            </div>
          </>
        )}
      </div>
    </>
  );
}
