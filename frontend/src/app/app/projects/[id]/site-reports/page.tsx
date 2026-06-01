'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { ClipboardList, Plus, Search, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  submitted: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  approved:  { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

type DiaryForm = {
  diary_date: string; weather: string; workers_on_site: string;
  works_carried_out: string; issues: string; materials_delivered: string;
};

function NewSiteDiaryModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<DiaryForm>({
    diary_date: new Date().toISOString().split('T')[0],
    weather: '', workers_on_site: '', works_carried_out: '', issues: '', materials_delivered: '',
  });
  const { mutate, isPending } = useMutation({
    mutationFn: (data: DiaryForm) => api.post(`/projects/${projectId}/site-diaries`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-site-diaries', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Site diary added');
      onClose();
    },
    onError: () => toast.error('Failed to add site diary'),
  });
  const set = (f: keyof DiaryForm, v: string) => setForm(p => ({ ...p, [f]: v }));
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-lg rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Site Diary</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Date *</label>
              <input type="date" value={form.diary_date} onChange={e => set('diary_date', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Weather</label>
              <input value={form.weather} onChange={e => set('weather', e.target.value)} placeholder="e.g. Sunny, 18°C" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Workers On Site</label>
              <input type="number" value={form.workers_on_site} onChange={e => set('workers_on_site', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Works Carried Out</label>
            <textarea value={form.works_carried_out} onChange={e => set('works_carried_out', e.target.value)} rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Materials Delivered</label>
            <textarea value={form.materials_delivered} onChange={e => set('materials_delivered', e.target.value)} rows={2} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Issues / Delays</label>
            <textarea value={form.issues} onChange={e => set('issues', e.target.value)} rows={2} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : 'Save Diary'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function ProjectSiteReportsPage() {
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['project-site-diaries', id],
    queryFn: () => api.get(`/projects/${id}/site-diaries`).then(r => r.data),
  });

  const diaries = (data?.data ?? []).filter((d: any) =>
    d.works_carried_out?.toLowerCase().includes(search.toLowerCase()) ||
    String(d.diary_date)?.includes(search)
  );

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Site Reports</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Daily site diaries and progress reports</p>
        </div>
        {canWrite && (
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New Diary
        </button>
        )}
      </div>

      <div className="relative max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search diaries…"
          className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      <div className="space-y-3">
        {isLoading ? (
          [...Array(4)].map((_, i) => (
            <div key={i} className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />

          ))
        ) : diaries.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <ClipboardList size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No site diaries yet</p>
            {canWrite && (
            <button onClick={() => setShowModal(true)} className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              Add First Diary
            </button>
            )}
          </div>
        ) : diaries.map((d: any) => {
          const badge = STATUS_COLORS[d.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div
              key={d.id}
              className="flex items-center justify-between p-4 rounded-xl cursor-pointer hover:bg-[var(--bg-elevated)] transition-colors"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
            >
              <div className="flex items-center gap-4">
                <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'rgba(16,185,129,0.1)' }}>
                  <ClipboardList size={16} style={{ color: '#10b981' }} />
                </div>
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                    Site Diary — {formatDate(d.diary_date)}
                  </p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    {d.weather ? `${d.weather} · ` : ''}{d.workers_on_site ?? 0} workers on site
                    {d.works_carried_out ? ` · ${d.works_carried_out.slice(0, 60)}${d.works_carried_out.length > 60 ? '…' : ''}` : ''}
                  </p>
                </div>
              </div>
              <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                    style={{ backgroundColor: badge.bg, color: badge.text }}>
                {d.status}
              </span>
            </div>
          );
        })}
      </div>

      {canWrite && showModal && <NewSiteDiaryModal projectId={id!} onClose={() => setShowModal(false)} />}
    </div>
  );
}
