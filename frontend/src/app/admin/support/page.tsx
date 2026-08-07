'use client';

import { useState } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import {
  LifeBuoy, Search, Clock, CheckCircle2, AlertCircle, MessageSquare, X,
  Paperclip, MapPin, Lock, Send, EyeOff,
} from 'lucide-react';
import { ScreenshotPreview, SupportTicketScreenshot } from '@/components/support/ScreenshotPreview';
import { RecentActivityList, RecentActivityEntry } from '@/components/support/RecentActivityList';
import { SUPPORT_CATEGORIES, SUPPORT_STATUSES, SUPPORT_STATUS_LABELS, SUPPORT_STATUS_COLORS } from '@/lib/supportContext';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import Select from '@/components/ui/Select';

interface TicketSummary {
  id: number;
  reference: string;
  subject: string;
  category: string | null;
  priority: string | null;
  status: string;
  company: { id: number; name: string } | null;
  submitted_by: string | null;
  submitted_by_email: string | null;
  created_at: string;
  updated_at: string;
  has_screenshot: boolean;
  latest_message_preview: string | null;
  unread_by_support: boolean;
}

interface TicketDetail extends TicketSummary {
  message: string;
  route: string | null;
  module: string | null;
  project: { id: number; name: string } | null;
  trade_package: { id: number; name: string } | null;
  diagnostics: Record<string, string | number> | null;
  recent_activity: RecentActivityEntry[] | null;
  screenshot: SupportTicketScreenshot | null;
}

interface ThreadMessage {
  id: number;
  sender_type: 'customer' | 'support';
  sender_name: string | null;
  body: string;
  created_at: string;
  visibility: 'public' | 'internal';
  screenshot: SupportTicketScreenshot | null;
}

interface PaginatedResponse {
  data: TicketSummary[];
  current_page: number;
  last_page: number;
  total: number;
  counts: Record<string, number>;
}

// Mirrors SupportTicketStatusService::OPERATOR_TRANSITIONS on the backend —
// used here only to disable buttons that would fail server-side, never as
// the actual authority (the backend re-validates every transition itself).
const OPERATOR_TRANSITIONS: Record<string, string[]> = {
  open:                ['waiting_for_support', 'waiting_for_you', 'resolved', 'closed'],
  waiting_for_support: ['waiting_for_you', 'resolved', 'closed'],
  waiting_for_you:     ['waiting_for_support', 'resolved', 'closed'],
  resolved:            ['waiting_for_support', 'closed'],
  closed:              ['waiting_for_support'],
};

function StatusBadge({ status }: { status: string }) {
  const badge = SUPPORT_STATUS_COLORS[status] || SUPPORT_STATUS_COLORS.closed;
  return (
    <span className="px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: badge.bg, color: badge.text }}>
      {SUPPORT_STATUS_LABELS[status] ?? status}
    </span>
  );
}

function MessageRow({ message }: { message: ThreadMessage }) {
  const isInternal = message.visibility === 'internal';
  return (
    <div
      className="rounded-xl p-3"
      style={{
        backgroundColor: isInternal ? 'rgba(234,179,8,0.08)' : 'var(--bg-elevated)',
        border: `1px solid ${isInternal ? 'rgba(234,179,8,0.3)' : 'var(--border)'}`,
      }}
    >
      <div className="flex items-center justify-between gap-2 mb-1.5">
        <div className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
          <span className="font-medium" style={{ color: 'var(--text-secondary)' }}>
            {message.sender_type === 'support' ? (message.sender_name || 'SureSign Support') : (message.sender_name || 'Customer')}
          </span>
          <span>·</span>
          <span>{formatDateTime(message.created_at, { timeZone: useAuthStore.getState().user?.effective_timezone })}</span>
        </div>
        {isInternal && (
          <span className="flex items-center gap-1 text-[11px] font-medium px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(234,179,8,0.15)', color: '#facc15' }}>
            <EyeOff size={10} /> Internal note
          </span>
        )}
      </div>
      <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-primary)' }}>{message.body}</p>
      {message.screenshot && (
        <div className="mt-2">
          <ScreenshotPreview ticketId={message.id} screenshot={message.screenshot} />
        </div>
      )}
    </div>
  );
}

function TicketModal({ ticketId, onClose }: { ticketId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [replyBody, setReplyBody] = useState('');
  const [visibility, setVisibility] = useState<'public' | 'internal'>('public');
  const [error, setError] = useState('');

  const { data: ticket, isLoading, isError } = useQuery({
    queryKey: ['admin-support-ticket', ticketId],
    queryFn: () => api.get(`/admin/support-tickets/${ticketId}`).then(r => r.data.data as TicketDetail),
  });

  const { data: thread } = useQuery({
    queryKey: ['admin-support-ticket-thread', ticketId],
    queryFn: () => api.get(`/support-tickets/${ticketId}/messages`).then(r => r.data.data as ThreadMessage[]),
    enabled: !!ticket,
  });

  const statusMutation = useMutation({
    mutationFn: (status: string) => api.put(`/admin/support-tickets/${ticketId}`, { status }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-support-tickets'] });
      qc.invalidateQueries({ queryKey: ['admin-support-ticket', ticketId] });
    },
    onError: (err) => setError(getErrorMessage(err, 'Could not update the status.')),
  });

  const replyMutation = useMutation({
    mutationFn: () => api.post(`/support-tickets/${ticketId}/messages`, { body: replyBody, visibility }).then(r => r.data),
    onSuccess: () => {
      setReplyBody('');
      setError('');
      qc.invalidateQueries({ queryKey: ['admin-support-ticket-thread', ticketId] });
      qc.invalidateQueries({ queryKey: ['admin-support-tickets'] });
      qc.invalidateQueries({ queryKey: ['admin-support-ticket', ticketId] });
    },
    onError: (err) => setError(getErrorMessage(err, 'Could not send the reply.')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-2xl rounded-2xl p-6 ss-animate-in max-h-[88vh] overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        {isError ? (
          <p className="text-sm" style={{ color: '#f87171' }}>This ticket could not be loaded.</p>
        ) : isLoading || !ticket ? (
          <div className="space-y-3">
            <div className="h-4 w-1/3 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            <div className="h-20 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          </div>
        ) : (
          <>
            <div className="flex items-start justify-between mb-4">
              <div>
                <p className="text-xs font-mono" style={{ color: 'var(--text-muted)' }}>{ticket.reference ?? `#${ticket.id}`}</p>
                <h2 className="text-base font-semibold mt-0.5" style={{ color: 'var(--text-primary)' }}>{ticket.subject}</h2>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                  {ticket.company?.name ?? 'Unknown company'} · {ticket.submitted_by ?? 'Unknown user'}
                  {ticket.submitted_by_email ? ` (${ticket.submitted_by_email})` : ''}
                </p>
                <div className="flex flex-wrap items-center gap-1.5 mt-1.5">
                  <StatusBadge status={ticket.status} />
                  {ticket.category && (
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-medium capitalize" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                      {ticket.category.replace(/_/g, ' ')}
                    </span>
                  )}
                  {ticket.priority && ticket.priority !== 'normal' && (
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-medium capitalize" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                      {ticket.priority} priority
                    </span>
                  )}
                </div>
              </div>
              <button onClick={onClose} aria-label="Close"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
            </div>

            {(ticket.route || ticket.project || ticket.trade_package) && (
              <div className="flex items-start gap-1.5 text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
                <MapPin size={12} className="mt-0.5 flex-shrink-0" />
                <span>
                  {ticket.module && <>{ticket.module} — </>}
                  {ticket.route}
                  {ticket.project && <> · Project: {ticket.project.name}</>}
                  {ticket.trade_package && <> · Trade package: {ticket.trade_package.name}</>}
                </span>
              </div>
            )}

            <div className="rounded-xl p-4 mb-4 text-sm whitespace-pre-wrap" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
              {ticket.message}
            </div>

            {ticket.screenshot && (
              <div className="mb-4">
                <h3 className="text-xs font-semibold uppercase tracking-wider mb-2 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
                  <Paperclip size={11} /> Screenshot
                </h3>
                <ScreenshotPreview ticketId={ticket.id} screenshot={ticket.screenshot} />
              </div>
            )}

            {ticket.diagnostics && (
              <div className="mb-4">
                <h3 className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>Diagnostics</h3>
                <div className="rounded-xl p-3 text-xs grid grid-cols-2 gap-x-4 gap-y-1" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
                  {Object.entries(ticket.diagnostics).map(([key, value]) => (
                    <div key={key} className="flex justify-between gap-2 truncate">
                      <span style={{ color: 'var(--text-muted)' }} className="capitalize">{key.replace(/_/g, ' ')}</span>
                      <span className="truncate">{String(value)}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            {ticket.recent_activity && ticket.recent_activity.length > 0 && (
              <div className="mb-4">
                <RecentActivityList entries={ticket.recent_activity} />
              </div>
            )}

            {/* Conversation thread */}
            {thread && thread.length > 0 && (
              <div className="mb-4 space-y-2">
                <h3 className="text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Conversation</h3>
                {thread.map(message => <MessageRow key={message.id} message={message} />)}
              </div>
            )}

            {error && <p className="text-xs mb-2" style={{ color: '#f87171' }} role="alert">{error}</p>}

            {/* Reply / internal note */}
            <div className="rounded-xl p-3 mb-4 space-y-2" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              <textarea
                value={replyBody}
                onChange={e => setReplyBody(e.target.value)}
                placeholder="Write a reply or internal note…"
                aria-label="Reply or internal note"
                rows={3}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
              <div className="flex items-center justify-between">
                <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                  {(['public', 'internal'] as const).map(v => (
                    <button
                      key={v}
                      onClick={() => setVisibility(v)}
                      className="px-2.5 py-1 rounded-full text-xs font-medium transition-all"
                      style={visibility === v
                        ? { backgroundColor: v === 'internal' ? 'rgba(234,179,8,0.2)' : 'var(--gold)', color: v === 'internal' ? '#facc15' : 'var(--accent-fg)' }
                        : { color: 'var(--text-secondary)' }}
                    >
                      {v === 'internal' ? <span className="flex items-center gap-1"><Lock size={10} /> Internal note</span> : 'Reply to customer'}
                    </button>
                  ))}
                </div>
                <button
                  onClick={() => replyMutation.mutate()}
                  disabled={!replyBody.trim() || replyMutation.isPending}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  <Send size={12} />
                  {replyMutation.isPending ? 'Sending…' : 'Send'}
                </button>
              </div>
            </div>

            <div className="flex items-center justify-end gap-1.5">
              {(OPERATOR_TRANSITIONS[ticket.status] ?? []).map(s => (
                <button
                  key={s}
                  onClick={() => statusMutation.mutate(s)}
                  disabled={statusMutation.isPending}
                  className="px-2.5 py-1 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50 hover:opacity-80"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                >
                  Mark {SUPPORT_STATUS_LABELS[s]}
                </button>
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
}

export default function AdminSupportPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [category, setCategory] = useState('');
  const [priority, setPriority] = useState('');
  const [page, setPage] = useState(1);
  const [openTicketId, setOpenTicketId] = useState<number | null>(() => {
    const t = searchParams.get('ticket');
    return t && /^\d+$/.test(t) ? Number(t) : null;
  });

  function closeModal() {
    setOpenTicketId(null);
    if (searchParams.get('ticket')) {
      router.replace('/admin/support', { scroll: false });
    }
  }

  const { data, isLoading } = useQuery({
    queryKey: ['admin-support-tickets', search, status, category, priority, page],
    queryFn: () =>
      api.get('/admin/support-tickets', {
        params: { search: search || undefined, status: status || undefined, category: category || undefined, priority: priority || undefined, page },
      }).then(r => r.data as PaginatedResponse).catch((): PaginatedResponse => ({ data: [], current_page: 1, last_page: 1, total: 0, counts: {} })),
  });

  const tickets = data?.data ?? [];

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Support</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Support tickets submitted from across all organizations</p>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-4 gap-4">
        {[
          { label: 'Waiting for Support', value: data?.counts?.waiting_for_support ?? 0, icon: AlertCircle, color: '#facc15' },
          { label: 'Waiting for You',      value: data?.counts?.waiting_for_you ?? 0,      icon: Clock,       color: '#60a5fa' },
          { label: 'Resolved',             value: data?.counts?.resolved ?? 0,             icon: CheckCircle2, color: '#4ade80' },
          { label: 'Total',                value: data?.counts?.total ?? 0,                icon: MessageSquare, color: 'var(--gold)' },
        ].map((stat, i) => (
          <div
            key={stat.label}
            className="rounded-xl p-4 flex items-center gap-3 ss-animate-in"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
          >
            <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ backgroundColor: stat.color + '18' }}>
              <stat.icon size={16} style={{ color: stat.color }} />
            </div>
            <div>
              <p className="text-xl font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>{isLoading ? '–' : stat.value}</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
            </div>
          </div>
        ))}
      </div>

      {/* Search + filters */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative max-w-sm flex-1 min-w-[200px]">
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

      {/* Tickets table */}
      <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['#', 'Subject', 'Company', 'Status', 'Updated'].map(h => (
                <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(5)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(5)].map((_, j) => (
                    <td key={j} className="px-5 py-4">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : tickets.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-12 text-center">
                  <LifeBuoy size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No support tickets</p>
                </td>
              </tr>
            ) : tickets.map((t) => (
              <tr
                key={t.id}
                onClick={() => setOpenTicketId(t.id)}
                className="cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
                style={{ borderBottom: '1px solid var(--border)' }}
              >
                <td className="px-5 py-3 font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>#{t.id}</td>
                <td className="px-5 py-3">
                  <div className="flex items-center gap-2">
                    {t.unread_by_support && <span className="w-2 h-2 rounded-full flex-shrink-0" style={{ backgroundColor: 'var(--gold)' }} aria-label="Unread customer reply" title="Unread customer reply" />}
                    <span className="font-medium" style={{ color: 'var(--text-primary)' }}>{t.subject}</span>
                    {t.has_screenshot && <Paperclip size={11} style={{ color: 'var(--text-muted)' }} />}
                  </div>
                  {t.latest_message_preview && (
                    <p className="text-xs mt-0.5 truncate max-w-[280px]" style={{ color: 'var(--text-muted)' }}>{t.latest_message_preview}</p>
                  )}
                </td>
                <td className="px-5 py-3" style={{ color: 'var(--text-secondary)' }}>{t.company?.name ?? '–'}</td>
                <td className="px-5 py-3"><StatusBadge status={t.status} /></td>
                <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{t.updated_at ? formatDateTime(t.updated_at, { timeZone: useAuthStore.getState().user?.effective_timezone }) : '–'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && data.last_page > 1 && (
        <div className="flex items-center justify-between">
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

      {openTicketId && <TicketModal ticketId={openTicketId} onClose={closeModal} />}
    </div>
  );
}
