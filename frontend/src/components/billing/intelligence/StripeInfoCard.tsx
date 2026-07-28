import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { CreditCard } from 'lucide-react';
import { formatDateTime } from '@/lib/dateTime';
import { StripeIntelligenceInfo } from '@/types/subscriptionIntelligence';

/**
 * Stage 10 — read-only Stripe summary. Every field is already resolved by
 * the backend from local, already-synced records
 * (`BillingCustomer`/`BillingPayment`/`Subscription`) — never a live
 * Stripe API call from this component, and never a raw provider
 * id/secret.
 */
export default function StripeInfoCard({ stripe, timeZone }: { stripe: StripeIntelligenceInfo; timeZone?: string }) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <CreditCard size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Stripe</CardTitle>
        </div>
        <Badge tone={stripe.customer_connected ? 'success' : 'neutral'}>
          {stripe.customer_connected ? 'Connected' : 'Not connected'}
        </Badge>
      </CardHeader>
      <CardBody className="space-y-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
        <p>Customer Portal: {stripe.portal_available ? 'Available' : 'Not available yet'}</p>
        <p>Payment method: {stripe.payment_method_type ?? 'None on file'}</p>
        <p>Invoices issued: {stripe.invoice_count}</p>
        {stripe.next_renewal_at && <p>Next renewal: {formatDateTime(stripe.next_renewal_at, { timeZone })}</p>}
      </CardBody>
    </Card>
  );
}
