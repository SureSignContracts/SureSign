'use client';

import { useId, useRef, useState } from 'react';
import { CheckCircle2 } from 'lucide-react';
import { submitCancellation } from '@/lib/publicAppointments';
import { formatDateInZone, formatTimeInZone } from '@/lib/appointmentFormat';
import type { AppointmentPublicView } from '@/lib/publicAppointments';

type Step = 'idle' | 'confirming' | 'submitting' | 'done';

export function CancelFlow({
  token,
  searchParams,
  appointment,
  onCancelled,
}: {
  token: string;
  searchParams: URLSearchParams;
  appointment: AppointmentPublicView;
  onCancelled: (result: { status: string }) => void;
}) {
  const [step, setStep] = useState<Step>('idle');
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const submittingRef = useRef(false);
  const reasonId = useId();

  if (!appointment.can_cancel) {
    return (
      <p role="status" className="rounded-xl border border-border bg-bg-elevated px-5 py-4 text-sm text-text-secondary">
        This appointment can no longer be cancelled online. Please contact us directly.
      </p>
    );
  }

  if (step === 'idle') {
    return (
      <button
        type="button"
        onClick={() => setStep('confirming')}
        className="w-full rounded-full border border-border px-6 py-3.5 text-sm font-medium text-text-primary transition-colors hover:border-border-light sm:w-auto"
      >
        Cancel appointment
      </button>
    );
  }

  if (step === 'done') {
    return (
      <div role="status" className="flex items-start gap-3 rounded-xl border border-border bg-bg-elevated px-5 py-4">
        <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-text-primary" strokeWidth={1.5} aria-hidden="true" />
        <div>
          <p className="text-sm font-medium text-text-primary">Appointment cancelled</p>
          <p className="mt-1 text-sm text-text-secondary">We&apos;ve let the team know. No further action is needed.</p>
        </div>
      </div>
    );
  }

  async function handleConfirm() {
    if (submittingRef.current) return;
    submittingRef.current = true;
    setStep('submitting');
    setError(null);

    const result = await submitCancellation(token, searchParams, reason.trim());
    submittingRef.current = false;

    if (!result.ok) {
      setError(result.message);
      setStep('confirming');
      return;
    }

    setStep('done');
    onCancelled(result.data);
  }

  return (
    <div className="rounded-2xl border border-border bg-bg-elevated p-6 sm:p-8">
      <h2 className="text-base font-medium text-text-primary">Confirm cancellation</h2>
      <p className="mt-2 text-sm text-text-secondary">
        You&apos;re cancelling your appointment on {formatDateInZone(appointment.starts_at, appointment.booking_timezone)} at{' '}
        {formatTimeInZone(appointment.starts_at, appointment.booking_timezone)}. This can&apos;t be undone from this page.
      </p>

      <div className="mt-5 flex flex-col gap-2">
        <label htmlFor={reasonId} className="text-sm font-medium text-text-primary">
          Reason <span className="font-normal text-text-muted">(optional)</span>
        </label>
        <textarea
          id={reasonId}
          rows={3}
          maxLength={255}
          value={reason}
          disabled={step === 'submitting'}
          onChange={e => setReason(e.target.value)}
          className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light disabled:opacity-60"
        />
      </div>

      {error && (
        <p role="alert" className="mt-4 text-sm text-text-primary">{error}</p>
      )}

      <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row">
        <button
          type="button"
          disabled={step === 'submitting'}
          onClick={() => { setStep('idle'); setError(null); }}
          className="rounded-full border border-border px-6 py-3 text-sm font-medium text-text-primary transition-colors hover:border-border-light disabled:opacity-60"
        >
          Go back
        </button>
        <button
          type="button"
          disabled={step === 'submitting'}
          onClick={handleConfirm}
          className="rounded-full bg-accent px-6 py-3 text-sm font-medium text-accent-fg transition-transform active:translate-y-px disabled:opacity-60"
        >
          {step === 'submitting' ? 'Cancelling…' : 'Confirm cancellation'}
        </button>
      </div>
    </div>
  );
}
