'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { fetchAppointmentView, signedQueryFrom } from '@/lib/publicAppointments';
import type { AppointmentAction, AppointmentPublicView, ApiResult } from '@/lib/publicAppointments';
import { isTerminalStatus, statusLabel } from '@/lib/appointmentFormat';
import { AppointmentSummaryCard } from './AppointmentSummaryCard';
import { CancelFlow } from './CancelFlow';
import { RescheduleFlow } from './RescheduleFlow';
import {
  ExpiredLinkScreen, InvalidLinkScreen, LoadingSkeleton, NetworkErrorScreen, StaleLinkScreen,
} from './StateScreens';

type ViewState =
  | { kind: 'loading' }
  | { kind: 'invalid' }
  | { kind: 'expired' }
  | { kind: 'stale' }
  | { kind: 'network'; message: string }
  | { kind: 'ready'; appointment: AppointmentPublicView; completed: boolean };

function terminalMessage(status: string): string {
  switch (status) {
    case 'cancelled':
      return 'This appointment has been cancelled. Get in touch if you need to arrange a new one.';
    case 'declined':
      return "This appointment request wasn't confirmed. Get in touch if you'd like to arrange a new time.";
    case 'completed':
      return 'This appointment has already taken place.';
    case 'no_show':
      return 'This appointment was marked as a no-show.';
    default:
      return `This appointment is ${statusLabel(status).toLowerCase()}.`;
  }
}

export function PublicAppointmentExperience({ token }: { token: string }) {
  const searchParams = useSearchParams();
  const actionParam = searchParams.get('action');
  const action: AppointmentAction | null = actionParam === 'cancel' || actionParam === 'reschedule' ? actionParam : null;

  const [state, setState] = useState<ViewState>({ kind: 'loading' });
  const cardRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  const load = useCallback(() => {
    setState({ kind: 'loading' });

    if (!action) {
      setState({ kind: 'invalid' });
      return;
    }
    const signed = signedQueryFrom(searchParams);
    if (!signed) {
      setState({ kind: 'invalid' });
      return;
    }

    fetchAppointmentView(token, action, searchParams).then((result: ApiResult<AppointmentPublicView>) => {
      if (result.ok) {
        setState({ kind: 'ready', appointment: result.data, completed: false });
        return;
      }
      if (result.kind === 'not_found') { setState({ kind: 'stale' }); return; }
      if (result.kind === 'expired') { setState({ kind: 'expired' }); return; }
      if (result.kind === 'invalid') { setState({ kind: 'invalid' }); return; }
      setState({ kind: 'network', message: result.message });
    });
  }, [token, action, searchParams]);

  useEffect(() => { load(); }, [load]); // eslint-disable-line react-hooks/set-state-in-effect

  useEffect(() => {
    if (state.kind !== 'ready' || reduced || !cardRef.current) return;
    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      gsap.from(cardRef.current, {
        opacity: 0, y: 16, duration: 0.5, ease: 'power2.out',
      });
    }, cardRef);
    return () => ctx.revert();
  }, [state.kind, reduced]);

  if (state.kind === 'loading') return <LoadingSkeleton />;
  if (state.kind === 'invalid') return <HeroReveal><InvalidLinkScreen /></HeroReveal>;
  if (state.kind === 'expired') return <HeroReveal><ExpiredLinkScreen /></HeroReveal>;
  if (state.kind === 'stale') return <HeroReveal><StaleLinkScreen /></HeroReveal>;
  if (state.kind === 'network') return <HeroReveal><NetworkErrorScreen onRetry={load} /></HeroReveal>;

  const { appointment, completed } = state;
  const terminal = isTerminalStatus(appointment.status);

  return (
    <div ref={cardRef} className="space-y-6">
      <AppointmentSummaryCard appointment={appointment} />

      {terminal && (
        <p role="status" className="rounded-xl border border-border bg-bg-elevated px-5 py-4 text-sm text-text-secondary">
          {terminalMessage(appointment.status)}
        </p>
      )}

      {!terminal && completed && (
        <p role="status" className="rounded-xl border border-border bg-bg-elevated px-5 py-4 text-sm text-text-secondary">
          Need to make another change? Check the latest email we sent you for an up-to-date link.
        </p>
      )}

      {!terminal && !completed && action === 'cancel' && (
        <CancelFlow
          token={token}
          searchParams={searchParams}
          appointment={appointment}
          onCancelled={() => setState({ kind: 'ready', appointment: { ...appointment, status: 'cancelled', can_cancel: false, can_reschedule: false }, completed: true })}
        />
      )}

      {!terminal && !completed && action === 'reschedule' && (
        <RescheduleFlow
          token={token}
          searchParams={searchParams}
          appointment={appointment}
          onRescheduled={updated => setState({ kind: 'ready', appointment: updated, completed: true })}
        />
      )}
    </div>
  );
}
