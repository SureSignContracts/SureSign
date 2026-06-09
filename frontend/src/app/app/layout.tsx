'use client';

import { useEffect } from 'react';
import { useRouter, usePathname } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useQuery } from '@tanstack/react-query';
import AppSidebar from '@/components/layout/AppSidebar';
import api from '@/lib/api';

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

  if (!_hasHydrated || !token || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center" style={{ backgroundColor: 'var(--bg-base)' }}>
        <div
          className="w-8 h-8 rounded-full border-2 animate-spin"
          style={{ borderColor: 'var(--border)', borderTopColor: 'var(--gold)' }}
        />
      </div>
    );
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

  return (
    <div className="flex h-screen overflow-hidden" style={{ backgroundColor: 'var(--bg-base)' }}>
      <AppSidebar />
      <main className="flex-1 overflow-y-auto">
        {children}
      </main>
    </div>
  );
}

