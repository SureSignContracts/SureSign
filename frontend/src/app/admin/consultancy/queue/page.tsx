'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { HeartHandshake, Search, Settings2, UserX, AlertTriangle, X, Clock, ChevronRight } from 'lucide-react';
import api from '@/lib/api';
import { formatDateTime } from '@/lib/dateTime';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

const ENGAGEMENT_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'awaiting_consultant', label: 'Awaiting Consultant' },
  { key: 'awaiting_customer', label: 'Awaiting Customer' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
] as const;

const ENGAGEMENT_COLOR: Record<string, string> = {
  awaiting_consultant: '#b7791f', awaiting_customer: '#4779c7', completed: '#299a54', cancelled: '#817b76',
};

const SORT_OPTIONS = [
  { key: 'consultation_date', dir: 'asc', label: 'Consultation date (soonest first)' },
  { key: 'created', dir: 'desc', label: 'Newest first' },
  { key: 'created', dir: 'asc', label: 'Oldest first' },
  { key: 'updated', dir: 'desc', label: 'Recently updated' },
  { key: 'customer', dir: 'asc', label: 'Customer (A–Z)' },
  { key: 'reference', dir: 'asc', label: 'Reference' },
] as const;

interface ConsultancyServiceOption { id: number; code: string; display_name: string }

interface QueueRow {
  id: number;
  reference: string;
  status: string;
  starts_at: string;
  booking_timezone: string;
  attendee_name: string;
  organization: { name: string } | null;
  assigned_consultant: { id: number; name: string } | null;
  consultation_enquiry: {
    title: string;
    engagement_status: string;
    consultancy_service: { code: string; display_name: string } | null;
  } | null;
}

export default function ConsultancyQueuePage() {
  const searchParams = useSearchParams();

  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [engagementFilter, setEngagementFilter] = useState<string>(() => searchParams.get('engagement_status') ?? 'all');
  const [serviceFilter, setServiceFilter] = useState<string>('all');
  const [sortIndex, setSortIndex] = useState(0);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  // Deep-link-only filters (Batch 6A) — set exclusively by the Consultancy
  // Dashboard's quick links (?unassigned=1 / ?overdue_awaiting_customer=1),
  // not exposed as their own filter chips in this page's own UI. Shown as a
  // single clearable pill so an operator arriving from the dashboard can
  // see and remove the filter without losing the rest of the queue's state.
  const [unassignedOnly, setUnassignedOnly] = useState(() => searchParams.get('unassigned') === '1');
  const [overdueOnly, setOverdueOnly] = useState(() => searchParams.get('overdue_awaiting_customer') === '1');

  useEffect(() => {
    const t = setTimeout(() => { setDebouncedSearch(search); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const { data: services } = useQuery({
    queryKey: ['consultancy-services', 'all'],
    queryFn: () => api.get('/consultancy-services').then(r => r.data as ConsultancyServiceOption[]),
  });

  const sort = SORT_OPTIONS[sortIndex];

  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin-consultancy-queue', debouncedSearch, engagementFilter, serviceFilter, unassignedOnly, overdueOnly, sort, page, perPage],
    queryFn: () => {
      const params: Record<string, string | number> = {
        page, per_page: perPage, sort_by: sort.key, sort_dir: sort.dir,
      };
      if (debouncedSearch) params.search = debouncedSearch;
      if (engagementFilter !== 'all') params.engagement_status = engagementFilter;
      if (serviceFilter !== 'all') params.consultancy_service_id = serviceFilter;
      if (unassignedOnly) params.unassigned = 1;
      if (overdueOnly) params.overdue_awaiting_customer = 1;
      return api.get('/admin/consultancy/consultations', { params }).then(r => r.data);
    },
    placeholderData: (prev: any) => prev,
  });

  const rows: QueueRow[] = data?.data ?? [];
  const meta = data ?? {};
  const unassignedCount = rows.filter(row => !row.assigned_consultant).length;
  const awaitingCount = rows.filter(row => row.consultation_enquiry?.engagement_status?.startsWith('awaiting_')).length;

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero eyebrow="Engagement operations" title="Consultancy Queue" description="Triage every booked consultation, customer response and consultant assignment from one working queue." loading={isLoading}
        metrics={[
          { label: 'Engagements', value: meta.total ?? rows.length, detail: 'in the queue', icon: HeartHandshake },
          { label: 'Awaiting action', value: awaitingCount, detail: 'in the current view', icon: Clock },
          { label: 'Unassigned', value: unassignedCount, detail: 'without a consultant', icon: UserX },
        ]}
        action={<Link href="/admin/consultancy/services" className="flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-sm font-semibold text-[#18211d] transition-colors hover:bg-[#b3efc6]"><Settings2 size={14} />Services</Link>}
      />

      {/* Deep-link filter pills (Batch 6A — set only via the Dashboard's quick links) */}
      {(unassignedOnly || overdueOnly) && (
        <div className="flex flex-wrap items-center gap-2 ss-animate-in">
          {unassignedOnly && (
            <button
              onClick={() => { setUnassignedOnly(false); setPage(1); }}
              className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}
            >
              <UserX size={12} /> Unassigned only <X size={12} />
            </button>
          )}
          {overdueOnly && (
            <button
              onClick={() => { setOverdueOnly(false); setPage(1); }}
              className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171' }}
            >
              <AlertTriangle size={12} /> Overdue awaiting customer (7+ days) <X size={12} />
            </button>
          )}
        </div>
      )}

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3 ss-animate-in" style={{ animationDelay: '50ms' }}>
        <div className="flex items-center gap-1 flex-wrap" role="tablist" aria-label="Filter by engagement status">
          {ENGAGEMENT_FILTERS.map(f => (
            <button
              key={f.key}
              role="tab"
              aria-selected={engagementFilter === f.key}
              onClick={() => { setEngagementFilter(f.key); setPage(1); }}
              className="px-3.5 py-1.5 rounded-full text-xs font-medium transition-all duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] active:scale-[0.96] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              style={
                engagementFilter === f.key
                  ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', boxShadow: 'var(--shadow-card)' }
                  : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }
              }
            >
              {f.label}
            </button>
          ))}
        </div>
        <Select value={serviceFilter} onChange={e => { setServiceFilter(e.target.value); setPage(1); }} className="rounded-full text-xs" aria-label="Filter by service">
          <option value="all">All services</option>
          {(services ?? []).map(s => <option key={s.id} value={s.id}>{s.display_name}</option>)}
        </Select>
        <Select value={sortIndex} onChange={e => setSortIndex(Number(e.target.value))} className="rounded-full text-xs" aria-label="Sort">
          {SORT_OPTIONS.map((s, i) => <option key={i} value={i}>{s.label}</option>)}
        </Select>
        <div className="relative flex-1 min-w-[200px]">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} aria-hidden="true" />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search by reference, customer, organisation, or service…"
            aria-label="Search consultations"
            className="w-full pl-9 pr-4 py-2 rounded-full text-sm outline-none transition-colors"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>
      </div>

      {isLoading ? <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{[...Array(6)].map((_, i) => <div key={i} className="h-64 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />)}</div>
      : isError ? <div className="rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}><EmptyState icon={HeartHandshake} title="Couldn't load the queue" description="Something went wrong fetching consultations." action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>} /></div>
      : rows.length === 0 ? <div className="rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}><EmptyState icon={HeartHandshake} title="No consultations found" description={debouncedSearch || engagementFilter !== 'all' || serviceFilter !== 'all' ? 'Try adjusting your search or filters.' : 'Bookings will appear here once customers start booking consultations.'} /></div>
      : <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((row, index) => {
            const engagement = row.consultation_enquiry?.engagement_status;
            const color = ENGAGEMENT_COLOR[engagement ?? ''] ?? 'var(--text-muted)';
            return <Link key={row.id} href={`/admin/consultancy/queue/${row.id}`} className="group flex min-h-[256px] flex-col rounded-2xl p-5 transition-all duration-300 hover:-translate-y-1 hover:border-[#9ee5b5]/70 hover:shadow-[0_18px_36px_rgba(24,33,29,0.10)] ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 3px 12px rgba(24,33,29,0.05)', animationDelay: `${Math.min(index * 45, 405)}ms` }}>
              <div className="flex items-center justify-between"><span className="font-mono text-[10px] tracking-[0.06em]" style={{ color: 'var(--text-muted)' }}>{row.reference}</span><span className="inline-flex items-center gap-1.5 text-[11px] font-medium capitalize" style={{ color }}><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: color }} />{engagement?.replace(/_/g, ' ') ?? row.status}</span></div>
              <p className="mt-5 text-base font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>{row.consultation_enquiry?.title || row.attendee_name}</p>
              <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>{row.attendee_name} · {row.organization?.name ?? 'Independent'}</p>
              <div className="mt-5 border-y py-3" style={{ borderColor: 'var(--border)' }}><p className="text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{row.consultation_enquiry?.consultancy_service?.display_name ?? 'General consultation'}</p><p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>{formatDateTime(row.starts_at, { timeZone: row.booking_timezone })}</p></div>
              <div className="mt-auto flex items-center justify-between pt-4"><span className="text-xs" style={{ color: row.assigned_consultant ? 'var(--text-secondary)' : '#d25454' }}>{row.assigned_consultant?.name ?? 'Needs consultant'}</span><span className="flex h-7 w-7 items-center justify-center rounded-full transition-colors group-hover:bg-[#9ee5b5]"><ChevronRight size={14} className="transition-transform group-hover:translate-x-0.5" /></span></div>
            </Link>;
          })}
        </div>}

      {!isLoading && meta.total > 0 && (
        <PaginationBar page={meta.current_page ?? page} lastPage={meta.last_page ?? 1} total={meta.total ?? 0} perPage={perPage} onPage={setPage} onPerPage={n => { setPerPage(n); setPage(1); }} />
      )}
    </div>
  );
}
