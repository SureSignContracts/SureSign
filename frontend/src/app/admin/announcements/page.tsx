'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Megaphone, Plus, Pencil, Trash2, X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { SEVERITY_STYLES, SEVERITY_LABELS } from '@/lib/announcements';
import { fromUtcIso, toUtcIso } from '@/lib/dateTime';

interface Announcement {
  id: number;
  title: string;
  message: string;
  severity: 'information' | 'maintenance' | 'degraded_service' | 'outage';
  is_active: boolean;
  starts_at: string;
  ends_at: string | null;
  link_url: string | null;
  created_at: string;
}

const SEVERITIES: Announcement['severity'][] = ['information', 'maintenance', 'degraded_service', 'outage'];

interface FormState {
  title: string;
  message: string;
  severity: Announcement['severity'];
  is_active: boolean;
  starts_at: string;
  ends_at: string;
  link_url: string;
}

const EMPTY_FORM: FormState = {
  title: '', message: '', severity: 'information', is_active: true,
  starts_at: fromUtcIso(new Date().toISOString()), ends_at: '', link_url: '',
};

export default function AdminAnnouncementsPage() {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<Announcement | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [error, setError] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['admin-announcements'],
    queryFn: () => api.get('/admin/platform-announcements').then(r => r.data.data as Announcement[]),
  });

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: form.title,
        message: form.message,
        severity: form.severity,
        is_active: form.is_active,
        // `<input type="datetime-local">` gives back a plain local
        // wall-clock string with no timezone info (e.g. "2026-07-16T23:47").
        // toUtcIso() parses it as local time (per spec) and converts to the
        // real UTC instant before it ever reaches the backend.
        starts_at: toUtcIso(form.starts_at),
        ends_at: form.ends_at ? toUtcIso(form.ends_at) : null,
        link_url: form.link_url || null,
      };
      return editing
        ? api.put(`/admin/platform-announcements/${editing.id}`, payload)
        : api.post('/admin/platform-announcements', payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-announcements'] });
      closeForm();
    },
    onError: (err) => setError(getErrorMessage(err, 'Could not save this announcement.')),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/platform-announcements/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-announcements'] }),
  });

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setError('');
    setShowForm(true);
  }

  function openEdit(a: Announcement) {
    setEditing(a);
    setForm({
      title: a.title, message: a.message, severity: a.severity, is_active: a.is_active,
      starts_at: fromUtcIso(a.starts_at), ends_at: fromUtcIso(a.ends_at), link_url: a.link_url ?? '',
    });
    setError('');
    setShowForm(true);
  }

  function closeForm() {
    setShowForm(false);
    setEditing(null);
    setError('');
  }

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <Megaphone size={20} />
            Announcements
          </h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            The banner shown in the Help Center for known issues, maintenance, or platform information.
          </p>
        </div>
        <button
          onClick={openCreate}
          className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={14} />
          New Announcement
        </button>
      </div>

      {showForm && (
        <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{editing ? 'Edit Announcement' : 'New Announcement'}</h2>
            <button onClick={closeForm} aria-label="Close"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
          </div>
          {error && <p className="text-xs" style={{ color: '#f87171' }}>{error}</p>}
          <input
            value={form.title}
            onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
            placeholder="Title"
            className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
          <textarea
            value={form.message}
            onChange={e => setForm(f => ({ ...f, message: e.target.value }))}
            placeholder="Message shown to users"
            rows={3}
            className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
          <div className="grid grid-cols-2 gap-3">
            <select
              value={form.severity}
              onChange={e => setForm(f => ({ ...f, severity: e.target.value as Announcement['severity'] }))}
              className="px-3.5 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              {SEVERITIES.map(s => <option key={s} value={s}>{SEVERITY_LABELS[s]}</option>)}
            </select>
            <label className="flex items-center gap-2 text-sm px-3.5" style={{ color: 'var(--text-secondary)' }}>
              <input type="checkbox" checked={form.is_active} onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))} />
              Active
            </label>
            <div>
              <label className="text-xs" style={{ color: 'var(--text-muted)' }}>Starts</label>
              <input
                type="datetime-local"
                value={form.starts_at}
                onChange={e => setForm(f => ({ ...f, starts_at: e.target.value }))}
                className="w-full mt-1 px-3.5 py-2.5 rounded-xl text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
            </div>
            <div>
              <label className="text-xs" style={{ color: 'var(--text-muted)' }}>Ends (optional)</label>
              <input
                type="datetime-local"
                value={form.ends_at}
                onChange={e => setForm(f => ({ ...f, ends_at: e.target.value }))}
                className="w-full mt-1 px-3.5 py-2.5 rounded-xl text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
            </div>
          </div>
          <input
            value={form.link_url}
            onChange={e => setForm(f => ({ ...f, link_url: e.target.value }))}
            placeholder="Optional link (internal path or https:// URL)"
            className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
          <div className="flex justify-end">
            <button
              onClick={() => saveMutation.mutate()}
              disabled={!form.title.trim() || !form.message.trim() || saveMutation.isPending}
              className="px-4 py-2 rounded-lg text-xs font-medium disabled:opacity-50"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {saveMutation.isPending ? 'Saving…' : 'Save'}
            </button>
          </div>
        </div>
      )}

      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {isLoading ? (
          <div className="p-5 space-y-2">
            {[...Array(3)].map((_, i) => <div key={i} className="h-12 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
          </div>
        ) : !data?.length ? (
          <div className="px-5 py-10 text-center">
            <Megaphone size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No announcements yet.</p>
          </div>
        ) : (
          data.map(a => {
            const style = SEVERITY_STYLES[a.severity];
            return (
              <div key={a.id} className="flex items-center justify-between gap-3 px-5 py-3.5" style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-medium" style={{ backgroundColor: style.bg, color: style.text }}>
                      {SEVERITY_LABELS[a.severity]}
                    </span>
                    {a.is_active && <span className="text-[11px] font-medium" style={{ color: '#4ade80' }}>Active</span>}
                    <span className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{a.title}</span>
                  </div>
                  <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{a.message}</p>
                </div>
                <div className="flex items-center gap-1.5 flex-shrink-0">
                  <button onClick={() => openEdit(a)} aria-label="Edit" className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
                    <Pencil size={13} style={{ color: 'var(--text-secondary)' }} />
                  </button>
                  <button onClick={() => deleteMutation.mutate(a.id)} aria-label="Delete" className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
                    <Trash2 size={13} style={{ color: '#f87171' }} />
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
