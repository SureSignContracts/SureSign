import type { Tone } from '@/components/ui/Badge';
import type { AccessMode } from '@/hooks/useBilling';

/**
 * Customer-facing translations of SureSign's internal Billing vocabulary
 * (backend/app/Support/Billing/SubscriptionStatus.php,
 * App\Support\Billing\PlanChangeState, App\Support\Entitlements\
 * SubscriptionAccessMode) — never render a raw status/mode string
 * directly in the UI. The longer explanatory copy for the current access
 * decision should come from the backend's own `access.reason` field
 * (already customer-safe prose written into SubscriptionAccessPolicy),
 * not be re-authored here — this module only supplies short labels/tones
 * for badges and headings.
 */

const STATUS_LABELS: Record<string, string> = {
  draft: 'Draft',
  pending_payment: 'Awaiting Payment',
  incomplete: 'Incomplete',
  trialing: 'Trial',
  active: 'Active',
  past_due: 'Past Due',
  unpaid: 'Unpaid',
  paused: 'Paused',
  cancelled: 'Cancelled',
  expired: 'Expired',
  suspended: 'Suspended',
};

const STATUS_TONE: Record<string, Tone> = {
  draft: 'neutral',
  pending_payment: 'warning',
  incomplete: 'warning',
  trialing: 'info',
  active: 'success',
  past_due: 'warning',
  unpaid: 'danger',
  paused: 'neutral',
  cancelled: 'neutral',
  expired: 'neutral',
  suspended: 'danger',
};

export function subscriptionStatusLabel(status: string | null | undefined): string {
  if (!status) return 'No Subscription';
  return STATUS_LABELS[status] ?? status.replace(/_/g, ' ');
}

export function subscriptionStatusTone(status: string | null | undefined): Tone {
  if (!status) return 'neutral';
  return STATUS_TONE[status] ?? 'neutral';
}

const ACCESS_MODE_TONE: Record<AccessMode, Tone> = {
  none: 'neutral',
  trial: 'info',
  full: 'success',
  grace: 'warning',
  restricted: 'danger',
};

export function accessModeTone(mode: AccessMode): Tone {
  return ACCESS_MODE_TONE[mode] ?? 'neutral';
}

const PLAN_CHANGE_STATE_LABELS: Record<string, string> = {
  requested: 'Requested',
  sent: 'Sent to Stripe',
  confirmed: 'Confirmed',
  applied: 'Applied',
  cancelled: 'Cancelled',
  superseded: 'Superseded',
  failed: 'Failed',
};

const PLAN_CHANGE_STATE_TONE: Record<string, Tone> = {
  requested: 'neutral',
  sent: 'info',
  confirmed: 'info',
  applied: 'success',
  cancelled: 'neutral',
  superseded: 'neutral',
  failed: 'danger',
};

export function planChangeStateLabel(state: string): string {
  return PLAN_CHANGE_STATE_LABELS[state] ?? state.replace(/_/g, ' ');
}

export function planChangeStateTone(state: string): Tone {
  return PLAN_CHANGE_STATE_TONE[state] ?? 'neutral';
}

export function planChangeTypeLabel(type: 'upgrade' | 'downgrade'): string {
  return type === 'upgrade' ? 'Upgrade' : 'Downgrade';
}

// SureSign pricing is VAT-exclusive (Slice E2) — the subscription's
// total_amount here is still the pre-tax commercial amount Stripe billed
// against; this suffix communicates that VAT is additional, never a
// VAT-inclusive total SureSign calculated itself.
const BILLING_INTERVAL_LABELS: Record<string, string> = {
  monthly: '/month + VAT',
  annual: '/year + VAT',
};

export function billingIntervalSuffix(interval: string | null | undefined): string {
  if (!interval) return '';
  return BILLING_INTERVAL_LABELS[interval] ?? `/${interval}`;
}
