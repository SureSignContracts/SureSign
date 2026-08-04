import Link from 'next/link';
import { AlertCircle, Ban, RefreshCw, type LucideIcon } from 'lucide-react';
import { useOrganisationBranding } from '@/lib/OrganisationBrandingContext';

function Frame({ icon: Icon, children }: { icon: LucideIcon; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center shadow-[var(--shadow-card)] sm:p-14" data-reveal>
      <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-bg-elevated">
        <Icon className="h-6 w-6 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
      </div>
      {children}
    </div>
  );
}

export function LoadingSkeleton() {
  // Phase 4 — only ever populated when this renders INSIDE an
  // OrganisationBrandingProvider that's already resolved branding (the
  // outer Suspense fallback in each page.tsx renders before that provider
  // even mounts, so it stays the plain generic skeleton there — this only
  // takes effect for the experience components' own internal
  // `state.kind === 'loading'` case, on a repeat navigation within the
  // same cached session). Falls back to the identical generic bar
  // whenever branding isn't available, never a distinct broken layout.
  const branding = useOrganisationBranding();

  return (
    <div aria-live="polite" aria-busy="true" className="rounded-2xl border border-border bg-bg-surface p-8 sm:p-10">
      <span className="sr-only">Loading your appointment…</span>
      <div className="flex items-center justify-between">
        {branding?.logo_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={branding.logo_url} alt="" className="h-8 w-auto" />
        ) : (
          <div className="h-4 w-32 animate-pulse rounded bg-bg-elevated" />
        )}
        <div className="h-7 w-24 animate-pulse rounded-full bg-bg-elevated" />
      </div>
      <div className="mt-8 h-6 w-3/4 animate-pulse rounded bg-bg-elevated" />
      <div className="mt-4 h-4 w-1/2 animate-pulse rounded bg-bg-elevated" />
      <div className="mt-2 h-4 w-2/5 animate-pulse rounded bg-bg-elevated" />
      <div className="mt-10 h-12 w-full animate-pulse rounded-full bg-bg-elevated" />
    </div>
  );
}

export function InvalidLinkScreen() {
  return (
    <Frame icon={Ban}>
      <h1 className="mt-5 text-xl font-medium text-text-primary">This link isn&apos;t valid</h1>
      <p className="mx-auto mt-3 max-w-sm text-sm text-text-secondary">
        We can&apos;t verify this appointment link. Please use the most recent email we sent you, or get in touch and we&apos;ll help directly.
      </p>
      <ContactAction />
    </Frame>
  );
}

export function ExpiredLinkScreen() {
  return (
    <Frame icon={AlertCircle}>
      <h1 className="mt-5 text-xl font-medium text-text-primary">This link has expired</h1>
      <p className="mx-auto mt-3 max-w-sm text-sm text-text-secondary">
        For your security, appointment links stop working after a while. Please get in touch and we&apos;ll help you directly.
      </p>
      <ContactAction />
    </Frame>
  );
}

export function StaleLinkScreen() {
  return (
    <Frame icon={Ban}>
      <h1 className="mt-5 text-xl font-medium text-text-primary">This link is no longer active</h1>
      <p className="mx-auto mt-3 max-w-sm text-sm text-text-secondary">
        This appointment link is no longer active. Please use the latest email we sent you.
      </p>
      <ContactAction />
    </Frame>
  );
}

export function NetworkErrorScreen({ onRetry }: { onRetry: () => void }) {
  return (
    <Frame icon={AlertCircle}>
      <h1 className="mt-5 text-xl font-medium text-text-primary">We couldn&apos;t load this appointment</h1>
      <p className="mx-auto mt-3 max-w-sm text-sm text-text-secondary">Check your connection and try again.</p>
      <button
        type="button"
        onClick={onRetry}
        className="mt-6 inline-flex items-center gap-2 rounded-full border border-border px-5 py-2.5 text-sm font-medium text-text-primary transition-colors hover:border-border-light"
      >
        <RefreshCw className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
        Try again
      </button>
    </Frame>
  );
}

function ContactAction() {
  return (
    <Link
      href="/book/demo?src=contact"
      className="mt-6 inline-flex items-center justify-center rounded-full bg-accent px-6 py-3 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
    >
      Contact us
    </Link>
  );
}
