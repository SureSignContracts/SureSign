'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from '@/lib/toast';
import api from '@/lib/api';
import PlatformPageHero from '@/components/admin/PlatformPageHero';
import { Wallet, Coins, Lock, PiggyBank, Building2, Brain, ShieldCheck, ShieldAlert, HelpCircle, ShieldOff, ShieldQuestion, X } from 'lucide-react';
import CountUp from '@/components/ui/CountUp';
import EmptyState from '@/components/ui/EmptyState';
import { useAuthStore } from '@/store/authStore';

interface ShadowCounts {
  sufficient: number;
  insufficient: number;
  unresolved: number;
  bypassed: number;
}

interface WorkflowSummary {
  total_analyses: number;
  credits_consumed: number;
  average_credits_per_analysis: number | null;
  shadow_sufficient: number;
  shadow_insufficient: number;
  shadow_unresolved: number;
}

interface SummaryResponse {
  issued: number;
  consumed: number;
  reserved: number;
  available: number;
  active_organizations: number;
  total_analyses: number;
  shadow: ShadowCounts;
  by_workflow: {
    contract_analysis: WorkflowSummary;
    trade_package_analysis: WorkflowSummary;
  };
}

const WORKFLOW_LABELS: Record<string, string> = {
  contract_analysis: 'Contract Analysis',
  trade_package_analysis: 'Trade Package Analysis',
};

const REASON_MIN_LENGTH = 10;

type OperatingMode = 'disabled' | 'shadow' | 'enforced';

interface OperatingModeResponse {
  operating_mode: OperatingMode;
  customer_meter_enabled: boolean;
  active_candidate: string | null;
  approved_candidate: string | null;
}

const MODE_META: Record<OperatingMode, {
  label: string;
  description: string;
  icon: React.ElementType;
  color: string;
  border: string;
}> = {
  disabled: {
    label: 'DISABLED',
    description: 'The AI Credit accounting lifecycle does not run at all — no reservation, simulation, settlement, or release is attempted, and no organisation is ever blocked. AI analysis itself is completely unaffected.',
    icon: ShieldOff,
    color: 'var(--text-muted)',
    border: 'var(--border)',
  },
  shadow: {
    label: 'SHADOW MODE',
    description: 'The full accounting lifecycle runs — reservation, settlement, release, ledger balances, candidate-policy simulation, and the customer usage meter all continue — but an insufficient balance never blocks an AI workflow.',
    icon: ShieldQuestion,
    color: '#facc15',
    border: 'rgba(250,204,21,0.3)',
  },
  enforced: {
    label: 'ENFORCED',
    description: 'The same accounting lifecycle runs as Shadow Mode, but an organisation whose available balance is insufficient is blocked from running AI analysis before the provider is ever called.',
    icon: ShieldAlert,
    color: '#ef4444',
    border: 'rgba(239,68,68,0.3)',
  },
};

const MODE_ORDER: OperatingMode[] = ['disabled', 'shadow', 'enforced'];

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string } } }).response;
    if (response?.data?.message) return response.data.message;
  }
  return fallback;
}

function OperatingModeDialog({
  currentMode,
  nextMode,
  onClose,
  onSubmit,
  submitting,
}: {
  currentMode: OperatingMode;
  nextMode: OperatingMode;
  onClose: () => void;
  onSubmit: (payload: { mode: OperatingMode; reason: string; confirmed: true }) => void;
  submitting: boolean;
}) {
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);

  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;
  const canSubmit = reasonValid && confirmed && !submitting;
  const meta = MODE_META[nextMode];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-md rounded-2xl p-6 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-start justify-between mb-5">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              Switch to {meta.label}
            </h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              Platform-wide setting — currently {MODE_META[currentMode].label}
            </p>
          </div>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <div className="space-y-4">
          <div
            className="rounded-xl p-3.5 text-xs space-y-1.5"
            style={{ backgroundColor: 'var(--bg-elevated)', border: `1px solid ${meta.border}`, color: 'var(--text-secondary)' }}
          >
            <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{meta.label}</p>
            <p>{meta.description}</p>
          </div>

          <div className="rounded-xl p-3.5 text-xs space-y-1.5" style={{ backgroundColor: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.2)', color: 'var(--text-secondary)' }}>
            <p className="font-medium" style={{ color: 'var(--text-primary)' }}>Submitting this will:</p>
            <ul className="list-disc pl-4 space-y-0.5">
              <li>Apply immediately, platform-wide, to every organisation</li>
              <li>Write an audit event recording the previous and new mode</li>
            </ul>
          </div>

          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Reason <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={3}
              placeholder="Explain the business basis for this change…"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            {!reasonValid && reason.length > 0 && (
              <p className="text-xs mt-1" style={{ color: '#f87171' }}>Reason must be at least {REASON_MIN_LENGTH} characters.</p>
            )}
          </div>

          <label className="flex items-start gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
            <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
            I confirm I want to switch AI Credit operating mode to {meta.label} platform-wide.
          </label>
        </div>

        <div className="flex gap-3 mt-6">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => onSubmit({ mode: nextMode, reason: reason.trim(), confirmed: true })}
            disabled={!canSubmit}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: meta.color, color: nextMode === 'shadow' ? '#1a1a1a' : '#fff' }}
          >
            {submitting ? 'Submitting…' : `Switch to ${meta.label}`}
          </button>
        </div>
      </div>
    </div>
  );
}

function OperatingModeCard() {
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;
  const queryClient = useQueryClient();
  const [pendingMode, setPendingMode] = useState<OperatingMode | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['ai-credits-operating-mode'],
    queryFn: () => api.get<OperatingModeResponse>('/admin/ai-credits/operating-mode').then(r => r.data),
  });

  const mutation = useMutation<unknown, unknown, { mode: OperatingMode; reason: string; confirmed: true }>({
    mutationFn: (payload) => api.put('/admin/ai-credits/operating-mode', payload).then(r => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['ai-credits-operating-mode'] }),
  });

  const mode = data?.operating_mode ?? 'shadow';
  const meta = MODE_META[mode];
  const Icon = meta.icon;

  const handleSubmit = (payload: { mode: OperatingMode; reason: string; confirmed: true }) => {
    mutation.mutate(payload, {
      onSuccess: () => {
        toast.success(`AI Credit operating mode switched to ${MODE_META[payload.mode].label}.`);
        setPendingMode(null);
      },
      onError: (e: unknown) => {
        toast.error(extractErrorMessage(e, 'Failed to update operating mode.'));
      },
    });
  };

  return (
    <div
      className="rounded-2xl p-4 space-y-3"
      style={{ backgroundColor: 'var(--bg-surface)', border: `1px solid ${meta.border}`, boxShadow: 'var(--shadow-card)' }}
    >
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div className="flex items-center gap-3">
          <Icon size={20} style={{ color: meta.color }} />
          <div>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              AI Credit Operating Mode — {isLoading ? '…' : meta.label}
            </p>
            <p className="text-xs mt-0.5 max-w-xl" style={{ color: 'var(--text-muted)' }}>{meta.description}</p>
          </div>
        </div>
        {!isSuperAdmin && (
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Super Admin only to change</span>
        )}
      </div>

      {isSuperAdmin && (
        <div className="flex items-center gap-2 flex-wrap">
          {MODE_ORDER.map(m => (
            <button
              key={m}
              onClick={() => setPendingMode(m)}
              disabled={isLoading || m === mode}
              className="px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90 disabled:opacity-50"
              style={m === mode
                ? { backgroundColor: MODE_META[m].color, color: m === 'shadow' ? '#1a1a1a' : '#fff' }
                : { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              {MODE_META[m].label}
            </button>
          ))}
        </div>
      )}

      {pendingMode !== null && (
        <OperatingModeDialog
          currentMode={mode}
          nextMode={pendingMode}
          onClose={() => setPendingMode(null)}
          onSubmit={handleSubmit}
          submitting={mutation.isPending}
        />
      )}
    </div>
  );
}

export default function AiCreditsDashboardPage() {
  const { data: summary, isLoading } = useQuery({
    queryKey: ['ai-credits-summary'],
    queryFn: () => api.get<SummaryResponse>('/admin/ai-credits/summary').then(r => r.data),
  });

  const noActivity = !isLoading && summary && summary.total_analyses === 0;

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero
        eyebrow="Credit operations"
        title="AI Credits"
        description="Monitor the internal credit ledger, available capacity and analysis activity across SureSign."
        loading={isLoading}
        metrics={[
          { label: 'Issued', value: summary?.issued ?? 0, detail: 'credits allocated', icon: Coins },
          { label: 'Consumed', value: summary?.consumed ?? 0, detail: 'credits used', icon: PiggyBank },
          { label: 'Reserved', value: summary?.reserved ?? 0, detail: 'currently held', icon: Lock },
          { label: 'Available', value: summary?.available ?? 0, detail: 'ready to use', icon: Wallet },
        ]}
      />

      <OperatingModeCard />

      {noActivity ? (
        <EmptyState
          surface
          icon={Wallet}
          title="No AI Credit activity yet"
          description="Ledger and shadow-accounting figures will appear here once an organisation runs a Contract or Trade Package AI analysis."
        />
      ) : (
        <>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <MetricCard label="Active Organisations" value={summary?.active_organizations} loading={isLoading} icon={Building2} />
            <MetricCard label="Total AI Analyses" value={summary?.total_analyses} loading={isLoading} icon={Brain} />
            <MetricCard
              label="Avg Credits / Analysis"
              value={summary && summary.total_analyses > 0 ? Math.round((summary.consumed / summary.total_analyses) * 100) / 100 : null}
              loading={isLoading}
              icon={Coins}
              decimals={2}
            />
          </div>

          <div>
            <p className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>
              Shadow Enforcement Results
            </p>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
              <ShadowCard label="Would Have Passed" value={summary?.shadow.sufficient} loading={isLoading} icon={ShieldCheck} tone="success" />
              <ShadowCard label="Would Have Blocked" value={summary?.shadow.insufficient} loading={isLoading} icon={ShieldAlert} tone="warning" />
              <ShadowCard label="Unresolved (No Policy)" value={summary?.shadow.unresolved} loading={isLoading} icon={HelpCircle} tone="neutral" />
              <ShadowCard label="Credit Lifecycle Bypassed" value={summary?.shadow.bypassed} loading={isLoading} icon={ShieldOff} tone="neutral" />
            </div>
            <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
              &ldquo;Credit Lifecycle Bypassed&rdquo; covers analyses with no credit evaluation recorded at all — most commonly a run while the operating mode was DISABLED, but also legacy or still-in-progress rows.
            </p>
          </div>

          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
              <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Usage by Workflow</p>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="text-xs font-medium uppercase tracking-wider" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                    <th className="text-left px-4 py-3">Workflow</th>
                    <th className="text-right px-4 py-3">Analyses</th>
                    <th className="text-right px-4 py-3">Credits Consumed</th>
                    <th className="text-right px-4 py-3">Avg / Analysis</th>
                    <th className="text-right px-4 py-3">Sufficient</th>
                    <th className="text-right px-4 py-3">Insufficient</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(summary?.by_workflow ?? {}).map(([key, w]) => (
                    <tr key={key} style={{ borderTop: '1px solid var(--border)' }}>
                      <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{WORKFLOW_LABELS[key] ?? key}</td>
                      <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{w.total_analyses}</td>
                      <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{w.credits_consumed}</td>
                      <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{w.average_credits_per_analysis ?? '—'}</td>
                      <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{w.shadow_sufficient}</td>
                      <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{w.shadow_insufficient}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function MetricCard({
  label, value, loading, icon: Icon, decimals = 0,
}: { label: string; value?: number | null; loading?: boolean; icon: React.ElementType; decimals?: number }) {
  return (
    <div className="rounded-2xl p-4 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 mb-2">
        <Icon size={14} style={{ color: 'var(--gold)' }} />
        <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>
      {loading || value === undefined || value === null ? (
        <div className="h-6 w-16 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      ) : decimals > 0 ? (
        <p className="text-xl font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>{value.toFixed(decimals)}</p>
      ) : (
        <CountUp value={value} className="text-xl font-bold" style={{ color: 'var(--text-primary)' }} />
      )}
    </div>
  );
}

const SHADOW_TONE_COLOR: Record<string, string> = {
  success: '#4ade80',
  warning: '#facc15',
  neutral: 'var(--text-muted)',
};

function ShadowCard({
  label, value, loading, icon: Icon, tone,
}: { label: string; value?: number; loading?: boolean; icon: React.ElementType; tone: 'success' | 'warning' | 'neutral' }) {
  return (
    <div className="rounded-2xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 mb-2">
        <Icon size={14} style={{ color: SHADOW_TONE_COLOR[tone] }} />
        <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>
      {loading || value === undefined ? (
        <div className="h-6 w-12 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      ) : (
        <CountUp value={value} className="text-xl font-bold" style={{ color: 'var(--text-primary)' }} />
      )}
    </div>
  );
}
