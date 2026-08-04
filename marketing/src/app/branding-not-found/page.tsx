import { Ban } from 'lucide-react';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';

export const metadata = {
  title: "We couldn't find this workspace",
  robots: { index: false, follow: false },
};

/**
 * Organisation URL Branding, Phase 4 — the neutral destination
 * middleware.ts rewrites to when a host looks like it was meant to be a
 * branded organisation/customer domain but the backend authoritatively
 * has nothing for it (a clean 404, never a network/timeout failure — see
 * lib/organisationBrandingServer.ts's tri-state result). Deliberately
 * generic: no organisation name, no internal id, no hint about which
 * hostnames DO resolve — a stray/typo'd subdomain should learn nothing
 * about the platform's tenant list from this page.
 */
export default function BrandingNotFoundPage() {
  return (
    <>
      <MarketingNav />
      <main className="py-20 md:py-28">
        <Container className="max-w-[640px]">
          <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center shadow-[var(--shadow-card)] sm:p-14">
            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-bg-elevated">
              <Ban className="h-6 w-6 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
            </div>
            <h1 className="mt-5 text-xl font-medium text-text-primary">We couldn&apos;t find this workspace</h1>
            <p className="mx-auto mt-3 max-w-sm text-sm text-text-secondary">
              This address doesn&apos;t match an active SureSign workspace. Check the link you were given, or visit
              the main SureSign site below.
            </p>
            <a
              href="https://suresigncontracts.app"
              className="mt-6 inline-flex items-center justify-center rounded-full bg-accent px-6 py-3 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
            >
              Go to SureSign
            </a>
          </div>
        </Container>
      </main>
      <Footer />
    </>
  );
}
