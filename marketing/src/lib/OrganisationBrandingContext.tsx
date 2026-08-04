'use client';

import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { currentHostname, fetchOrganisationBranding, type OrganisationBranding } from './organisationBranding';

const OrganisationBrandingContext = createContext<OrganisationBranding | null>(null);

/**
 * Organisation URL Branding, Phase 2 — the ONE shared resolution flow for
 * every branded public page (Appointment/Consultation experiences). Wrap
 * each experience component's root in this once; every descendant reads
 * branding via `useOrganisationBranding()` instead of fetching
 * independently.
 *
 * Also owns the historic-slug redirect: when the resolved hostname turns
 * out to be an organisation's SUPERSEDED slug (still valid, but the
 * organisation now lives elsewhere), this replaces the current URL with
 * the organisation's current canonical base — preserving the exact path
 * and query string (including a signed link's expires/signature pair) —
 * via `redirect_base_url`, a value that only ever comes from our own
 * trusted backend response, never from anything user-controlled. See
 * App\Http\Controllers\Api\PublicOrganisationBrandingController.
 *
 * Only one of these mounts at a time in a real browser tab (a visitor is
 * on exactly one page), so this is deliberately scoped per-experience
 * rather than hoisted into the root layout — see internal-docs/super-admin/
 * organisation-url-branding.md for why a platform-wide host-classification
 * layer remains out of scope.
 */
export function OrganisationBrandingProvider({ children }: { children: ReactNode }) {
  const [branding, setBranding] = useState<OrganisationBranding | null>(null);

  useEffect(() => {
    const host = currentHostname();
    if (!host) return;

    let cancelled = false;
    fetchOrganisationBranding(host).then((result) => {
      if (cancelled || !result) return;

      if (result.host_type === 'historic_slug' && result.redirect_base_url) {
        const target = `${result.redirect_base_url}${window.location.pathname}${window.location.search}`;
        window.location.replace(target);
        return;
      }

      setBranding(result);
    });

    return () => {
      cancelled = true;
    };
  }, []);

  // Phase 4 — the organisation's accent colour cascades to every existing
  // `bg-accent`/`text-accent-fg` element already inside this subtree via a
  // scoped CSS custom-property override, rather than touching each
  // button/CTA individually. Deliberately just this one variable pair —
  // no other layout/typography/spacing changes — matching the "accent
  // only, never a full re-theme" scope. isReadableForeground() mirrors
  // the exact luminance check already used by the Company Branding
  // colour picker's own live preview (frontend/src/app/(dashboard)/settings/page.tsx).
  const accentStyle = branding?.accent_color
    ? ({ '--accent': branding.accent_color, '--accent-fg': isReadableForeground(branding.accent_color) ? '#0a0a0a' : '#ffffff' } as React.CSSProperties)
    : undefined;

  return (
    <OrganisationBrandingContext.Provider value={branding}>
      <div style={accentStyle}>{children}</div>
    </OrganisationBrandingContext.Provider>
  );
}

function isReadableForeground(hex: string): boolean {
  const h = hex.replace('#', '');
  if (h.length !== 6) return true;
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return (r * 299 + g * 587 + b * 114) / 1000 > 128;
}

/** Null when unbranded (default host), unresolved, or still loading — callers should render their normal, unbranded UI in every one of those cases; this is presentation only. */
export function useOrganisationBranding(): OrganisationBranding | null {
  return useContext(OrganisationBrandingContext);
}
