'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Menu } from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import AdminSidebar from '@/components/layout/AdminSidebar';
import NotificationBell from '@/components/notifications/NotificationBell';
import SureSignLoader from '@/components/ui/SureSignLoader';

const MIN_SPLASH_MS = 1800;

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, token, _hasHydrated } = useAuthStore();
  const [splashDone, setSplashDone] = useState(false);
  const [navOpen, setNavOpen] = useState(false);

  const isSystemUser = user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin');

  useEffect(() => {
    const t = setTimeout(() => setSplashDone(true), MIN_SPLASH_MS);
    return () => clearTimeout(t);
  }, []);

  useEffect(() => {
    if (_hasHydrated) {
      if (!token) {
        router.push('/login');
      } else if (!isSystemUser) {
        router.push('/app');
      }
    }
  }, [_hasHydrated, token, isSystemUser, router]);

  if (!_hasHydrated || !token || !user || !splashDone) {
    return <SureSignLoader />;
  }

  if (!isSystemUser) {
    return null;
  }

  return (
    <div className="flex h-screen overflow-hidden" style={{ backgroundColor: 'var(--bg-base)' }}>
      <AdminSidebar mobileOpen={navOpen} onMobileClose={() => setNavOpen(false)} />
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        <header className="h-12 flex items-center justify-end px-4 flex-shrink-0" style={{ backgroundColor: 'var(--bg-base)' }}>
          <button
            onClick={() => setNavOpen(true)}
            aria-label="Open navigation menu"
            className="lg:hidden mr-auto w-9 h-9 flex items-center justify-center rounded-lg transition-colors hover:bg-[var(--bg-hover)] active:scale-95"
            style={{ color: 'var(--text-secondary)' }}
          >
            <Menu size={20} />
          </button>
          <NotificationBell />
        </header>
        <main className="flex-1 overflow-y-auto">
          {children}
        </main>
      </div>
    </div>
  );
}
