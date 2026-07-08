'use client';

import { useEffect, useState } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useQuery } from '@tanstack/react-query';
import AppSidebar from '@/components/layout/AppSidebar';
import MobileTopBar from '@/components/layout/MobileTopBar';
import SureSignLoader from '@/components/ui/SureSignLoader';
import AiAnalysisWidget from '@/components/ai/AiAnalysisWidget';
import GlobalTourLauncher from '@/components/tours/GlobalTourLauncher';
import ForcePasswordChangeGate from '@/components/auth/ForcePasswordChangeGate';
import api from '@/lib/api';

function isLightColor(hex: string): boolean {
  const h = hex.replace('#', '');
  if (h.length < 6) return true;
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return (r * 299 + g * 587 + b * 114) / 1000 > 128;
}

const MIN_SPLASH_MS = 1800;

export default function AppLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const { user, token, _hasHydrated } = useAuthStore();
  // Skip splash if we already have a token in localStorage (returning user / new tab)
  const alreadyAuthed = typeof window !== 'undefined' && !!localStorage.getItem('suresign_token');
  const [splashDone, setSplashDone] = useState(alreadyAuthed);
  const [navOpen, setNavOpen] = useState(false);

  useEffect(() => {
    if (alreadyAuthed) return; // skip timer — already showing app
    const t = setTimeout(() => setSplashDone(true), MIN_SPLASH_MS);
    return () => clearTimeout(t);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  // Fetch branding to apply client-specific accent colour
  const { data: branding } = useQuery({
    queryKey: ['branding'],
    queryFn: () => api.get('/organization/branding').then(r => r.data?.data ?? r.data),
    enabled: !!token,
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

  // System users (Admin/Super Admin) don't belong in /app — send them to /admin
  // Exception: project detail pages are shared, so allow access there
  useEffect(() => {
    if (_hasHydrated && token && user) {
      const isSystemUser = user.roles?.includes('Super Admin') || user.roles?.includes('Admin');
      const isProjectPath = !!pathname?.startsWith('/app/projects/');
      const isSettingsSubPath = !!pathname?.startsWith('/app/settings/');
      if (isSystemUser && !isProjectPath && !isSettingsSubPath) {
        router.push('/admin');
        return;
      }
    }
  }, [_hasHydrated, token, user, pathname, router]);

  // Onboarding guard — redirect to onboarding if org not yet set up (clients only)
  useEffect(() => {
    if (_hasHydrated && token && user) {
      const isSystemUser = user.roles?.includes('Super Admin') || user.roles?.includes('Admin');
      const isOnboarded = user.organization?.is_onboarded;
      const onOnboardingPage = pathname === '/app/onboarding';
      if (!isSystemUser && !isOnboarded && !onOnboardingPage) {
        router.push('/app/onboarding');
      }
    }
  }, [_hasHydrated, token, user, pathname, router]);

  if (!_hasHydrated || !token || !user || !splashDone) {
    return <SureSignLoader />;
  }

  // A Super Admin forced a password reset (or set a temp password requiring
  // one) — block everything else until it's changed.
  if (user.must_change_password) {
    return <ForcePasswordChangeGate />;
  }

  // On the onboarding page, don't show the sidebar
  if (pathname === '/app/onboarding') {
    return <>{children}</>;
  }

  // On project detail pages, the ProjectSidebar handles its own layout
  const isProjectDetail = !!pathname?.match(/^\/app\/projects\/[^/]+\//);
  if (isProjectDetail) {
    return <>{children}</>;
  }

  const orgName = branding?.company_name || user?.organization?.name || 'Company Portal';
  const logoUrl = branding?.logo_url ?? null;

  return (
    <div className="flex h-screen overflow-hidden" style={{ backgroundColor: 'var(--bg-base)' }}>
      <AppSidebar mobileOpen={navOpen} onMobileClose={() => setNavOpen(false)} />
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        <MobileTopBar
          onMenu={() => setNavOpen(true)}
          title={orgName}
          logoUrl={logoUrl}
          fallbackInitial={orgName.charAt(0).toUpperCase()}
        />
        <main className="flex-1 overflow-y-auto">
          {children}
        </main>
      </div>
      {/* Global — persists across all app pages so analysis progress stays visible
          even after navigating away from the project. */}
      <AiAnalysisWidget />
      <GlobalTourLauncher />
    </div>
  );
}

