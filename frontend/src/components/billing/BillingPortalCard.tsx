'use client';

import { useState } from 'react';
import { ExternalLink, Loader2 } from 'lucide-react';
import { Card, CardHeader, CardTitle, CardBody } from '@/components/ui/Card';
import Button from '@/components/ui/Button';
import { useCreatePortalSession, PORTAL_RETURN_FLAG_KEY } from '@/hooks/useBilling';
import { getErrorMessage } from '@/lib/getErrorMessage';
import BillingRedirectOverlay from '@/components/billing/BillingRedirectOverlay';
import toast from '@/lib/toast';

/**
 * Opens Stripe's restricted Customer Portal (Slice E2) — payment methods,
 * billing details and invoice history only. Deliberately never labelled
 * "Manage subscription": plan changes and cancellation are NOT available
 * there, and never will be — both stay on SureSign's own Billing page.
 */
export default function BillingPortalCard() {
  const [opening, setOpening] = useState(false);
  const portal = useCreatePortalSession();

  const handleOpen = () => {
    if (opening || portal.isPending) return; // duplicate-click prevention
    setOpening(true);
    portal.mutate(undefined, {
      onSuccess: (data) => {
        if (data.url) {
          // Consumed once, on mount, by the Billing page — see
          // PORTAL_RETURN_FLAG_KEY's own docblock in useBilling.ts.
          sessionStorage.setItem(PORTAL_RETURN_FLAG_KEY, '1');
          window.location.href = data.url;
        } else {
          toast.error('Billing management could not be opened. Please try again.');
          setOpening(false);
        }
      },
      onError: (err: unknown) => {
        toast.error(getErrorMessage(err, 'Billing management could not be opened. Please try again.'));
        setOpening(false);
      },
    });
  };

  return (
    <Card className="ss-animate-in transition-shadow duration-300 hover:shadow-[var(--shadow-pop)]">
      <CardHeader>
        <CardTitle>Payment Methods &amp; Billing Details</CardTitle>
      </CardHeader>
      <CardBody className="space-y-3">
        <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
          Manage your saved payment methods, billing address and view your full invoice history on Stripe&apos;s
          secure billing page.
        </p>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Plan changes and cancellation are managed here in SureSign, not on that page.
        </p>
        <Button variant="secondary" size="sm" onClick={handleOpen} disabled={opening || portal.isPending} className="group">
          {opening || portal.isPending
            ? <Loader2 size={14} className="animate-spin" />
            : <ExternalLink size={14} className="transition-transform duration-200 group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />}
          {opening || portal.isPending ? 'Opening…' : 'Manage payment methods & invoices'}
        </Button>
      </CardBody>

      {opening && <BillingRedirectOverlay variant="portal" />}
    </Card>
  );
}
