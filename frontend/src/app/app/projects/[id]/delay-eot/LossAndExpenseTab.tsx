'use client';

import { useMemo, useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { Plus, X, Trash2, Check, Ban, ExternalLink } from 'lucide-react';
import toast from 'react-hot-toast';
import { getErrorMessage, assertDeleteSucceeded, type ContractOption, type TradePackageOption } from './page';
import { INPUT_STYLE } from './DelayEventsTab';

// ─── Types ─────────────────────────────────────────────────────────────────

type LossAndExpenseClaim = {
  id: number;
  claim_number: number;
  title: string;
  description: string | null;
  amount_claimed: number | string | null;
  amount_assessed: number | string | null;
  amount_agreed: number | string | null;
  status: 'draft' | 'submitted' | 'under_assessment' | 'agreed' | 'rejected';
  contract?: ContractOption | null;
  trade_package?: TradePackageOption | null;
  delay_event?: { id: number; event_number: number; title: string } | null;
  eot_request?: { id: number; eot_number: number; title: string } | null;
  final_account_item?: { id: number; final_account_id: number; amount: number | string } | null;
};

type DelayEventOption = { id: number; event_number: number; title: string };
type EotOption = { id: number; eot_number: number; title: string };

const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  draft: { label: 'Draft', bg: 'rgba(90,86,82,0.2)', text: '#9a9490' },
  submitted: { label: 'Submitted', bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  under_assessment: { label: 'Under Assessment', bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  agreed: { label: 'Agreed', bg: 'rgba(34,197,94,0.12)', text: '#4ade80' },
  rejected: { label: 'Rejected', bg: 'rgba(239,68,68,0.12)', text: '#f87171' },
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

function fmt(v: number | string | null | undefined): number {
  return typeof v === 'string' ? parseFloat(v) || 0 : Number(v) || 0;
}

// ─── Create / Edit modal ────────────────────────────────────────────────────

function ClaimModal({ projectId, contracts, tradePackages, delayEvents, eots, claim, onClose, invalidateKey }: {
  projectId: string; contracts: ContractOption[]; tradePackages: TradePackageOption[];
  delayEvents: DelayEventOption[]; eots: EotOption[]; claim: LossAndExpenseClaim | null; onClose: () => void; invalidateKey: unknown[];
}) {
  const qc = useQueryClient();
  const isEdit = !!claim;
  const [form, setForm] = useState({
    title: claim?.title ?? '',
    description: claim?.description ?? '',
    amount_claimed: claim?.amount_claimed?.toString() ?? '',
    amount_assessed: claim?.amount_assessed?.toString() ?? '',
    contract_id: claim?.contract?.id?.toString() ?? '',
    trade_package_id: claim?.trade_package?.id?.toString() ?? '',
    delay_event_id: claim?.delay_event?.id?.toString() ?? '',
    eot_request_id: claim?.eot_request?.id?.toString() ?? '',
  });

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: form.title,
        description: form.description || null,
        amount_claimed: form.amount_claimed ? Number(form.amount_claimed) : null,
        amount_assessed: form.amount_assessed ? Number(form.amount_assessed) : null,
        contract_id: form.contract_id ? Number(form.contract_id) : null,
        delay_event_id: form.delay_event_id ? Number(form.delay_event_id) : null,
        eot_request_id: form.eot_request_id ? Number(form.eot_request_id) : null,
      };
      if (isEdit) return api.put(`/projects/${projectId}/loss-and-expense-claims/${claim!.id}`, payload);
      if (form.trade_package_id) return api.post(`/projects/${projectId}/trade-packages/${form.trade_package_id}/loss-and-expense-claims`, payload);
      return api.post(`/projects/${projectId}/loss-and-expense-claims`, payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Claim updated' : 'Claim raised');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save claim')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-lg rounded-xl p-6 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>{isEdit ? 'Edit L&E Claim' : 'Raise L&E Claim'}</h2>
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
                  <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.contract_id} disabled={!!form.trade_package_id}
                    onChange={e => setForm({ ...form, contract_id: e.target.value, trade_package_id: '' })}>
                    <option value="">—</option>
                    {contracts.map(c => <option key={c.id} value={c.id}>{c.title}</option>)}
                  </select>
                </Field>
                <Field label="Trade Package">
                  <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.trade_package_id} disabled={!!form.contract_id}
                    onChange={e => setForm({ ...form, trade_package_id: e.target.value, contract_id: '' })}>
                    <option value="">—</option>
                    {tradePackages.map(tp => <option key={tp.id} value={tp.id}>{tp.name}</option>)}
                  </select>
                </Field>
              </div>
              <div className="grid grid-cols-2 gap-3">
                <Field label="Related Delay Event">
                  <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.delay_event_id} onChange={e => setForm({ ...form, delay_event_id: e.target.value })}>
                    <option value="">—</option>
                    {delayEvents.map(d => <option key={d.id} value={d.id}>#{d.event_number} — {d.title}</option>)}
                  </select>
                </Field>
                <Field label="Related EOT">
                  <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.eot_request_id} onChange={e => setForm({ ...form, eot_request_id: e.target.value })}>
                    <option value="">—</option>
                    {eots.map(e => <option key={e.id} value={e.id}>#{e.eot_number} — {e.title}</option>)}
                  </select>
                </Field>
              </div>
            </>
          )}

          <div className="grid grid-cols-2 gap-3">
            <Field label="Amount Claimed">
              <input type="number" min={0} step="0.01" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.amount_claimed} onChange={e => setForm({ ...form, amount_claimed: e.target.value })} />
            </Field>
            <Field label="Amount Assessed">
              <input type="number" min={0} step="0.01" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={form.amount_assessed} onChange={e => setForm({ ...form, amount_assessed: e.target.value })} />
            </Field>
          </div>

          <Field label="Particulars">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={3} value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} />
          </Field>
        </div>

        <div className="flex justify-end gap-2 mt-5">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => mutation.mutate()}
            disabled={!form.title || mutation.isPending}
            className="px-4 py-2 rounded-lg text-sm font-semibold disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Raise Claim'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Decision modal ─────────────────────────────────────────────────────────

function DecisionModal({ projectId, claim, decision, onClose, invalidateKey }: {
  projectId: string; claim: LossAndExpenseClaim; decision: 'agreed' | 'rejected'; onClose: () => void; invalidateKey: unknown[];
}) {
  const qc = useQueryClient();
  const [amount, setAmount] = useState((claim.amount_assessed ?? claim.amount_claimed)?.toString() ?? '0');

  const mutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/loss-and-expense-claims/${claim.id}/decide`, {
      status: decision,
      amount_agreed: decision === 'agreed' ? Number(amount) : undefined,
    }),
    onSuccess: () => {
      toast.success(decision === 'agreed' ? 'Claim agreed' : 'Claim rejected');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to record decision')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <h2 className="text-base font-bold mb-3" style={{ color: 'var(--text-primary)' }}>
          {decision === 'agreed' ? 'Agree Claim' : 'Reject Claim'}
        </h2>
        {decision === 'agreed' && (
          <Field label="Amount Agreed" required>
            <input type="number" min={0} step="0.01" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} value={amount} onChange={e => setAmount(e.target.value)} />
          </Field>
        )}
        {decision === 'agreed' && (
          <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
            If a Final Account already exists for this contract/trade package, this will create a line item automatically.
          </p>
        )}
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={onClose} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
            className="px-3 py-1.5 rounded-lg text-sm font-semibold disabled:opacity-50"
            style={{ backgroundColor: decision === 'agreed' ? '#1a7a3a' : '#a11a1a', color: '#fff' }}>
            {mutation.isPending ? 'Saving…' : `Confirm ${decision === 'agreed' ? 'Agreement' : 'Rejection'}`}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Main tab ───────────────────────────────────────────────────────────────

export function LossAndExpenseTab({ projectId, contracts, tradePackages, canWrite, tradePackageId }: {
  projectId: string; contracts: ContractOption[]; tradePackages: TradePackageOption[]; canWrite: boolean; tradePackageId?: number;
}) {
  const qc = useQueryClient();
  const formatCurrency = useCurrencyFormatter();
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [modalTarget, setModalTarget] = useState<LossAndExpenseClaim | 'new' | null>(null);
  const [decisionTarget, setDecisionTarget] = useState<{ claim: LossAndExpenseClaim; decision: 'agreed' | 'rejected' } | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<LossAndExpenseClaim | null>(null);

  const listQueryKey = tradePackageId ? ['trade-package-loss-and-expense', tradePackageId] : ['project-loss-and-expense', projectId];
  const { data, isLoading } = useQuery<{ data?: LossAndExpenseClaim[] }>({
    queryKey: listQueryKey,
    queryFn: () => tradePackageId
      ? api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/loss-and-expense-claims`).then(r => ({ data: r.data }))
      : api.get(`/projects/${projectId}/loss-and-expense-claims`).then(r => r.data),
  });
  // Scoped the same way as the main list — otherwise the "Related Delay Event"/
  // "Related EOT" dropdowns in the create modal would offer records from every
  // trade package/contract in the project, not just this one (Sprint 6C review).
  const { data: delayData } = useQuery<{ data?: DelayEventOption[] }>({
    queryKey: tradePackageId ? ['trade-package-delay-events', tradePackageId] : ['project-delay-events', projectId],
    queryFn: () => tradePackageId
      ? api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/delay-events`).then(r => ({ data: r.data }))
      : api.get(`/projects/${projectId}/delay-events`).then(r => r.data),
  });
  const { data: eotData } = useQuery<{ data?: EotOption[] }>({
    queryKey: tradePackageId ? ['trade-package-eot-requests', tradePackageId] : ['project-eot-requests', projectId],
    queryFn: () => tradePackageId
      ? api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/eot-requests`).then(r => ({ data: r.data }))
      : api.get(`/projects/${projectId}/eot-requests`).then(r => r.data),
  });

  const claims = data?.data ?? [];
  const delayEvents = delayData?.data ?? [];
  const eots = eotData?.data ?? [];
  const filtered = useMemo(
    () => statusFilter === 'all' ? claims : claims.filter(c => c.status === statusFilter),
    [claims, statusFilter]
  );

  const deleteMutation = useMutation({
    mutationFn: (claim: LossAndExpenseClaim) => api.delete(`/projects/${projectId}/loss-and-expense-claims/${claim.id}`).then(res => { assertDeleteSucceeded(res); return res; }),
    onSuccess: () => {
      toast.success('Claim deleted');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setDeleteTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete')),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div className="flex gap-1.5 flex-wrap">
          {['all', 'draft', 'submitted', 'under_assessment', 'agreed', 'rejected'].map(s => (
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
          <button onClick={() => setModalTarget('new')} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            <Plus size={15} /> Raise Claim
          </button>
        )}
      </div>

      <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['#', 'Title', 'Claimed', 'Assessed', 'Agreed', 'Source', 'Status', 'Final Account', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && filtered.length === 0 && <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No claims{statusFilter !== 'all' ? ` with status "${statusFilter}"` : ''}.</td></tr>}
            {filtered.map(claim => (
              <tr key={claim.id} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-3 py-2.5 font-mono text-xs" style={{ color: 'var(--text-muted)' }}>#{claim.claim_number}</td>
                <td className="px-3 py-2.5">
                  <div className="font-medium" style={{ color: 'var(--text-primary)' }}>{claim.title}</div>
                  <div className="flex gap-2 mt-0.5">
                    {claim.delay_event && (
                      <a href={`/app/projects/${projectId}/delay-eot?tab=delay-events`} className="text-xs inline-flex items-center gap-0.5 hover:underline" style={{ color: '#60a5fa' }}>
                        Delay #{claim.delay_event.event_number} <ExternalLink size={9} />
                      </a>
                    )}
                    {claim.eot_request && (
                      <a href={`/app/projects/${projectId}/delay-eot?tab=eot`} className="text-xs inline-flex items-center gap-0.5 hover:underline" style={{ color: '#60a5fa' }}>
                        EOT #{claim.eot_request.eot_number} <ExternalLink size={9} />
                      </a>
                    )}
                  </div>
                </td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{claim.amount_claimed ? formatCurrency(fmt(claim.amount_claimed)) : '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{claim.amount_assessed ? formatCurrency(fmt(claim.amount_assessed)) : '—'}</td>
                <td className="px-3 py-2.5 text-xs font-semibold" style={{ color: claim.amount_agreed ? '#4ade80' : 'var(--text-muted)' }}>{claim.amount_agreed ? formatCurrency(fmt(claim.amount_agreed)) : '—'}</td>
                <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{claim.trade_package?.name ?? claim.contract?.title ?? '—'}</td>
                <td className="px-3 py-2.5"><StatusBadge status={claim.status} /></td>
                <td className="px-3 py-2.5 text-xs">
                  {claim.final_account_item
                    ? <a href={`/app/projects/${projectId}/commercial?tab=final-account`} className="inline-flex items-center gap-1 hover:underline" style={{ color: '#4ade80' }}>Linked <ExternalLink size={10} /></a>
                    : <span style={{ color: 'var(--text-muted)' }}>—</span>}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex items-center gap-1 justify-end">
                    {canWrite && claim.status !== 'agreed' && claim.status !== 'rejected' && (
                      <button onClick={() => setModalTarget(claim)} className="p-1.5 rounded hover:bg-white/5 text-xs" style={{ color: 'var(--text-muted)' }}>Edit</button>
                    )}
                    {canWrite && ['draft', 'submitted', 'under_assessment'].includes(claim.status) && (
                      <>
                        <button onClick={() => setDecisionTarget({ claim, decision: 'agreed' })} title="Agree" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#4ade80' }}><Check size={14} /></button>
                        <button onClick={() => setDecisionTarget({ claim, decision: 'rejected' })} title="Reject" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#f87171' }}><Ban size={14} /></button>
                      </>
                    )}
                    {canWrite && <button onClick={() => setDeleteTarget(claim)} title="Delete" className="p-1.5 rounded hover:bg-white/5" style={{ color: '#f87171' }}><Trash2 size={14} /></button>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {modalTarget && (
        <ClaimModal
          projectId={projectId} contracts={contracts} tradePackages={tradePackages} delayEvents={delayEvents} eots={eots}
          claim={modalTarget === 'new' ? null : modalTarget}
          onClose={() => setModalTarget(null)}
          invalidateKey={listQueryKey}
        />
      )}

      {decisionTarget && (
        <DecisionModal projectId={projectId} claim={decisionTarget.claim} decision={decisionTarget.decision} onClose={() => setDecisionTarget(null)} invalidateKey={listQueryKey} />
      )}

      {deleteTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>Delete Claim #{deleteTarget.claim_number}? This cannot be undone.</p>
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
