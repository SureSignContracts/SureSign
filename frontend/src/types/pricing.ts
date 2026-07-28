export type PricingPlanStatus = 'draft' | 'active' | 'archived';

export type PlanFeatureStatus = 'included' | 'not_included' | 'limited' | 'custom';

export const BADGE_COLORS = ['gold', 'accent', 'success', 'neutral', 'danger'] as const;
export const ACCENT_COLORS = ['gold', 'accent', 'neutral', 'success'] as const;
export const BACKGROUND_STYLES = ['solid', 'surface', 'gradient', 'elevated'] as const;
export const PLAN_ICONS = [
  'zap', 'shield', 'star', 'rocket', 'building', 'users',
  'layers', 'check-circle', 'crown', 'sparkles', 'briefcase', 'award',
] as const;

export interface PricingPlanProviderPrice {
  id: number;
  provider: string;
  billing_interval: string;
  currency: string;
  provider_product_id: string;
  provider_price_id: string;
  livemode: boolean;
  unit_amount: number;
  is_active: boolean;
}

export interface PricingPlan {
  id: number;
  code: string;
  slug: string;
  name: string;
  order: number;
  monthly_price: string | null;
  annual_price: string | null;
  currency: string;
  price_prefix: string | null;
  price_suffix: string | null;
  description: string | null;
  summary: string | null;
  cta_text: string | null;
  cta_url: string | null;
  cta_new_tab: boolean;
  is_visible: boolean;
  is_popular: boolean;
  badge_text: string | null;
  badge_color: string | null;
  accent_color: string | null;
  background_style: string | null;
  icon: string | null;
  custom_label: string | null;
  status: PricingPlanStatus;
  published_at: string | null;
  provider_prices?: PricingPlanProviderPrice[];
}

/** Entitlement Specification v1's three value types this UI actually needs to render. */
export type EntitlementValueType = 'boolean' | 'integer' | 'decimal' | 'string' | 'enum';

/**
 * Phase G2 — one row of the entitlement editor, dynamically generated
 * server-side from the Feature registry (App\Support\Entitlements\Feature)
 * plus this plan's pricing_plan_entitlements row. Metadata fields
 * (everything except is_applicable/is_unlimited/value) are read-only here —
 * they're owned by the backend Feature registry, never edited or
 * duplicated client-side.
 */
export type EntitlementCategoryKey = 'usage' | 'feature' | 'reserved';

export interface PlanEntitlementRow {
  feature_key: string;
  display_name: string;
  description: string;
  category: EntitlementCategoryKey;
  value_type: EntitlementValueType;
  unit: string | null;
  enforcement_level: string | null;
  customer_visible: boolean;
  currently_sold: boolean;
  overrideable: boolean;
  /** Dormant/reserved key — never has a row, never editable, never sold or customer-visible. */
  is_reserved: boolean;
  is_applicable: boolean;
  is_unlimited: boolean;
  value: boolean | number | string | null;
}

/**
 * Stage X — section metadata for the entitlement editor, generated
 * server-side from `App\Support\Entitlements\EntitlementCategory`. The
 * frontend groups `PlanEntitlementRow`s by `category` using this list's
 * order and labels — it never hardcodes a category name or its section
 * title, so a future approved category appears automatically.
 */
export interface EntitlementCategoryMeta {
  key: EntitlementCategoryKey;
  label: string;
  description: string;
}

export interface PlanEntitlementsPayload {
  categories: EntitlementCategoryMeta[];
  entitlements: PlanEntitlementRow[];
}

export interface PricingFeatureSection {
  id: number;
  name: string;
  order: number;
  is_visible: boolean;
  features?: PricingFeature[];
}

export interface PricingFeature {
  id: number;
  section_id: number;
  name: string;
  order: number;
  is_visible: boolean;
}

export interface PricingPlanFeatureRow {
  id: number;
  plan_id: number;
  feature_id: number;
  status: PlanFeatureStatus;
  value_text: string | null;
  icon_override: string | null;
}

export interface PricingFaq {
  id: number;
  question: string;
  answer: string;
  order: number;
  is_enabled: boolean;
}

export interface PricingIncludedItem {
  id: number;
  text: string;
  icon: string | null;
  order: number;
  is_visible: boolean;
}

export interface PricingSettings {
  id: number;
  hero_title: string | null;
  hero_subtitle: string | null;
  section_title: string | null;
  monthly_billing_enabled: boolean;
  annual_billing_enabled: boolean;
  discount_label: string | null;
  everything_included_title: string | null;
  everything_included_subtitle: string | null;
  final_cta_title: string | null;
  final_cta_subtitle: string | null;
  primary_cta_text: string | null;
  primary_cta_url: string | null;
  primary_cta_new_tab: boolean;
  secondary_cta_text: string | null;
  secondary_cta_url: string | null;
  secondary_cta_new_tab: boolean;
  published: boolean;
}
