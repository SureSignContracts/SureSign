import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

interface FridayPackEntitlementResponse {
  entitled: boolean;
  is_platform_operator: boolean;
}

/**
 * Friday Pack Plan Entitlement Enforcement — Entitlement UX phase.
 * Reads the authoritative commercial entitlement state from the backend
 * (`GET /projects/{id}/friday-packs/entitlement` → `FeatureGate::allows()`)
 * — never derived from a plan name, subscription status, or any other
 * client-side guess. A Super Admin/Admin always resolves `entitled: true`
 * (the endpoint itself mirrors `EnsureFeatureIsEntitled`'s own bypass), so
 * a platform operator never sees an upgrade warning for a feature they
 * may legitimately operate.
 *
 * `entitled` defaults to `true` while the query is loading/errored — this
 * is deliberate: it means a mutation control is never hidden purely
 * because this fast, secondary check hasn't resolved yet, and the
 * backend's own 403 remains the real enforcement boundary regardless (see
 * this hook's callers — every one of them still relies on the existing
 * server-side gate as the actual protection).
 */
export function useFridayPackEntitlement(projectId: string | undefined) {
  const { data, isLoading } = useQuery<FridayPackEntitlementResponse>({
    queryKey: ['friday-pack-entitlement', projectId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/entitlement`).then(r => r.data),
    enabled: !!projectId,
    staleTime: 60_000,
  });

  return {
    entitled: data?.entitled ?? true,
    isPlatformOperator: data?.is_platform_operator ?? false,
    isLoading,
  };
}
