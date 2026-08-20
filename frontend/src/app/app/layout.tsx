'use client';

import { useEffect, useState } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useAuthSplash } from '@/hooks/useAuthSplash';
import { useQuery } from '@tanstack/react-query';
import AppSidebar from '@/components/layout/AppSidebar';
import MobileTopBar from '@/components/layout/MobileTopBar';
import WorkspaceTransition from '@/components/layout/WorkspaceTransition';
import NotificationBell from '@/components/notifications/NotificationBell';
import SureSignLoader from '@/components/ui/SureSignLoader';
import AiAnalysisWidget from '@/components/ai/AiAnalysisWidget';
import GlobalTourLauncher from '@/components/tours/GlobalTourLauncher';
import PendingTourLauncher from '@/components/tours/PendingTourLauncher';
import WhatsNewLauncher from '@/components/product-updates/WhatsNewLauncher';
import ForcePasswordChangeGate from '@/components/auth/ForcePasswordChangeGate';
import WorkspaceAccessGate from '@/components/auth/WorkspaceAccessGate';
import BrandedWorkspaceBanner from '@/components/auth/BrandedWorkspaceBanner';
import api from '@/lib/api';
import { fetchWorkspaceContext, type WorkspaceContextResult } from '@/lib/workspaceContext';
import { isCurrentHostPlatform, currentHostname } from '@/lib/hostContext';
import { useDocumentTitle } from '@/hooks/useDocumentTitle';
import { getAppPageLabel } from '@/lib/pageTitle';
import { useNewNotificationWatcher } from '@/hooks/useNewNotificationWatcher';
import { useNotificationSound } from '@/hooks/useNotificationSound';

const BLOCKING_WORKSPACE_STATES = new Set([
  'wrong_workspace',
  'platform_staff_on_customer_host',
  'inactive_workspace',
  'host_not_found',
]);

function isLightColor(hex: string): boolean {
  const h = hex.replace('#', '');
  if (h.length < 6) return true;
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return (r * 299 + g * 587 + b * 114) / 1000 > 128;
}

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { user, token, _hasHydrated } = useAuthStore();
  const [navOpen, setNavOpen] = useState(false);

  // Organisation URL Branding, Phase 5 (Stage 3) — the central authenticated
  // host guard. Resolved BEFORE any organisation-scoped query is allowed to
  // fire (see the branding query's own `enabled` below) — a brief flash of
  // one organisation's data on another organisation's hostname is treated
  // as a tenant-isolation defect, not a cosmetic issue. Never compares
  // organisation IDs itself — only renders whichever workspace_state the
  // backend's AuthenticatedWorkspaceContextService returned.
  const [workspaceCtx, setWorkspaceCtx] = useState<WorkspaceContextResult | null>(null);
  // Stage 3 policy revisit — a resolver outage must NOT fail open into a
  // customer's workspace under an UNVERIFIED branded/custom hostname (we
  // can't prove it's even theirs). On the fixed platform host there's
  // nothing branded to misattribute, so normal operation is safe there.
  // This client-side host comparison decides ONLY this presentation-layer
  // outage behaviour — it is never the actual authorization boundary
  // (every controller still independently scopes by organization_id).
  const onPlatformHost = isCurrentHostPlatform(currentHostname() ?? '');
  const workspaceBlocking = !!workspaceCtx && (
    BLOCKING_WORKSPACE_STATES.has(workspaceCtx.workspace_state)
    || (workspaceCtx.workspace_state === 'resolver_unavailable' && !onPlatformHost)
  );

  useEffect(() => {
    if (_hasHydrated && token && user && !workspaceCtx) {
      fetchWorkspaceContext().then(setWorkspaceCtx);
    }
  }, [_hasHydrated, token, user, workspaceCtx]);

  // Fetch branding to apply client-specific accent colour — gated on
  // workspace context having resolved to a non-blocking state, not merely
  // on a token existing. This is the actual enforcement point: no
  // organisation-scoped query in this layout (or any child page rendered
  // beneath it) can fire until the host guard has cleared.
  const { data: branding, isFetched: brandingFetched } = useQuery({
    queryKey: ['branding'],
    queryFn: () => api.get('/organization/branding').then(r => r.data?.data ?? r.data),
    enabled: !!token && !!workspaceCtx && !workspaceBlocking,
    staleTime: 5 * 60 * 1000,
  });

  // Apply client accent colour — stored in DB per-org, NOT in localStorage
  useEffect(() => {
    if (branding?.primary_color) {
      const color = branding.primary_color;
      document.documentElement.style.setProperty('--gold', color);
      document.documentElement.style.setProperty('--accent-fg', isLightColor(color) ? '#0a0a0a' : '#ffffff');
    }
  }, [branding?.primary_color]);

  // Auth guard
  useEffect(() => {
    if (_hasHydrated && !token) {
      router.push('/login');
    }
  }, [_hasHydrated, token, router]);

  // Browser tab title. Skipped entirely on project workspace routes — those
  // render their own layout further down (`isProjectDetail` below), which
  // owns the title itself via the actual loaded Project. Reuses the same
  // `branding` query already fetched above for the accent colour — no
  // second Organisation request just for document.title.
  const isProjectRouteForTitle = !!pathname?.match(/^\/app\/projects\/[^/]+\//);
  useDocumentTitle(
    isProjectRouteForTitle ? undefined : getAppPageLabel(pathname),
    { organization: branding?.company_name || user?.organization?.name }
  );

  // Notification Sound System — the shell-level lifecycle owner. Mounted
  // unconditionally here (not inside NotificationBell, which this layout
  // only renders on non-project-detail pages further down) specifically so
  // notification polling keeps running — and the new-notification baseline
  // keeps advancing — while the user is inside Project Workspace, where no
  // NotificationBell is rendered at all. Reuses the same guard the branding
  // query above already applies (workspace context resolved, not blocked)
  // so this never fires an authenticated request before that's settled.
  const { playNotificationSound } = useNotificationSound();
  useNewNotificationWatcher({
    enabled: !!token && !!workspaceCtx && !workspaceBlocking,
    onNew: playNotificationSound,
  });

  // System users (Admin/Super Admin) don't belong in /app — send them to /admin
  // Exception: project detail pages are shared, so allow access there.
  // Skipped entirely while workspace context is blocking (e.g.
  // platform_staff_on_customer_host) — the WorkspaceAccessGate below is
  // authoritative in that state, not a race with this redirect.
  useEffect(() => {
    if (_hasHydrated && token && user && !workspaceBlocking) {
      const isSystemUser = user.roles?.includes('Super Admin') || user.roles?.includes('Admin');
      const isProjectPath = !!pathname?.startsWith('/app/projects/');
      const isSettingsSubPath = !!pathname?.startsWith('/app/settings/');
      if (isSystemUser && !isProjectPath && !isSettingsSubPath) {
        router.push('/admin');
        return;
      }
    }
  }, [_hasHydrated, token, user, pathname, router, workspaceBlocking]);

  // Onboarding guard — redirect to onboarding if org not yet set up (clients only)
  useEffect(() => {
    if (_hasHydrated && token && user && !workspaceBlocking) {
      const isSystemUser = user.roles?.includes('Super Admin') || user.roles?.includes('Admin');
      const isOnboarded = user.organization?.is_onboarded;
      const onOnboardingPage = pathname === '/app/onboarding';
      if (!isSystemUser && !isOnboarded && !onOnboardingPage) {
        router.push('/app/onboarding');
      }
    }
  }, [_hasHydrated, token, user, pathname, router, workspaceBlocking]);

  // Wait for the branding fetch to settle before first paint of the real UI —
  // otherwise --gold briefly renders at its CSS default (SureSign's own
  // colour) before the org's custom accent colour effect gets a chance to
  // run, flashing on every hard reload for orgs with custom branding.
  // brandingFetched flips true on either success or failure (React Query's
  // isFetched), so a slow/failing branding request delays the splash but
  // never blocks it indefinitely. When workspaceBlocking, the branding
  // query never runs at all (disabled above) — brandingFetched would never
  // become true, so it's excluded from the readiness condition in that
  // case; the WorkspaceAccessGate is shown instead, not the normal shell.
  const { showLoaderNode, loaderExiting, playEntrance } = useAuthSplash(
    _hasHydrated && !!token && !!user && !!workspaceCtx && (workspaceBlocking || brandingFetched)
  );
  // `user`/`workspaceCtx` are guaranteed non-null here since showLoaderNode
  // is false only once isReady (which includes both) has been true —
  // TypeScript narrowing, not new runtime behaviour.
  if (showLoaderNode || !user || !workspaceCtx) {
    return <SureSignLoader exiting={loaderExiting} />;
  }

  if (workspaceBlocking) {
    return (
      <div className={playEntrance ? 'ss-authenticated-entrance' : undefined}>
        <WorkspaceAccessGate context={workspaceCtx} />
      </div>
    );
  }

  // A Super Admin forced a password reset (or set a temp password requiring
  // one) — block everything else until it's changed.
  if (user.must_change_password) {
    return (
      <div className={playEntrance ? 'ss-authenticated-entrance' : undefined}>
        <ForcePasswordChangeGate />
      </div>
    );
  }

  // On the onboarding page, don't show the sidebar
  if (pathname === '/app/onboarding') {
    return (
      <div className={playEntrance ? 'ss-authenticated-entrance' : undefined}>
        {children}
      </div>
    );
  }

  // On project detail pages, the ProjectSidebar handles its own layout
  const isProjectDetail = !!pathname?.match(/^\/app\/projects\/[^/]+\//);
  if (isProjectDetail) {
    return (
      <div className={playEntrance ? 'ss-authenticated-entrance' : undefined}>
        {children}
      </div>
    );
  }

  const orgName = branding?.company_name || user?.organization?.name || 'Company Portal';
  const logoUrl = branding?.logo_url ?? null;

  // Stage 3 policy revisit, Part E — restrained, dismissible recommendation
  // only on the fixed platform host, only for an ordinary customer user
  // (platform staff's own authoritative URL always equals the platform
  // host itself, so this naturally never shows for them), and only when
  // the authoritative destination is actually a DIFFERENT host.
  const isSystemUser = !!user?.roles?.includes('Super Admin') || !!user?.roles?.includes('Admin');
  const brandedWorkspaceUrl = workspaceCtx.workspace_state === 'platform_host'
    && !isSystemUser
    && workspaceCtx.authoritative_workspace_url
    && (() => {
      try {
        return new URL(workspaceCtx.authoritative_workspace_url).host !== window.location.host;
      } catch {
        return false;
      }
    })()
    ? workspaceCtx.authoritative_workspace_url
    : null;

  return (
    <div
      className={`flex h-screen overflow-hidden${playEntrance ? ' ss-authenticated-entrance' : ''}`}
      style={{ backgroundColor: 'var(--bg-base)' }}
    >
      <AppSidebar mobileOpen={navOpen} onMobileClose={() => setNavOpen(false)} />
      <div className="flex flex-col flex-1 min-w-0 min-h-0 overflow-hidden">
        {brandedWorkspaceUrl && <BrandedWorkspaceBanner authoritativeUrl={brandedWorkspaceUrl} />}
        <MobileTopBar
          onMenu={() => setNavOpen(true)}
          title={orgName}
          logoUrl={logoUrl}
          fallbackInitial={orgName.charAt(0).toUpperCase()}
          right={<NotificationBell basePath="/app/notifications" />}
        />
        <header className="hidden lg:flex h-12 items-center justify-end px-4 flex-shrink-0" style={{ backgroundColor: 'var(--bg-base)' }}>
          <NotificationBell basePath="/app/notifications" />
        </header>
        {/* min-h-0: without it, a flex item defaults to never shrinking
            below its own content's height even with flex-1 set — a
            content-heavy page pushes this <main> (and the column/row it's
            nested in) taller than h-screen, so the document scrolls
            instead of just this element's own overflow-y-auto region. */}
        <main className="flex-1 min-h-0 overflow-y-auto">
          <WorkspaceTransition>{children}</WorkspaceTransition>
        </main>
      </div>
      {/* Global — persists across all app pages so analysis progress stays visible
          even after navigating away from the project. */}
      <AiAnalysisWidget />
      <GlobalTourLauncher />
      <PendingTourLauncher />
      <WhatsNewLauncher historyHref="/app/whats-new" />
    </div>
  );
}
