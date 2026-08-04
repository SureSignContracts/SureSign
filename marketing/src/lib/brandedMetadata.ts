import type { Metadata } from 'next';
import { resolveRequestHost, fetchOrganisationBrandingServer } from './organisationBrandingServer';

/**
 * Organisation URL Branding, Phase 4 — shared server-side metadata
 * resolution for the 3 branded token pages (appointments/[token],
 * consultations/[token], consultations/[token]/summary). Deliberately
 * resolves branding from the REQUEST'S OWN Host header only — never from
 * a query parameter or any other client-suppliable value — so a visitor
 * can't spoof another organisation's name/logo into the page's metadata.
 *
 * Falls back to the exact same static title every one of these pages
 * already had before Phase 4 whenever branding is anything other than
 * cleanly `resolved` (unbranded host, authoritatively unknown host, or
 * the resolver being temporarily unavailable) — one consistent fallback
 * regardless of *why* branding didn't apply, never a distinct broken
 * state per reason.
 */
export async function buildBrandedMetadata(defaultTitle: string): Promise<Metadata> {
  const host = await resolveRequestHost();
  const result = host ? await fetchOrganisationBrandingServer(host) : { status: 'unavailable' as const };

  if (result.status !== 'resolved') {
    return { title: defaultTitle, robots: { index: false, follow: false } };
  }

  const { organisation_name, accent_color } = result.data;

  return {
    title: `${organisation_name} • SureSign`,
    robots: { index: false, follow: false },
    themeColor: accent_color || undefined,
  };
}
