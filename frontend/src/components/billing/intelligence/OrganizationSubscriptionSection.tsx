'use client';

import { useState } from 'react';
import toast from 'react-hot-toast';
import { CreditCard, ShieldAlert, History, Gauge, Plus, X, OctagonMinus } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge, Tone } from '@/components/ui/Badge';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import {
  useOrganizationSubscriptionAdmin,
  useAssignManualSubscription,
  useAssignComplimentarySubscription,
  useTerminateOrganizationSubscription,
} from '@/hooks/useBilling';
import UsageMeter from './UsageMeter';
import StorageMeterCard from './StorageMeterCard';
import AiUsageMeterCard from './AiUsageMeterCard';
import TrialCardComponent from './TrialCard';
import HealthOverview from './HealthOverview';
import StripeInfoCard from './StripeInfoCard';
import Select from '@/components/ui/Select';
import { AssignablePlan, SnapshotSummary, SubscriptionSummaryView } from '@/types/subscriptionIntelligence';

const REASON_MIN_LENGTH = 10;

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string } } }).response;
    if (response?.data?.message) return response.data.message;
  }
  return fallback;
}

const ACCESS_MODE_TONE: Record<string, Tone> = {
  none: 'neutral',
  trial: 'accent',
  full: 'success',
  grace: 'warning',
  restricted: 'danger',
};

const ACCESS_MODE_LABEL: Record<string, string> = {
  none: 'No access',
  trial: 'Trial',
  full: 'Full access',
  grace: 'Grace period',
  restricted: 'Restricted',
};

// G4B.1 — commercial origin, operator-facing labels only (never shown on
// a Client-facing surface). A null source is a not-yet-backfilled legacy
// row in this environment — shown as "Unknown", never guessed as Manual
// or Complimentary.
const SOURCE_TONE: Record<string, Tone> = {
  stripe: 'info',
  manual: 'neutral',
  complimentary: 'accent',
};
const SOURCE_LABEL: Record<string, string> = {
  stripe: 'Stripe',
  manual: 'Manual',
  complimentary: 'Complimentary',
};

function Skeleton() {
  return (
    <div className="space-y-4" aria-hidden>
      <div className="h-32 rounded-2xl animate-pulse motion-reduce:animate-none" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        {[...Array(3)].map((_, i) => (
          <div key={i} className="h-32 rounded-2xl animate-pulse motion-reduce:animate-none" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ))}
      </div>
    </div>
  );
}

function SnapshotCard({ snapshot }: { snapshot: SnapshotSummary | null }) {
  if (snapshot === null) {
    return (
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <ShieldAlert size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
            <CardTitle>Entitlement snapshot</CardTitle>
          </div>
        </CardHeader>
        <CardBody>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No subscription exists yet — nothing to snapshot.</p>
        </CardBody>
      </Card>
    );
  }

  const tone: Tone = snapshot.requires_attention ? 'danger' : snapshot.exists ? 'success' : 'neutral';
  const label = snapshot.requires_attention
    ? 'Missing — needs attention'
    : snapshot.exists
      ? 'Present'
      : 'Legacy fallback';

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <ShieldAlert size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Entitlement snapshot</CardTitle>
        </div>
        <Badge tone={tone}>{label}</Badge>
      </CardHeader>
      <CardBody className="space-y-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
        {snapshot.exists ? (
          <>
            <p>Source: {snapshot.source_transition ?? '—'}</p>
            <p>Reason: {snapshot.lifecycle_reason ?? '—'}</p>
            <p>Effective from: {snapshot.effective_from ? formatDateTime(snapshot.effective_from) : '—'}</p>
            <p>Plan at snapshot: {snapshot.plan_code_snapshot ?? '—'}</p>
          </>
        ) : snapshot.is_legacy_fallback ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            No snapshot exists — this subscription predates entitlement snapshots (or is in a status FeatureGate never
            consults a snapshot for), so live plan defaults are resolved instead. This is expected, not an error.
          </p>
        ) : (
          <p className="text-xs" style={{ color: '#f87171' }}>
            No snapshot exists and this subscription is not a documented legacy case ({snapshot.integrity_classification}) —
            entitlements are failing safe to &ldquo;not entitled&rdquo; until this is investigated.
          </p>
        )}
      </CardBody>
    </Card>
  );
}

function ActivityCard({ activity }: { activity: { action: string; description: string; occurred_at: string }[] }) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <History size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Recent subscription activity</CardTitle>
        </div>
      </CardHeader>
      <CardBody>
        {activity.length === 0 ? (
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No subscription activity recorded yet.</p>
        ) : (
          <ul className="space-y-3">
            {activity.map((entry, i) => (
              <li key={i} className="flex items-start justify-between gap-3 text-sm">
                <div>
                  <p style={{ color: 'var(--text-primary)' }}>{entry.description}</p>
                  <p className="text-xs mt-0.5 font-mono" style={{ color: 'var(--text-muted)' }}>{entry.action}</p>
                </div>
                <span className="text-xs tabular-nums flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
                  {formatDateTime(entry.occurred_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  );
}

/**
 * G4B.2 — Assign Subscription dialog. Only rendered when
 * `can_assign_subscription` is true (no conflicting subscription exists).
 * Source is chosen via which mutation the caller passes in — never a
 * dropdown value sent to a single generic endpoint.
 */
function AssignSubscriptionDialog({
  organizationName,
  plans,
  source,
  onClose,
  onSubmit,
  submitting,
}: {
  organizationName: string;
  plans: AssignablePlan[];
  source: 'manual' | 'complimentary';
  onClose: () => void;
  onSubmit: (payload: { plan_code: string; billing_interval: 'monthly' | 'annual'; reason: string; confirmed: true; ends_at?: string }) => void;
  submitting: boolean;
}) {
  const [planCode, setPlanCode] = useState(plans[0]?.code ?? '');
  const [interval, setInterval] = useState<'monthly' | 'annual'>('monthly');
  const [reason, setReason] = useState('');
  const [endsAt, setEndsAt] = useState('');
  const [confirmed, setConfirmed] = useState(false);

  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;
  const canSubmit = planCode !== '' && reasonValid && confirmed && !submitting;
  const sourceLabel = source === 'manual' ? 'Manual' : 'Complimentary';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-lg rounded-2xl p-6 ss-animate-in max-h-[90vh] overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-start justify-between mb-5">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Assign {sourceLabel} Subscription</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{organizationName}</p>
          </div>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <div className="space-y-4">
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Plan</label>
            <Select
              value={planCode}
              onChange={e => setPlanCode(e.target.value)}
              className="w-full"
            >
              {plans.length === 0 && <option value="">No assignable plans</option>}
              {plans.map(p => <option key={p.code} value={p.code}>{p.name}</option>)}
            </Select>
          </div>

          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Billing interval</label>
            <div className="flex gap-2">
              {(['monthly', 'annual'] as const).map(i => (
                <button
                  key={i}
                  onClick={() => setInterval(i)}
                  className="flex-1 py-2 rounded-lg text-sm font-medium capitalize transition-all"
                  style={interval === i
                    ? { backgroundColor: 'var(--gold-15)', border: '1px solid var(--gold-50)', color: 'var(--text-primary)' }
                    : { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                >
                  {i}
                </button>
              ))}
            </div>
          </div>

          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Reason <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={3}
              placeholder="Explain the business basis for this assignment…"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            {!reasonValid && reason.length > 0 && (
              <p className="text-xs mt-1" style={{ color: '#f87171' }}>Reason must be at least {REASON_MIN_LENGTH} characters and explain the business basis.</p>
            )}
          </div>

          {source === 'complimentary' && (
            <div>
              <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                End date <span style={{ color: 'var(--text-muted)' }}>(recommended, optional)</span>
              </label>
              <input
                type="date"
                value={endsAt}
                onChange={e => setEndsAt(e.target.value)}
                className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
            </div>
          )}

          <div className="rounded-xl p-3.5 text-xs space-y-1.5" style={{ backgroundColor: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.2)', color: 'var(--text-secondary)' }}>
            <p className="font-medium" style={{ color: 'var(--text-primary)' }}>Submitting this will:</p>
            <ul className="list-disc pl-4 space-y-0.5">
              <li>Create a real subscription record for {organizationName}</li>
              <li>Activate {planCode || 'the selected plan'} access immediately</li>
              <li>Create an entitlement snapshot</li>
              <li>Write an audit event</li>
              <li>Never charge the organisation through Stripe{source === 'complimentary' ? ' — no payment will be collected' : ''}</li>
            </ul>
          </div>

          <label className="flex items-start gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
            <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
            I confirm this {sourceLabel.toLowerCase()} subscription assignment for {organizationName}.
          </label>
        </div>

        <div className="flex gap-3 mt-6">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => onSubmit({ plan_code: planCode, billing_interval: interval, reason: reason.trim(), confirmed: true, ...(endsAt ? { ends_at: `${endsAt}T00:00:00Z` } : {}) })}
            disabled={!canSubmit}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {submitting ? 'Assigning…' : `Assign ${sourceLabel} Subscription`}
          </button>
        </div>
      </div>
    </div>
  );
}

/** G4B.2 — terminate a manual/complimentary subscription; never offered for a Stripe-sourced row. */
function TerminateSubscriptionDialog({
  organizationName,
  planName,
  onClose,
  onSubmit,
  submitting,
}: {
  organizationName: string;
  planName: string;
  onClose: () => void;
  onSubmit: (payload: { reason: string; confirmed: true }) => void;
  submitting: boolean;
}) {
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-md rounded-2xl p-6 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-start justify-between mb-5">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>End Subscription</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{organizationName} · {planName}</p>
          </div>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <div className="rounded-xl p-3.5 text-xs space-y-1.5 mb-4" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.2)', color: 'var(--text-secondary)' }}>
          <p className="font-medium" style={{ color: '#f87171' }}>This will restrict organisation access.</p>
          <p>Ending this subscription moves the organisation to a restricted access mode immediately. The subscription record, its entitlement snapshots, and its audit history are all preserved — nothing is deleted or reversed.</p>
          <p>To correct the plan or source instead of simply ending access, end this subscription and then assign a replacement — there is no in-place edit.</p>
        </div>

        <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
          Reason <span style={{ color: '#ef4444' }}>*</span>
        </label>
        <textarea
          value={reason}
          onChange={e => setReason(e.target.value)}
          rows={3}
          placeholder="Explain why this subscription is being ended…"
          className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none mb-3"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />

        <label className="flex items-start gap-2 text-xs mb-5" style={{ color: 'var(--text-secondary)' }}>
          <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
          I confirm ending this subscription and understand it will restrict {organizationName}&rsquo;s access.
        </label>

        <div className="flex gap-3">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => onSubmit({ reason: reason.trim(), confirmed: true })}
            disabled={!reasonValid || !confirmed || submitting}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: '#ef4444', color: '#fff' }}
          >
            {submitting ? 'Ending…' : 'End Subscription'}
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * G4A — Super Admin/Admin Organisation Subscription Administration.
 * Read-only: reuses every Subscription Intelligence presentational
 * component (UsageMeter/StorageMeterCard/AiUsageMeterCard/TrialCard/
 * HealthOverview/StripeInfoCard) rather than reimplementing them, and adds
 * only the operator-only diagnostic cards (snapshot integrity, recent
 * activity) this phase's approved scope calls for.
 */
export default function OrganizationSubscriptionSection({ organizationId }: { organizationId: string | number }) {
  const { data, isLoading, isError } = useOrganizationSubscriptionAdmin(organizationId);
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;

  const [assignDialogSource, setAssignDialogSource] = useState<'manual' | 'complimentary' | null>(null);
  const [terminateDialogOpen, setTerminateDialogOpen] = useState(false);

  const assignManual = useAssignManualSubscription(organizationId);
  const assignComplimentary = useAssignComplimentarySubscription(organizationId);
  const terminate = useTerminateOrganizationSubscription(organizationId);

  if (isLoading) return <Skeleton />;

  if (isError || !data) {
    return (
      <Card>
        <CardBody>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Could not load subscription information for this organisation.</p>
        </CardBody>
      </Card>
    );
  }

  const info = data.data;
  const subscription = info.subscription as unknown as SubscriptionSummaryView | null;
  const access = subscription?.access;
  const otherUsage = info.usage.filter(m => m.feature_key !== 'storage_gb' && m.feature_key !== 'ai_analyses_per_month');
  const organizationName = info.organization.name;

  const handleAssign = (source: 'manual' | 'complimentary', payload: { plan_code: string; billing_interval: 'monthly' | 'annual'; reason: string; confirmed: true; ends_at?: string }) => {
    const mutation = source === 'manual' ? assignManual : assignComplimentary;
    mutation.mutate(payload, {
      onSuccess: () => {
        toast.success(`${source === 'manual' ? 'Manual' : 'Complimentary'} subscription assigned.`);
        setAssignDialogSource(null);
      },
      onError: (e: unknown) => {
        toast.error(extractErrorMessage(e, 'Failed to assign subscription.'));
      },
    });
  };

  const handleTerminate = (payload: { reason: string; confirmed: true }) => {
    if (!subscription) return;
    terminate.mutate({ subscriptionId: subscription.id, ...payload }, {
      onSuccess: () => {
        toast.success('Subscription ended.');
        setTerminateDialogOpen(false);
      },
      onError: (e: unknown) => {
        toast.error(extractErrorMessage(e, 'Failed to end subscription.'));
      },
    });
  };

  return (
    <section aria-labelledby="org-subscription-heading" className="space-y-4">
      <div className="flex items-center justify-between gap-2 flex-wrap">
        <div className="flex items-center gap-2">
          <Gauge size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <h2 id="org-subscription-heading" className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            Subscription
          </h2>
        </div>

        {/* G4B.2 — Super Admin only; Admin/Client never see these controls, matching the backend's role:Super Admin-only route group. */}
        {isSuperAdmin && (
          <div className="flex items-center gap-2">
            {info.can_assign_subscription && (
              <>
                <button
                  onClick={() => setAssignDialogSource('manual')}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                >
                  <Plus size={12} /> Assign Manual
                </button>
                <button
                  onClick={() => setAssignDialogSource('complimentary')}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  <Plus size={12} /> Assign Complimentary
                </button>
              </>
            )}
            {info.can_terminate_subscription && (
              <button
                onClick={() => setTerminateDialogOpen(true)}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
                style={{ backgroundColor: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171' }}
              >
                <OctagonMinus size={12} /> End Subscription
              </button>
            )}
          </div>
        )}
      </div>

      {isSuperAdmin && !info.can_assign_subscription && !info.can_terminate_subscription && subscription && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          This organisation&rsquo;s current {info.subscription_source ?? ''} subscription is Stripe-connected or otherwise not eligible for manual
          assignment or termination here.
        </p>
      )}

      {/* Subscription summary */}
      <Card>
        <CardHeader>
          <div className="flex items-center gap-2">
            <CreditCard size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
            <CardTitle>Current subscription</CardTitle>
          </div>
          <div className="flex items-center gap-2">
            {info.subscription_source && (
              <Badge tone={SOURCE_TONE[info.subscription_source] ?? 'neutral'}>
                {SOURCE_LABEL[info.subscription_source] ?? info.subscription_source}
              </Badge>
            )}
            {access && (
              <Badge tone={ACCESS_MODE_TONE[access.mode] ?? 'neutral'}>
                {ACCESS_MODE_LABEL[access.mode] ?? access.mode}
              </Badge>
            )}
          </div>
        </CardHeader>
        <CardBody>
          {subscription === null ? (
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
              This organisation has no subscription yet — no plan, trial, or paid access exists.
            </p>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-x-6 gap-y-4">
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Plan</p>
                <p className="text-sm font-medium mt-0.5" style={{ color: 'var(--text-primary)' }}>
                  {subscription.plan_name ?? subscription.plan_name_snapshot ?? '—'}
                </p>
              </div>
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Status</p>
                <p className="text-sm font-medium mt-0.5 capitalize" style={{ color: 'var(--text-primary)' }}>
                  {String(subscription.status ?? '—').replace(/_/g, ' ')}
                </p>
              </div>
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Billing interval</p>
                <p className="text-sm font-medium mt-0.5 capitalize" style={{ color: 'var(--text-primary)' }}>
                  {subscription.billing_interval ?? '—'}
                </p>
              </div>
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Current period ends</p>
                <p className="text-sm font-medium mt-0.5" style={{ color: 'var(--text-primary)' }}>
                  {subscription.current_period_ends_at ? formatDateTime(subscription.current_period_ends_at) : '—'}
                </p>
              </div>
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Trial ends</p>
                <p className="text-sm font-medium mt-0.5" style={{ color: 'var(--text-primary)' }}>
                  {subscription.trial_ends_at ? formatDateTime(subscription.trial_ends_at) : '—'}
                </p>
              </div>
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Cancellation</p>
                <p className="text-sm font-medium mt-0.5" style={{ color: 'var(--text-primary)' }}>
                  {subscription.cancel_at_period_end
                    ? 'Scheduled at period end'
                    : subscription.cancelled_at
                      ? `Cancelled ${formatDateTime(subscription.cancelled_at)}`
                      : 'Not scheduled'}
                </p>
              </div>
            </div>
          )}
          {access && (
            <p className="text-xs mt-4 pt-4" style={{ color: 'var(--text-muted)', borderTop: '1px solid var(--border)' }}>
              {access.reason}
            </p>
          )}
        </CardBody>
      </Card>

      {info.trial && <TrialCardComponent trial={info.trial} />}

      {(info.storage || info.ai || otherUsage.length > 0) && (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {info.storage && <StorageMeterCard storage={info.storage} />}
          {info.ai && <AiUsageMeterCard ai={info.ai} />}
          {otherUsage.map(metric => <UsageMeter key={metric.feature_key} metric={metric} />)}
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <HealthOverview health={info.health} />
        <StripeInfoCard stripe={info.stripe} />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <SnapshotCard snapshot={info.snapshot} />
        <ActivityCard activity={info.recent_activity} />
      </div>

      {assignDialogSource && (
        <AssignSubscriptionDialog
          organizationName={organizationName}
          plans={info.assignable_plans}
          source={assignDialogSource}
          onClose={() => setAssignDialogSource(null)}
          onSubmit={payload => handleAssign(assignDialogSource, payload)}
          submitting={assignManual.isPending || assignComplimentary.isPending}
        />
      )}

      {terminateDialogOpen && subscription && (
        <TerminateSubscriptionDialog
          organizationName={organizationName}
          planName={subscription.plan_name ?? subscription.plan_name_snapshot ?? 'Current plan'}
          onClose={() => setTerminateDialogOpen(false)}
          onSubmit={handleTerminate}
          submitting={terminate.isPending}
        />
      )}
    </section>
  );
}
