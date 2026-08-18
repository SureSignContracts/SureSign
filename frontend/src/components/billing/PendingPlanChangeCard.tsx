'use client';

import { useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ArrowRight, Loader2, X } from 'lucide-react';
import { Card, CardHeader, CardTitle, CardBody } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import { formatDateTime } from '@/lib/dateTime';
import { useCancelPlanChange } from '@/hooks/useBilling';
import type { PlanChangeSummary } from '@/hooks/useBilling';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { planChangeStateLabel, planChangeStateTone, planChangeTypeLabel } from '@/lib/billingStatus';
import toast from '@/lib/toast';

/**
 * Never implies the target plan is already active — the organisation's
 * CURRENT plan remains authoritative until a verified webhook confirms
 * the change (see SubscriptionPlanChangeService). Cancellation is only
 * offered while `state === 'requested'` — once sent to Stripe, the
 * backend itself refuses to cancel locally (updateSubscriptionPrice() is
 * a direct, synchronous provider write; there's nothing left to safely
 * "cancel" at that point). Replacing a pending change is not a separate
 * action here — selecting a different plan in the comparison section
 * below transparently supersedes this one (the backend handles that).
 */
export default function PendingPlanChangeCard({
  planChange,
  timeZone,
}: {
  planChange: PlanChangeSummary;
  timeZone?: string;
}) {
  const cancelChange = useCancelPlanChange();
  const qc = useQueryClient();
  const [confirming, setConfirming] = useState(false);

  const canCancel = planChange.state === 'requested';

  const handleCancel = () => {
    cancelChange.mutate(planChange.id, {
      onSuccess: () => {
        toast.success('Pending plan change cancelled.');
        setConfirming(false);
        qc.invalidateQueries({ queryKey: ['billing'] });
      },
      onError: (err: unknown) => {
        toast.error(getErrorMessage(err, 'This plan change could not be cancelled.'));
        setConfirming(false);
      },
    });
  };

  return (
    <Card className="ss-animate-in transition-shadow duration-300 hover:shadow-[var(--shadow-pop)]">
      <CardHeader>
        <CardTitle>Pending Plan Change</CardTitle>
        <Badge tone={planChangeStateTone(planChange.state)}>{planChangeStateLabel(planChange.state)}</Badge>
      </CardHeader>
      <CardBody className="space-y-3">
        <div className="flex items-center gap-3 text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
          <span>{planChange.source_plan_name ?? planChange.source_plan_code ?? 'Current plan'}</span>
          <ArrowRight size={14} style={{ color: 'var(--text-muted)' }} />
          <span>{planChange.target_plan_name ?? planChange.target_plan_code ?? 'New plan'}</span>
          <Badge tone="neutral">{planChangeTypeLabel(planChange.change_type)}</Badge>
        </div>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {planChange.requested_effective_at
            ? `Takes effect ${formatDateTime(planChange.requested_effective_at, { timeZone })}. `
            : ''}
          Your current plan remains active until this change is confirmed.
          {canCancel && ' Choose a different plan below to replace this request.'}
        </p>
        {planChange.failure_message && (
          <p className="text-xs" style={{ color: '#f87171' }}>{planChange.failure_message}</p>
        )}

        {canCancel && (
          confirming ? (
            <div className="flex items-center gap-2">
              <span className="text-xs" style={{ color: 'var(--text-secondary)' }}>Cancel this pending change?</span>
              <Button variant="danger" size="sm" onClick={handleCancel} disabled={cancelChange.isPending}>
                {cancelChange.isPending ? <Loader2 size={13} className="animate-spin" /> : null}
                Yes, cancel
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setConfirming(false)} disabled={cancelChange.isPending}>
                Never mind
              </Button>
            </div>
          ) : (
            <Button variant="secondary" size="sm" onClick={() => setConfirming(true)}>
              <X size={13} /> Cancel pending change
            </Button>
          )
        )}
      </CardBody>
    </Card>
  );
}
