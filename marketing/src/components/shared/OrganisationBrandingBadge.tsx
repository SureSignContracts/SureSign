'use client';

import { useOrganisationBranding } from '@/lib/OrganisationBrandingContext';

/**
 * Organisation URL Branding, Phase 1 (context upgraded Phase 2) — a small,
 * additive "booked with"/"powered by" badge shown above a public
 * Appointment/Consultation page when it's being served on a branded
 * organisation hostname OR a verified customer domain. Deliberately does
 * NOT override the page's own accent colour/theme (a broader re-skin is
 * out of scope — see internal-docs/super-admin/
 * organisation-url-branding.md) — this only adds organisation identity,
 * never changes layout or business behaviour. Renders nothing when
 * unbranded, unresolved, or still loading (see OrganisationBrandingProvider,
 * which must wrap this component's page).
 */
export function OrganisationBrandingBadge() {
  const branding = useOrganisationBranding();

  if (!branding) return null;

  return (
    <div className="mb-6 flex items-center gap-3 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] px-4 py-3">
      {branding.logo_url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img src={branding.logo_url} alt={`${branding.organisation_name} logo`} className="h-8 w-auto" />
      ) : null}
      <span className="text-sm text-[var(--text-secondary)]">
        Booked with <span className="font-medium text-[var(--text-primary)]">{branding.organisation_name}</span>
      </span>
    </div>
  );
}
