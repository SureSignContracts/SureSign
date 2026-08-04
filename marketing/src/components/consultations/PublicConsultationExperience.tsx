'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { fetchConsultationView } from '@/lib/publicConsultations';
import type { ConsultationPublicView, ApiResult } from '@/lib/publicConsultations';
import {
  ExpiredLinkScreen, InvalidLinkScreen, LoadingSkeleton, NetworkErrorScreen, StaleLinkScreen,
} from '@/components/appointments/StateScreens';
import { OrganisationBrandingBadge } from '@/components/shared/OrganisationBrandingBadge';
import { OrganisationBrandingProvider } from '@/lib/OrganisationBrandingContext';
import { ConsultationDetailCard } from './ConsultationDetailCard';

type ViewState =
  | { kind: 'loading' }
  | { kind: 'invalid' }
  | { kind: 'expired' }
  | { kind: 'stale' }
  | { kind: 'network'; message: string }
  | { kind: 'ready'; consultation: ConsultationPublicView };

/**
 * Batch 3, Scope A/B/F — the public, no-account "view your consultation"
 * page. Deliberately read-only: no cancel/reschedule flow lives here (the
 * existing signed reschedule/cancel confirmation pages at
 * /appointments/{token} still own those actions) — this page's only job is
 * status, schedule, Meet join, calendar download, and a link onward to the
 * published summary once one exists.
 */
export function PublicConsultationExperience(props: { token: string }) {
  return (
    <OrganisationBrandingProvider>
      <PublicConsultationExperienceInner {...props} />
    </OrganisationBrandingProvider>
  );
}

function PublicConsultationExperienceInner({ token }: { token: string }) {
  const searchParams = useSearchParams();
  const [state, setState] = useState<ViewState>({ kind: 'loading' });
  const cardRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  const load = useCallback(() => {
    setState({ kind: 'loading' });

    fetchConsultationView(token, searchParams).then((result: ApiResult<ConsultationPublicView>) => {
      if (result.ok) {
        setState({ kind: 'ready', consultation: result.data });
        return;
      }
      if (result.kind === 'not_found') { setState({ kind: 'stale' }); return; }
      if (result.kind === 'expired') { setState({ kind: 'expired' }); return; }
      if (result.kind === 'invalid') { setState({ kind: 'invalid' }); return; }
      setState({ kind: 'network', message: result.message });
    });
  }, [token, searchParams]);

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

  return (
    <div ref={cardRef}>
      <OrganisationBrandingBadge />
      <ConsultationDetailCard consultation={state.consultation} />
    </div>
  );
}
