'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useAuthSplash } from '@/hooks/useAuthSplash';
import Sidebar from '@/components/layout/Sidebar';
import SureSignLoader from '@/components/ui/SureSignLoader';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, token, _hasHydrated } = useAuthStore();
  const { showLoaderNode, loaderExiting, playEntrance } = useAuthSplash(_hasHydrated && !!token && !!user);

  useEffect(() => {
    if (_hasHydrated && !token) {
      router.push('/login');
    }
  }, [_hasHydrated, token, router]);

  if (showLoaderNode) {
    return <SureSignLoader exiting={loaderExiting} />;
  }

  return (
    <div
      className={`flex h-screen overflow-hidden${playEntrance ? ' ss-authenticated-entrance' : ''}`}
      style={{ backgroundColor: 'var(--bg-base)' }}
    >
      <Sidebar />
      {/* min-h-0: without it, a flex item defaults to never shrinking
          below its own content's height even with flex-1 set — a
          content-heavy page pushes this <main> (and the whole row)
          taller than h-screen, so the document scrolls instead of just
          this element's own overflow-y-auto region. See admin/layout.tsx's
          identical fix for the full writeup. */}
      <main className="flex-1 min-h-0 overflow-y-auto">
        {children}
      </main>
    </div>
  );
}
