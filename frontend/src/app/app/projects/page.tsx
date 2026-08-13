'use client';

import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useRouter, usePathname, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd, parseDateOnly } from '@/lib/dateTime';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { SUPPORTED_CURRENCIES } from '@/lib/currency';
import { useAuthStore } from '@/store/authStore';
import { EASE, staggerDelay } from '@/lib/motion';
import Select from '@/components/ui/Select';
import { PROJECT_ORGANIZATION_ROLE_OPTIONS } from '@/lib/projectOrganizationRole';
import {
  Plus, Search, FolderKanban, ChevronRight, X, AlertTriangle, CheckCircle2,
  ChevronLeft, ArrowRight, LayoutGrid, LayoutList, Activity, Archive,
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

const CONTRACT_TYPES = ['JCT', 'NEC3', 'NEC4', 'FIDIC', 'Bespoke', 'Other'];
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

function CreateProjectModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient();
  const { user } = useAuthStore();
  // '' means "inherit the organisation default" — never pre-filled from
  // country/location; the placeholder option below shows what that
  // resolves to today, but the stored value stays null until someone
  // deliberately picks an explicit currency.
  const [form, setForm] = useState({
    name: '', code: '', contract_type: '', type: '', organization_role: '', status: 'active',
    contract_value: '', start_date: '', end_date: '', description: '', currency: '',
    address: '', city: '', state: '', postcode: '', country: '', latitude: '', longitude: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  const mutation = useMutation({
    mutationFn: (data: typeof form) => api.post('/projects', {
      ...data,
      currency: data.currency || null,
      organization_role: data.organization_role || null,
      // Empty string must become `null`, never 0 — 0,0 is a real coordinate,
      // never a stand-in for "not entered" (see backend validation).
      latitude: data.latitude === '' ? null : data.latitude,
      longitude: data.longitude === '' ? null : data.longitude,
    }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['projects-portfolio'] });
      onClose();
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
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div
        className="ss-animate-in w-full max-w-2xl rounded-2xl flex flex-col max-h-[90vh]"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New project</h2>
          <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {/* Form */}
        <div className="overflow-y-auto flex-1 px-6 py-5 space-y-4">
          {error && (
            <div className="px-4 py-3 rounded-lg text-sm" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#ef4444' }}>
              {error}
            </div>
          )}

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Project Name *</label>
              <input
                className={INPUT_CLS}
                style={inputStyle}
                value={form.name}
                onChange={e => set('name', e.target.value)}
                placeholder="e.g. High Street Development"
                aria-invalid={fieldErrors.name ? true : undefined}
                aria-describedby={fieldErrors.name ? 'project-name-error' : undefined}
              />
              {fieldErrors.name && (
                <p id="project-name-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.name[0]}</p>
              )}
            </div>
            <div>
              <label style={labelStyle}>Project Number / Code</label>
              <input className={INPUT_CLS} style={inputStyle} value={form.code} onChange={e => set('code', e.target.value)} placeholder="e.g. PRJ-2026-001" />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Contract Type</label>
              <Select className="w-full" value={form.contract_type} onChange={e => set('contract_type', e.target.value)}>
                <option value="">Select contract type…</option>
                {CONTRACT_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </Select>
            </div>
            <div>
              <label style={labelStyle}>Type of Work</label>
              <Select className="w-full" value={form.type} onChange={e => set('type', e.target.value)}>
                <option value="">Select type of work…</option>
                {WORK_TYPES.map(t => <option key={t} value={t}>{t}</option>)}
              </Select>
            </div>
          </div>

          <div>
            <label style={labelStyle}>Your organization&rsquo;s role on this project</label>
            <Select className="w-full" value={form.organization_role} onChange={e => set('organization_role', e.target.value)}>
              <option value="">Role not set</option>
              {PROJECT_ORGANIZATION_ROLE_OPTIONS.map(({ value, label }) => (
                <option key={value} value={value}>{label}</option>
              ))}
            </Select>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Tell SureSign how your organization is acting on this project. This can differ between projects.
            </p>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Contract Value</label>
              <input
                className={INPUT_CLS}
                style={inputStyle}
                type="number"
                min="0"
                step="0.01"
                value={form.contract_value}
                onChange={e => set('contract_value', e.target.value)}
                placeholder="0.00"
                aria-invalid={fieldErrors.contract_value ? true : undefined}
                aria-describedby={fieldErrors.contract_value ? 'project-contract-value-error' : undefined}
              />
              {fieldErrors.contract_value && (
                <p id="project-contract-value-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.contract_value[0]}</p>
              )}
            </div>
            <div>
              <label style={labelStyle}>Status</label>
              <Select className="w-full" value={form.status} onChange={e => set('status', e.target.value)}>
                <option value="active">Active</option>
                <option value="on_hold">On Hold</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </Select>
            </div>
          </div>

          <div>
            <label style={labelStyle}>Currency</label>
            <Select className="w-full" value={form.currency} onChange={e => set('currency', e.target.value)}>
              <option value="">Use organisation default — {user?.organization?.effective_currency ?? 'GBP'}</option>
              {SUPPORTED_CURRENCIES.map(code => (
                <option key={code} value={code}>{code}</option>
              ))}
            </Select>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label style={labelStyle}>Start Date</label>
              <input className={INPUT_CLS} style={inputStyle} type="date" value={form.start_date} onChange={e => set('start_date', e.target.value)} />
            </div>
            <div>
              <label style={labelStyle}>Completion Date</label>
              <input
                className={INPUT_CLS}
                style={inputStyle}
                type="date"
                value={form.end_date}
                onChange={e => set('end_date', e.target.value)}
                aria-invalid={fieldErrors.end_date ? true : undefined}
                aria-describedby={fieldErrors.end_date ? 'project-end-date-error' : undefined}
              />
              {fieldErrors.end_date && (
                <p id="project-end-date-error" className="text-xs mt-1" style={{ color: '#f87171' }}>
                  The completion date cannot be earlier than the start date.
                </p>
              )}
            </div>
          </div>

          {/* Project Location — address fields already existed on the model
              but had no form anywhere in the app; coordinates are new
              (Dashboard Project Map). Both are grouped together since they
              describe the same thing: where this project physically is. */}
          <div className="pt-2" style={{ borderTop: '1px solid var(--border)' }}>
            <p className="text-sm font-medium mb-3" style={{ color: 'var(--text-primary)' }}>Project Location</p>

            <div className="space-y-3">
              <div>
                <label style={labelStyle}>Address</label>
                <input className={INPUT_CLS} style={inputStyle} value={form.address} onChange={e => set('address', e.target.value)} placeholder="Street address" />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>City</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.city} onChange={e => set('city', e.target.value)} />
                </div>
                <div>
                  <label style={labelStyle}>State / Region</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.state} onChange={e => set('state', e.target.value)} />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Postcode / ZIP</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.postcode} onChange={e => set('postcode', e.target.value)} />
                </div>
                <div>
                  <label style={labelStyle}>Country</label>
                  <input className={INPUT_CLS} style={inputStyle} value={form.country} onChange={e => set('country', e.target.value)} placeholder="e.g. United Kingdom" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <label style={labelStyle}>Latitude</label>
                  <input
                    className={INPUT_CLS} style={inputStyle} type="number" step="any"
                    value={form.latitude} onChange={e => set('latitude', e.target.value)} placeholder="e.g. 51.5074"
                    aria-invalid={fieldErrors.latitude ? true : undefined}
                    aria-describedby={fieldErrors.latitude ? 'project-latitude-error' : undefined}
                  />
                  {fieldErrors.latitude && (
                    <p id="project-latitude-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.latitude[0]}</p>
                  )}
                </div>
                <div>
                  <label style={labelStyle}>Longitude</label>
                  <input
                    className={INPUT_CLS} style={inputStyle} type="number" step="any"
                    value={form.longitude} onChange={e => set('longitude', e.target.value)} placeholder="e.g. -0.1278"
                    aria-invalid={fieldErrors.longitude ? true : undefined}
                    aria-describedby={fieldErrors.longitude ? 'project-longitude-error' : undefined}
                  />
                  {fieldErrors.longitude && (
                    <p id="project-longitude-error" className="text-xs mt-1" style={{ color: '#f87171' }}>{fieldErrors.longitude[0]}</p>
                  )}
                </div>
              </div>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Optional. Used to position this project on the organisation Project Map.
              </p>
            </div>
          </div>

          <div>
            <label style={labelStyle}>Description</label>
            <textarea
              rows={3}
              className={INPUT_CLS}
              style={{ ...inputStyle, resize: 'vertical' }}
              value={form.description}
              onChange={e => set('description', e.target.value)}
              placeholder="Brief project overview…"
            />
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-3 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
          <Button variant="ghost" onClick={onClose}>
            Cancel
          </Button>
          <Button
            onClick={() => { setError(null); setFieldErrors({}); mutation.mutate(form); }}
            disabled={!form.name || mutation.isPending}
          >
            {mutation.isPending ? 'Creating…' : 'Create Project'}
          </Button>
        </div>
      </div>
    </div>
  );
}

function AttentionBadge({ attention }: { attention: PortfolioRow['attention'] }) {
  if (attention.requires_attention) {
    const count = attention.overdue_count + attention.due_today_count;
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.15)', color: '#f87171' }}>
        <AlertTriangle size={11} /> Requires Attention{count > 0 ? ` (${count})` : ''}
      </span>
    );
  }
  return (
    <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: 'rgba(34,197,94,0.12)', color: '#4ade80' }}>
      <CheckCircle2 size={11} /> On Track
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
      className={`group ss-animate-in rounded-2xl p-5 flex flex-col gap-4 transition-all duration-300 ${EASE} hover:-translate-y-0.5 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)]`}
      style={{
        backgroundColor: 'var(--bg-surface)',
        border: '1px solid var(--border)',
        animationDelay: staggerDelay(index),
      }}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-3 min-w-0">
          <div
            className="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold shrink-0 ring-1"
            style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)', boxShadow: 'inset 0 0 0 1px var(--gold-8)' }}
          >
            {row.name?.charAt(0)?.toUpperCase()}
          </div>
          <div className="min-w-0">
            <p className="text-sm font-semibold leading-tight truncate" style={{ color: 'var(--text-primary)' }}>
              {row.name}
            </p>
            <p className="font-mono text-[11px] mt-1 truncate" style={{ color: 'var(--text-muted)' }}>
              {row.reference ?? 'No reference'}{row.location ? ` · ${row.location}` : ''}
            </p>
          </div>
        </div>
        <Badge tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status.replace(/_/g, ' ')}</Badge>
      </div>

      <AttentionBadge attention={row.attention} />

      {pct !== null && (
        <div
          className="h-1 rounded-full overflow-hidden"
          style={{ backgroundColor: 'var(--bg-elevated)' }}
          title={`${Math.round(pct)}% of programme elapsed`}
        >
          <div className="h-full rounded-full" style={{ width: `${pct}%`, backgroundColor: 'var(--gold)' }} />
        </div>
      )}

      <div className="grid grid-cols-2 gap-2 pt-1" style={{ borderTop: '1px solid var(--border)' }}>
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
            {row.completion_date ? formatDate(row.completion_date) : '—'}
          </p>
        </div>
      </div>

      <div
        className={`flex items-center justify-end gap-1 text-xs opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300 ${EASE}`}
        style={{ color: 'var(--gold)' }}
      >
        Open workspace <ChevronRight size={13} />
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
      <div className="p-6 max-w-7xl mx-auto space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <div className="flex items-center gap-1.5">
              <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Projects</h1>
              <PageTourButton tourKey="page-projects" label="Take a tour of this page" />
            </div>
            <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
              The organisation-wide project portfolio
            </p>
          </div>
          <Button data-tour="projects-new" onClick={() => setShowCreate(true)}>
            <Plus size={15} />
            New project
          </Button>
        </div>

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
            {/* Portfolio Summary */}
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-3" data-tour="projects-summary">
              {[
                { label: 'Total Projects', value: data?.summary.total_projects, color: 'var(--text-primary)', icon: FolderKanban, emphasise: false },
                { label: 'Active Projects', value: data?.summary.active_projects, color: 'var(--text-primary)', icon: Activity, emphasise: false },
                { label: 'Requires Attention', value: data?.summary.projects_requiring_attention, color: '#f87171', icon: AlertTriangle, emphasise: (data?.summary.projects_requiring_attention ?? 0) > 0 },
                { label: 'Completed / Closed', value: data?.summary.completed_projects, color: 'var(--text-muted)', icon: Archive, emphasise: false },
              ].map(stat => (
                <div
                  key={stat.label}
                  className="relative overflow-hidden rounded-xl p-3.5 shadow-[var(--shadow-card)]"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
                >
                  {stat.emphasise && (
                    <div className="absolute inset-y-0 left-0 w-0.5" style={{ backgroundColor: '#f87171' }} />
                  )}
                  <div className="flex items-center justify-between">
                    <p className="text-[10px] uppercase tracking-widest font-semibold" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
                    <stat.icon size={13} style={{ color: stat.emphasise ? '#f87171' : 'var(--text-muted)', opacity: 0.7 }} />
                  </div>
                  <p className="text-2xl font-bold mt-1.5 tabular-nums" style={{ color: stat.color }}>
                    {isLoading ? '–' : stat.value}
                  </p>
                </div>
              ))}
            </div>

            {/* Filters */}
            <div className="flex gap-3 flex-wrap items-center" data-tour="projects-filters">
              <div className="relative">
                <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
                <input
                  value={search}
                  onChange={e => setParam({ search: e.target.value })}
                  placeholder="Search name, reference, location..."
                  aria-label="Search projects"
                  className="pl-9 pr-4 py-2 rounded-xl text-sm outline-none border border-[var(--border)] focus:border-[var(--gold)] transition-colors duration-200"
                  style={{ backgroundColor: 'var(--bg-surface)', color: 'var(--text-primary)', minWidth: '240px', boxShadow: 'var(--shadow-card)' }}
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
                  className="flex items-center gap-1 px-3 py-2 rounded-xl text-sm font-medium"
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
            <div data-tour="projects-grid">
              {isLoading ? (
                view === 'cards' ? (
                  <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                    {[...Array(6)].map((_, i) => (
                      <div key={i} className="h-44 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
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
                <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4" style={{ opacity: isFetching ? 0.6 : 1 }}>
                  {rows.map((row, i) => (
                    <ProjectCard key={row.id} row={row} index={i} formatCurrency={formatCurrency} />
                  ))}
                </div>
              ) : (
                <>
                  {/* Desktop table */}
                  <div className="hidden md:block rounded-xl overflow-x-auto" style={{ border: '1px solid var(--border)', opacity: isFetching ? 0.6 : 1 }}>
                    <table className="w-full text-sm">
                      <thead>
                        <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                          {['Project', 'Status', 'Location', 'Contract Type', 'Completion', 'Attention', 'Outstanding', 'Retention', 'Last Activity', ''].map(h => (
                            <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{h}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {rows.map(row => (
                          <tr key={row.id} style={{ borderBottom: '1px solid var(--border)' }} className="hover:bg-[var(--bg-hover)] transition-colors">
                            <td className="px-3 py-3">
                              <Link href={row.urls.workspace} className="block">
                                <p className="font-medium truncate max-w-[220px]" style={{ color: 'var(--text-primary)' }}>{row.name}</p>
                                <p className="font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>{row.reference ?? 'No reference'}</p>
                              </Link>
                            </td>
                            <td className="px-3 py-3"><Badge tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status.replace(/_/g, ' ')}</Badge></td>
                            <td className="px-3 py-3 whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{row.location ?? '—'}</td>
                            <td className="px-3 py-3 whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{row.contract_type ?? '—'}</td>
                            <td className="px-3 py-3 whitespace-nowrap tabular-nums" style={{ color: 'var(--text-secondary)' }}>{row.completion_date ?? '—'}</td>
                            <td className="px-3 py-3 whitespace-nowrap"><AttentionBadge attention={row.attention} /></td>
                            <td className="px-3 py-3 whitespace-nowrap tabular-nums font-semibold" style={{ color: row.commercial.outstanding > 0 ? '#fb923c' : 'var(--text-secondary)' }}>
                              {formatCurrency(row.commercial.outstanding, row.commercial.currency)}
                            </td>
                            <td className="px-3 py-3 whitespace-nowrap tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                              {formatCurrency(row.commercial.retention_held, row.commercial.currency)}
                            </td>
                            <td className="px-3 py-3 whitespace-nowrap text-xs" style={{ color: 'var(--text-muted)' }}>
                              {row.last_activity ? row.last_activity.description : '—'}
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
                  <div className="md:hidden space-y-3" style={{ opacity: isFetching ? 0.6 : 1 }}>
                    {rows.map(row => (
                      <Link
                        key={row.id}
                        href={row.urls.workspace}
                        className="block rounded-2xl p-4 space-y-2"
                        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
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
