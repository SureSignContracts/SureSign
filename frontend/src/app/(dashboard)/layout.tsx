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
  const showSplash = useAuthSplash(_hasHydrated && !!token && !!user);

  useEffect(() => {
    if (_hasHydrated && !token) {
      router.push('/login');
    }
  }, [_hasHydrated, token, router]);

  if (showSplash) {
    return <SureSignLoader />;
  }

  return (
    <div className="flex h-screen overflow-hidden" style={{ backgroundColor: 'var(--bg-base)' }}>
      <Sidebar />
      <main className="flex-1 overflow-y-auto">
        {children}
      </main>
    </div>
  );
}
