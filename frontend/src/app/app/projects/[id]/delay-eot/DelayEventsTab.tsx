'use client';

import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { Plus, X, FileOutput, Trash2, Check, Ban, ExternalLink } from 'lucide-react';
import toast from 'react-hot-toast';
import Select from '@/components/ui/Select';
import { getErrorMessage, blobDownload, assertDeleteSucceeded, type ContractOption, type TradePackageOption } from './page';

// ─── Types ─────────────────────────────────────────────────────────────────

type DelayEvent = {
  id: number;
  event_number: number;
  title: string;
  description: string | null;
  cause_category: string;
  date_occurred: string | null;
  date_notified: string | null;
  notified_by: string | null;
  estimated_delay_days: number | null;
  status: 'open' | 'under_assessment' | 'closed' | 'rejected';
  notes: string | null;
  contract?: ContractOption | null;
  trade_package?: TradePackageOption | null;
  affected_milestone?: { id: number; name: string } | null;
  variation?: { id: number; title: string } | null;
  related_eot?: { id: number; eot_number: number; status: string } | null;
};

export const INPUT_STYLE = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

const CAUSE_LABELS: Record<string, string> = {
  weather: 'Weather',
  employer_instruction: 'Employer / Contractor Instruction',
  utility: 'Statutory Undertaker / Utility',
  access: 'Access',
  design: 'Design',
  third_party: 'Third Party',
  other: 'Other',
};

const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  open: { label: 'Open', bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  under_assessment: { label: 'Under Assessment', bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  closed: { label: 'Closed', bg: 'rgba(34,197,94,0.12)', text: '#4ade80' },
  rejected: { label: 'Rejected', bg: 'rgba(239,68,68,0.12)', text: '#f87171' },
};

function StatusBadge({ status }: { status: string }) {
  const s = STATUS_CONFIG[status] ?? STATUS_CONFIG.open;
  return (
    <span className="px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap" style={{ backgroundColor: s.bg, color: s.text }}>
      {s.label}
    </span>
  );
}

// ─── Create / Edit modal ────────────────────────────────────────────────────

function DelayEventModal({ projectId, contracts, tradePackages, delayEvent, onClose, invalidateKey }: {
  projectId: string; contracts: ContractOption[]; tradePackages: TradePackageOption[];
  delayEvent: DelayEvent | null; onClose: () => void; invalidateKey: unknown[];
}) {
  const qc = useQueryClient();
  const isEdit = !!delayEvent;
  const [form, setForm] = useState({
    title: delayEvent?.title ?? '',
    description: delayEvent?.description ?? '',
    cause_category: delayEvent?.cause_category ?? 'other',
    date_occurred: delayEvent?.date_occurred?.slice(0, 10) ?? effectiveTodayYmd(),
    date_notified: delayEvent?.date_notified?.slice(0, 10) ?? '',
    notified_by: delayEvent?.notified_by ?? '',
    estimated_delay_days: delayEvent?.estimated_delay_days?.toString() ?? '',
    contract_id: delayEvent?.contract?.id?.toString() ?? '',
    trade_package_id: delayEvent?.trade_package?.id?.toString() ?? '',
    notes: delayEvent?.notes ?? '',
  });

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: form.title,
        description: form.description || null,
        cause_category: form.cause_category,
        date_occurred: form.date_occurred,
        date_notified: form.date_notified || null,
        notified_by: form.notified_by || null,
        estimated_delay_days: form.estimated_delay_days ? Number(form.estimated_delay_days) : null,
        contract_id: form.contract_id ? Number(form.contract_id) : null,
        notes: form.notes || null,
      };
      if (isEdit) return api.put(`/projects/${projectId}/delay-events/${delayEvent!.id}`, payload);
      if (form.trade_package_id) return api.post(`/projects/${projectId}/trade-packages/${form.trade_package_id}/delay-events`, payload);
      return api.post(`/projects/${projectId}/delay-events`, payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Delay Event updated' : 'Delay Event raised');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save Delay Event')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-lg rounded-xl p-6 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>{isEdit ? 'Edit Delay Event' : 'Raise Delay Event'}</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <div className="space-y-3">
          <Field label="Title" required>
            <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} />
          </Field>

          <Field label="Cause Category">
            <Select className="w-full" value={form.cause_category} onChange={e => setForm({ ...form, cause_category: e.target.value })}>
              {Object.entries(CAUSE_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </Select>
          </Field>

          {!isEdit && (
            <div className="grid grid-cols-2 gap-3">
              <Field label="Contract">
                <Select className="w-full" value={form.contract_id} onChange={e => setForm({ ...form, contract_id: e.target.value, trade_package_id: '' })} disabled={!!form.trade_package_id}>
                  <option value="">—</option>
                  {contracts.map(c => <option key={c.id} value={c.id}>{c.title}</option>)}
                </Select>
              </Field>
              <Field label="Trade Package">
                <Select className="w-full" value={form.trade_package_id} onChange={e => setForm({ ...form, trade_package_id: e.target.value, contract_id: '' })} disabled={!!form.contract_id}>
                  <option value="">—</option>
                  {tradePackages.map(tp => <option key={tp.id} value={tp.id}>{tp.name}</option>)}
                </Select>
              </Field>
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <Field label="Date Occurred" required>
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.date_occurred} onChange={e => setForm({ ...form, date_occurred: e.target.value })} />
            </Field>
            <Field label="Date Notified">
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.date_notified} onChange={e => setForm({ ...form, date_notified: e.target.value })} />
            </Field>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Notified By">
              <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.notified_by} onChange={e => setForm({ ...form, notified_by: e.target.value })} />
            </Field>
            <Field label="Estimated Delay (days)">
              <input type="number" min={0} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.estimated_delay_days} onChange={e => setForm({ ...form, estimated_delay_days: e.target.value })} />
            </Field>
          </div>

          <Field label="Particulars">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={3} value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} />
          </Field>

          <Field label="Notes">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={2} value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} />
          </Field>
        </div>

        <div className="flex justify-end gap-2 mt-5">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => mutation.mutate()}
            disabled={!form.title || !form.date_occurred || mutation.isPending}
            className="px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Raise Delay Event'}
          </button>
        </div>
      </div>
    </div>
  );
}

function Field({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</span>
      {children}
    </label>
  );
}

// ─── Main tab ───────────────────────────────────────────────────────────────

export function DelayEventsTab({ projectId, contracts, tradePackages, canWrite, tradePackageId }: {
  projectId: string; contracts: ContractOption[]; tradePackages: TradePackageOption[]; canWrite: boolean; tradePackageId?: number;
}) {
  const qc = useQueryClient();
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [modalTarget, setModalTarget] = useState<DelayEvent | 'new' | null>(null);
  const [confirmTarget, setConfirmTarget] = useState<{ event: DelayEvent; action: 'close' | 'reject' | 'delete' } | null>(null);

  // When scoped to a single trade package (Sprint 6C workspace tab), fetch from the
  // trade-package-scoped endpoint instead of the project-wide one. Response is a bare
  // array there vs. {data:[...]} project-wide — normalised to the same shape here so
  // the rest of this component doesn't need to know which source it came from.
  const listQueryKey = tradePackageId ? ['trade-package-delay-events', tradePackageId] : ['project-delay-events', projectId];
  const { data, isLoading, isError, error, refetch } = useQuery<{ data?: DelayEvent[] }>({
    queryKey: listQueryKey,
    queryFn: () => tradePackageId
      ? api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/delay-events`).then(r => ({ data: r.data }))
      : api.get(`/projects/${projectId}/delay-events`).then(r => r.data),
  });

  const events = data?.data ?? [];
  const filtered = useMemo(
    () => statusFilter === 'all' ? events : events.filter(e => e.status === statusFilter),
    [events, statusFilter]
  );

  const statusMutation = useMutation({
    mutationFn: ({ event, status }: { event: DelayEvent; status: string }) => api.put(`/projects/${projectId}/delay-events/${event.id}`, { status }),
    onSuccess: () => {
      toast.success('Delay Event updated');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setConfirmTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to update')),
  });

  const deleteMutation = useMutation({
    mutationFn: (event: DelayEvent) => api.delete(`/projects/${projectId}/delay-events/${event.id}`).then(res => { assertDeleteSucceeded(res); return res; }),
    onSuccess: () => {
      toast.success('Delay Event deleted');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setConfirmTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete')),
  });

  const generateNoticeMutation = useMutation({
    mutationFn: (event: DelayEvent) => api.post(`/projects/${projectId}/delay-events/${event.id}/generate-notice`).then(r => r.data),
    onSuccess: (doc) => { blobDownload(doc); toast.success('Notice of Delay generated'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to generate — has this event been marked as notified?')),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between" data-tour="delay-events-filters">
        <div className="flex gap-1.5 flex-wrap">
          {['all', 'open', 'under_assessment', 'closed', 'rejected'].map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-full text-xs font-medium capitalize"
              style={statusFilter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}>
              {s === 'all' ? 'All' : (STATUS_CONFIG[s]?.label ?? s)}
            </button>
          ))}
        </div>
        {canWrite && (
          <button data-tour="delay-events-new" onClick={() => setModalTarget('new')} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            <Plus size={15} /> Raise Delay Event
          </button>
        )}
      </div>

      <div className="rounded-xl overflow-hidden" data-tour="delay-events-table" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['#', 'Title', 'Cause', 'Occurred', 'Notified', 'Est. Delay', 'Source', 'Status', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && isError && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: '#f87171' }}>
                We couldn&rsquo;t load delay events. {getErrorMessage(error, 'Please try again.')}{' '}
                <button onClick={() => refetch()} className="underline font-medium">Try again</button>
              </td></tr>
            )}
            {!isLoading && !isError && filtered.length === 0 && <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No delay events{statusFilter !== 'all' ? ` with status "${statusFilter}"` : ''}.</td></tr>}
            {filtered.map(ev => (
              <tr key={ev.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5 font-mono text-xs" style={{ color: 'var(--text-muted)' }}>#{ev.event_number}</td>
                <td className="px-3 py-2.5">
                  <div className="font-medium" style={{ color: 'var(--text-primary)' }}>{ev.title}</div>
                  {ev.affected_milestone && <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Milestone: {ev.affected_milestone.name}</div>}
                  {ev.variation && <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Variation: {ev.variation.title}</div>}
                  {ev.related_eot && (
                    <a href={`/app/projects/${projectId}/delay-eot?tab=eot`} className="text-xs mt-0.5 inline-flex items-center gap-1 hover:underline" style={{ color: '#60a5fa' }}>
                      EOT #{ev.related_eot.eot_number} ({ev.related_eot.status}) <ExternalLink size={10} />
                    </a>
                  )}
                </td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{CAUSE_LABELS[ev.cause_category] ?? ev.cause_category}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{ev.date_occurred ? formatDate(ev.date_occurred) : '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{ev.date_notified ? formatDate(ev.date_notified) : <span style={{ color: '#fb923c' }}>Not notified</span>}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{ev.estimated_delay_days !== null ? `${ev.estimated_delay_days}d` : '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{ev.trade_package?.name ?? ev.contract?.title ?? '—'}</td>
                <td className="px-3 py-2.5"><StatusBadge status={ev.status} /></td>
                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-1 justify-end">
                    {canWrite && <button onClick={() => setModalTarget(ev)} className="p-1.5 rounded hover:bg-white/5 text-xs" style={{ color: 'var(--text-muted)' }}>Edit</button>}
                    <button onClick={() => generateNoticeMutation.mutate(ev)} title="Generate Notice of Delay" className="p-1.5 rounded hover:bg-white/5" style={{ color: 'var(--text-muted)' }}><FileOutput size={14} /></button>
                    {canWrite && ['open', 'under_assessment'].includes(ev.status) && (
                      <>
                        <button onClick={() => setConfirmTarget({ event: ev, action: 'close' })} title="Close" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#4ade80' }}><Check size={14} /></button>
                        <button onClick={() => setConfirmTarget({ event: ev, action: 'reject' })} title="Reject" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#f87171' }}><Ban size={14} /></button>
                      </>
                    )}
                    {canWrite && <button onClick={() => setConfirmTarget({ event: ev, action: 'delete' })} title="Delete" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#f87171' }}><Trash2 size={14} /></button>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {modalTarget && (
        <DelayEventModal
          projectId={projectId} contracts={contracts} tradePackages={tradePackages}
          delayEvent={modalTarget === 'new' ? null : modalTarget}
          onClose={() => setModalTarget(null)}
          invalidateKey={listQueryKey}
        />
      )}

      {confirmTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              {confirmTarget.action === 'delete'
                ? `Delete Delay Event #${confirmTarget.event.event_number}? This cannot be undone.`
                : `Mark Delay Event #${confirmTarget.event.event_number} as ${confirmTarget.action === 'close' ? 'closed' : 'rejected'}?`}
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => confirmTarget.action === 'delete'
                  ? deleteMutation.mutate(confirmTarget.event)
                  : statusMutation.mutate({ event: confirmTarget.event, status: confirmTarget.action === 'close' ? 'closed' : 'rejected' })}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white" style={{ backgroundColor: '#a11a1a' }}>
                Confirm
              </button>
            </div>
          </div>
        </div>
      )}

      <style jsx>{`
        .input {
          width: 100%; padding: 8px 10px; border-radius: 8px; font-size: 13px;
          background-color: var(--bg-primary); border: 1px solid var(--border); color: var(--text-primary);
        }
      `}</style>
    </div>
  );
}
