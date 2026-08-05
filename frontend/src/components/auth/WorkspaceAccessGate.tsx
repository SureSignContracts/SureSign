'use client';

import { ShieldAlert } from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import type { WorkspaceContextResult } from '@/lib/workspaceContext';

// Organisation URL Branding, Phase 5 (Stage 3) — rendered instead of the
// normal app shell whenever GET /auth/workspace-context returns a blocking
// workspace_state (wrong_workspace, platform_staff_on_customer_host,
// inactive_workspace, host_not_found). Deliberately never renders
// AppSidebar/{children} in these states — no organisation-scoped query
// (branding, projects, notifications) has fired yet at the point this
// gate is shown, since AppLayout resolves workspace context BEFORE
// rendering children at all.
//
// Never reveals which organisation actually owns the current hostname,
// internal IDs, subscription state, or membership details — only the
// backend-supplied authoritative_workspace_url (their OWN correct
// destination, never the mismatched host's own URL) and organisation_name
// (only ever the AUTHENTICATED user's own organisation, on the
// matching-organisation display path elsewhere — never used here).
export default function WorkspaceAccessGate({ context }: { context: WorkspaceContextResult }) {
  const logout = useAuthStore((s) => s.logout);

  const copy: Record<string, { title: string; body: string }> = {
    wrong_workspace: {
      title: 'This account does not have access to this workspace.',
      body: "You're signed in, but this account belongs to a different SureSign workspace.",
    },
    platform_staff_on_customer_host: {
      title: 'Platform administration is not available here.',
      body: 'Please continue to the platform application to manage this account.',
    },
    inactive_workspace: {
      title: 'This workspace is currently unavailable.',
      body: 'Contact your administrator if you believe this is a mistake.',
    },
    host_not_found: {
      title: 'Workspace not found.',
      body: "This SureSign workspace address doesn't exist or is no longer available.",
    },
    // Stage 3 policy revisit — a resolver outage on a BRANDED/custom host
    // must never fail open into rendering a workspace under an unverified
    // hostname (AppLayout only routes this state here when NOT on the
    // fixed platform host). Deliberately distinct wording from
    // inactive_workspace/host_not_found — this is temporary, not a
    // permanent denial.
    resolver_unavailable: {
      title: 'Workspace temporarily unavailable.',
      body: "We couldn't verify this workspace just now. Please try again shortly.",
    },
  };

  const { title, body } = copy[context.workspace_state] ?? copy.host_not_found;
  const isOutage = context.workspace_state === 'resolver_unavailable';

  async function handleSignOut() {
    await logout();
    window.location.href = '/login';
  }

  return (
    <div className="min-h-dvh flex items-center justify-center px-4" style={{ backgroundColor: 'var(--bg-base)' }}>
      <div
        className="w-full max-w-sm rounded-2xl p-7 ss-animate-in text-center"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        <div
          className="mx-auto w-11 h-11 rounded-xl flex items-center justify-center mb-4"
          style={{ backgroundColor: 'rgba(220,38,38,0.1)' }}
        >
          <ShieldAlert size={19} style={{ color: '#dc2626' }} />
        </div>
        <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h1>
        <p className="mt-1.5 text-sm" style={{ color: 'var(--text-muted)' }}>{body}</p>

        <div className="mt-6 space-y-2.5">
          {isOutage ? (
            <button
              type="button"
              onClick={() => window.location.reload()}
              className="block w-full rounded-full py-2.5 text-sm font-medium text-white"
              style={{ backgroundColor: 'var(--gold)' }}
            >
              Try again
            </button>
          ) : (
            context.authoritative_workspace_url && (
              <a
                href={context.authoritative_workspace_url}
                className="block w-full rounded-full py-2.5 text-sm font-medium text-white"
                style={{ backgroundColor: 'var(--gold)' }}
              >
                Continue to your workspace
              </a>
            )
          )}
          <button
            type="button"
            onClick={handleSignOut}
            className="block w-full rounded-full py-2.5 text-sm font-medium"
            style={{ border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          >
            Sign out
          </button>
        </div>
      </div>
    </div>
  );
}
