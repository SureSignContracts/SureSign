'use client';

import { Loader2, AlertTriangle } from 'lucide-react';
import Button from '@/components/ui/Button';
import Modal from '@/components/ui/Modal';
import { formatDateTime } from '@/lib/dateTime';

/**
 * Deliberately not visually equivalent to a primary action (danger
 * variant, not gold) — and deliberately not a dark pattern either: states
 * plainly what happens and when, no guilt copy, no hidden fees language,
 * no forced multi-step "are you really sure" beyond one clear
 * confirmation.
 */
export default function CancelSubscriptionConfirmDialog({
  periodEndsAt,
  timeZone,
  isPending,
  onConfirm,
  onClose,
}: {
  periodEndsAt: string | null;
  timeZone?: string;
  isPending: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  return (
    <Modal title="Cancel your subscription?" icon={AlertTriangle} tone="warning" onClose={onClose} busy={isPending}>
      {(close) => (
        <>
          <ul className="text-sm space-y-1.5" style={{ color: 'var(--text-secondary)' }}>
            <li>Your subscription stays fully active until your current billing period ends.</li>
            {periodEndsAt && (
              <li>
                Access ends on <strong style={{ color: 'var(--text-primary)' }}>{formatDateTime(periodEndsAt, { timeZone })}</strong>.
              </li>
            )}
            <li>No refund is issued for the remainder of the current period.</li>
            <li>You can undo this any time before it takes effect.</li>
          </ul>

          <div className="flex items-center justify-end gap-3 pt-2">
            <Button variant="secondary" size="sm" onClick={close} disabled={isPending}>Keep subscription</Button>
            <Button variant="danger" size="sm" onClick={onConfirm} disabled={isPending}>
              {isPending ? <Loader2 size={14} className="animate-spin" /> : null}
              {isPending ? 'Submitting…' : 'Confirm cancellation'}
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}
