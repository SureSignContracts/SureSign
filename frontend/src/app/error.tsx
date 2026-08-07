'use client';

import { useEffect } from 'react';

// Error Messaging & Recovery UX, Batch 1 — the platform's first route-level
// error boundary (see internal-docs/error-messaging-recovery-ux-audit.md
// §2/§18: none existed anywhere before this). Next.js renders this in place
// of any page/nested-layout content under app/ that throws during render —
// it does NOT catch an error in this same root layout.tsx (that would
// require a separate global-error.tsx, which replaces <html>/<body> entirely
// and was judged unsafe/unnecessary for Batch 1's scope: it would drop
// DemoBanner, the theme bootstrap script, and the QueryClient/Toaster
// providers wired in layout.tsx/providers.tsx). This still covers the
// confirmed gap — an uncaught render exception on any page previously fell
// through to Next.js's own unbranded default error screen.
//
// Deliberately minimal: no error message, no `error.digest`, no stack
// trace, no organisation/user detail — a render-time exception could be
// anything, so this never assumes it's safe to describe. `reset()` is
// Next.js's own built-in retry (re-renders the segment); it is safe to
// offer unconditionally here since re-rendering never has a data-state
// side effect of its own.
export default function GlobalErrorBoundary({ error, reset }: { error: Error & { digest?: string }; reset: () => void }) {
  // Browser console only — never sent anywhere, never shown to the user.
  // Next.js's own convention for this file is to report to an error-tracking
  // service here; none is wired into this repo yet, so this is the smallest
  // safe placeholder that preserves the diagnostic value for now.
  useEffect(() => {
    console.error(error);
  }, [error]);

  return (
    <div
      className="min-h-dvh flex flex-col items-center justify-center gap-6 px-6 text-center"
      style={{ backgroundColor: 'var(--bg-base)' }}
    >
      <img
        src="/logo_black/SureSign_BLOGO.webp"
        alt="SureSign"
        className="w-9 h-9 object-contain"
        style={{ filter: 'var(--logo-filter, none)' }}
      />

      <div className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight" style={{ color: 'var(--text-primary)' }}>
          Something went wrong on our side
        </h1>
        <p className="text-sm max-w-sm" style={{ color: 'var(--text-secondary)' }}>
          Please try again. If this keeps happening, contact your administrator or SureSign support.
        </p>
      </div>

      <div className="flex items-center gap-3">
        <button
          onClick={reset}
          className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-opacity hover:opacity-85"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          Try again
        </button>
        <a
          href="/app"
          className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-opacity hover:opacity-85"
          style={{ border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        >
          Back to workspace
        </a>
      </div>
    </div>
  );
}
