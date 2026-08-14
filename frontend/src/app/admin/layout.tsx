'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Menu } from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import { useAuthSplash } from '@/hooks/useAuthSplash';
import AdminSidebar from '@/components/layout/AdminSidebar';
import NotificationBell from '@/components/notifications/NotificationBell';
import SureSignLoader from '@/components/ui/SureSignLoader';
import ForcePasswordChangeGate from '@/components/auth/ForcePasswordChangeGate';
import WhatsNewLauncher from '@/components/product-updates/WhatsNewLauncher';

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, token, _hasHydrated } = useAuthStore();
  const [navOpen, setNavOpen] = useState(false);

  const isSystemUser = user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin');
  const { showSplash, playEntrance } = useAuthSplash(_hasHydrated && !!token && !!user);

  useEffect(() => {
    if (_hasHydrated) {
      if (!token) {
        router.push('/login');
      } else if (!isSystemUser) {
        router.push('/app');
      }
    }
  }, [_hasHydrated, token, isSystemUser, router]);

  if (showSplash) {
    return <SureSignLoader />;
  }

  // `user` is guaranteed non-null here since showSplash is false only once
  // useAuthSplash's isReady (which includes !!user) has been true — this
  // check is TypeScript narrowing, not new runtime behaviour.
  if (!user || !isSystemUser) {
    return null;
  }

  if (user.must_change_password) {
    return (
      <div className={playEntrance ? 'ss-authenticated-entrance' : undefined}>
        <ForcePasswordChangeGate />
      </div>
    );
  }

  return (
    <div
      className={`flex h-screen overflow-hidden${playEntrance ? ' ss-authenticated-entrance' : ''}`}
      style={{ backgroundColor: 'var(--bg-base)' }}
    >
      <AdminSidebar mobileOpen={navOpen} onMobileClose={() => setNavOpen(false)} />
      <div className="flex flex-col flex-1 min-w-0 min-h-0 overflow-hidden">
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
        {/* min-h-0 is load-bearing here, not decorative: a flex item's
            default min-height is `auto`, meaning it refuses to shrink
            below its own content's height even with flex-1 set. Without
            it, a content-heavy page (more content than one viewport tall)
            forces this <main> — and the column/row it's nested in — taller
            than h-screen, so the browser scrolls the whole document
            instead of just this element's own overflow-y-auto region.
            Shorter pages never hit this, since their content already fits
            without needing to shrink — which is why this only showed up
            on pages with enough stacked content to exceed one viewport. */}
        <main className="ss-admin-content flex-1 min-h-0 overflow-y-auto">
          {children}
        </main>
      </div>
      <WhatsNewLauncher />
    </div>
  );
}
