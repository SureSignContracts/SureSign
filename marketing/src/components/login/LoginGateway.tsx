'use client';

import { useEffect, useState } from 'react';
import { currentHostname, fetchOrganisationBranding, type OrganisationBranding } from '@/lib/organisationBranding';
import { isHostnameSyntacticallyValid } from '@/lib/hostnameValidation';

const FRONTEND_URL = process.env.NEXT_PUBLIC_FRONTEND_URL || 'http://localhost:3000';

type ResolutionState =
  | { phase: 'loading' }
  | { phase: 'branded'; branding: OrganisationBranding; host: string }
  | { phase: 'unbranded' };

/**
 * Organisation URL Branding, Phase 4 — the branded pre-auth login gateway.
 * Deliberately collects NOTHING (no email, no password) — this is a
 * decision, not a placeholder: `marketing/` never authenticates against
 * the app API (see backend/config/cors.php's own docblock), so there is
 * nothing safe for a form here to submit. It only decorates the handoff
 * to `frontend/`'s real, unmodified login with the organisation's own
 * identity, then gets out of the way.
 *
 * Three explicit states rather than a single nullable branding value —
 * unlike OrganisationBrandingBadge/OrganisationBrandingProvider (which
 * treat "still loading" and "definitely unbranded" the same, fine for a
 * badge that only ever adds decoration), this page's OWN redirect
 * decision must never fire before resolution actually completes — an
 * early "loading = unbranded" redirect would send a legitimately branded
 * visitor straight past their own organisation's gateway.
 */
export function LoginGateway() {
  const [state, setState] = useState<ResolutionState>({ phase: 'loading' });

  useEffect(() => {
    let cancelled = false;
    const host = currentHostname();

    // Both branches resolve through a promise (never a synchronous
    // setState directly in the effect body) — matching the
    // react-hooks/set-state-in-effect convention already used elsewhere
    // in this codebase for an identical pattern (see the Appointments
    // Availability page).
    Promise.resolve(host ? fetchOrganisationBranding(host) : null).then((branding) => {
      if (cancelled) return;
      if (host && branding && branding.host_type !== 'historic_slug') {
        setState({ phase: 'branded', branding, host });
      } else {
        setState({ phase: 'unbranded' });
      }
    });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (state.phase === 'unbranded') {
      window.location.replace(`${FRONTEND_URL}/login`);
    }
  }, [state.phase]);

  if (state.phase !== 'branded') {
    // Loading and unbranded (mid-redirect) both render nothing — no
    // flash of branded/unbranded content, no generic login form ever
    // shown here (this page's only job is the branded gateway or an
    // immediate handoff, never a fallback form of its own).
    return null;
  }

  const { branding, host } = state;

  function handleContinue() {
    const url = new URL('/login', FRONTEND_URL);
    if (isHostnameSyntacticallyValid(host)) {
      url.searchParams.set('brandHost', host);
    }
    window.location.href = url.toString();
  }

  return (
    <div className="mx-auto flex max-w-[420px] flex-col items-center rounded-2xl border border-border bg-bg-surface p-10 text-center shadow-[var(--shadow-card)] sm:p-12">
      {branding.logo_url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={branding.logo_url} alt={`${branding.organisation_name} logo`} className="h-12 w-auto" />
      ) : null}
      <h1 className="mt-6 text-xl font-medium text-text-primary">Welcome to {branding.organisation_name}</h1>
      <p className="mt-2 text-sm text-text-secondary">Sign in to your organisation&apos;s SureSign workspace.</p>
      <button
        type="button"
        onClick={handleContinue}
        className="mt-8 inline-flex w-full items-center justify-center rounded-full px-6 py-3 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
        style={{ backgroundColor: branding.accent_color || undefined }}
      >
        Continue to Sign In
      </button>
      <p className="mt-6 text-xs text-text-muted">Powered by SureSign</p>
    </div>
  );
}
