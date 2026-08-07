'use client';

import { useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import {
  ChevronLeft, LifeBuoy, ClipboardList, Search, Paperclip, MessageSquare,
} from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { ContactSupportForm } from '@/components/support/ContactSupportForm';
import { EmergencyBanner } from '@/components/support/EmergencyBanner';
import Select from '@/components/ui/Select';
import {
  SUPPORT_CATEGORIES, SUPPORT_STATUSES, SUPPORT_STATUS_LABELS, SUPPORT_STATUS_COLORS,
} from '@/lib/supportContext';

type Tab = 'new' | 'requests';

interface TicketSummary {
  id: number;
  reference: string;
  subject: string;
  category: string | null;
  priority: string | null;
  status: string;
  has_screenshot: boolean;
  created_at: string;
  updated_at: string;
  latest_message_preview: string | null;
  unread_by_client: boolean;
}

interface PaginatedResponse {
  data: TicketSummary[];
  current_page: number;
  last_page: number;
  total: number;
}

function StatusBadge({ status }: { status: string }) {
  const badge = SUPPORT_STATUS_COLORS[status] || SUPPORT_STATUS_COLORS.closed;
  return (
    <span
      className="flex-shrink-0 px-2 py-0.5 rounded-full text-[11px] font-medium"
      style={{ backgroundColor: badge.bg, color: badge.text }}
    >
      {SUPPORT_STATUS_LABELS[status] ?? status}
    </span>
  );
}

function MyRequestsTab() {
  const router = useRouter();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [category, setCategory] = useState('');
  const [priority, setPriority] = useState('');
  const [page, setPage] = useState(1);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['my-support-requests', search, status, category, priority, page],
    queryFn: () =>
      api
        .get('/support-tickets', {
          params: { search: search || undefined, status: status || undefined, category: category || undefined, priority: priority || undefined, page },
        })
        .then(r => r.data as PaginatedResponse),
  });

  return (
    <div className="space-y-4">
      {/* Search + filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative flex-1 min-w-[200px]">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => { setSearch(e.target.value); setPage(1); }}
            placeholder="Search by reference or subject…"
            className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>
        <Select
          value={status}
          onChange={e => { setStatus(e.target.value); setPage(1); }}
          aria-label="Filter by status"
        >
          <option value="">All statuses</option>
          {SUPPORT_STATUSES.filter(s => s !== 'open').map(s => <option key={s} value={s}>{SUPPORT_STATUS_LABELS[s]}</option>)}
        </Select>
        <Select
          value={category}
          onChange={e => { setCategory(e.target.value); setPage(1); }}
          aria-label="Filter by category"
        >
          <option value="">All categories</option>
          {SUPPORT_CATEGORIES.map(c => <option key={c.value} value={c.value}>{c.label}</option>)}
        </Select>
        <Select
          value={priority}
          onChange={e => { setPriority(e.target.value); setPage(1); }}
          aria-label="Filter by priority"
        >
          <option value="">All priorities</option>
          <option value="low">Low</option>
          <option value="normal">Normal</option>
          <option value="high">High</option>
        </Select>
      </div>

      {/* List */}
      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {isError ? (
          <p className="px-5 py-6 text-sm" style={{ color: '#f87171' }}>Your requests could not be loaded. Please try again.</p>
        ) : isLoading ? (
          <div className="p-5 space-y-2">
            {[...Array(4)].map((_, i) => (
              <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ))}
          </div>
        ) : !data?.data.length ? (
          <div className="px-5 py-14 text-center">
            <ClipboardList size={26} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No support requests yet.</p>
          </div>
        ) : (
          <div>
            {data.data.map(ticket => (
              <button
                key={ticket.id}
                onClick={() => router.push(`/app/help/support/${ticket.id}`)}
                className="w-full flex items-center justify-between gap-3 px-5 py-3.5 text-left transition-colors hover:bg-[var(--bg-hover)]"
                style={{ borderBottom: '1px solid var(--border)' }}
              >
                <div className="min-w-0 flex items-start gap-2.5">
                  {ticket.unread_by_client && (
                    <span className="mt-1.5 w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: 'var(--gold)' }} aria-label="Unread reply" title="Unread reply" />
                  )}
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-mono flex-shrink-0" style={{ color: 'var(--text-muted)' }}>{ticket.reference}</span>
                      <span className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{ticket.subject}</span>
                      {ticket.has_screenshot && <Paperclip size={11} className="flex-shrink-0" style={{ color: 'var(--text-muted)' }} />}
                    </div>
                    {ticket.latest_message_preview ? (
                      <p className="text-xs mt-0.5 truncate flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
                        <MessageSquare size={10} className="flex-shrink-0" />
                        {ticket.latest_message_preview}
                      </p>
                    ) : (
                      <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                        {formatDate(ticket.created_at)}
                      </p>
                    )}
                  </div>
                </div>
                <StatusBadge status={ticket.status} />
              </button>
            ))}
          </div>
        )}

        {data && data.last_page > 1 && (
          <div className="flex items-center justify-between px-5 py-3" style={{ borderTop: '1px solid var(--border)' }}>
            <button
              onClick={() => setPage(p => Math.max(1, p - 1))}
              disabled={page <= 1}
              className="text-xs font-medium disabled:opacity-40"
              style={{ color: 'var(--gold)' }}
            >
              Previous
            </button>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Page {data.current_page} of {data.last_page}</span>
            <button
              onClick={() => setPage(p => Math.min(data.last_page, p + 1))}
              disabled={page >= data.last_page}
              className="text-xs font-medium disabled:opacity-40"
              style={{ color: 'var(--gold)' }}
            >
              Next
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

export default function SupportPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const initialCategory = searchParams.get('category') ?? undefined;
  const initialRoute = searchParams.get('route') ?? undefined;
  const initialModule = searchParams.get('module') ?? undefined;

  const [tab, setTab] = useState<Tab>(searchParams.get('tab') === 'requests' ? 'requests' : 'new');

  // Switching tabs updates the URL (no reload) so each tab stays a real,
  // shareable/bookmarkable link — e.g. the Help flyout's "My Support
  // Requests" entry points straight at ?tab=requests.
  function selectTab(next: Tab) {
    setTab(next);
    const params = new URLSearchParams(searchParams.toString());
    if (next === 'requests') params.set('tab', 'requests');
    else params.delete('tab');
    const query = params.toString();
    router.replace(`/app/help/support${query ? `?${query}` : ''}`, { scroll: false });
  }

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-6">
      <Link
        href="/app/help"
        className="inline-flex items-center gap-1 text-xs font-medium hover:opacity-80"
        style={{ color: 'var(--text-muted)' }}
      >
        <ChevronLeft size={13} />
        Help Center
      </Link>

      {/* Header */}
      <div className="flex items-start gap-3">
        <div className="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--gold-15)' }}>
          <LifeBuoy size={20} style={{ color: 'var(--gold)' }} />
        </div>
        <div>
          <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Support</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Send a new request, or check on ones you&apos;ve already sent.
          </p>
        </div>
      </div>

      <EmergencyBanner />

      {/* Tabs */}
      <div className="flex gap-1 p-1 rounded-full w-fit" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <button
          onClick={() => selectTab('new')}
          className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium transition-all"
          style={tab === 'new' ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}
        >
          <LifeBuoy size={13} />
          New Request
        </button>
        <button
          onClick={() => selectTab('requests')}
          className="flex items-center gap-1.5 px-3.5 py-1.5 rounded-full text-xs font-medium transition-all"
          style={tab === 'requests' ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}
        >
          <ClipboardList size={13} />
          My Requests
        </button>
      </div>

      {tab === 'new' ? (
        <ContactSupportForm
          key={`${initialCategory ?? 'default'}:${initialRoute ?? ''}`}
          initialCategory={initialCategory}
          initialRoute={initialRoute}
          initialModule={initialModule}
        />
      ) : (
        <MyRequestsTab />
      )}
    </div>
  );
}
