'use client';

import { useEffect, useRef } from 'react';
import { CheckCircle2, Mail, CalendarCheck, RefreshCw } from 'lucide-react';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import { formatFullDate } from '@/lib/calendarDate';

function to12Hour(time: string): string {
  const [h, m] = time.split(':').map(Number);
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h % 12 === 0 ? 12 : h % 12;
  return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
}

export function BookingSuccess({
  status,
  title,
  dateIso,
  time,
  timezone,
  durationMinutes,
}: {
  status: string;
  title: string;
  dateIso: string;
  time: string;
  timezone: string;
  durationMinutes: number;
}) {
  const iconRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();
  const confirmed = status === 'confirmed';

  useEffect(() => {
    if (reduced || !iconRef.current) return;
    const { gsap } = getGsap();
    gsap.fromTo(iconRef.current, { opacity: 0, scale: 0.6 }, { opacity: 1, scale: 1, duration: 0.5, ease: 'back.out(1.7)' });
  }, [reduced]);

  return (
    <div className="rounded-2xl border border-border bg-bg-surface p-8 text-center sm:p-12" data-reveal>
      <div ref={iconRef} className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-bg-elevated">
        <CheckCircle2 className="h-7 w-7 text-text-primary" strokeWidth={1.5} aria-hidden="true" />
      </div>

      <h1 className="mt-6 text-2xl font-medium tracking-tight text-text-primary">
        {confirmed ? 'Booking confirmed' : 'Request received'}
      </h1>
      <p className="mt-2 text-sm text-text-secondary">
        {confirmed
          ? "We've sent the details to your email. See you then."
          : "Someone from the SureSign team will confirm your exact time shortly."}
      </p>

      <div className="mx-auto mt-8 max-w-sm rounded-xl border border-border bg-bg-elevated p-5 text-left">
        <p className="text-sm font-medium text-text-primary">{title}</p>
        <p className="mt-1 text-sm text-text-secondary">
          {formatFullDate(dateIso)} · {to12Hour(time)} ({timezone})
        </p>
        <p className="mt-1 text-xs text-text-muted">{durationMinutes} minutes</p>
      </div>

      <div className="mx-auto mt-8 max-w-sm space-y-3 text-left text-sm text-text-secondary">
        <div className="flex items-start gap-3">
          <Mail className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <span>{confirmed ? 'Confirmation email sent, with a calendar invite attached.' : "We've emailed you confirming your request was received."}</span>
        </div>
        {confirmed && (
          <div className="flex items-start gap-3">
            <CalendarCheck className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
            <span>Add it to your calendar from the attached invite.</span>
          </div>
        )}
        <div className="flex items-start gap-3">
          <RefreshCw className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
          <span>Need to change or cancel? Use the link in that email at any time.</span>
        </div>
      </div>
    </div>
  );
}
