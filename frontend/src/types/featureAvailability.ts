// SureSign Feature Availability — frontend types matching the Phase A
// backend contract exactly (App\Support\FeatureAvailability\
// FeatureAvailabilityStatus / FeatureAvailabilityController /
// FeatureAvailabilityAdminController). The backend registry remains
// authoritative — this file represents shapes returned over the wire, it
// does not re-declare which features exist.

export type FeatureAvailabilityStatus = 'active' | 'maintenance' | 'coming_soon';

/** GET /feature-availability — one entry per non-Active override. A missing
 *  key means Active; never invent an entry for a key not present. */
export interface FeatureAvailabilityEntry {
  status: FeatureAvailabilityStatus;
  message: string | null;
  available_at: string | null; // UTC ISO 8601, or null
}

export type FeatureAvailabilityMap = Record<string, FeatureAvailabilityEntry>;

/** GET /admin/feature-availability — the full registry combined with
 *  effective state, Super Admin only. Never includes audit/actor detail
 *  beyond `updated_by` (a bare user id — the management screen's own
 *  concern, never shown to a customer). */
export interface FeatureAvailabilityAdminEntry {
  label: string;
  description: string;
  category: string;
  frontend_routes: string[];
  maintenance_supported: boolean;
  coming_soon_supported: boolean;
  status: FeatureAvailabilityStatus;
  message: string | null;
  available_at: string | null;
  updated_by: number | null;
  updated_at: string | null;
}

export type FeatureAvailabilityAdminMap = Record<string, FeatureAvailabilityAdminEntry>;

/** PUT /admin/feature-availability/{feature_key} request body — mirrors
 *  UpdateFeatureAvailabilityRequest exactly. */
export interface UpdateFeatureAvailabilityPayload {
  status: FeatureAvailabilityStatus;
  message: string | null;
  available_at: string | null; // UTC ISO 8601, or null
  reason: string;
  confirmed: true;
}
