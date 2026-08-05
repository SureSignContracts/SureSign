'use client';

import { useState } from 'react';
import { X } from 'lucide-react';

const DISMISS_KEY = 'suresign_dismissed_branded_workspace_banner';

// Organisation URL Branding, Phase 5 (Stage 3 policy revisit, Part E) —
// the approved resolution: NEVER auto-redirect a customer off
// app.suresigncontracts.app, NEVER force a second login. A restrained,
// dismissible recommendation only. `authoritativeUrl` is always the
// backend-supplied AuthenticatedWorkspaceContextService value — never
// constructed here. Dismissal is remembered in localStorage only (no new
// database preference) — deliberately per-browser/per-origin, matching
// this phase's own approved per-origin-session model, not synced
// anywhere.
export default function BrandedWorkspaceBanner({ authoritativeUrl }: { authoritativeUrl: string }) {
  const [dismissed, setDismissed] = useState(
    () => typeof window !== 'undefined' && localStorage.getItem(DISMISS_KEY) === '1'
  );

  if (dismissed) return null;

  function dismiss() {
    localStorage.setItem(DISMISS_KEY, '1');
    setDismissed(true);
  }

  return (
    <div
      className="flex items-center justify-between gap-4 px-4 py-2.5 text-sm"
      style={{ backgroundColor: 'var(--gold-15)', borderBottom: '1px solid var(--border)', color: 'var(--text-primary)' }}
    >
      <div className="min-w-0">
        <span className="font-medium">Your organisation has a branded workspace.</span>{' '}
        <span style={{ color: 'var(--text-muted)' }}>
          Signing in there may require a fresh sign-in, since sessions are specific to each address.
        </span>
      </div>
      <div className="flex items-center gap-2 flex-shrink-0">
        <a
          href={authoritativeUrl}
          className="rounded-full px-3.5 py-1.5 text-xs font-medium text-white whitespace-nowrap"
          style={{ backgroundColor: 'var(--gold)' }}
        >
          Open branded workspace
        </a>
        <button
          type="button"
          onClick={dismiss}
          className="rounded-full px-3.5 py-1.5 text-xs font-medium whitespace-nowrap"
          style={{ border: '1px solid var(--border)' }}
        >
          Continue here
        </button>
        <button
          type="button"
          onClick={dismiss}
          aria-label="Dismiss"
          className="p-1 rounded-full hover:opacity-70"
          style={{ color: 'var(--text-muted)' }}
        >
          <X size={14} />
        </button>
      </div>
    </div>
  );
}
