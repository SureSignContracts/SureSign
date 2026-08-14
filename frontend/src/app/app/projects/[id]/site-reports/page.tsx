'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { ClipboardList, Plus, Search, X, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PageTourButton from '@/components/tours/PageTourButton';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  submitted: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  approved:  { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

const STATUS_LABELS: Record<string, string> = { draft: 'Draft', submitted: 'Submitted', approved: 'Approved' };

type DiaryForm = {
  diary_date: string; weather: string; workers_on_site: string;
  works_carried_out: string; issues: string; materials_delivered: string;
  visitors: string; status: string;
};

const emptyForm: DiaryForm = {
  diary_date: effectiveTodayYmd(),
  weather: '', workers_on_site: '', works_carried_out: '', issues: '',
  materials_delivered: '', visitors: '', status: 'draft',
};

function SiteDiaryModal({ projectId, diary, readOnly, onClose }: { projectId: string; diary: any | null; readOnly: boolean; onClose: () => void }) {
  const queryClient = useQueryClient();
  const isEdit = !!diary;
  const [form, setForm] = useState<DiaryForm>(isEdit ? {
    diary_date: String(diary.diary_date).slice(0, 10),
    weather: diary.weather ?? '',
    workers_on_site: diary.workers_on_site != null ? String(diary.workers_on_site) : '',
    works_carried_out: diary.works_carried_out ?? '',
    issues: diary.issues ?? '',
    materials_delivered: diary.materials_delivered ?? '',
    visitors: diary.visitors ?? '',
    status: diary.status ?? 'draft',
  } : emptyForm);

  const { mutate, isPending } = useMutation({
    mutationFn: (data: DiaryForm) => isEdit
      ? api.put(`/projects/${projectId}/site-diaries/${diary.id}`, data).then(r => r.data)
      : api.post(`/projects/${projectId}/site-diaries`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-site-diaries', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success(isEdit ? 'Site diary updated' : 'Site diary added');
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, isEdit ? 'Failed to update site diary' : 'Failed to add site diary')),
  });
  const set = (f: keyof DiaryForm, v: string) => setForm(p => ({ ...p, [f]: v }));
  const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
  const labelStyle = { color: 'var(--text-muted)' };
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-lg rounded-2xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{readOnly ? 'Site Diary' : isEdit ? 'Edit Site Diary' : 'New Site Diary'}</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <fieldset disabled={readOnly} className="space-y-4">
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
              <label className="block text-xs mb-1" style={labelStyle}>Workers on site</label>
              <input type="number" value={form.workers_on_site} onChange={e => set('workers_on_site', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Status</label>
              <Select value={form.status} onChange={e => set('status', e.target.value)} disabled={readOnly} className="w-full">
                <option value="draft">Draft</option>
                <option value="submitted">Submitted</option>
                <option value="approved">Approved</option>
              </Select>
            </div>
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Works carried out</label>
            <textarea value={form.works_carried_out} onChange={e => set('works_carried_out', e.target.value)} rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Materials delivered</label>
            <textarea value={form.materials_delivered} onChange={e => set('materials_delivered', e.target.value)} rows={2} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Visitors</label>
            <input value={form.visitors} onChange={e => set('visitors', e.target.value)} placeholder="Names / companies on site" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Issues / delays</label>
            <textarea value={form.issues} onChange={e => set('issues', e.target.value)} rows={2} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} />
          </div>
          </fieldset>
          <div className="flex justify-end gap-3 pt-2">
            {readOnly ? (
              <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Close</button>
            ) : (
              <>
                <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
                <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium transition-all active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
                  {isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Save Diary'}
                </button>
              </>
            )}
          </div>
        </form>
      </div>
    </div>
  );
}

export default function ProjectSiteReportsPage() {
  const { id } = useParams<{ id: string }>();
  const { canManageSiteReports: canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'draft' | 'submitted' | 'approved'>('all');
  const [modalDiary, setModalDiary] = useState<any | 'new' | null>(null);
  const [confirmTarget, setConfirmTarget] = useState<any | null>(null);
  const queryClient = useQueryClient();

  const { data, isLoading, isError, error, refetch } = useQuery({
    queryKey: ['project-site-diaries', id, statusFilter],
    queryFn: () => api.get(`/projects/${id}/site-diaries`, { params: statusFilter !== 'all' ? { status: statusFilter } : {} }).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (diary: any) => api.delete(`/projects/${id}/site-diaries/${diary.id}`),
    onSuccess: () => {
      toast.success('Site diary deleted');
      queryClient.invalidateQueries({ queryKey: ['project-site-diaries', id] });
      setConfirmTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete site diary')),
  });

  const diaries = (data?.data ?? []).filter((d: any) =>
    d.works_carried_out?.toLowerCase().includes(search.toLowerCase()) ||
    String(d.diary_date)?.includes(search)
  );

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      <ProjectModuleHeader
        category="Delivery control"
        title="Site reports"
        description="Keep a reliable daily record of progress, labour, weather and activity on site."
        icon={ClipboardList}
        tour={<PageTourButton tourKey="page-site-reports" label="Take a tour of this page" />}
        action={canWrite ? (
          <button
            data-tour="site-reports-new"
            onClick={() => setModalDiary('new')}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> New diary
          </button>
        ) : undefined}
      />

      <div className="ss-animate-in flex flex-wrap items-center gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" data-tour="site-reports-filters" style={{ animationDelay: '100ms' }}>
        <div className="relative min-w-[220px] flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search diaries…"
            className="h-10 w-full rounded-xl bg-[var(--bg-elevated)] pl-9 pr-4 text-sm outline-none transition-colors focus:ring-2 focus:ring-[var(--gold)]/30"
            style={{ color: 'var(--text-primary)' }}
          />
        </div>
        <div className="flex gap-1 overflow-x-auto rounded-xl bg-[var(--bg-elevated)] p-1">
          {(['all', 'draft', 'submitted', 'approved'] as const).map(s => (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium capitalize transition-all"
              style={statusFilter === s
                ? { backgroundColor: 'var(--bg-surface)', boxShadow: '0 1px 3px rgba(0,0,0,0.1)', color: 'var(--text-primary)' }
                : { color: 'var(--text-muted)' }}
            >
              {s === 'all' ? 'All' : STATUS_LABELS[s]}
            </button>
          ))}
        </div>
      </div>

      <div className="space-y-3" data-tour="site-reports-list">
        {isLoading ? (
          [...Array(4)].map((_, i) => (
            <div key={i} className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />

          ))
        ) : isError ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <ClipboardList size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load site diaries</p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
            <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-3">
              Try again
            </Button>
          </div>
        ) : diaries.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <ClipboardList size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No site diaries{statusFilter !== 'all' ? ` with status "${statusFilter}"` : ''} yet</p>
            {canWrite && (
            <Button onClick={() => setModalDiary('new')} variant="secondary" size="sm" className="mt-3">
              Add first diary
            </Button>
            )}
          </div>
        ) : diaries.map((d: any, i: number) => {
          const badge = STATUS_COLORS[d.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div
              key={d.id}
              onClick={() => setModalDiary(d)}
              className="ss-animate-in flex items-center justify-between p-4 rounded-xl cursor-pointer transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] hover:-translate-y-0.5"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
            >
              <div className="flex items-center gap-4">
                <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'rgba(16,185,129,0.1)' }}>
                  <ClipboardList size={16} style={{ color: '#10b981' }} />
                </div>
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                    Site diary — <span className="tabular-nums">{formatDate(d.diary_date)}</span>
                  </p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    {d.weather ? `${d.weather} · ` : ''}{d.workers_on_site ?? 0} workers on site
                    {d.works_carried_out ? ` · ${d.works_carried_out.slice(0, 60)}${d.works_carried_out.length > 60 ? '…' : ''}` : ''}
                  </p>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                      style={{ backgroundColor: badge.bg, color: badge.text }}>
                  {d.status}
                </span>
                {canWrite && (
                  <button onClick={e => { e.stopPropagation(); setConfirmTarget(d); }} title="Delete">
                    <Trash2 size={14} style={{ color: 'var(--text-muted)' }} />
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {modalDiary && (
        <SiteDiaryModal projectId={id!} diary={modalDiary === 'new' ? null : modalDiary} readOnly={!canWrite} onClose={() => setModalDiary(null)} />
      )}

      {confirmTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete the site diary for {formatDate(confirmTarget.diary_date)}? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => deleteMutation.mutate(confirmTarget)}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white"
                style={{ backgroundColor: '#a11a1a' }}
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
