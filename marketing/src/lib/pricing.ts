// This fetch runs server-side (a Next.js server component with a
// `revalidate` cache directive). Inside a container, `localhost` means
// the container itself, not the host machine, so it must use the
// Docker-internal service URL, never the browser-facing
// NEXT_PUBLIC_API_URL. See docker-compose.dev.yml's BACKEND_INTERNAL_URL
// (same pattern the main `frontend` app already uses) for the local-dev
// value; production sets it to the same internal address the container
// orchestrator resolves.
const API_BASE = process.env.BACKEND_INTERNAL_URL || process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

export type PlanFeatureStatus = 'included' | 'not_included' | 'limited' | 'custom';

export interface PricingPlanFeatureCell {
  status: PlanFeatureStatus;
  value_text: string | null;
  icon_override: string | null;
}

export interface PricingPlan {
  slug: string;
  name: string;
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
  is_popular: boolean;
  badge_text: string | null;
  badge_color: string | null;
  accent_color: string | null;
  background_style: string | null;
  icon: string | null;
  custom_label: string | null;
  features: Record<string, PricingPlanFeatureCell>;
}

export interface PricingFeature {
  id: number;
  name: string;
}

export interface PricingFeatureSection {
  name: string;
  features: PricingFeature[];
}

export interface PricingIncludedItem {
  text: string;
  icon: string | null;
}

export interface PricingFaq {
  question: string;
  answer: string;
}

export interface PricingSettings {
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
}

export interface PricingData {
  published: boolean;
  settings: PricingSettings;
  plans: PricingPlan[];
  feature_sections: PricingFeatureSection[];
  included_items: PricingIncludedItem[];
  faqs: PricingFaq[];
}

/**
 * Fetches the public Pricing payload server-side. Returns null on any
 * failure (network error, non-2xx, malformed body) or when the page hasn't
 * been published. The page component renders a graceful fallback in that
 * case instead of throwing or rendering a blank/broken page.
 */
export async function getPricingData(): Promise<PricingData | null> {
  try {
    const res = await fetch(`${API_BASE}/pricing`, { next: { revalidate: 300 } });
    if (!res.ok) return null;

    const body = await res.json();
    const data = body?.data as PricingData | undefined;
    if (!data || !data.published || !Array.isArray(data.plans) || data.plans.length === 0) {
      return null;
    }

    return data;
  } catch {
    return null;
  }
}
