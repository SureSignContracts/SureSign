'use client';

import { Loader2, CircleX } from 'lucide-react';
import Button from '@/components/ui/Button';
import Modal from '@/components/ui/Modal';

/**
 * Phase E4 — confirms abandoning an unfinished Checkout attempt. Distinct
 * from CancelSubscriptionConfirmDialog: there is no active period to lose,
 * no refund consideration — this is undoing an attempt that was never
 * paid for, so the copy stays correspondingly light.
 */
export default function CancelPendingCheckoutConfirmDialog({
  planName,
  isPending,
  onConfirm,
  onClose,
}: {
  planName: string;
  isPending: boolean;
  onConfirm: () => void;
  onClose: () => void;
}) {
  return (
    <Modal title="Cancel pending Checkout?" icon={CircleX} tone="neutral" onClose={onClose} busy={isPending}>
      {(close) => (
        <>
          <ul className="text-sm space-y-1.5" style={{ color: 'var(--text-secondary)' }}>
            <li>Your {planName} Checkout was never completed — you have not been charged.</li>
            <li>Cancelling stops this attempt so you can choose a plan again immediately.</li>
            <li>Nothing about your account or access changes.</li>
          </ul>

          <div className="flex items-center justify-end gap-3 pt-2">
            <Button variant="secondary" size="sm" onClick={close} disabled={isPending}>Keep it</Button>
            <Button variant="danger" size="sm" onClick={onConfirm} disabled={isPending}>
              {isPending ? <Loader2 size={14} className="animate-spin" /> : null}
              {isPending ? 'Cancelling…' : 'Cancel pending Checkout'}
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}
