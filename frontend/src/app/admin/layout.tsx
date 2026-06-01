'use client';

import { useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import AdminSidebar from '@/components/layout/AdminSidebar';

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, token, _hasHydrated } = useAuthStore();

  const isSystemUser = user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin');

  useEffect(() => {
    if (_hasHydrated) {
      if (!token) {
        router.push('/login');
      } else if (!isSystemUser) {
        router.push('/app');
      }
    }
  }, [_hasHydrated, token, isSystemUser, router]);

  if (!_hasHydrated || !token || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center" style={{ backgroundColor: 'var(--bg-base)' }}>
        <div
          className="w-8 h-8 rounded-full border-2 animate-spin"
          style={{ borderColor: 'var(--border)', borderTopColor: '#ef4444' }}
        />
      </div>
    );
  }

  if (!isSystemUser) {
    return null;
  }

  return (
    <div className="flex h-screen overflow-hidden" style={{ backgroundColor: 'var(--bg-base)' }}>
      <AdminSidebar />
      <main className="flex-1 overflow-y-auto">
        {children}
      </main>
    </div>
  );
}
