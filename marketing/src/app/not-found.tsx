import { Ban } from 'lucide-react';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { resolveRequestHost, fetchOrganisationBrandingServer } from '@/lib/organisationBrandingServer';

export const metadata = {
  title: "Page not found",
  robots: { index: false, follow: false },
};

// Forces this to render per-request rather than being statically
// prerendered at build time — it calls headers() (via
// resolveRequestHost()), a dynamic API with no real request context
// available during static generation of the special /_not-found shell.
export const dynamic = 'force-dynamic';

/**
 * Organisation URL Branding, Phase 4 — the genuine 404 (an unknown PATH,
 * not an unknown hostname — that case is proxy.ts's own
 * `branding-not-found` rewrite, a separate page). Server-rendered, so
 * branding resolves the same way generateMetadata() does on the branded
 * token pages: from the request's own Host header, never a client value.
 * Renders only branding-safe fields — no hostname-resolution internals,
 * no ids.
 */
export default async function NotFound() {
  const host = await resolveRequestHost();
  const result = host ? await fetchOrganisationBrandingServer(host) : { status: 'unavailable' as const };
  const branding = result.status === 'resolved' ? result.data : null;

  return (
    <>
      <MarketingNav />
      <main className="py-20 md:py-28">
        <Container className="max-w-[640px]">
          <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center shadow-[var(--shadow-card)] sm:p-14">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-bg-elevated">
              <Ban className="h-6 w-6 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
            </div>
            <h1 className="mt-5 text-xl font-medium text-text-primary">This page doesn&apos;t exist.</h1>
            <p className="mx-auto mt-3 max-w-sm text-sm text-text-secondary">
              The page you&apos;re looking for isn&apos;t here. Check the link, or head back to{' '}
              {branding ? `${branding.organisation_name}'s workspace` : 'the SureSign homepage'}.
            </p>
            <a
              href={branding ? `https://${host}` : 'https://suresigncontracts.app'}
              className="mt-6 inline-flex items-center justify-center rounded-full bg-accent px-6 py-3 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              style={branding?.accent_color ? { backgroundColor: branding.accent_color } : undefined}
            >
              {branding ? `Return to ${branding.organisation_name} Workspace` : 'Return to SureSign'}
            </a>
          </div>
        </Container>
      </main>
      <Footer />
    </>
  );
}
