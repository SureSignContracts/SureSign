'use client';

import { useEffect, useState } from 'react';
import { AlertCircle } from 'lucide-react';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { currentHostname, fetchOrganisationBranding, type OrganisationBranding } from '@/lib/organisationBranding';

/**
 * Organisation URL Branding, Phase 4 — the generic error boundary. Must
 * be a Client Component (Next's own requirement for error.tsx), so this
 * resolves branding the same way LoginGateway/OrganisationBrandingBadge
 * do — client-side, from `window.location.hostname` — rather than
 * reusing the server-only resolver `not-found.tsx` uses. Renders nothing
 * but a generic, branding-safe message: never the error's own stack
 * trace, message, or any internal identifier.
 */
export default function ErrorBoundary({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  const [branding, setBranding] = useState<OrganisationBranding | null>(null);

  useEffect(() => {
    const host = currentHostname();
    if (!host) return;
    fetchOrganisationBranding(host).then((result) => {
      if (result && result.host_type !== 'historic_slug') setBranding(result);
    });
  }, []);

  return (
    <>
      <MarketingNav />
      <main className="py-20 md:py-28">
        <Container className="max-w-[640px]">
          <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center shadow-[var(--shadow-card)] sm:p-14">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-bg-elevated">
              <AlertCircle className="h-6 w-6 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
            </div>
            <h1 className="mt-5 text-xl font-medium text-text-primary">Something went wrong</h1>
            <p className="mx-auto mt-3 max-w-sm text-sm text-text-secondary">
              We hit an unexpected problem loading this page. Please try again.
            </p>
            <button
              type="button"
              onClick={reset}
              className="mt-6 inline-flex items-center justify-center rounded-full px-6 py-3 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              style={{ backgroundColor: branding?.accent_color || 'var(--accent)' }}
            >
              Try again
            </button>
          </div>
        </Container>
      </main>
      <Footer />
    </>
  );
}
