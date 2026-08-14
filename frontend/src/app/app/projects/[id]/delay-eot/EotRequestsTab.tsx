'use client';

import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { Plus, X, FileOutput, Trash2, Check, Ban } from 'lucide-react';
import toast from 'react-hot-toast';
import Select from '@/components/ui/Select';
import { getErrorMessage, blobDownload, assertDeleteSucceeded, type ContractOption, type TradePackageOption } from './page';
import { INPUT_STYLE } from './DelayEventsTab';

// ─── Types ─────────────────────────────────────────────────────────────────

type EotRequest = {
  id: number;
  eot_number: number;
  title: string;
  notice_date: string | null;
  grounds: string | null;
  days_claimed: number | null;
  days_granted: number | null;
  revised_completion_date: string | null;
  current_completion_date: string | null;
  status: 'draft' | 'submitted' | 'under_assessment' | 'granted' | 'refused';
  decided_at: string | null;
  decision_user?: { id: number; name: string } | null;
  contract?: ContractOption | null;
  trade_package?: TradePackageOption | null;
  delay_event?: { id: number; event_number: number; title: string } | null;
};

type DelayEventOption = { id: number; event_number: number; title: string };

const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  draft: { label: 'Draft', bg: 'rgba(90,86,82,0.2)', text: '#9a9490' },
  submitted: { label: 'Submitted', bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  under_assessment: { label: 'Under Assessment', bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  granted: { label: 'Granted', bg: 'rgba(34,197,94,0.12)', text: '#4ade80' },
  refused: { label: 'Refused', bg: 'rgba(239,68,68,0.12)', text: '#f87171' },
};

function StatusBadge({ status }: { status: string }) {
  const s = STATUS_CONFIG[status] ?? STATUS_CONFIG.draft;
  return (
    <span className="px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap" style={{ backgroundColor: s.bg, color: s.text }}>
      {s.label}
    </span>
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

// ─── Create / Edit modal ────────────────────────────────────────────────────

function EotModal({ projectId, contracts, tradePackages, delayEvents, eot, onClose, invalidateKey }: {
  projectId: string; contracts: ContractOption[]; tradePackages: TradePackageOption[];
  delayEvents: DelayEventOption[]; eot: EotRequest | null; onClose: () => void; invalidateKey: unknown[];
}) {
  const qc = useQueryClient();
  const isEdit = !!eot;
  const [form, setForm] = useState({
    title: eot?.title ?? '',
    notice_date: eot?.notice_date?.slice(0, 10) ?? effectiveTodayYmd(),
    grounds: eot?.grounds ?? '',
    days_claimed: eot?.days_claimed?.toString() ?? '',
    contract_id: eot?.contract?.id?.toString() ?? '',
    trade_package_id: eot?.trade_package?.id?.toString() ?? '',
    delay_event_id: eot?.delay_event?.id?.toString() ?? '',
  });

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: form.title,
        notice_date: form.notice_date,
        grounds: form.grounds || null,
        days_claimed: form.days_claimed ? Number(form.days_claimed) : null,
        contract_id: form.contract_id ? Number(form.contract_id) : null,
        delay_event_id: form.delay_event_id ? Number(form.delay_event_id) : null,
      };
      if (isEdit) return api.put(`/projects/${projectId}/eot-requests/${eot!.id}`, payload);
      if (form.trade_package_id) return api.post(`/projects/${projectId}/trade-packages/${form.trade_package_id}/eot-requests`, payload);
      return api.post(`/projects/${projectId}/eot-requests`, payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'EOT updated' : 'EOT submitted');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save EOT')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-lg rounded-xl p-6 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>{isEdit ? 'Edit EOT Request' : 'Submit EOT Request'}</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <div className="space-y-3">
          <Field label="Title" required>
            <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.title} onChange={e => setForm({ ...form, title: e.target.value })} />
          </Field>

          {!isEdit && (
            <>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Contract">
                  <Select className="w-full" value={form.contract_id} disabled={!!form.trade_package_id}
                    onChange={e => setForm({ ...form, contract_id: e.target.value, trade_package_id: '' })}>
                    <option value="">—</option>
                    {contracts.map(c => <option key={c.id} value={c.id}>{c.title}</option>)}
                  </Select>
                </Field>
                <Field label="Trade Package">
                  <Select className="w-full" value={form.trade_package_id} disabled={!!form.contract_id}
                    onChange={e => setForm({ ...form, trade_package_id: e.target.value, contract_id: '' })}>
                    <option value="">—</option>
                    {tradePackages.map(tp => <option key={tp.id} value={tp.id}>{tp.name}</option>)}
                  </Select>
                </Field>
              </div>
              <Field label="Related Delay Event">
                <Select className="w-full" value={form.delay_event_id} onChange={e => setForm({ ...form, delay_event_id: e.target.value })}>
                  <option value="">—</option>
                  {delayEvents.map(d => <option key={d.id} value={d.id}>#{d.event_number} — {d.title}</option>)}
                </Select>
              </Field>
            </>
          )}

          <div className="grid grid-cols-2 gap-3">
            <Field label="Notice Date" required>
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.notice_date} onChange={e => setForm({ ...form, notice_date: e.target.value })} />
            </Field>
            <Field label="Days Claimed">
              <input type="number" min={0} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.days_claimed} onChange={e => setForm({ ...form, days_claimed: e.target.value })} />
            </Field>
          </div>

          <Field label="Grounds">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={3} value={form.grounds} onChange={e => setForm({ ...form, grounds: e.target.value })} />
          </Field>
        </div>

        <div className="flex justify-end gap-2 mt-5">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => mutation.mutate()}
            disabled={!form.title || !form.notice_date || mutation.isPending}
            className="px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Submit EOT'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Decision modal ─────────────────────────────────────────────────────────

function DecisionModal({ projectId, eot, decision, onClose, invalidateKey }: {
  projectId: string; eot: EotRequest; decision: 'granted' | 'refused'; onClose: () => void; invalidateKey: unknown[];
}) {
  const qc = useQueryClient();
  const [days, setDays] = useState(eot.days_claimed?.toString() ?? '0');

  const mutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/eot-requests/${eot.id}/decide`, {
      status: decision,
      days_granted: decision === 'granted' ? Number(days) : undefined,
    }),
    onSuccess: () => {
      toast.success(decision === 'granted' ? 'EOT granted' : 'EOT refused');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to record decision')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <h2 className="text-base font-bold mb-3" style={{ color: 'var(--text-primary)' }}>
          {decision === 'granted' ? 'Grant Extension of Time' : 'Refuse Extension of Time'}
        </h2>
        {decision === 'granted' && (
          <Field label="Days Granted" required>
            <input type="number" min={0} className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={days} onChange={e => setDays(e.target.value)} />
          </Field>
        )}
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={onClose} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
            className="px-3 py-1.5 rounded-lg text-sm font-semibold disabled:opacity-50"
            style={{ backgroundColor: decision === 'granted' ? '#1a7a3a' : '#a11a1a', color: '#fff' }}>
            {mutation.isPending ? 'Saving…' : `Confirm ${decision === 'granted' ? 'Grant' : 'Refusal'}`}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Main tab ───────────────────────────────────────────────────────────────

export function EotRequestsTab({ projectId, contracts, tradePackages, canWrite, tradePackageId }: {
  projectId: string; contracts: ContractOption[]; tradePackages: TradePackageOption[]; canWrite: boolean; tradePackageId?: number;
}) {
  const qc = useQueryClient();
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [modalTarget, setModalTarget] = useState<EotRequest | 'new' | null>(null);
  const [decisionTarget, setDecisionTarget] = useState<{ eot: EotRequest; decision: 'granted' | 'refused' } | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<EotRequest | null>(null);

  const listQueryKey = tradePackageId ? ['trade-package-eot-requests', tradePackageId] : ['project-eot-requests', projectId];
  const { data, isLoading, isError, error, refetch } = useQuery<{ data?: EotRequest[] }>({
    queryKey: listQueryKey,
    queryFn: () => tradePackageId
      ? api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/eot-requests`).then(r => ({ data: r.data }))
      : api.get(`/projects/${projectId}/eot-requests`).then(r => r.data),
  });
  // Scoped the same way as the main list — otherwise the "Related Delay Event"
  // dropdown in the create modal would offer delay events from every trade
  // package/contract in the project, not just this one (Sprint 6C review).
  const { data: delayData } = useQuery<{ data?: DelayEventOption[] }>({
    queryKey: tradePackageId ? ['trade-package-delay-events', tradePackageId] : ['project-delay-events', projectId],
    queryFn: () => tradePackageId
      ? api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/delay-events`).then(r => ({ data: r.data }))
      : api.get(`/projects/${projectId}/delay-events`).then(r => r.data),
  });

  const eots = useMemo(() => data?.data ?? [], [data?.data]);
  const delayEvents = useMemo(() => delayData?.data ?? [], [delayData?.data]);
  const filtered = useMemo(
    () => statusFilter === 'all' ? eots : eots.filter(e => e.status === statusFilter),
    [eots, statusFilter]
  );

  const deleteMutation = useMutation({
    mutationFn: (eot: EotRequest) => api.delete(`/projects/${projectId}/eot-requests/${eot.id}`).then(res => { assertDeleteSucceeded(res); return res; }),
    onSuccess: () => {
      toast.success('EOT deleted');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setDeleteTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete')),
  });

  const generateDecisionMutation = useMutation({
    mutationFn: (eot: EotRequest) => api.post(`/projects/${projectId}/eot-requests/${eot.id}/generate-decision-notice`).then(r => r.data),
    onSuccess: (doc) => { blobDownload(doc); toast.success('Decision Notice generated'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to generate — has this EOT been decided?')),
  });

  return (
    <div className="space-y-4">
      <div className="ss-animate-in flex flex-col gap-3 rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] p-3 shadow-[var(--shadow-card)] sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap gap-1 rounded-xl bg-[var(--bg-elevated)] p-1">
          {['all', 'draft', 'submitted', 'under_assessment', 'granted', 'refused'].map(s => (
            <button key={s} onClick={() => setStatusFilter(s)}
              className="rounded-lg px-3 py-1.5 text-xs font-medium capitalize transition-all active:scale-[0.97]"
              style={statusFilter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }}>
              {s === 'all' ? 'All' : (STATUS_CONFIG[s]?.label ?? s)}
            </button>
          ))}
        </div>
        {canWrite && (
          <button onClick={() => setModalTarget('new')} className="flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-semibold transition-all hover:-translate-y-0.5 active:translate-y-0" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            <Plus size={15} /> Submit EOT
          </button>
        )}
      </div>

      <div className="ss-animate-in overflow-hidden rounded-2xl bg-[var(--bg-surface)]" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '70ms' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['#', 'Title', 'Days Claimed', 'Days Granted', 'Revised Completion', 'Source', 'Status', 'Decided By', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && isError && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: '#f87171' }}>
                We couldn&rsquo;t load EOT requests. {getErrorMessage(error, 'Please try again.')}{' '}
                <button onClick={() => refetch()} className="underline font-medium">Try again</button>
              </td></tr>
            )}
            {!isLoading && !isError && filtered.length === 0 && <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No EOT requests{statusFilter !== 'all' ? ` with status "${statusFilter}"` : ''}.</td></tr>}
            {filtered.map(eot => (
              <tr key={eot.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5 font-mono text-xs" style={{ color: 'var(--text-muted)' }}>#{eot.eot_number}</td>
                <td className="px-3 py-2.5">
                  <div className="font-medium" style={{ color: 'var(--text-primary)' }}>{eot.title}</div>
                  {eot.delay_event && (
                    <a href={`/app/projects/${projectId}/delay-eot?tab=delay-events`} className="text-xs mt-0.5 inline-block hover:underline" style={{ color: '#60a5fa' }}>
                      Delay Event #{eot.delay_event.event_number}
                    </a>
                  )}
                </td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{eot.days_claimed ?? '—'}</td>
                <td className="px-3 py-2.5 text-xs font-semibold" style={{ color: eot.days_granted ? '#4ade80' : 'var(--text-muted)' }}>{eot.days_granted ?? '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>
                  {eot.revised_completion_date ? formatDate(eot.revised_completion_date) : (eot.current_completion_date ? <span style={{ color: 'var(--text-muted)' }}>{formatDate(eot.current_completion_date)} (base)</span> : '—')}
                </td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{eot.trade_package?.name ?? eot.contract?.title ?? '—'}</td>
                <td className="px-3 py-2.5"><StatusBadge status={eot.status} /></td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>
                  {eot.decision_user ? <>{eot.decision_user.name}<div style={{ color: 'var(--text-muted)' }}>{eot.decided_at ? formatDate(eot.decided_at) : ''}</div></> : '—'}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-1 justify-end">
                    {canWrite && eot.status !== 'granted' && eot.status !== 'refused' && (
                      <button onClick={() => setModalTarget(eot)} className="p-1.5 rounded hover:bg-white/5 text-xs" style={{ color: 'var(--text-muted)' }}>Edit</button>
                    )}
                    <button onClick={() => generateDecisionMutation.mutate(eot)} title="Generate Decision Notice" className="p-1.5 rounded hover:bg-white/5" style={{ color: 'var(--text-muted)' }}><FileOutput size={14} /></button>
                    {canWrite && ['draft', 'submitted', 'under_assessment'].includes(eot.status) && (
                      <>
                        <button onClick={() => setDecisionTarget({ eot, decision: 'granted' })} title="Grant" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#4ade80' }}><Check size={14} /></button>
                        <button onClick={() => setDecisionTarget({ eot, decision: 'refused' })} title="Refuse" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#f87171' }}><Ban size={14} /></button>
                      </>
                    )}
                    {canWrite && <button onClick={() => setDeleteTarget(eot)} title="Delete" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#f87171' }}><Trash2 size={14} /></button>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {modalTarget && (
        <EotModal
          projectId={projectId} contracts={contracts} tradePackages={tradePackages} delayEvents={delayEvents}
          eot={modalTarget === 'new' ? null : modalTarget}
          onClose={() => setModalTarget(null)}
          invalidateKey={listQueryKey}
        />
      )}

      {decisionTarget && (
        <DecisionModal projectId={projectId} eot={decisionTarget.eot} decision={decisionTarget.decision} onClose={() => setDecisionTarget(null)} invalidateKey={listQueryKey} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>Delete EOT #{deleteTarget.eot_number}? This cannot be undone.</p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setDeleteTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button onClick={() => deleteMutation.mutate(deleteTarget)} className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white" style={{ backgroundColor: '#a11a1a' }}>Confirm</button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
