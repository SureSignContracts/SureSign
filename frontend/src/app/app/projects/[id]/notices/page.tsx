'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { Bell, Plus, Search, AlertTriangle, X, Trash2 } from 'lucide-react';
import toast from '@/lib/toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PageTourButton from '@/components/tours/PageTourButton';
import { ProjectModuleHeader } from '@/components/projects/ProjectModuleHeader';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/getErrorMessage';

// Delay Notices used to be a third tab here, but it fetched the EOT Requests
// endpoint under the "Delay Notices" label — Delay Events have their own
// complete, correct CRUD (create/update/delete/generate-notice) on the
// Delay & EOT page (/app/projects/[id]/delay-eot), so the broken duplicate
// tab was removed rather than reimplemented a second time here.
type NoticeType = 'eot' | 'pay-less' | 'site-instruction';

const NOTICE_TABS: { id: NoticeType; label: string }[] = [
  { id: 'eot',              label: 'EOT Requests' },
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

const inputStyle = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };
const labelStyle = { color: 'var(--text-muted)' };

function NewEotModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ title: '', grounds: '', notice_date: effectiveTodayYmd(), days_claimed: '' });
  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) => api.post(`/projects/${projectId}/eot-requests`, data).then(r => r.data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['project-notices', projectId] }); queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] }); toast.success('EOT submitted'); onClose(); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed')),
  });
  const set = (f: keyof typeof form, v: string) => setForm(p => ({ ...p, [f]: v }));
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl p-5 space-y-4 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between"><h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>New EOT Request</h2><button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button></div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="space-y-3">
          <div><label className="block text-xs mb-1" style={labelStyle}>Title *</label><input value={form.title} onChange={e => set('title', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Notice Date</label><input type="date" value={form.notice_date} onChange={e => set('notice_date', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Days Claimed</label><input type="number" value={form.days_claimed} onChange={e => set('days_claimed', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Grounds</label><textarea value={form.grounds} onChange={e => set('grounds', e.target.value)} rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} /></div>
          <div className="flex justify-end gap-3"><button type="button" onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button><button type="submit" disabled={isPending} className="px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>{isPending ? 'Submitting…' : 'Submit EOT'}</button></div>
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
    notice_date: effectiveTodayYmd(),
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
    onError: (err: any) => toast.error(getErrorMessage(err, 'Failed to issue notice')),
  });
  const set = (f: keyof typeof form, v: string) => setForm(p => ({ ...p, [f]: v }));
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl p-5 space-y-4 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between"><h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>New Pay Less Notice</h2><button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button></div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="space-y-3">
          <div>
            <label className="block text-xs mb-1" style={labelStyle}>Payment Application</label>
            <Select value={form.payment_application_id} onChange={e => set('payment_application_id', e.target.value)} className="w-full">
              <option value="">Not linked to a payment application</option>
              {paymentApps.map((pa: any) => (
                <option key={pa.id} value={pa.id}>
                  Application #{pa.application_number}{pa.period_ending ? ` (${pa.period_ending})` : ''}
                </option>
              ))}
            </Select>
            {paymentApps.length === 0 && (
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>No payment applications found for this project.</p>
            )}
          </div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Notice Date *</label><input type="date" value={form.notice_date} onChange={e => set('notice_date', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Notified Sum (£) *</label><input type="number" step="0.01" value={form.amount} onChange={e => set('amount', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Reference</label><input value={form.reference} onChange={e => set('reference', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Basis / Reason *</label><textarea value={form.reason} onChange={e => set('reason', e.target.value)} required rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} /></div>
          <div className="flex justify-end gap-3"><button type="button" onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button><button type="submit" disabled={isPending} className="px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>{isPending ? 'Issuing…' : 'Issue Notice'}</button></div>
        </form>
      </div>
    </div>
  );
}

// Handles create AND edit/view — Site Instructions previously had no way to
// open, edit, or delete an existing instruction once issued (unlike RFIs,
// Site Diaries and Meetings, which all support this). `readOnly` mirrors the
// pattern already used by site-reports/page.tsx's SiteDiaryModal.
function SiteInstructionModal({ projectId, instruction, readOnly, onClose }: { projectId: string; instruction: any | null; readOnly: boolean; onClose: () => void }) {
  const queryClient = useQueryClient();
  const isEdit = !!instruction;
  const [form, setForm] = useState({
    title: instruction?.title ?? '',
    type: instruction?.type ?? 'general',
    description: instruction?.description ?? '',
    issued_date: instruction?.issued_date ? String(instruction.issued_date).slice(0, 10) : effectiveTodayYmd(),
    issued_to: instruction?.issued_to ?? '',
    status: instruction?.status ?? 'draft',
  });
  const { mutate, isPending } = useMutation({
    mutationFn: (data: typeof form) => isEdit
      ? api.put(`/projects/${projectId}/site-instructions/${instruction.id}`, data).then(r => r.data)
      : api.post(`/projects/${projectId}/site-instructions`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-notices', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success(isEdit ? 'Site instruction updated' : 'Site instruction issued');
      onClose();
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, isEdit ? 'Failed to update instruction' : 'Failed to issue instruction')),
  });
  const set = (f: keyof typeof form, v: string) => setForm(p => ({ ...p, [f]: v }));
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl p-5 space-y-4 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
            {readOnly ? 'Site Instruction' : isEdit ? 'Edit Site Instruction' : 'New Site Instruction'}
          </h2>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="space-y-3">
          <fieldset disabled={readOnly} className="space-y-3">
          <div><label className="block text-xs mb-1" style={labelStyle}>Title *</label><input value={form.title} onChange={e => set('title', e.target.value)} required className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Type *</label>
              <Select value={form.type} onChange={e => set('type', e.target.value)} disabled={readOnly} className="w-full">
                {SITE_INSTRUCTION_TYPES.map(t => (
                  <option key={t.value} value={t.value}>{t.label}</option>
                ))}
              </Select>
            </div>
            <div>
              <label className="block text-xs mb-1" style={labelStyle}>Status</label>
              <Select value={form.status} onChange={e => set('status', e.target.value)} disabled={readOnly} className="w-full">
                <option value="draft">Draft</option>
                <option value="issued">Issued</option>
              </Select>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className="block text-xs mb-1" style={labelStyle}>Issued Date</label><input type="date" value={form.issued_date} onChange={e => set('issued_date', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
            <div><label className="block text-xs mb-1" style={labelStyle}>Issued To</label><input value={form.issued_to} onChange={e => set('issued_to', e.target.value)} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={inputStyle} /></div>
          </div>
          <div><label className="block text-xs mb-1" style={labelStyle}>Description</label><textarea value={form.description} onChange={e => set('description', e.target.value)} rows={3} className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none" style={inputStyle} /></div>
          </fieldset>
          <div className="flex justify-end gap-3">
            {readOnly ? (
              <button type="button" onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Close</button>
            ) : (
              <>
                <button type="button" onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
                <button type="submit" disabled={isPending} className="px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
                  {isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Issue Instruction'}
                </button>
              </>
            )}
          </div>
        </form>
      </div>
    </div>
  );
}

function ProjectNoticesPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  // EOT is reviewed (Batch 3), Pay Less Notices reviewed (Batch 4) — each
  // gets its own flag. Site Instructions hasn't been reviewed in any batch,
  // so it stays on the legacy `canWrite` (Admin-only) unchanged.
  const { canWrite, canManageEotRequests, canManagePayLessNotices } = useProjectPermissions();
  const [tab, setTab] = useState<NoticeType>('eot');
  const canWriteForTab = tab === 'eot'
    ? canManageEotRequests
    : tab === 'pay-less'
      ? canManagePayLessNotices
      : canWrite;
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [instructionModal, setInstructionModal] = useState<any | null>(null);
  const [confirmDeleteInstruction, setConfirmDeleteInstruction] = useState<any | null>(null);

  const API_ENDPOINT_MAP: Record<NoticeType, string> = {
    'eot':              `/projects/${id}/eot-requests`,
    'pay-less':         `/projects/${id}/pay-less-notices`,
    'site-instruction': `/projects/${id}/site-instructions`,
  };

  const { data, isLoading } = useQuery({
    queryKey: ['project-notices', id, tab],
    queryFn: () => api.get(API_ENDPOINT_MAP[tab]).then(r => r.data).catch(() => ({ data: [] })),
  });

  const deleteInstructionMutation = useMutation({
    mutationFn: (instruction: any) => api.delete(`/projects/${id}/site-instructions/${instruction.id}`),
    onSuccess: () => {
      toast.success('Site instruction deleted');
      queryClient.invalidateQueries({ queryKey: ['project-notices', id] });
      setConfirmDeleteInstruction(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete site instruction')),
  });

  const items = (data?.data ?? []).filter((item: any) =>
    item.title?.toLowerCase().includes(search.toLowerCase()) ||
    item.subject?.toLowerCase().includes(search.toLowerCase()) ||
    item.grounds?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
      <ProjectModuleHeader
        category="Contract administration"
        title="Notices"
        description="Issue and track contractual notices, time requests and site instructions with a clear audit trail."
        icon={Bell}
        tour={<PageTourButton tourKey="page-notices" label="Take a tour of this page" />}
        action={canWriteForTab ? (
          <button data-tour="notices-new" onClick={() => setShowModal(true)} className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0">
            <Plus size={16} /> New notice
          </button>
        ) : undefined}
      />

      <div className="ss-animate-in rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-2 shadow-[var(--shadow-card)]" style={{ animationDelay: '90ms' }}>
        <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex gap-1 overflow-x-auto rounded-xl bg-[var(--bg-elevated)] p-1" data-tour="notices-tabs">
            {NOTICE_TABS.map(t => (
              <button key={t.id} onClick={() => { setTab(t.id); setShowModal(false); }}
                className="whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition-all active:scale-[0.97]"
                style={tab === t.id ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
                {t.label}
              </button>
            ))}
          </div>
          <div className="relative min-w-[240px] lg:w-80" data-tour="notices-search">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search this register…"
              className="h-10 w-full rounded-xl bg-[var(--bg-elevated)] pl-9 pr-4 text-sm outline-none transition-colors focus:ring-2 focus:ring-[var(--gold)]/30"
              style={{ color: 'var(--text-primary)' }} />
          </div>
        </div>
      </div>

      {/* Items */}
      <div className="space-y-3" data-tour="notices-list">
        {isLoading ? (
          [...Array(3)].map((_, i) => (
            <div key={i} className="h-18 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))
        ) : items.length === 0 ? (
          <div className="ss-animate-in grid min-h-[260px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.75fr_1.25fr]">
            <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
              <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
                <Bell size={38} strokeWidth={1.5} />
              </div>
            </div>
            <div className="flex flex-col items-start justify-center p-8 sm:p-10">
              <h2 className="text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>
                No {NOTICE_TABS.find(t => t.id === tab)?.label.toLowerCase()} yet
              </h2>
              <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
                Start the register when a formal project communication needs to be issued and tracked.
              </p>
              {canWriteForTab && (
                <Button onClick={() => setShowModal(true)} size="sm" className="mt-5">
                  <Plus size={14} /> Create notice
                </Button>
              )}
            </div>
          </div>
        ) : items.map((item: any, i: number) => {
          const badge = STATUS_COLORS[item.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
          const isSiteInstruction = tab === 'site-instruction';
          return (
            <div key={item.id}
              onClick={() => isSiteInstruction && setInstructionModal(item)}
              className="group flex items-center justify-between rounded-2xl p-4 transition-all duration-300 ss-animate-in hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)]"
              style={{
                backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)',
                animationDelay: `${Math.min(i * 45, 360)}ms`,
                cursor: isSiteInstruction ? 'pointer' : 'default',
              }}>
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
              <div className="flex items-center gap-3">
                <span className="text-xs px-2 py-0.5 rounded-full"
                      style={{ backgroundColor: badge.bg, color: badge.text }}>
                  {STATUS_LABELS[item.status] ?? item.status?.replace(/_/g, ' ')}
                </span>
                {isSiteInstruction && canWrite && (
                  <button onClick={e => { e.stopPropagation(); setConfirmDeleteInstruction(item); }} title="Delete">
                    <Trash2 size={14} style={{ color: 'var(--text-muted)' }} />
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>

      {canManageEotRequests   && showModal && tab === 'eot'              && <NewEotModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canManagePayLessNotices && showModal && tab === 'pay-less'        && <NewPayLessModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite               && showModal && tab === 'site-instruction' && <SiteInstructionModal projectId={id!} instruction={null} readOnly={false} onClose={() => setShowModal(false)} />}

      {instructionModal && (
        <SiteInstructionModal projectId={id!} instruction={instructionModal} readOnly={!canWrite} onClose={() => setInstructionModal(null)} />
      )}

      {confirmDeleteInstruction && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete site instruction &ldquo;{confirmDeleteInstruction.title}&rdquo;? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmDeleteInstruction(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => deleteInstructionMutation.mutate(confirmDeleteInstruction)}
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

export default function GatedProjectNoticesPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.notices" title="Notices" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectNoticesPage />
    </FeatureAvailabilityGate>
  );
}
