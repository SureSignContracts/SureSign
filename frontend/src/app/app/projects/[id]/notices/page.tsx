'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Bell, Plus, Search, Clock, AlertTriangle, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';

type NoticeType = 'eot' | 'delay' | 'pay-less' | 'site-instruction';

const NOTICE_TABS: { id: NoticeType; label: string }[] = [
  { id: 'eot',              label: 'EOT Requests' },
  { id: 'delay',            label: 'Delay Notices' },
  { id: 'pay-less',         label: 'Pay Less Notices' },
  { id: 'site-instruction', label: 'Site Instructions' },
];

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  submitted:        { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  under_assessment: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  granted:          { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  refused:          { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  draft:            { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  issued:           { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
};

const STATUS_LABELS: Record<string, string> = {
  submitted:        'Submitted',
  under_assessment: 'Under Assessment',
  granted:          'Granted',
  refused:          'Refused',
  draft:            'Draft',
  issued:           'Issued',
};

const SITE_INSTRUCTION_TYPES = [
  { value: 'variation',  label: 'Variation' },
  { value: 'safety',     label: 'Safety' },
  { value: 'quality',    label: 'Quality' },
  { value: 'design',     label: 'Design' },
  { value: 'general',    label: 'General' },
  { value: 'urgent',     label: 'Urgent' },
];

const ENDPOINT_MAP: Record<NoticeType, string> = {
  'eot':              'eot-requests',
  'delay':            'eot-requests',
  'pay-less':         'pay-less-notices',
  'site-instruction': 'site-instructions',
};

const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
const labelStyle = { color: 'var(--text-muted)' };

function NewEotModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ title: '', grounds: '', notice_date: new Date().toISOString().split('T')[0], days_claimed: '' });
  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) => api.post(`/projects/${projectId}/eot-requests`, data).then(r => r.data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['project-notices', projectId] }); queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] }); toast.success('EOT submitted'); onClose(); },
    onError: () => toast.error('Failed'),
  });
  const set = (f: keyof typeof form, v: string) => setForm(p => ({ ...p, [f]: v }));
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between"><h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>New EOT Request</h2><button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button></div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="space-y-3">
          <div><label className="block text-xs mb-1" style={labelStyle}>Title *</label><input value={form.title} onChange={e => set('title', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Notice Date</label><input type="date" value={form.notice_date} onChange={e => set('notice_date', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Days Claimed</label><input type="number" value={form.days_claimed} onChange={e => set('days_claimed', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Grounds</label><textarea value={form.grounds} onChange={e => set('grounds', e.target.value)} rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} /></div>
          <div className="flex justify-end gap-3"><button type="button" onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button><button type="submit" disabled={isPending} className="px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>{isPending ? 'Submitting…' : 'Submit EOT'}</button></div>
        </form>
      </div>
    </div>
  );
}

function NewPayLessModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();

  const { data: payAppsData } = useQuery({
    queryKey: ['project-payment-apps-for-pl', projectId],
    queryFn: () => api.get(`/projects/${projectId}/payment-applications`).then(r => r.data),
  });
  const paymentApps = payAppsData?.data ?? [];

  const [form, setForm] = useState({
    payment_application_id: '',
    notice_date: new Date().toISOString().split('T')[0],
    amount: '',
    reason: '',
    reference: '',
  });
  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) => api.post(`/projects/${projectId}/pay-less-notices`, {
      ...data,
      payment_application_id: data.payment_application_id || null,
    }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-notices', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-payment-applications', projectId] });
      toast.success('Pay Less Notice issued');
      onClose();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message ?? 'Failed to issue notice'),
  });
  const set = (f: keyof typeof form, v: string) => setForm(p => ({ ...p, [f]: v }));
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between"><h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>New Pay Less Notice</h2><button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button></div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="space-y-3">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Payment Application</label>
            <select value={form.payment_application_id} onChange={e => set('payment_application_id', e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
              <option value="">Not linked to a payment application</option>
              {paymentApps.map((pa: any) => (
                <option key={pa.id} value={pa.id}>
                  Application #{pa.application_number}{pa.period_ending ? ` — ${pa.period_ending}` : ''}
                </option>
              ))}
            </select>
            {paymentApps.length === 0 && (
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>No payment applications found for this project.</p>
            )}
          </div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Notice Date *</label><input type="date" value={form.notice_date} onChange={e => set('notice_date', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Notified Sum (£) *</label><input type="number" step="0.01" value={form.amount} onChange={e => set('amount', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Reference</label><input value={form.reference} onChange={e => set('reference', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Basis / Reason *</label><textarea value={form.reason} onChange={e => set('reason', e.target.value)} required rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} /></div>
          <div className="flex justify-end gap-3"><button type="button" onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button><button type="submit" disabled={isPending} className="px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>{isPending ? 'Issuing…' : 'Issue Notice'}</button></div>
        </form>
      </div>
    </div>
  );
}

function NewSiteInstructionModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    title: '',
    type: 'general',
    description: '',
    issued_date: new Date().toISOString().split('T')[0],
    issued_to: '',
  });
  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) => api.post(`/projects/${projectId}/site-instructions`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-notices', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Site instruction issued');
      onClose();
    },
    onError: (err: any) => toast.error(err?.response?.data?.message ?? 'Failed to issue instruction'),
  });
  const set = (f: keyof typeof form, v: string) => setForm(p => ({ ...p, [f]: v }));
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between"><h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>New Site Instruction</h2><button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button></div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="space-y-3">
          <div><label className="block text-xs mb-1" style={labelStyle}>Title *</label><input value={form.title} onChange={e => set('title', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Type *</label>
            <select value={form.type} onChange={e => set('type', e.target.value)} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle}>
              {SITE_INSTRUCTION_TYPES.map(t => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="block text-xs mb-1" style={labelStyle}>Issued Date</label><input type="date" value={form.issued_date} onChange={e => set('issued_date', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
            <div><label className="block text-xs mb-1" style={labelStyle}>Issued To</label><input value={form.issued_to} onChange={e => set('issued_to', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          </div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Description</label><textarea value={form.description} onChange={e => set('description', e.target.value)} rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} /></div>
          <div className="flex justify-end gap-3"><button type="button" onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button><button type="submit" disabled={isPending} className="px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>{isPending ? 'Issuing…' : 'Issue Instruction'}</button></div>
        </form>
      </div>
    </div>
  );
}

export default function ProjectNoticesPage() {
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const [tab, setTab] = useState<NoticeType>('eot');
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);

  const API_ENDPOINT_MAP: Record<NoticeType, string> = {
    'eot':              `/projects/${id}/eot-requests`,
    'delay':            `/projects/${id}/eot-requests`,
    'pay-less':         `/projects/${id}/pay-less-notices`,
    'site-instruction': `/projects/${id}/site-instructions`,
  };

  const { data, isLoading } = useQuery({
    queryKey: ['project-notices', id, tab],
    queryFn: () => api.get(API_ENDPOINT_MAP[tab]).then(r => r.data).catch(() => ({ data: [] })),
  });

  const items = (data?.data ?? []).filter((item: any) =>
    item.title?.toLowerCase().includes(search.toLowerCase()) ||
    item.subject?.toLowerCase().includes(search.toLowerCase()) ||
    item.grounds?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Notices</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>EOT requests, delay notices, pay less notices and site instructions</p>
        </div>
        {canWrite && (
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New Notice
        </button>
        )}
      </div>

      {/* Tabs */}
      <div className="flex gap-1 p-1 rounded-lg w-fit" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {NOTICE_TABS.map(t => (
          <button key={t.id} onClick={() => { setTab(t.id); setShowModal(false); }}
            className="px-4 py-2 rounded-md text-sm font-medium transition-all"
            style={tab === t.id ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
            {t.label}
          </button>
        ))}
      </div>

      {/* Search */}
      <div className="relative max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search notices…"
          className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
      </div>

      {/* Items */}
      <div className="space-y-3">
        {isLoading ? (
          [...Array(3)].map((_, i) => (
            <div key={i} className="h-18 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))
        ) : items.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <Bell size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No {NOTICE_TABS.find(t => t.id === tab)?.label.toLowerCase()} yet</p>
            {canWrite && (
            <button onClick={() => setShowModal(true)} className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              Create New
            </button>
            )}
          </div>
        ) : items.map((item: any) => {
          const badge = STATUS_COLORS[item.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          return (
            <div key={item.id}
              className="flex items-center justify-between p-4 rounded-xl cursor-pointer hover:bg-[var(--bg-elevated)] transition-colors"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
              <div className="flex items-center gap-4">
                <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ backgroundColor: 'rgba(249,115,22,0.1)' }}>
                  <AlertTriangle size={15} style={{ color: '#fb923c' }} />
                </div>
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                    {item.title ?? item.subject ?? `#${item.eot_number ?? item.instruction_number ?? item.id}`}
                  </p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    {item.days_claimed ? `${item.days_claimed} days claimed · ` : ''}
                    {item.notice_date ? formatDate(item.notice_date) : item.issued_date ? formatDate(item.issued_date) : ''}
                  </p>
                </div>
              </div>
              <span className="text-xs px-2 py-0.5 rounded-full"
                    style={{ backgroundColor: badge.bg, color: badge.text }}>
                {STATUS_LABELS[item.status] ?? item.status?.replace(/_/g, ' ')}
              </span>
            </div>
          );
        })}
      </div>

      {canWrite && showModal && tab === 'eot'              && <NewEotModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && showModal && tab === 'delay'            && <NewEotModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && showModal && tab === 'pay-less'         && <NewPayLessModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && showModal && tab === 'site-instruction' && <NewSiteInstructionModal projectId={id!} onClose={() => setShowModal(false)} />}
    </div>
  );
}
