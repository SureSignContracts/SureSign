'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { HeartHandshake, Search, Settings2, UserX, AlertTriangle, X } from 'lucide-react';
import api from '@/lib/api';
import { formatDateTime } from '@/lib/dateTime';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';

const ENGAGEMENT_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'awaiting_consultant', label: 'Awaiting Consultant' },
  { key: 'awaiting_customer', label: 'Awaiting Customer' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
] as const;

const ENGAGEMENT_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'info' | 'danger' | 'accent'> = {
  awaiting_consultant: 'warning', awaiting_customer: 'info', completed: 'success', cancelled: 'neutral',
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
  const router = useRouter();
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

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3 ss-animate-in">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <HeartHandshake size={20} /> Consultancy
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
            Every booked consultation — Super Admin and every Admin can view any engagement here for operational continuity.
          </p>
        </div>
        <Link href="/admin/consultancy/services">
          <Button variant="secondary" size="md" className="rounded-full"><Settings2 size={14} /> Consultancy Services</Button>
        </Link>
      </div>

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

      {/* Table */}
      <div className="rounded-2xl overflow-x-auto ss-animate-in" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '100ms' }}>
        <table className="w-full min-w-[920px]">
          <caption className="sr-only">Consultancy engagement queue</caption>
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Reference', 'Customer', 'Service', 'When', 'Consultant', 'Engagement', 'Appointment'].map((h, i) => (
                <th key={i} scope="col" className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(5)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                  {[...Array(7)].map((_, j) => (
                    <td key={j} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: j === 0 ? '60%' : '40%' }} /></td>
                  ))}
                </tr>
              ))
            ) : isError ? (
              <tr>
                <td colSpan={7}>
                  <EmptyState
                    icon={HeartHandshake}
                    title="Couldn't load the queue"
                    description="Something went wrong fetching consultations."
                    action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}
                  />
                </td>
              </tr>
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={7}>
                  <EmptyState
                    icon={HeartHandshake}
                    title="No consultations found"
                    description={debouncedSearch || engagementFilter !== 'all' || serviceFilter !== 'all' ? 'Try adjusting your search or filters.' : 'Bookings will appear here once customers start booking consultations.'}
                  />
                </td>
              </tr>
            ) : rows.map((row, idx) => (
              <tr
                key={row.id}
                onClick={() => router.push(`/admin/consultancy/queue/${row.id}`)}
                className="ss-animate-in cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
                style={{
                  borderBottom: idx < rows.length - 1 ? '1px solid var(--border)' : undefined,
                  backgroundColor: 'var(--bg-surface)',
                  animationDelay: `${Math.min(idx * 40, 320)}ms`,
                }}
              >
                <td className="px-4 py-3">
                  <Link
                    href={`/admin/consultancy/queue/${row.id}`}
                    onClick={e => e.stopPropagation()}
                    className="text-sm font-medium transition-colors hover:text-[var(--gold)] hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    {row.reference}
                  </Link>
                </td>
                <td className="px-4 py-3">
                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{row.attendee_name}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{row.organization?.name ?? '—'}</p>
                </td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{row.consultation_enquiry?.consultancy_service?.display_name ?? '—'}</td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>
                  {formatDateTime(row.starts_at, { timeZone: row.booking_timezone })}
                </td>
                <td className="px-4 py-3 text-sm" style={{ color: row.assigned_consultant ? 'var(--text-secondary)' : 'var(--text-muted)' }}>
                  {row.assigned_consultant?.name ?? 'Unassigned'}
                </td>
                <td className="px-4 py-3">
                  {row.consultation_enquiry && (
                    <Badge tone={ENGAGEMENT_TONE[row.consultation_enquiry.engagement_status]}>
                      {row.consultation_enquiry.engagement_status.replace(/_/g, ' ')}
                    </Badge>
                  )}
                </td>
                <td className="px-4 py-3"><Badge status={row.status} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {!isLoading && meta.total > 0 && (
        <PaginationBar page={meta.current_page ?? page} lastPage={meta.last_page ?? 1} total={meta.total ?? 0} perPage={perPage} onPage={setPage} onPerPage={n => { setPerPage(n); setPage(1); }} />
      )}
    </div>
  );
}
