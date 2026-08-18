'use client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { GooeyToaster } from 'goey-toast';
import 'goey-toast/styles.css';
import { useTheme } from '@/hooks/useTheme';
import toast from '@/lib/toast';

export default function Providers({ children }: { children: React.ReactNode }) {
  // Dev-only console hook — lets any toast variant/option be tried live from
  // DevTools (`toast.warning('title', { description: '...' })`) without a
  // code change + rebuild each time. Never runs in production.
  useEffect(() => {
    if (process.env.NODE_ENV !== 'production') {
      (window as unknown as { toast: typeof toast }).toast = toast;
    }
  }, []);

  // Tracks the same per-user 'suresign-theme-*' preference every other
  // themed surface in the app reads — goey-toast has no "system"/auto
  // option, so this is what keeps toasts in sync with the app's own
  // light/dark toggle instead of defaulting to light always.
  const { theme } = useTheme();
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
      <GooeyToaster
        position="top-center"
        gap={10}
        duration={4000}
        theme={theme}
        closeButton="top-right"
        showProgress
      />
    </QueryClientProvider>
  );
}
