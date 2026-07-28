import { useMutation, useQuery, useQueryClient, keepPreviousData } from '@tanstack/react-query';
import api from '@/lib/api';
import { OrganizationSubscriptionAdmin, SubscriptionIntelligence, UserInheritedSubscription } from '@/types/subscriptionIntelligence';

/**
 * Organisation-facing Billing data hooks — read-only (Stripe Test Mode
 * Integration checkpoint, Slice B). Every shape here mirrors
 * backend/app/Support/Billing/BillingPresenter.php exactly; never invent a
 * field the backend doesn't return, and never read a `provider_price_id`
 * or any other raw provider field — the backend presenter deliberately
 * never sends one.
 */

/**
 * Phase E5 — a purely client-side "did we just come back from the Stripe
 * Customer Portal" signal. The Portal's return_url is a single fixed,
 * server-configured value (`config('billing.portal_return_url')` —
 * see BillingPortalService) with no per-request query string, so the
 * frontend can't distinguish a genuine Portal return from a plain page
 * visit via the URL alone. Set in sessionStorage immediately before the
 * redirect (BillingPortalCard) and consumed once, on mount, by the
 * Billing page — never sent to the backend, never persisted beyond the
 * single return visit.
 */
export const PORTAL_RETURN_FLAG_KEY = 'ss_billing_portal_return';

export type AccessMode = 'none' | 'trial' | 'full' | 'grace' | 'restricted';

export interface AccessDecision {
  mode: AccessMode;
  subscription_status: string | null;
  reason_code: string;
  reason: string;
}

export interface PlanChangeSummary {
  id: number;
  change_type: 'upgrade' | 'downgrade';
  state: 'requested' | 'sent' | 'confirmed' | 'applied' | 'cancelled' | 'superseded' | 'failed';
  source_plan_code: string | null;
  source_plan_name: string | null;
  target_plan_code: string | null;
  target_plan_name: string | null;
  requested_effective_at: string | null;
  requested_at: string | null;
  sent_at: string | null;
  provider_confirmed_at: string | null;
  applied_at: string | null;
  cancelled_at: string | null;
  superseded_at: string | null;
  failure_message: string | null;
}

export interface SubscriptionSummary {
  id: number;
  internal_reference: string;
  status: string;
  billing_interval: string;
  currency: string;
  unit_amount: number;
  quantity: number;
  subtotal_amount: number;
  tax_amount: number;
  total_amount: number;
  starts_at: string | null;
  trial_ends_at: string | null;
  current_period_starts_at: string | null;
  current_period_ends_at: string | null;
  cancel_at_period_end: boolean;
  cancelled_at: string | null;
  ended_at: string | null;
  grace_period_ends_at: string | null;
  plan_code_snapshot: string | null;
  plan_name_snapshot: string | null;
  plan_code: string | null;
  plan_name: string | null;
  pending_plan_code: string | null;
  pending_plan_name: string | null;
  pending_plan_change: PlanChangeSummary | null;
  /** Only true while cancel_at_period_end is set AND the subscription is still active — nothing left to resume once cancelled/expired. */
  can_resume_cancellation: boolean;
  /** Only populated while status is 'pending_payment' — null for every other status (Phase E4). */
  pending_checkout: PendingCheckoutSummary | null;
  /** Set only once the subscription genuinely went live at least once — null for a Checkout cancelled/expired before payment (Phase E6). */
  activated_at: string | null;
  /**
   * True when this subscription is cancelled/expired but activated_at is
   * null — an abandoned Checkout attempt, not a real commercial
   * cancellation (Phase E6). Never present this the same way as a
   * genuine past-active cancellation: no "Current Subscription" title, no
   * "Cancelled"/"Ended" fields implying a subscription once existed.
   */
  is_abandoned_checkout: boolean;
}

export interface PendingCheckoutSummary {
  plan_code: string;
  plan_name: string;
  billing_interval: 'monthly' | 'annual';
  /** True if a still-open, unexpired Checkout Session can be resumed directly. False means the previous attempt expired — starting again is still safe (the backend transparently cleans up the stale attempt first). */
  is_resumable: boolean;
  expires_at: string | null;
}

export interface BillingCustomerSummary {
  id: number;
  billing_email: string | null;
  billing_name: string | null;
  tax_id: string | null;
  currency: string;
}

export interface InvoiceSummary {
  id: number;
  /** SureSign's own internal correlation reference — NOT the number printed on the Stripe invoice/PDF. */
  invoice_number: string;
  /** Stripe's own invoice number (as shown on the hosted invoice page/PDF) — null for invoices Stripe hasn't numbered. */
  provider_invoice_number: string | null;
  status: string;
  currency: string;
  subtotal_amount: number;
  tax_amount: number;
  discount_amount: number;
  total_amount: number;
  amount_due: number;
  amount_paid: number;
  amount_remaining: number;
  hosted_invoice_url: string | null;
  invoice_pdf_url: string | null;
  period_starts_at: string | null;
  period_ends_at: string | null;
  due_at: string | null;
  paid_at: string | null;
  voided_at: string | null;
}

export interface PaymentSummary {
  id: number;
  internal_reference: string;
  status: string;
  currency: string;
  amount: number;
  amount_refunded: number;
  payment_method_type: string | null;
  failure_message: string | null;
  paid_at: string | null;
  refunded_at: string | null;
}

export interface BillingOverview {
  has_subscription: boolean;
  /** Whether the organisation may start a fresh Checkout right now — use this, not has_subscription, to gate the Plans grid (Phase E6). A cancelled/expired subscription never blocks a new attempt. */
  can_start_new_checkout: boolean;
  subscription: SubscriptionSummary | null;
  access: AccessDecision;
  billing_customer: BillingCustomerSummary | null;
  pending_plan_change: PlanChangeSummary | null;
  latest_invoice: InvoiceSummary | null;
  latest_payment: PaymentSummary | null;
}

export interface PurchasablePlan {
  code: string;
  name: string;
  summary: string | null;
  description: string | null;
  is_popular: boolean;
  is_current: boolean;
  monthly: { currency: string; unit_amount: number } | null;
  annual: { currency: string; unit_amount: number } | null;
  is_self_serve: boolean;
  cta_text: string | null;
  cta_url: string | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export function useBillingOverview() {
  return useQuery<BillingOverview>({
    queryKey: ['billing', 'overview'],
    queryFn: () => api.get('/billing/overview').then(r => r.data),
  });
}

/**
 * Phase G3 — Subscription Intelligence Centre. One composed, read-only
 * payload (`GET /billing/intelligence`) — see
 * `App\Services\Intelligence\SubscriptionIntelligenceService`.
 */
export function useSubscriptionIntelligence() {
  return useQuery<{ data: SubscriptionIntelligence }>({
    queryKey: ['billing', 'intelligence'],
    queryFn: () => api.get('/billing/intelligence').then(r => r.data),
  });
}

/**
 * G4A — Super Admin/Admin Organisation Subscription Administration
 * (read-only). See `App\Http\Controllers\Api\OrganizationController::subscription()`.
 */
export function useOrganizationSubscriptionAdmin(organizationId: string | number | undefined) {
  return useQuery<{ data: OrganizationSubscriptionAdmin }>({
    queryKey: ['admin', 'organization-subscription', organizationId],
    queryFn: () => api.get(`/organizations/${organizationId}/subscription`).then(r => r.data),
    enabled: organizationId !== undefined,
  });
}

export interface AssignSubscriptionPayload {
  plan_code: string;
  billing_interval: 'monthly' | 'annual';
  reason: string;
  confirmed: true;
  starts_at?: string;
  ends_at?: string;
}

/**
 * G4B.2 — Super Admin-only manual/complimentary assignment. Two distinct
 * mutations (never a single `source`-parameterised one) matching the
 * backend's two explicit endpoints — see
 * `App\Http\Controllers\Api\OrganizationSubscriptionAssignmentController`.
 */
export function useAssignManualSubscription(organizationId: string | number) {
  const queryClient = useQueryClient();
  return useMutation<{ data: OrganizationSubscriptionAdmin }, unknown, AssignSubscriptionPayload>({
    mutationFn: (payload) => api.post(`/organizations/${organizationId}/subscriptions/assign-manual`, payload).then(r => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'organization-subscription', organizationId] }),
  });
}

export function useAssignComplimentarySubscription(organizationId: string | number) {
  const queryClient = useQueryClient();
  return useMutation<{ data: OrganizationSubscriptionAdmin }, unknown, AssignSubscriptionPayload>({
    mutationFn: (payload) => api.post(`/organizations/${organizationId}/subscriptions/assign-complimentary`, payload).then(r => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'organization-subscription', organizationId] }),
  });
}

/**
 * G4B.2 — Super Admin-only termination of a manual/complimentary
 * subscription. Never valid for a Stripe-sourced row (the backend refuses
 * with 409 `stripe_termination_not_permitted`).
 */
export function useTerminateOrganizationSubscription(organizationId: string | number) {
  const queryClient = useQueryClient();
  return useMutation<{ data: OrganizationSubscriptionAdmin }, unknown, { subscriptionId: number; reason: string; confirmed: true }>({
    mutationFn: ({ subscriptionId, reason, confirmed }) =>
      api.post(`/organizations/${organizationId}/subscriptions/${subscriptionId}/terminate`, { reason, confirmed }).then(r => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin', 'organization-subscription', organizationId] }),
  });
}

/**
 * G4A — the Users page's inherited-subscription display (fetched lazily
 * only when a user's detail is actually opened — never once per list row).
 * See `App\Http\Controllers\Api\UserController::subscription()`.
 */
export function useUserInheritedSubscription(userId: number | undefined) {
  return useQuery<{ data: UserInheritedSubscription }>({
    queryKey: ['admin', 'user-subscription', userId],
    queryFn: () => api.get(`/users/${userId}/subscription`).then(r => r.data),
    enabled: userId !== undefined,
  });
}

export function useBillingSubscription() {
  return useQuery<{ subscription: SubscriptionSummary | null }>({
    queryKey: ['billing', 'subscription'],
    queryFn: () => api.get('/billing/subscription').then(r => r.data),
  });
}

export function useBillingPlans() {
  return useQuery<{ plans: PurchasablePlan[] }>({
    queryKey: ['billing', 'plans'],
    queryFn: () => api.get('/billing/plans').then(r => r.data),
  });
}

export function usePendingPlanChange() {
  return useQuery<{ pending_plan_change: PlanChangeSummary | null }>({
    queryKey: ['billing', 'pending-plan-change'],
    queryFn: () => api.get('/billing/pending-plan-change').then(r => r.data),
  });
}

// BillingController's index endpoints paginate server-side at a fixed 15
// per page (Laravel's paginate() auto-detects `?page=` from the request,
// but there is no `per_page` override on the backend today — pass only
// `page`, matching the actual contract, rather than a param the backend
// silently ignores).
export function useBillingInvoices(page: number) {
  return useQuery<PaginatedResponse<InvoiceSummary>>({
    queryKey: ['billing', 'invoices', page],
    queryFn: () => api.get('/billing/invoices', { params: { page } }).then(r => r.data),
    placeholderData: keepPreviousData,
  });
}

export function useBillingInvoice(id: number | null) {
  return useQuery<InvoiceSummary>({
    queryKey: ['billing', 'invoice', id],
    queryFn: () => api.get(`/billing/invoices/${id}`).then(r => r.data),
    enabled: id !== null,
  });
}

export function useBillingPayments(page: number) {
  return useQuery<PaginatedResponse<PaymentSummary>>({
    queryKey: ['billing', 'payments', page],
    queryFn: () => api.get('/billing/payments', { params: { page } }).then(r => r.data),
    placeholderData: keepPreviousData,
  });
}

/** Billing amounts are integer MINOR units (Stripe convention) — pricing_plans' own decimal major-unit fields are unrelated. */
export function minorToMajor(amount: number): number {
  return amount / 100;
}

export interface CheckoutSessionResponse {
  id: number;
  internal_reference: string;
  status: string;
  billing_interval: string;
  currency: string;
  amount: number;
  checkout_url: string | null;
  expires_at: string | null;
  completed_at: string | null;
}

/**
 * Starts (or safely reuses) a first-subscription Checkout attempt — the
 * ONLY mutating Billing call the frontend makes. Submits just the local
 * plan code and billing interval; the backend resolves everything else
 * (organisation, currency, Stripe Price, success/cancel URLs) server-side.
 * Never pass a provider Price ID or a return URL here — there is nowhere
 * in this payload for one.
 */
export function useCreateCheckout() {
  return useMutation<CheckoutSessionResponse, unknown, { plan_code: string; billing_interval: 'monthly' | 'annual' }>({
    mutationFn: (payload) => api.post('/billing/checkout', payload).then(r => r.data),
  });
}

/**
 * Phase E4 — explicitly abandons an unfinished Checkout (customer closed
 * the Stripe tab and does not want to continue). No body: the
 * organisation's current subscription is resolved server-side. Only ever
 * valid while that subscription is still 'pending_payment'.
 */
export function useCancelPendingCheckout() {
  return useMutation<SubscriptionSummary, unknown, void>({
    mutationFn: () => api.post('/billing/checkout/cancel-pending').then(r => r.data),
  });
}

/**
 * Requests an upgrade or (period-end) downgrade for an organisation that
 * already has an active subscription. The backend alone decides which one
 * this is (App\Support\Billing\PlanChangeClassifier, ranked by the plan's
 * own `order`) — never trust a frontend label for the commercial
 * decision. Submits only the target plan code and billing interval.
 */
export function useRequestPlanChange() {
  return useMutation<PlanChangeSummary, unknown, { plan_code: string; billing_interval: 'monthly' | 'annual' }>({
    mutationFn: (payload) => api.post('/billing/plan-change', payload).then(r => r.data),
  });
}

/** Cancels a still-pending (not yet sent to Stripe) upgrade or downgrade. */
export function useCancelPlanChange() {
  return useMutation<PlanChangeSummary, unknown, number>({
    mutationFn: (planChangeId) => api.post(`/billing/plan-change/${planChangeId}/cancel`).then(r => r.data),
  });
}

/**
 * First-party subscription cancellation — always scheduled for the
 * current billing period's end, never immediate. No body is submitted;
 * the backend derives everything (organisation, subscription, effective
 * date) server-side.
 */
export function useCancelSubscription() {
  return useMutation<SubscriptionSummary, unknown, void>({
    mutationFn: () => api.post('/billing/subscription/cancel').then(r => r.data),
  });
}

/** Undoes a still-reversible pending cancellation. */
export function useResumeSubscription() {
  return useMutation<SubscriptionSummary, unknown, void>({
    mutationFn: () => api.post('/billing/subscription/resume').then(r => r.data),
  });
}

/**
 * Creates a restricted Stripe Customer Portal session (Slice E2) — payment
 * methods, billing details and invoice history only. No body is submitted;
 * the backend resolves the Organisation's billing customer and the
 * restricted Portal configuration server-side. Never pass a Stripe
 * Customer ID, Portal Configuration ID, or return URL here — there is
 * nowhere in this payload for one, and the backend would reject it.
 */
export function useCreatePortalSession() {
  return useMutation<{ url: string }, unknown, void>({
    mutationFn: () => api.post('/billing/portal').then(r => r.data),
  });
}
