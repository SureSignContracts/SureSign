'use client';

import { Loader2, Clock } from 'lucide-react';
import Button from '@/components/ui/Button';
import Modal from '@/components/ui/Modal';

/**
 * Phase E4, Stage 8 (Option A) — shown when the customer selects a
 * DIFFERENT plan while a still-valid (resumable) pending Checkout exists.
 * Deliberately never silently discards a still-open Stripe Checkout
 * attempt the customer might still complete (e.g. in another tab) —
 * requires an explicit choice. An already-expired pending Checkout never
 * reaches this dialog: the backend transparently cleans it up and starting
 * a new one just works (see Stage 5).
 */
export default function PendingCheckoutConflictDialog({
  pendingPlanName,
  targetPlanName,
  isContinuing,
  isCancelling,
  onContinue,
  onCancelPending,
  onClose,
}: {
  pendingPlanName: string;
  targetPlanName: string;
  isContinuing: boolean;
  isCancelling: boolean;
  onContinue: () => void;
  onCancelPending: () => void;
  onClose: () => void;
}) {
  const isBusy = isContinuing || isCancelling;

  return (
    <Modal title="You already have a pending Checkout" icon={Clock} tone="info" onClose={onClose} busy={isBusy}>
      {() => (
        <>
          <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
            You started checking out for <strong style={{ color: 'var(--text-primary)' }}>{pendingPlanName}</strong> and
            it&apos;s still waiting for payment. Continue that instead, or cancel it first if you&apos;d rather subscribe to{' '}
            <strong style={{ color: 'var(--text-primary)' }}>{targetPlanName}</strong>.
          </p>

          <div className="flex items-center justify-end gap-3 pt-2 flex-wrap">
            <Button variant="secondary" size="sm" onClick={onCancelPending} disabled={isBusy}>
              {isCancelling ? <Loader2 size={14} className="animate-spin" /> : null}
              {isCancelling ? 'Cancelling…' : 'Cancel pending Checkout'}
            </Button>
            <Button variant="primary" size="sm" onClick={onContinue} disabled={isBusy}>
              {isContinuing ? <Loader2 size={14} className="animate-spin" /> : null}
              {isContinuing ? 'Continuing…' : 'Continue Payment'}
            </Button>
          </div>
        </>
      )}
    </Modal>
  );
}
