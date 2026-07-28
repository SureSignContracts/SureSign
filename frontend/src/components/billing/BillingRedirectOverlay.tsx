'use client';

import { createPortal } from 'react-dom';
import { ShieldCheck, CreditCard } from 'lucide-react';

export type BillingRedirectVariant = 'checkout' | 'portal';

const COPY: Record<BillingRedirectVariant, { title: string; body: string; trust: string }> = {
  checkout: {
    title: 'Preparing your secure checkout…',
    body: 'You’re being securely connected to Stripe to complete payment. SureSign never stores your card details. This normally takes only a few seconds.',
    trust: 'Payments secured by Stripe',
  },
  portal: {
    title: 'Opening Secure Billing Centre…',
    body: 'You’re being connected to Stripe’s secure billing page to manage payment methods, billing details and invoices. Plan changes and cancellation stay here in SureSign.',
    trust: 'Hosted securely by Stripe',
  },
};

/**
 * Phase E5 — the branded reassurance screen shown for the (brief, real)
 * window between clicking a Checkout/Portal action and the browser
 * actually navigating to Stripe. Rendered only while that action's own
 * mutation is in flight (`{isPending && <BillingRedirectOverlay .../>}`)
 * — its visible lifetime is exactly the real network/processing time,
 * never an artificial minimum. Portrayed to `document.body` (see
 * components/ui/Modal.tsx's docblock for why: a `position: fixed`
 * element must never be a DOM descendant of a card that could apply a
 * transform).
 *
 * `role="status"`/`aria-live="polite"` announce the transition to screen
 * readers without stealing focus (there is nothing to interact with here
 * — the page is about to navigate away). Motion is a single opacity fade
 * (`.ss-animate-in`) plus a CSS spin, both already covered by the global
 * `prefers-reduced-motion` rules in globals.css.
 */
export default function BillingRedirectOverlay({ variant }: { variant: BillingRedirectVariant }) {
  if (typeof document === 'undefined') return null;

  const copy = COPY[variant];

  return createPortal(
    <div
      role="status"
      aria-live="polite"
      className="fixed inset-0 z-[60] flex flex-col items-center justify-center gap-5 px-6 text-center ss-animate-in"
      style={{ backgroundColor: 'var(--bg-base)' }}
    >
      <div className="relative flex items-center justify-center">
        <div
          className="absolute w-16 h-16 rounded-full border-2 animate-spin motion-reduce:animate-none"
          style={{ borderColor: 'var(--border)', borderTopColor: 'var(--gold)' }}
        />
        <CreditCard size={26} style={{ color: 'var(--text-secondary)' }} />
      </div>

      <div className="flex flex-col items-center gap-2 max-w-sm">
        <h1 className="text-base font-semibold tracking-tight" style={{ color: 'var(--text-primary)' }}>
          {copy.title}
        </h1>
        <p className="text-sm leading-relaxed" style={{ color: 'var(--text-muted)' }}>
          {copy.body}
        </p>
      </div>

      <div
        className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium"
        style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
      >
        <ShieldCheck size={13} style={{ color: '#4ade80' }} />
        {copy.trust}
      </div>
    </div>,
    document.body,
  );
}
