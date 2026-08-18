'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd, formatTime } from '@/lib/dateTime';
import { getErrorMessage } from '@/lib/getErrorMessage';
import TimezoneSelect from '@/components/shared/TimezoneSelect';
import { useAuthStore } from '@/store/authStore';
import { Users2, Plus, Search, Calendar, Clock, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PageTourButton from '@/components/tours/PageTourButton';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';

/** One hour after `time` (HH:MM), wrapping past midnight if needed — used
 * only as a starting suggestion when a user first switches a meeting to
 * timed mode, never forced afterwards. */
function addOneHour(time: string): string {
  const [h, m] = time.split(':').map(Number);
  return `${String((h + 1) % 24).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

/** The default scheduling timezone for a new meeting — the organisation's
 * own timezone (Batch 6 product decision: business meetings default to the
 * organisation's timezone, not the individual organiser's personal
 * override; anyone may still explicitly pick a different one in the
 * selector below). */
function defaultSchedulingTimezone(): string {
  return useAuthStore.getState().user?.organization?.timezone ?? 'Europe/London';
}

/** Shared "Add a specific time" block — date/type/status stay in the
 * caller's own grid; this renders the toggle plus (when on) start/end time
 * + timezone fields, identically for both the create and edit forms. */
function TimedScheduleFields({
  isTimed, onToggle, startTime, endTime, timezone, onStartTime, onEndTime, onTimezone,
}: {
  isTimed: boolean; onToggle: (v: boolean) => void;
  startTime: string; endTime: string; timezone: string;
  onStartTime: (v: string) => void; onEndTime: (v: string) => void; onTimezone: (v: string) => void;
}) {
  const labelStyle = { color: 'var(--text-muted)' };
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  return (
    <div className="space-y-3">
      <label className="flex items-center gap-2 cursor-pointer select-none">
        <input
          type="checkbox"
          checked={isTimed}
          onChange={e => {
            const next = e.target.checked;
            onToggle(next);
            if (next && !startTime) {
              onStartTime('09:00');
              onEndTime('10:00');
              onTimezone(timezone || defaultSchedulingTimezone());
            }
          }}
          className="w-4 h-4"
        />
        <span className="text-sm flex items-center gap-1.5" style={{ color: 'var(--text-secondary)' }}>
          <Clock size={13} /> Add a specific time
        </span>
      </label>

      {isTimed && (
        <div className="grid grid-cols-2 gap-4 pl-6">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Start time *</label>
            <input
              type="time" value={startTime} required
              onChange={e => {
                const wasDefaultSpan = endTime === addOneHour(startTime);
                onStartTime(e.target.value);
                if (wasDefaultSpan) onEndTime(addOneHour(e.target.value));
              }}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}
            />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>End time *</label>
            <input
              type="time" value={endTime} required
              onChange={e => onEndTime(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}
            />
          </div>
          <div className="col-span-2">
            <label className="block text-xs mb-1" style={labelStyle}>Timezone *</label>
            <TimezoneSelect value={timezone} onChange={onTimezone} background="var(--bg-base)" className="w-full px-3 py-2 rounded-lg text-sm outline-none" />
            <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
              Attendees each see this in their own timezone — this is just what you are scheduling against.
            </p>
          </div>
        </div>
      )}
    </div>
  );
}

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:    { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  issued:   { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  approved: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

const TYPE_LABELS: Record<string, string> = {
  progress:      'Progress',
  design:        'Design',
  commercial:    'Commercial',
  safety:        'Safety',
  subcontractor: 'Subcontractor',
  other:         'Other',
};

/**
 * Human-readable "when" for a meeting, for both the list row and the detail
 * header. Date-only meetings show just the date, exactly as before Batch 6.
 * Timed meetings show the date plus the start/end time converted to the
 * VIEWER's own effective timezone (not the scheduling timezone) — the
 * scheduling timezone is shown alongside only when it actually differs
 * from the viewer's, so attendees always know at a glance whether "2pm"
 * means their own local 2pm or someone else's.
 */
function formatMeetingWhen(meeting: any): string {
  if (!meeting.is_timed || !meeting.starts_at) {
    return formatDate(meeting.meeting_date);
  }
  const viewerTz = useAuthStore.getState().user?.effective_timezone;
  const start = formatTime(meeting.starts_at, { timeZone: viewerTz });
  const end = formatTime(meeting.ends_at, { timeZone: viewerTz });
  const suffix = viewerTz && viewerTz !== meeting.scheduled_timezone ? ` (${viewerTz})` : '';
  return `${formatDate(meeting.meeting_date)} · ${start}–${end}${suffix}`;
}

type MeetingForm = {
  title: string; meeting_date: string; location: string; type: string; status: string;
  is_timed: boolean; start_time: string; end_time: string; timezone: string;
};

function NewMeetingModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<MeetingForm>({
    title: '', meeting_date: effectiveTodayYmd(),
    location: '', type: 'progress', status: 'draft',
    is_timed: false, start_time: '', end_time: '', timezone: defaultSchedulingTimezone(),
  });
  const [error, setError] = useState('');
  const { mutate, isPending } = useMutation({
    mutationFn: (data: MeetingForm) => {
      const { is_timed, start_time, end_time, timezone, ...rest } = data;
      return api.post(`/projects/${projectId}/meetings`, {
        ...rest,
        is_timed,
        ...(is_timed ? { start_time, end_time, timezone } : {}),
      }).then(r => r.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-meetings', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Meeting created');
      onClose();
    },
    onError: (err) => setError(getErrorMessage(err, 'Failed to create meeting.')),
  });
  const set = <K extends keyof MeetingForm>(f: K, v: MeetingForm[K]) => setForm(p => ({ ...p, [f]: v }));
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl ss-animate-in shadow-[var(--shadow-pop)]" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New meeting</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); setError(''); mutate(form); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Title *</label>
            <input value={form.title} onChange={e => set('title', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Date *</label>
              <input type="date" value={form.meeting_date} onChange={e => set('meeting_date', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Location</label>
              <input value={form.location} onChange={e => set('location', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Type</label>
              <Select value={form.type} onChange={e => set('type', e.target.value)} className="w-full">
                {Object.entries(TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </Select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Status</label>
              <Select value={form.status} onChange={e => set('status', e.target.value)} className="w-full">
                <option value="draft">Draft</option>
                <option value="issued">Issued</option>
                <option value="approved">Approved</option>
              </Select>
            </div>
          </div>

          <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem' }}>
            <TimedScheduleFields
              isTimed={form.is_timed} onToggle={v => set('is_timed', v)}
              startTime={form.start_time} endTime={form.end_time} timezone={form.timezone}
              onStartTime={v => set('start_time', v)} onEndTime={v => set('end_time', v)} onTimezone={v => set('timezone', v)}
            />
          </div>

          {error && <p className="text-xs" style={{ color: '#f87171' }}>{error}</p>}

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Creating…' : 'Create meeting'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Meeting Detail / Edit Modal ─────────────────────────────────────────────

function MeetingDetailModal({
  meeting, projectId, canWrite, onClose, onUpdated,
}: { meeting: any; projectId: string; canWrite: boolean; onClose: () => void; onUpdated: (updated: any) => void }) {
  const queryClient = useQueryClient();
  const [editMode, setEditMode] = useState(false);
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };

  // Timed meetings pre-populate start/end time in the SCHEDULING timezone
  // (not the viewer's own) — reopening the edit form must show back what
  // the organiser actually typed, never a silently reinterpreted value.
  const [form, setForm] = useState({
    title:        meeting.title ?? '',
    meeting_date: meeting.meeting_date ? String(meeting.meeting_date).slice(0, 10) : '',
    location:     meeting.location ?? '',
    type:         meeting.type ?? 'progress',
    status:       meeting.status ?? 'draft',
    attendees:    Array.isArray(meeting.attendees) ? meeting.attendees.join(', ') : (meeting.attendees ?? ''),
    agenda:       meeting.agenda ?? '',
    minutes:      meeting.minutes ?? '',
    action_items: Array.isArray(meeting.action_items)
      ? meeting.action_items.join('\n')
      : (meeting.action_items ?? ''),
    is_timed:   Boolean(meeting.is_timed),
    start_time: meeting.is_timed ? formatTime(meeting.starts_at, { timeZone: meeting.scheduled_timezone }) : '',
    end_time:   meeting.is_timed ? formatTime(meeting.ends_at, { timeZone: meeting.scheduled_timezone }) : '',
    timezone:   meeting.scheduled_timezone ?? defaultSchedulingTimezone(),
  });
  const [error, setError] = useState('');

  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) => {
      const { is_timed, start_time, end_time, timezone, ...rest } = data;
      return api.put(`/projects/${projectId}/meetings/${meeting.id}`, {
        ...rest,
        attendees:    data.attendees.split(',').map((s: string) => s.trim()).filter(Boolean),
        action_items: data.action_items.split('\n').map((s: string) => s.trim()).filter(Boolean),
        is_timed,
        ...(is_timed ? { start_time, end_time, timezone } : {}),
      }).then(r => r.data);
    },
    onSuccess: (updated: any) => {
      queryClient.invalidateQueries({ queryKey: ['project-meetings', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Meeting updated');
      onUpdated(updated?.data ?? updated);
      setEditMode(false);
    },
    onError: (err) => setError(getErrorMessage(err, 'Failed to update meeting.')),
  });

  const set = <K extends keyof typeof form>(f: K, v: (typeof form)[K]) => setForm(p => ({ ...p, [f]: v }));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-2xl rounded-2xl ss-animate-in shadow-[var(--shadow-pop)] max-h-[90vh] overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              <span className="font-mono text-xs">#{meeting.meeting_number}</span> — {meeting.title}
            </h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              {TYPE_LABELS[meeting.type] ?? meeting.type} · {formatMeetingWhen(meeting)}
            </p>
          </div>
          <div className="flex items-center gap-2">
            {canWrite && !editMode && (
              <button onClick={() => setEditMode(true)}
                className="px-3 py-1.5 rounded-lg text-xs font-medium"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Edit
              </button>
            )}
            <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
          </div>
        </div>

        {editMode ? (
          <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
            <div className="grid grid-cols-2 gap-4">
              <div className="col-span-2">
                <label className="block text-xs mb-1" style={labelStyle}>Title *</label>
                <input value={form.title} onChange={e => set('title', e.target.value)} required
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Date *</label>
                <input type="date" value={form.meeting_date} onChange={e => set('meeting_date', e.target.value)} required
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Location</label>
                <input value={form.location} onChange={e => set('location', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Type</label>
                <Select value={form.type} onChange={e => set('type', e.target.value)} className="w-full">
                  {Object.entries(TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </Select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Status</label>
                <Select value={form.status} onChange={e => set('status', e.target.value)} className="w-full">
                  <option value="draft">Draft</option>
                  <option value="issued">Issued</option>
                  <option value="approved">Approved</option>
                </Select>
              </div>
            </div>

            <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem' }}>
              <TimedScheduleFields
                isTimed={form.is_timed} onToggle={v => set('is_timed', v)}
                startTime={form.start_time} endTime={form.end_time} timezone={form.timezone}
                onStartTime={v => set('start_time', v)} onEndTime={v => set('end_time', v)} onTimezone={v => set('timezone', v)}
              />
            </div>

            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Attendees (comma-separated)</label>
              <input value={form.attendees} onChange={e => set('attendees', e.target.value)}
                placeholder="John Smith, Jane Doe, …"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Agenda</label>
              <textarea value={form.agenda} onChange={e => set('agenda', e.target.value)} rows={3}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Minutes</label>
              <textarea value={form.minutes} onChange={e => set('minutes', e.target.value)} rows={5}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Action Items (one per line)</label>
              <textarea value={form.action_items} onChange={e => set('action_items', e.target.value)} rows={4}
                placeholder="Action item 1&#10;Action item 2"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
            </div>
            {error && <p className="text-xs" style={{ color: '#f87171' }}>{error}</p>}
            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={() => setEditMode(false)} className="px-4 py-2 rounded-lg text-sm"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
              <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
                {isPending ? 'Saving…' : 'Save Changes'}
              </button>
            </div>
          </form>
        ) : (
          <div className="p-5 space-y-5">
            <div className="grid grid-cols-2 gap-4 text-sm">
              {[
                { label: meeting.is_timed ? 'Date & time' : 'Date', value: formatMeetingWhen(meeting) },
                { label: 'Location', value: meeting.location || '—' },
                { label: 'Type', value: TYPE_LABELS[meeting.type] ?? meeting.type },
                { label: 'Status', value: meeting.status },
              ].map(({ label, value }) => (
                <div key={label}>
                  <p className="text-xs mb-0.5" style={{ color: 'var(--text-muted)' }}>{label}</p>
                  <p className="capitalize" style={{ color: 'var(--text-primary)' }}>{value}</p>
                </div>
              ))}
            </div>
            {meeting.attendees?.length > 0 && (
              <div>
                <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Attendees</p>
                <div className="flex flex-wrap gap-2">
                  {(Array.isArray(meeting.attendees) ? meeting.attendees : [meeting.attendees]).map((a: string) => (
                    <span key={a} className="text-xs px-2 py-0.5 rounded-full"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>{a}</span>
                  ))}
                </div>
              </div>
            )}
            {meeting.agenda && (
              <div>
                <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Agenda</p>
                <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-primary)' }}>{meeting.agenda}</p>
              </div>
            )}
            {meeting.minutes && (
              <div>
                <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Minutes</p>
                <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-primary)' }}>{meeting.minutes}</p>
              </div>
            )}
            {meeting.action_items?.length > 0 && (
              <div>
                <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>Action items</p>
                <ul className="space-y-1">
                  {(Array.isArray(meeting.action_items) ? meeting.action_items : [meeting.action_items]).map((item: string, i: number) => (
                    <li key={i} className="flex items-start gap-2 text-sm" style={{ color: 'var(--text-primary)' }}>
                      <span className="mt-1 w-1.5 h-1.5 rounded-full flex-shrink-0" style={{ backgroundColor: 'var(--gold)' }} />
                      {item}
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
}

function ProjectMeetingsPage() {
  const { id } = useParams<{ id: string }>();
  const { canManageMeetings: canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [detailMeeting, setDetailMeeting] = useState<any | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-meetings', id],
    queryFn: () => api.get(`/projects/${id}/meetings`).then(r => r.data),
  });

  const meetings = (data?.data ?? []).filter((m: any) => {
    const matchSearch = m.title?.toLowerCase().includes(search.toLowerCase());
    const matchType = typeFilter === 'all' || m.type === typeFilter;
    return matchSearch && matchType;
  });

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      <ProjectModuleHeader
        category="Project communication"
        title="Meetings"
        description="Capture meeting records, decisions and action items in a structured project history."
        icon={Users2}
        tour={<PageTourButton tourKey="page-meetings" label="Take a tour of this page" />}
        action={canWrite ? (
          <button data-tour="meetings-new" onClick={() => setShowModal(true)} className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0">
            <Plus size={16} /> New meeting
          </button>
        ) : undefined}
      />

      <div className="flex gap-3 flex-wrap" data-tour="meetings-filters">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input

            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search meetings…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px', boxShadow: 'var(--shadow-card)' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {['all', 'progress', 'design', 'commercial', 'safety'].map(t => (
            <button
              key={t}
              onClick={() => setTypeFilter(t)}
              className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
              style={typeFilter === t
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }
              }
            >
              {t === 'all' ? 'All' : TYPE_LABELS[t] ?? t}
            </button>
          ))}
        </div>
      </div>

      <div className="space-y-3" data-tour="meetings-table">
        {isLoading ? (
          [...Array(4)].map((_, i) => (
            <div key={i} className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))
        ) : isError ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <Users2 size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load meetings</p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
            <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-3">
              Try again
            </Button>
          </div>
        ) : meetings.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <Users2 size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No meetings recorded yet</p>
            {canWrite && (
            <Button onClick={() => setShowModal(true)} variant="secondary" size="sm" className="mt-3">
              Create First Meeting
            </Button>
            )}
          </div>
        ) : meetings.map((m: any, i: number) => {
          const badge = STATUS_COLORS[m.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div
              key={m.id}
              className="ss-animate-in flex items-center justify-between p-4 rounded-xl cursor-pointer transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] hover:-translate-y-0.5"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
              onClick={() => setDetailMeeting(m)}
            >
              <div className="flex items-center gap-4">
                <div
                  className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
                  style={{ backgroundColor: 'rgba(59,130,246,0.1)' }}
                >
                  <Calendar size={16} style={{ color: '#3b82f6' }} />
                </div>
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                    <span className="font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>#{m.meeting_number}</span> — {m.title}
                  </p>
                  <p className="text-xs mt-0.5 tabular-nums" style={{ color: 'var(--text-muted)' }}>
                    {TYPE_LABELS[m.type] ?? m.type} · {formatMeetingWhen(m)} {m.location ? `· ${m.location}` : ''}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                {m.action_items?.length > 0 && (
                  <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(249,115,22,0.1)', color: '#fb923c' }}>
                    {m.action_items.length} actions
                  </span>
                )}
                <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                      style={{ backgroundColor: badge.bg, color: badge.text }}>
                  {m.status}
                </span>
              </div>
            </div>
          );
        })}
      </div>

      {canWrite && showModal && <NewMeetingModal projectId={id!} onClose={() => setShowModal(false)} />}
      {detailMeeting && (
        <MeetingDetailModal
          meeting={detailMeeting}
          projectId={id!}
          canWrite={canWrite}
          onClose={() => setDetailMeeting(null)}
          onUpdated={(updated: any) => setDetailMeeting(updated)}
        />
      )}
    </div>
  );
}

export default function GatedProjectMeetingsPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.meetings" title="Meetings" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectMeetingsPage />
    </FeatureAvailabilityGate>
  );
}
