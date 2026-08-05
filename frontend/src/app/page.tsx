'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { isCurrentHostPlatform, currentHostname, resolveHostContext } from '@/lib/hostContext';
import WorkspaceAccessGate from '@/components/auth/WorkspaceAccessGate';
import type { WorkspaceContextResult } from '@/lib/workspaceContext';

// Organisation URL Branding, Phase 5 (Stage 5 pre-cutover) — the frontend's
// root route. Previously unhandled entirely (no app/page.tsx existed),
// falling through to Next.js's generic, host-agnostic not-found.tsx.
//
// isCurrentHostPlatform() is checked FIRST as the cheap fixed-host case —
// mirrors the exact pattern already used in login/page.tsx and
// AppLayout. resolveHostContext() (the one pre-auth host resolver — see
// lib/hostContext.ts) is only called for anything else. No new resolver,
// no server-side host parsing: this is a client component using the same
// "brief loading state, then decide" convention as login/page.tsx.
//
// not_found/unavailable states are rendered via WorkspaceAccessGate,
// reusing its existing host_not_found/resolver_unavailable copy rather
// than a third copy of nearly-identical messaging — constructed as a
// plain WorkspaceContextResult-shaped object rather than the real
// authenticated /auth/workspace-context response, since this route runs
// entirely pre-auth and never calls that endpoint.
type RootState = { phase: 'loading' } | { phase: 'blocked'; context: WorkspaceContextResult };

export default function RootPage() {
  const router = useRouter();
  const [state, setState] = useState<RootState>({ phase: 'loading' });

  useEffect(() => {
    let cancelled = false;

    const host = currentHostname() ?? '';

    if (isCurrentHostPlatform(host)) {
      // Fixed platform app host — the existing authoritative entry point.
      router.replace('/login');
      return;
    }

    resolveHostContext().then((ctx) => {
      if (cancelled) return;

      if (ctx.type === 'organisation') {
        // Same hostname, /login — never construct a different host here.
        router.replace('/login');
        return;
      }

      if (ctx.type === 'historical_redirect' && ctx.redirect_base_url) {
        // Backend-supplied destination only — never built client-side.
        window.location.replace(`${ctx.redirect_base_url}/login`);
        return;
      }

      if (ctx.type === 'not_found') {
        setState({
          phase: 'blocked',
          context: { workspace_state: 'host_not_found', authoritative_workspace_url: null, may_continue: false, organisation_name: null },
        });
        return;
      }

      if (ctx.type === 'unavailable') {
        // Non-platform host, resolver unavailable — never fail open into
        // a workspace under an unverified hostname, and never route
        // through marketing. Neutral temporary state only.
        setState({
          phase: 'blocked',
          context: { workspace_state: 'resolver_unavailable', authoritative_workspace_url: null, may_continue: false, organisation_name: null },
        });
        return;
      }

      // 'platform' shouldn't occur here (already handled above via
      // isCurrentHostPlatform), but fall back safely to the same
      // authoritative entry point rather than rendering nothing.
      router.replace('/login');
    });

    return () => {
      cancelled = true;
    };
  }, [router]);

  if (state.phase === 'blocked') {
    return <WorkspaceAccessGate context={state.context} />;
  }

  // Brief client-side loading state while resolution is in flight — same
  // "render nothing until ready" convention as login/page.tsx.
  return null;
}
