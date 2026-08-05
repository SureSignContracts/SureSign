// Organisation URL Branding, Phase 5 (Stage 3) — fetches the entirely
// server-side wrong-workspace decision from GET /auth/workspace-context.
// This file NEVER compares organisation IDs itself — it only forwards the
// current hostname and renders whichever workspace_state the backend
// returns. See backend/app/Services/Organizations/
// AuthenticatedWorkspaceContextService.php, the single source of truth.

import api from '@/lib/api';

export type WorkspaceState =
  | 'platform_host'
  | 'matching_workspace'
  | 'wrong_workspace'
  | 'platform_staff_on_customer_host'
  | 'inactive_workspace'
  | 'host_not_found'
  | 'resolver_unavailable';

export interface WorkspaceContextResult {
  workspace_state: WorkspaceState;
  authoritative_workspace_url: string | null;
  may_continue: boolean;
  organisation_name: string | null;
}

/**
 * `resolver_unavailable` is produced entirely CLIENT-SIDE here (a network
 * failure/non-2xx calling the endpoint) — the backend service itself never
 * returns this state; if it can't resolve, the whole request fails and
 * this catch block is what actually classifies that as an outage rather
 * than any negative workspace decision. Mirrors lib/hostContext.ts's own
 * outage-safety contract for the pre-auth case.
 */
export async function fetchWorkspaceContext(): Promise<WorkspaceContextResult> {
  const host = typeof window !== 'undefined' ? window.location.hostname : '';

  try {
    const { data } = await api.get<WorkspaceContextResult>('/auth/workspace-context', {
      headers: host ? { 'X-Suresign-Org-Host': host } : {},
    });
    return data;
  } catch {
    // Fail OPEN, not closed — this guard is a presentation-layer safety
    // check, never the actual authorization boundary (every controller
    // still independently scopes data to the authenticated user's real
    // organization_id regardless of host). Per Stage 3's own instruction:
    // a temporary outage must never permanently deny a valid workspace.
    return {
      workspace_state: 'resolver_unavailable',
      authoritative_workspace_url: null,
      may_continue: true,
      organisation_name: null,
    };
  }
}
