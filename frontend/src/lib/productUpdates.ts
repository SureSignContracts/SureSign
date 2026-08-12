import { Sparkles, TrendingUp, AlertCircle, Lightbulb, type LucideIcon } from 'lucide-react';

export type ProductUpdateCategory = 'new_feature' | 'improvement' | 'important_update' | 'tip';
export type ProductUpdateStatus = 'draft' | 'published' | 'archived';
export type ProductUpdateAudience = 'all' | 'client' | 'operator';

export const CATEGORY_LABELS: Record<ProductUpdateCategory, string> = {
  new_feature: 'New Feature',
  improvement: 'Improvement',
  important_update: 'Important Update',
  tip: 'Tip',
};

export const CATEGORY_ICONS: Record<ProductUpdateCategory, LucideIcon> = {
  new_feature: Sparkles,
  improvement: TrendingUp,
  important_update: AlertCircle,
  tip: Lightbulb,
};

// Same restrained palette convention as SEVERITY_STYLES (lib/announcements.ts)
// — a product update should read as good news/information, never an alert.
export const CATEGORY_STYLES: Record<ProductUpdateCategory, { bg: string; text: string }> = {
  new_feature:       { bg: 'rgba(167,139,250,0.14)', text: '#a78bfa' },
  improvement:       { bg: 'rgba(74,222,128,0.14)',  text: '#4ade80' },
  important_update:  { bg: 'rgba(234,179,8,0.14)',   text: '#facc15' },
  tip:               { bg: 'rgba(96,165,250,0.14)',  text: '#60a5fa' },
};

export const AUDIENCE_LABELS: Record<ProductUpdateAudience, string> = {
  all: 'All Users',
  client: 'Client Users',
  operator: 'Platform Operators',
};

/** Customer-facing shape — GET /product-updates/pending and /history. */
export interface ProductUpdate {
  id: number;
  title: string;
  summary: string;
  content: string;
  category: ProductUpdateCategory;
  cta_label: string | null;
  cta_url: string | null;
  published_at: string | null;
}

/** Super Admin/Admin management shape — GET/POST/PUT /admin/product-updates. */
export interface AdminProductUpdate extends ProductUpdate {
  audience: ProductUpdateAudience;
  status: ProductUpdateStatus;
  created_by: string | null;
  updated_by: string | null;
  created_at: string;
  updated_at: string;
}

/**
 * Validates a CTA URL exactly as the backend does (App\Http\Controllers\Api\
 * ProductUpdateController::validated()) — an internal path (never a
 * protocol-relative "//host") or an https:// URL. Used only to disable the
 * "navigate" action client-side and give inline editor feedback; the
 * backend validation is the real enforcement boundary.
 */
export function isSafeProductUpdateUrl(url: string): boolean {
  return /^(\/(?!\/)[a-zA-Z0-9\-/_?=&%.]*|https:\/\/[a-zA-Z0-9\-.]+(\/[a-zA-Z0-9\-/_?=&%.]*)?)$/.test(url);
}
