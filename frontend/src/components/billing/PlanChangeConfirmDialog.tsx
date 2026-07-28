'use client';

import { Loader2, ArrowUpCircle, ArrowDownCircle } from 'lucide-react';
import Button from '@/components/ui/Button';
import Modal from '@/components/ui/Modal';

/**
 * Explains the exact commercial consequence before submitting — never
 * claims an exact proration amount (no preview exists in this checkpoint,
 * see Stage 11's own "optional, do not broaden the slice" note).
 */
export default function PlanChangeConfirmDialog({
  planName,
  changeType,
  isPending,
  onConfirm,
  onClose,
}: {
  planName: string;
  changeType: 'upgrade' | 'downgrade';
  isPending: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  return (
    <Modal
      title={changeType === 'upgrade' ? `Upgrade to ${planName}?` : `Downgrade to ${planName}?`}
      icon={changeType === 'upgrade' ? ArrowUpCircle : ArrowDownCircle}
      tone="info"
      onClose={onClose}
      busy={isPending}
    >
      {(close) => (
        <>
          {changeType === 'upgrade' ? (
            <ul className="text-sm space-y-1.5" style={{ color: 'var(--text-secondary)' }}>
              <li>Your plan changes as soon as Stripe confirms the update, usually within seconds.</li>
              <li>Stripe may create a prorated charge for the remainder of your current billing period.</li>
              <li>Your billing date does not change.</li>
              <li>Your current plan stays active until the change is confirmed.</li>
            </ul>
          ) : (
            <ul className="text-sm space-y-1.5" style={{ color: 'var(--text-secondary)' }}>
              <li>Your current plan remains active until your next renewal date.</li>
              <li>The downgrade takes effect on that date, not immediately.</li>
              <li>You won&apos;t lose access early.</li>
              <li>You can cancel this scheduled downgrade any time before it takes effect.</li>
            </ul>
          )}

          <div className="flex items-center justify-end gap-3 pt-2">
            <Button variant="secondary" size="sm" onClick={close} disabled={isPending}>Cancel</Button>
            <Button variant="primary" size="sm" onClick={onConfirm} disabled={isPending}>
              {isPending ? <Loader2 size={14} className="animate-spin" /> : null}
              {isPending ? 'Submitting…' : changeType === 'upgrade' ? 'Confirm upgrade' : 'Confirm downgrade'}
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}
