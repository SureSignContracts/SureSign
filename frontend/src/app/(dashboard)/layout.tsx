'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import Sidebar from '@/components/layout/Sidebar';
import SureSignLoader from '@/components/ui/SureSignLoader';

const MIN_SPLASH_MS = 1800;

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, token, _hasHydrated } = useAuthStore();
  const alreadyAuthed = typeof window !== 'undefined' && !!localStorage.getItem('suresign_token');
  const [splashDone, setSplashDone] = useState(alreadyAuthed);

  useEffect(() => {
    if (alreadyAuthed) return;
    const t = setTimeout(() => setSplashDone(true), MIN_SPLASH_MS);
    return () => clearTimeout(t);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (_hasHydrated && !token) {
      router.push('/login');
    }
  }, [_hasHydrated, token, router]);

  if (!_hasHydrated || !token || !user || !splashDone) {
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
