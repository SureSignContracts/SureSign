/**
 * Phase G3 — Subscription Intelligence Centre. Every shape here mirrors
 * `App\Services\Intelligence\SubscriptionIntelligenceService::intelligenceFor()`
 * exactly (backend/app/Services/Intelligence/) — never invent a field the
 * backend doesn't return.
 */

export type EntitlementHealthStatusKey = 'unknown' | 'healthy' | 'warning' | 'critical' | 'exceeded';

export interface UsageMetric {
  feature_key: string;
  display_name: string;
  unit: string | null;
  value_type: 'boolean' | 'integer' | 'decimal' | 'string' | 'enum';
  used: number | null;
  limit: number | null;
  is_unlimited: boolean;
  percent_used: number | null;
  status: EntitlementHealthStatusKey;
}

export interface AiUsageMetric extends UsageMetric {
  next_reset_at: string;
}

export interface TrialCard {
  is_active: true;
  starts_at: string;
  ends_at: string;
  days_remaining: number;
  percent_elapsed: number;
}

export interface HealthItem {
  key: string;
  label: string;
  status: EntitlementHealthStatusKey;
  detail: string;
}

export interface SubscriptionHealth {
  items: HealthItem[];
  overall: EntitlementHealthStatusKey;
}

export interface SubscriptionRecommendation {
  key: string;
  title: string;
  detail: string;
  severity: 'low' | 'medium' | 'high';
}

export interface TimelineEntry {
  action: string;
  description: string;
  occurred_at: string;
}

export interface StripeIntelligenceInfo {
  customer_connected: boolean;
  portal_available: boolean;
  payment_method_type: string | null;
  invoice_count: number;
  current_period_ends_at: string | null;
  next_renewal_at: string | null;
}

/**
 * The subset of BillingOverviewService::subscriptionDetail()'s shape that
 * G4A's admin-facing views read. Kept separate from `SubscriptionSummary`
 * (useBilling.ts) to avoid a circular import; cast `SubscriptionIntelligence.subscription`
 * to this (via `unknown`) rather than reaching for `any`.
 */
export interface SubscriptionSummaryView {
  id: number;
  plan_name: string | null;
  plan_name_snapshot: string | null;
  status: string | null;
  billing_interval: string | null;
  current_period_ends_at: string | null;
  trial_ends_at: string | null;
  cancel_at_period_end: boolean;
  cancelled_at: string | null;
  access: { mode: string; reason: string };
}

export interface SubscriptionIntelligence {
  organization: { id: number; name: string };
  // Matches BillingOverviewService::subscriptionDetail()'s shape — reuse
  // SubscriptionSummary from useBilling.ts if importing both; kept as
  // unknown here to avoid a circular/duplicated type definition. Cast to
  // SubscriptionSummaryView above where fields need to be read.
  subscription: Record<string, unknown> | null;
  trial: TrialCard | null;
  usage: UsageMetric[];
  storage: UsageMetric | null;
  ai: AiUsageMetric | null;
  health: SubscriptionHealth;
  recommendations: SubscriptionRecommendation[];
  timeline: TimelineEntry[];
  stripe: StripeIntelligenceInfo;
}

/**
 * G4A — Super Admin/Admin Organisation Subscription Administration.
 * Mirrors `App\Services\Admin\OrganizationSubscriptionAdminService::forOrganization()`
 * exactly: every field of `SubscriptionIntelligence` plus operator-only
 * diagnostic fields never shown on the customer-facing Billing page.
 */
export interface OrganizationDetail {
  id: number;
  name: string;
  is_active: boolean;
  contact_name: string | null;
  email: string | null;
  created_at: string;
}

export type SnapshotIntegrityClassification =
  | 'legacy_pre_snapshot'
  | 'expected_snapshot_present'
  | 'expected_snapshot_missing_recoverable'
  | 'expected_snapshot_missing_ambiguous'
  | 'not_applicable';

export interface SnapshotSummary {
  exists: boolean;
  source_transition: string | null;
  lifecycle_reason: string | null;
  effective_from: string | null;
  plan_code_snapshot: string | null;
  integrity_classification: SnapshotIntegrityClassification;
  /** No snapshot, but this is the documented pre-snapshot/not-applicable compatibility case — not a problem. */
  is_legacy_fallback: boolean;
  /** No snapshot, and this subscription genuinely should have one — worth an operator's attention. */
  requires_attention: boolean;
}

export interface SubscriptionActivityEntry {
  action: string;
  description: string;
  occurred_at: string;
}

/**
 * G4B.1 — commercial origin. `null` only for a not-yet-backfilled legacy
 * row in this environment; never render that as "Manual"/"Complimentary".
 */
export type SubscriptionSourceValue = 'stripe' | 'manual' | 'complimentary';

/** G4B.2 — the plan selector's source list; assignability is commercial (status=active), never marketing visibility. */
export interface AssignablePlan {
  code: string;
  name: string;
}

export interface OrganizationSubscriptionAdmin extends SubscriptionIntelligence {
  organization_detail: OrganizationDetail;
  subscription_source: SubscriptionSourceValue | null;
  snapshot: SnapshotSummary | null;
  recent_activity: SubscriptionActivityEntry[];
  /** G4B.2 — true iff SubscriptionLifecycleService::hasConflictingSubscription() is false for this organisation. */
  can_assign_subscription: boolean;
  /** G4B.2 — true only for a non-Stripe, non-terminal current subscription. */
  can_terminate_subscription: boolean;
  assignable_plans: AssignablePlan[];
}

/**
 * G4A — the Users page's inherited-subscription display (User detail /
 * "Manage User"). Mirrors `UserController::subscription()` exactly.
 */
export type UserInheritedSubscription =
  | { is_platform_operator: true }
  | ({ is_platform_operator: false } & SubscriptionIntelligence);
