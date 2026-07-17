'use client';

import { useRef, useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ChevronLeft, MapPin, Paperclip, Send, X, Lock,
} from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { SUPPORT_STATUS_COLORS, SUPPORT_STATUS_LABELS } from '@/lib/supportContext';
import { ScreenshotPreview, SupportTicketScreenshot } from '@/components/support/ScreenshotPreview';
import { RecentActivityList, RecentActivityEntry } from '@/components/support/RecentActivityList';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';

const MAX_SCREENSHOT_BYTES = 5 * 1024 * 1024;
const ALLOWED_SCREENSHOT_TYPES = ['image/png', 'image/jpeg', 'image/webp'];

interface TicketDetail {
  id: number;
  reference: string;
  subject: string;
  category: string | null;
  priority: string | null;
  status: string;
  message: string;
  route: string | null;
  module: string | null;
  project: { id: number; name: string } | null;
  trade_package: { id: number; name: string } | null;
  diagnostics: Record<string, string | number> | null;
  recent_activity: RecentActivityEntry[] | null;
  screenshot: SupportTicketScreenshot | null;
  created_at: string;
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

function StatusBadge({ status }: { status: string }) {
  const badge = SUPPORT_STATUS_COLORS[status] || SUPPORT_STATUS_COLORS.closed;
  return (
    <span className="px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: badge.bg, color: badge.text }}>
      {SUPPORT_STATUS_LABELS[status] ?? status}
    </span>
  );
}

function MessageBubble({ message }: { message: ThreadMessage }) {
  const isSupport = message.sender_type === 'support';
  return (
    <div className="flex flex-col gap-1" style={{ alignItems: isSupport ? 'flex-start' : 'flex-end' }}>
      <div className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
        <span className="font-medium" style={{ color: 'var(--text-secondary)' }}>
          {isSupport ? 'SureSign Support' : (message.sender_name || 'You')}
        </span>
        <span>·</span>
        <span>{formatDateTime(message.created_at, { timeZone: useAuthStore.getState().user?.effective_timezone })}</span>
      </div>
      <div
        className="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap"
        style={{
          backgroundColor: isSupport ? 'var(--bg-elevated)' : 'var(--gold-15)',
          border: '1px solid var(--border)',
          color: 'var(--text-primary)',
        }}
      >
        {message.body}
      </div>
      {message.screenshot && (
        <div className="max-w-[85%]">
          <ScreenshotPreview ticketId={message.id} screenshot={message.screenshot} />
        </div>
      )}
    </div>
  );
}

export default function SupportRequestDetailPage() {
  const params = useParams();
  const ticketId = Number(params.id);
  const qc = useQueryClient();

  const [reply, setReply] = useState('');
  const [screenshot, setScreenshot] = useState<File | null>(null);
  const [screenshotPreview, setScreenshotPreview] = useState<string | null>(null);
  const [screenshotError, setScreenshotError] = useState('');
  const [error, setError] = useState('');
  const fileInputRef = useRef<HTMLInputElement>(null);

  const { data: ticket, isLoading, isError } = useQuery({
    queryKey: ['my-support-request', ticketId],
    queryFn: () => api.get(`/support-tickets/${ticketId}`).then(r => r.data.data as TicketDetail),
  });

  const { data: thread } = useQuery({
    queryKey: ['my-support-request-thread', ticketId],
    queryFn: () => api.get(`/support-tickets/${ticketId}/messages`).then(r => r.data.data as ThreadMessage[]),
    enabled: !!ticket,
  });

  function handleFileChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;

    if (!ALLOWED_SCREENSHOT_TYPES.includes(file.type)) {
      setScreenshotError('Only PNG, JPEG, or WebP images are supported.');
      return;
    }
    if (file.size > MAX_SCREENSHOT_BYTES) {
      setScreenshotError('Screenshots must be under 5 MB.');
      return;
    }

    setScreenshotError('');
    setScreenshot(file);
    setScreenshotPreview(URL.createObjectURL(file));
  }

  function removeScreenshot() {
    if (screenshotPreview) URL.revokeObjectURL(screenshotPreview);
    setScreenshot(null);
    setScreenshotPreview(null);
    setScreenshotError('');
  }

  const replyMutation = useMutation({
    mutationFn: () => {
      const fd = new FormData();
      fd.append('body', reply);
      if (screenshot) fd.append('screenshot', screenshot);
      return api.post(`/support-tickets/${ticketId}/messages`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    },
    onSuccess: () => {
      setReply('');
      removeScreenshot();
      setError('');
      qc.invalidateQueries({ queryKey: ['my-support-request-thread', ticketId] });
      qc.invalidateQueries({ queryKey: ['my-support-request', ticketId] });
      qc.invalidateQueries({ queryKey: ['my-support-requests'] });
    },
    onError: (err) => setError(getErrorMessage(err, 'Could not send your reply. Please try again.')),
  });

  if (isError) {
    return (
      <div className="p-6 max-w-2xl mx-auto">
        <p className="text-sm" style={{ color: '#f87171' }}>This request could not be loaded, or you don&apos;t have access to it.</p>
      </div>
    );
  }

  const isClosed = ticket?.status === 'closed';

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-6">
      <Link href="/app/help/support?tab=requests" className="inline-flex items-center gap-1 text-xs font-medium hover:opacity-80" style={{ color: 'var(--text-muted)' }}>
        <ChevronLeft size={13} />
        My Support Requests
      </Link>

      {isLoading || !ticket ? (
        <div className="space-y-3">
          <div className="h-6 w-1/2 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          <div className="h-24 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        </div>
      ) : (
        <>
          {/* Header */}
          <div>
            <p className="text-xs font-mono" style={{ color: 'var(--text-muted)' }}>{ticket.reference}</p>
            <h1 className="text-xl font-bold mt-0.5" style={{ color: 'var(--text-primary)' }}>{ticket.subject}</h1>
            <div className="flex items-center gap-1.5 mt-2">
              <StatusBadge status={ticket.status} />
              {ticket.priority && ticket.priority !== 'normal' && (
                <span className="px-2 py-0.5 rounded-full text-xs font-medium capitalize" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                  {ticket.priority} priority
                </span>
              )}
            </div>
            {(ticket.route || ticket.project || ticket.trade_package) && (
              <div className="flex items-start gap-1.5 text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
                <MapPin size={12} className="mt-0.5 flex-shrink-0" />
                <span>
                  {ticket.module && <>{ticket.module} — </>}
                  {ticket.route}
                  {ticket.project && <> · Project: {ticket.project.name}</>}
                  {ticket.trade_package && <> · Trade package: {ticket.trade_package.name}</>}
                </span>
              </div>
            )}
          </div>

          {/* Original message */}
          <div className="rounded-2xl p-4 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-secondary)' }}>{ticket.message}</p>
            {ticket.screenshot && <ScreenshotPreview ticketId={ticket.id} screenshot={ticket.screenshot} />}
            {ticket.diagnostics && (
              <div className="rounded-xl p-3 text-xs grid grid-cols-2 gap-x-4 gap-y-1" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
                {Object.entries(ticket.diagnostics).map(([key, value]) => (
                  <div key={key} className="flex justify-between gap-2 truncate">
                    <span style={{ color: 'var(--text-muted)' }} className="capitalize">{key.replace(/_/g, ' ')}</span>
                    <span className="truncate">{String(value)}</span>
                  </div>
                ))}
              </div>
            )}
            {ticket.recent_activity && ticket.recent_activity.length > 0 && <RecentActivityList entries={ticket.recent_activity} />}
          </div>

          {/* Conversation thread */}
          {thread && thread.length > 0 && (
            <div className="space-y-4">
              {thread.map(message => <MessageBubble key={message.id} message={message} />)}
            </div>
          )}

          {/* Reply */}
          {isClosed ? (
            <div className="rounded-2xl p-4 flex items-center gap-2 text-sm" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <Lock size={14} className="flex-shrink-0" />
              This request is closed. Submit a new request from Contact Support if you need further help.
            </div>
          ) : (
            <div className="rounded-2xl p-4 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
              {error && <p className="text-xs" style={{ color: '#f87171' }} role="alert">{error}</p>}
              <textarea
                value={reply}
                onChange={e => setReply(e.target.value)}
                placeholder="Write a reply…"
                aria-label="Reply"
                rows={3}
                className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />

              {screenshotPreview ? (
                <div className="relative inline-block">
                  {/* eslint-disable-next-line @next/next/no-img-element -- local blob preview */}
                  <img src={screenshotPreview} alt="Screenshot preview" className="rounded-lg max-h-28 block" style={{ border: '1px solid var(--border)' }} />
                  <button
                    onClick={removeScreenshot}
                    aria-label="Remove screenshot"
                    className="absolute -top-2 -right-2 w-5 h-5 rounded-full flex items-center justify-center"
                    style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                  >
                    <X size={11} />
                  </button>
                </div>
              ) : (
                <label
                  className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                >
                  <Paperclip size={12} />
                  Attach a screenshot
                  <input ref={fileInputRef} type="file" accept="image/png,image/jpeg,image/webp" onChange={handleFileChange} className="hidden" />
                </label>
              )}
              {screenshotError && <p className="text-xs" style={{ color: '#f87171' }} role="alert">{screenshotError}</p>}

              <div className="flex justify-end">
                <button
                  onClick={() => replyMutation.mutate()}
                  disabled={!reply.trim() || replyMutation.isPending}
                  className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50 active:scale-[0.98]"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  <Send size={12} />
                  {replyMutation.isPending ? 'Sending…' : 'Send Reply'}
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </div>
  );
}
