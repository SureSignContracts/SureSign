'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';
import Sidebar from '@/components/layout/Sidebar';

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, token, _hasHydrated } = useAuthStore();
  const { theme } = useTheme();

  useEffect(() => {
    if (_hasHydrated && !token) {
      router.push('/login');
    }
  }, [_hasHydrated, token, router]);

  // Logo loading screen while store rehydrates from localStorage
  if (!_hasHydrated || !token || !user) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center gap-6" style={{ backgroundColor: 'var(--bg-base)' }}>
        <style>{`
          @keyframes ss-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.92); }
          }
          @keyframes ss-ring {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
          }
          .ss-logo-pulse { animation: ss-pulse 1.8s ease-in-out infinite; }
          .ss-ring { animation: ss-ring 1.1s linear infinite; }
        `}</style>

        {/* Logo with pulse */}
        <div className="relative flex items-center justify-center">
          {/* Spinning ring */}
          <div
            className="ss-ring absolute w-20 h-20 rounded-full border-2"
            style={{ borderColor: 'var(--border)', borderTopColor: 'var(--gold)' }}
          />
          {/* Logo */}
          <div className="ss-logo-pulse w-12 h-12 flex items-center justify-center">
            <img
              src={theme === 'dark' ? '/logo_white/SureSign_WLOGO.png' : '/logo_black/SureSign_BLOGO.png'}
              alt="SureSign"
              className="w-10 h-10 object-contain"
            />
          </div>
        </div>

        {/* Wordmark */}
        <div className="flex flex-col items-center gap-1">
          <span className="text-lg font-semibold tracking-tight" style={{ color: 'var(--text-primary)' }}>
            SureSign
          </span>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading your workspace…</span>
        </div>
      </div>
    );
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
