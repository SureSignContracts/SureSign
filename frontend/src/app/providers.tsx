'use client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useState } from 'react';
import { Toaster } from 'react-hot-toast';

export default function Providers({ children }: { children: React.ReactNode }) {
  const [queryClient] = useState(() => new QueryClient({
    // staleTime: 0 — show cached data instantly, then refetch in the background on every
    // page mount so navigating to a page always reflects the latest data without a manual
    // reload. refetchOnWindowFocus is off to avoid refetching on every browser tab switch.
    defaultOptions: {
      queries: {
        staleTime: 0,
        retry: 1,
        refetchOnMount: true,
        refetchOnWindowFocus: false,
      },
    },
  }));
  return (
    <QueryClientProvider client={queryClient}>
      {children}
      <Toaster
        position="bottom-right"
        gutter={10}
        toastOptions={{
          duration: 4000,
          style: {
            background: 'var(--bg-surface)',
            color: 'var(--text-primary)',
            border: '1px solid var(--border)',
            borderRadius: '10px',
            fontSize: '13px',
            fontWeight: '500',
            padding: '12px 16px',
            boxShadow: '0 8px 24px rgba(0,0,0,0.15), 0 2px 6px rgba(0,0,0,0.1)',
            maxWidth: '360px',
          },
          success: {
            iconTheme: { primary: 'var(--gold)', secondary: 'var(--accent-fg)' },
            style: {
              background: 'var(--bg-surface)',
              border: '1px solid var(--gold-30)',
              color: 'var(--text-primary)',
            },
          },
          error: {
            iconTheme: { primary: '#ef4444', secondary: '#fff' },
            style: {
              background: 'var(--bg-surface)',
              border: '1px solid rgba(239,68,68,0.35)',
              color: 'var(--text-primary)',
            },
          },
        }}
      />
    </QueryClientProvider>
  );
}

