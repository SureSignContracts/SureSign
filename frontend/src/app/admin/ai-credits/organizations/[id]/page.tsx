'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import toast from '@/lib/toast';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { ArrowLeft, Wallet, Coins, Lock, PiggyBank, Plus, Minus, TimerOff, X } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import { formatDate } from '@/lib/utils';
import { useAuthStore } from '@/store/authStore';

const REASON_MIN_LENGTH = 10;

type GrantAction = 'grant' | 'adjust-credit' | 'adjust-debit' | 'expire';

const ACTION_LABEL: Record<GrantAction, string> = {
  grant: 'Grant Credits',
  'adjust-credit': 'Adjust Credit',
  'adjust-debit': 'Adjust Debit',
  expire: 'Expire Credits',
};

const ACTION_EFFECT: Record<GrantAction, string> = {
  grant: 'increase this organisation’s available AI credits',
  'adjust-credit': 'increase this organisation’s available AI credits as a correction',
  'adjust-debit': 'decrease this organisation’s available AI credits as a correction',
  expire: 'decrease this organisation’s available AI credits due to expiry',
};

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string } } }).response;
    if (response?.data?.message) return response.data.message;
  }
  return fallback;
}

function useManageAiCredits(organizationId: string | number, action: GrantAction) {
  const queryClient = useQueryClient();
  return useMutation<unknown, unknown, { amount: number; reason: string; confirmed: true }>({
    mutationFn: (payload) => api.post(`/admin/ai-credits/organizations/${organizationId}/${action}`, payload).then(r => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['ai-credits-organization', String(organizationId)] }),
  });
}

function ManageCreditsDialog({
  organizationName,
  action,
  onClose,
  onSubmit,
  submitting,
}: {
  organizationName: string;
  action: GrantAction;
  onClose: () => void;
  onSubmit: (payload: { amount: number; reason: string; confirmed: true }) => void;
  submitting: boolean;
}) {
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);

  const amountValue = Number(amount);
  const amountValid = amount.trim() !== '' && Number.isFinite(amountValue) && amountValue > 0;
  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;
  const canSubmit = amountValid && reasonValid && confirmed && !submitting;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-md rounded-2xl p-6 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-start justify-between mb-5">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{ACTION_LABEL[action]}</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{organizationName}</p>
          </div>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <div className="space-y-4">
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Amount (credits) <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <input
              type="number"
              min="0.01"
              step="0.01"
              value={amount}
              onChange={e => setAmount(e.target.value)}
              placeholder="e.g. 50"
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Reason <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={3}
              placeholder="Explain the business basis for this action…"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            {!reasonValid && reason.length > 0 && (
              <p className="text-xs mt-1" style={{ color: '#f87171' }}>Reason must be at least {REASON_MIN_LENGTH} characters and explain the business basis.</p>
            )}
          </div>

          <div className="rounded-xl p-3.5 text-xs space-y-1.5" style={{ backgroundColor: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.2)', color: 'var(--text-secondary)' }}>
            <p className="font-medium" style={{ color: 'var(--text-primary)' }}>Submitting this will:</p>
            <ul className="list-disc pl-4 space-y-0.5">
              <li>Create a permanent, immutable ledger entry for {organizationName}</li>
              <li>{amountValid ? `${amountValue} credits will ${ACTION_EFFECT[action]}` : `${ACTION_EFFECT[action]}`.replace(/^./, c => c.toUpperCase())}</li>
              <li>Write an audit event</li>
            </ul>
          </div>

          <label className="flex items-start gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
            <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
            I confirm this {ACTION_LABEL[action].toLowerCase()} action for {organizationName}.
          </label>
        </div>

        <div className="flex gap-3 mt-6">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => onSubmit({ amount: amountValue, reason: reason.trim(), confirmed: true })}
            disabled={!canSubmit}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {submitting ? 'Submitting…' : ACTION_LABEL[action]}
          </button>
        </div>
      </div>
    </div>
  );
}

interface Balance {
  issued: number; consumed: number; reserved: number; available: number;
}

interface WorkflowUsage {
  total_analyses: number;
  credits_consumed: number;
  average_credits_per_analysis: number | null;
  shadow_sufficient: number;
  shadow_insufficient: number;
  shadow_unresolved: number;
}

interface Transaction {
  id: number; created_at: string; workflow: string | null; transaction_type: string;
  amount: number; reason: string; actor_type: string; reference_type: string | null; reference_id: number | null;
}

interface RecentAnalysis {
  id: number; workflow: string; status: string; shadow_enforcement_result: string | null;
  credit_reservation_amount: number | null; created_at: string;
}

interface OrganizationDetailResponse {
  organization: { id: number; name: string };
  balance: Balance;
  workflow_usage: { contract_analysis: WorkflowUsage; trade_package_analysis: WorkflowUsage };
  recent_transactions: Transaction[];
  recent_analyses: RecentAnalysis[];
}

const WORKFLOW_LABELS: Record<string, string> = {
  contract_analysis: 'Contract Analysis',
  trade_package_analysis: 'Trade Package Analysis',
};

function shadowTone(status: string | null): 'success' | 'warning' | 'neutral' {
  if (status === 'sufficient') return 'success';
  if (status === 'insufficient') return 'warning';
  return 'neutral';
}

export default function AiCreditsOrganizationDetailPage() {
  const { id } = useParams<{ id: string }>();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;
  const [activeAction, setActiveAction] = useState<GrantAction | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['ai-credits-organization', id],
    queryFn: () => api.get<OrganizationDetailResponse>(`/admin/ai-credits/organizations/${id}`).then(r => r.data),
    enabled: !!id,
  });

  const grant = useManageAiCredits(id ?? '', 'grant');
  const adjustCredit = useManageAiCredits(id ?? '', 'adjust-credit');
  const adjustDebit = useManageAiCredits(id ?? '', 'adjust-debit');
  const expire = useManageAiCredits(id ?? '', 'expire');

  const mutationForAction: Record<GrantAction, ReturnType<typeof useManageAiCredits>> = {
    grant, 'adjust-credit': adjustCredit, 'adjust-debit': adjustDebit, expire,
  };

  const handleSubmit = (payload: { amount: number; reason: string; confirmed: true }) => {
    if (!activeAction) return;
    mutationForAction[activeAction].mutate(payload, {
      onSuccess: () => {
        toast.success(`${ACTION_LABEL[activeAction]} completed.`);
        setActiveAction(null);
      },
      onError: (e: unknown) => {
        toast.error(extractErrorMessage(e, `Failed to complete ${ACTION_LABEL[activeAction].toLowerCase()}.`));
      },
    });
  };

  if (isLoading) {
    return (
      <div className="p-6 max-w-6xl mx-auto space-y-4">
        <div className="h-6 w-40 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        <div className="h-32 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
      </div>
    );
  }

  if (!data) {
    return (
      <div className="p-8 text-center py-24">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Organisation not found.</p>
      </div>
    );
  }

  const { organization, balance, workflow_usage, recent_transactions, recent_analyses } = data;

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <Link
        href="/admin/ai-credits/organizations"
        className="inline-flex items-center gap-1.5 text-xs transition-colors hover:text-[var(--text-primary)]"
        style={{ color: 'var(--text-muted)' }}
      >
        <ArrowLeft size={13} /> Organisations
      </Link>

      <div className="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h1 className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>{organization.name}</h1>
          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>AI Credits</p>
        </div>

        {isSuperAdmin && (
          <div className="flex items-center gap-2">
            <button
              onClick={() => setActiveAction('grant')}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Plus size={12} /> Grant
            </button>
            <button
              onClick={() => setActiveAction('adjust-credit')}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              <Plus size={12} /> Adjust Credit
            </button>
            <button
              onClick={() => setActiveAction('adjust-debit')}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              <Minus size={12} /> Adjust Debit
            </button>
            <button
              onClick={() => setActiveAction('expire')}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171' }}
            >
              <TimerOff size={12} /> Expire
            </button>
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <BalanceCard label="Available" value={balance.available} icon={Wallet} />
        <BalanceCard label="Reserved" value={balance.reserved} icon={Lock} />
        <BalanceCard label="Consumed" value={balance.consumed} icon={PiggyBank} />
        <BalanceCard label="Total Granted" value={balance.issued} icon={Coins} />
      </div>

      {/* AI Workflow Usage — derived from the ledger's settle transactions and the
          analysis tables' own counts, no independent calculation. */}
      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>AI Workflow Usage</p>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs font-medium uppercase tracking-wider" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                <th className="text-left px-4 py-3">Workflow</th>
                <th className="text-right px-4 py-3">Analyses</th>
                <th className="text-right px-4 py-3">Credits Consumed</th>
                <th className="text-right px-4 py-3">Avg / Analysis</th>
                <th className="text-right px-4 py-3">Shadow Sufficient</th>
                <th className="text-right px-4 py-3">Shadow Insufficient</th>
              </tr>
            </thead>
            <tbody>
              {(['contract_analysis', 'trade_package_analysis'] as const).map(key => {
                const w = workflow_usage[key];
                return (
                  <tr key={key} style={{ borderTop: '1px solid var(--border)' }}>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{WORKFLOW_LABELS[key]}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{w.total_analyses}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{w.credits_consumed}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{w.average_credits_per_analysis ?? '—'}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{w.shadow_sufficient}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{w.shadow_insufficient}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent Ledger Transactions</p>
          </div>
          {recent_transactions.length === 0 ? (
            <EmptyState icon={Coins} title="No transactions yet" />
          ) : (
            <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {recent_transactions.map(t => (
                <li key={t.id} className="px-4 py-3 flex items-center justify-between text-sm">
                  <div>
                    <p style={{ color: 'var(--text-primary)' }}>
                      <Badge status={t.transaction_type} /> <span className="ml-2">{t.amount} credits</span>
                    </p>
                    <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{t.reason}</p>
                  </div>
                  <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(t.created_at)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent AI Activity</p>
          </div>
          {recent_analyses.length === 0 ? (
            <EmptyState icon={Wallet} title="No AI analyses yet" />
          ) : (
            <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
              {recent_analyses.map(a => (
                <li key={`${a.workflow}-${a.id}`} className="px-4 py-3 flex items-center justify-between text-sm">
                  <div>
                    <p style={{ color: 'var(--text-primary)' }}>{WORKFLOW_LABELS[a.workflow] ?? a.workflow}</p>
                    <p className="text-xs mt-0.5 flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
                      <Badge status={a.status} />
                      {a.shadow_enforcement_result && <Badge tone={shadowTone(a.shadow_enforcement_result)}>{a.shadow_enforcement_result}</Badge>}
                    </p>
                  </div>
                  <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(a.created_at)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>

      {activeAction && (
        <ManageCreditsDialog
          organizationName={organization.name}
          action={activeAction}
          onClose={() => setActiveAction(null)}
          onSubmit={handleSubmit}
          submitting={mutationForAction[activeAction].isPending}
        />
      )}
    </div>
  );
}

function BalanceCard({ label, value, icon: Icon }: { label: string; value: number; icon: React.ElementType }) {
  return (
    <div className="rounded-2xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 mb-2">
        <Icon size={14} style={{ color: 'var(--gold)' }} />
        <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>
      <p className="text-xl font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>{value}</p>
    </div>
  );
}
