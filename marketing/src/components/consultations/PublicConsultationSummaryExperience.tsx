'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { fetchConsultationSummary } from '@/lib/publicConsultations';
import type { ConsultationPublicSummary, ApiResult } from '@/lib/publicConsultations';
import { formatDateInZone } from '@/lib/appointmentFormat';
import {
  ExpiredLinkScreen, InvalidLinkScreen, LoadingSkeleton, NetworkErrorScreen, StaleLinkScreen,
} from '@/components/appointments/StateScreens';
import { OrganisationBrandingBadge } from '@/components/shared/OrganisationBrandingBadge';
import { OrganisationBrandingProvider } from '@/lib/OrganisationBrandingContext';

type ViewState =
  | { kind: 'loading' }
  | { kind: 'invalid' }
  | { kind: 'expired' }
  | { kind: 'stale' }
  | { kind: 'network'; message: string }
  | { kind: 'ready'; summary: ConsultationPublicSummary };

/**
 * Batch 3, Scope E — the public, no-account published-summary page.
 * `stale` (404) deliberately covers BOTH "token doesn't exist" and "no
 * summary has been published yet" — PublicConsultationViewController::summary()
 * returns the same generic 404 for either, so this page can't be used to
 * probe whether a given token is real but simply unpublished.
 */
export function PublicConsultationSummaryExperience(props: { token: string }) {
  return (
    <OrganisationBrandingProvider>
      <PublicConsultationSummaryExperienceInner {...props} />
    </OrganisationBrandingProvider>
  );
}

function PublicConsultationSummaryExperienceInner({ token }: { token: string }) {
  const searchParams = useSearchParams();
  const [state, setState] = useState<ViewState>({ kind: 'loading' });
  const cardRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  const load = useCallback(() => {
    setState({ kind: 'loading' });

    fetchConsultationSummary(token, searchParams).then((result: ApiResult<ConsultationPublicSummary>) => {
      if (result.ok) {
        setState({ kind: 'ready', summary: result.data });
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

  const { summary } = state;

  return (
    <div ref={cardRef} className="rounded-2xl border border-border bg-bg-surface p-8 sm:p-10">
      <OrganisationBrandingBadge />
      <span className="text-xs font-medium uppercase tracking-wide text-text-muted">{summary.reference}</span>

      <h1 className="mt-4 text-2xl font-medium tracking-tight text-text-primary">
        {summary.title ?? summary.consultancy_service?.display_name ?? 'Consultation summary'}
      </h1>

      <p className="mt-2 text-sm text-text-secondary">
        {formatDateInZone(summary.starts_at, summary.booking_timezone)}
        {summary.assigned_consultant?.name && <> &middot; with {summary.assigned_consultant.name}</>}
      </p>

      <div className="mt-8 border-t border-border pt-8">
        <p className="whitespace-pre-wrap text-[15px] leading-relaxed text-text-secondary">
          {summary.summary}
        </p>
      </div>

      {summary.published_at && (
        <p className="mt-8 text-xs text-text-muted">Published {formatDateInZone(summary.published_at, summary.booking_timezone)}</p>
      )}
    </div>
  );
}
