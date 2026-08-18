import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import type { FeatureAvailabilityEntry, FeatureAvailabilityMap, FeatureAvailabilityStatus } from '@/types/featureAvailability';

// SureSign Feature Availability — the one canonical frontend read path.
// Mirrors useSiteSettings.ts's shape exactly: one lightweight authenticated
// request, cached, fails safe to an empty map (i.e. every feature Active)
// on any network/HTTP/parsing failure — a broken availability lookup must
// never make the application unusable. Never scatter
// `features[key]?.status === ...` across pages — use the helpers below.

const EMPTY_MAP: FeatureAvailabilityMap = {};
const ACTIVE_ENTRY: FeatureAvailabilityEntry = { status: 'active', message: null, available_at: null };

function isValidStatus(status: unknown): status is FeatureAvailabilityStatus {
  return status === 'active' || status === 'maintenance' || status === 'coming_soon';
}

/** Normalizes one raw response entry — an unrecognised/malformed status
 *  (or a malformed entry shape entirely) resolves Active, never a guess. */
function normalizeEntry(raw: unknown): FeatureAvailabilityEntry {
  if (!raw || typeof raw !== 'object') return ACTIVE_ENTRY;
  const entry = raw as Partial<FeatureAvailabilityEntry>;
  if (!isValidStatus(entry.status)) return ACTIVE_ENTRY;
  return {
    status: entry.status,
    message: typeof entry.message === 'string' ? entry.message : null,
    available_at: typeof entry.available_at === 'string' ? entry.available_at : null,
  };
}

function normalizeMap(raw: unknown): FeatureAvailabilityMap {
  if (!raw || typeof raw !== 'object') return EMPTY_MAP;
  const result: FeatureAvailabilityMap = {};
  for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
    const entry = normalizeEntry(value);
    // An entry that normalizes to Active is simply omitted — a missing key
    // already means Active, so there's no reason to keep it around.
    if (entry.status !== 'active') result[key] = entry;
  }
  return result;
}

export function useFeatureAvailability() {
  const query = useQuery<FeatureAvailabilityMap>({
    queryKey: ['feature-availability'],
    queryFn: () =>
      api.get('/feature-availability')
        .then(r => normalizeMap(r.data?.features))
        .catch(() => EMPTY_MAP),
    staleTime: 5 * 60 * 1000,
    placeholderData: EMPTY_MAP,
    // The global QueryClient default (retry: 1) already avoids aggressive
    // retry loops — no override needed here.
  });

  const features = query.data ?? EMPTY_MAP;

  const entryFor = (featureKey: string): FeatureAvailabilityEntry => features[featureKey] ?? ACTIVE_ENTRY;
  const statusFor = (featureKey: string): FeatureAvailabilityStatus => entryFor(featureKey).status;

  return {
    ...query,
    features,
    entryFor,
    statusFor,
    isActive: (featureKey: string) => statusFor(featureKey) === 'active',
    isMaintenance: (featureKey: string) => statusFor(featureKey) === 'maintenance',
    isComingSoon: (featureKey: string) => statusFor(featureKey) === 'coming_soon',
    // isSettingsReady-style flag (see useSiteSettings) — false only while
    // the very first fetch is genuinely in flight, so a nav badge/gate can
    // show a neutral/loading state instead of flashing "unavailable" then
    // "available". Once resolved (success OR the queryFn's own internal
    // catch), this is always true — a failed fetch still fails open to
    // Active immediately, never leaving the UI stuck loading.
    isAvailabilityReady: !query.isLoading && !query.isPlaceholderData,
  };
}
