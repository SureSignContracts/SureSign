'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Users2, Plus, Search, Calendar, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';

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

type MeetingForm = { title: string; meeting_date: string; location: string; type: string; status: string };

function NewMeetingModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<MeetingForm>({
    title: '', meeting_date: new Date().toISOString().split('T')[0],
    location: '', type: 'progress', status: 'draft',
  });
  const { mutate, isPending } = useMutation({
    mutationFn: (data: MeetingForm) => api.post(`/projects/${projectId}/meetings`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-meetings', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Meeting created');
      onClose();
    },
    onError: () => toast.error('Failed to create meeting'),
  });
  const set = (f: keyof MeetingForm, v: string) => setForm(p => ({ ...p, [f]: v }));
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl shadow-xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Meeting</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
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
              <select value={form.type} onChange={e => set('type', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                {Object.entries(TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Status</label>
              <select value={form.status} onChange={e => set('status', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                <option value="draft">Draft</option>
                <option value="issued">Issued</option>
                <option value="approved">Approved</option>
              </select>
            </div>
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Creating…' : 'Create Meeting'}
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
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) =>
      api.put(`/meetings/${meeting.id}`, {
        ...data,
        attendees:    data.attendees.split(',').map((s: string) => s.trim()).filter(Boolean),
        action_items: data.action_items.split('\n').map((s: string) => s.trim()).filter(Boolean),
      }).then(r => r.data),
    onSuccess: (updated: any) => {
      queryClient.invalidateQueries({ queryKey: ['project-meetings', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Meeting updated');
      onUpdated(updated?.data ?? updated);
      setEditMode(false);
    },
    onError: () => toast.error('Failed to update meeting'),
  });

  const set = (f: keyof typeof form, v: string) => setForm(p => ({ ...p, [f]: v }));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-2xl rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              Meeting #{meeting.meeting_number} — {meeting.title}
            </h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              {TYPE_LABELS[meeting.type] ?? meeting.type} · {formatDate(meeting.meeting_date)}
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
                <select value={form.type} onChange={e => set('type', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  {Object.entries(TYPE_LABELS).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                </select>
              </div>
              <div>
                <label className="block text-xs mb-1" style={labelStyle}>Status</label>
                <select value={form.status} onChange={e => set('status', e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
                  <option value="draft">Draft</option>
                  <option value="issued">Issued</option>
                  <option value="approved">Approved</option>
                </select>
              </div>
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
            <div className="flex justify-end gap-3 pt-2">
              <button type="button" onClick={() => setEditMode(false)} className="px-4 py-2 rounded-lg text-sm"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
              <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
                {isPending ? 'Saving…' : 'Save Changes'}
              </button>
            </div>
          </form>
        ) : (
          <div className="p-5 space-y-5">
            <div className="grid grid-cols-2 gap-4 text-sm">
              {[
                { label: 'Date', value: formatDate(meeting.meeting_date) },
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
                <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>Action Items</p>
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
            <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>AI Summary</p>
              <p className="text-sm italic" style={{ color: 'var(--text-muted)' }}>AI summary — coming soon</p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

export default function ProjectMeetingsPage() {
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [detailMeeting, setDetailMeeting] = useState<any | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['project-meetings', id],
    queryFn: () => api.get(`/projects/${id}/meetings`).then(r => r.data),
  });

  const meetings = (data?.data ?? []).filter((m: any) => {
    const matchSearch = m.title?.toLowerCase().includes(search.toLowerCase());
    const matchType = typeFilter === 'all' || m.type === typeFilter;
    return matchSearch && matchType;
  });

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Meetings</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Meeting minutes and action items</p>
        </div>
        {canWrite && (
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New Meeting
        </button>
        )}
      </div>

      <div className="flex gap-3 flex-wrap">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input

            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search meetings…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {['all', 'progress', 'design', 'commercial', 'safety'].map(t => (
            <button
              key={t}
              onClick={() => setTypeFilter(t)}
              className="px-3 py-1.5 rounded-md text-xs font-medium capitalize transition-all"
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

      <div className="space-y-3">
        {isLoading ? (
          [...Array(4)].map((_, i) => (
            <div key={i} className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))
        ) : meetings.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <Users2 size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No meetings recorded yet</p>
            {canWrite && (
            <button onClick={() => setShowModal(true)} className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              Create First Meeting
            </button>
            )}
          </div>
        ) : meetings.map((m: any) => {
          const badge = STATUS_COLORS[m.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div
              key={m.id}
              className="flex items-center justify-between p-4 rounded-xl cursor-pointer hover:bg-[var(--bg-hover)] transition-colors"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
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
                    Meeting #{m.meeting_number} — {m.title}
                  </p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    {TYPE_LABELS[m.type] ?? m.type} · {formatDate(m.meeting_date)} {m.location ? `· ${m.location}` : ''}
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
