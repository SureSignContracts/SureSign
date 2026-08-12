'use client';

import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import type { ProductUpdate } from '@/lib/productUpdates';

/**
 * "What's New in SureSign" — customer-facing queries. One centralized
 * pending-updates fetch (mounted once, in WhatsNewLauncher), not refetched
 * per-page — per spec ("do not make every authenticated page independently
 * fetch Product Updates"). A long staleTime and no window-focus refetch are
 * deliberate: a newly published update appearing mid-session is not
 * required, only on the next session/reload (per spec).
 */
export function usePendingProductUpdates(enabled: boolean) {
  return useQuery({
    queryKey: ['product-updates', 'pending'],
    queryFn: () => api.get('/product-updates/pending').then(r => r.data.data as ProductUpdate[]),
    enabled,
    staleTime: 10 * 60 * 1000,
    refetchOnWindowFocus: false,
  });
}

export function useProductUpdateHistory(enabled = true) {
  return useQuery({
    queryKey: ['product-updates', 'history'],
    queryFn: () => api.get('/product-updates/history').then(r => r.data.data as ProductUpdate[]),
    enabled,
    staleTime: 5 * 60 * 1000,
  });
}

export function useDismissProductUpdate() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => api.post(`/product-updates/${id}/dismiss`),
    // Optimistic — clicking "Don't show this update again" should never
    // wait on a round-trip before the modal moves on to the next update.
    onMutate: async (id: number) => {
      const previous = qc.getQueryData<ProductUpdate[]>(['product-updates', 'pending']);
      qc.setQueryData<ProductUpdate[]>(['product-updates', 'pending'], (old) => (old ?? []).filter(u => u.id !== id));
      return { previous };
    },
    onError: (_err, _id, context) => {
      if (context?.previous) qc.setQueryData(['product-updates', 'pending'], context.previous);
    },
  });
}
