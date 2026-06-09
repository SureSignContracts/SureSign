import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

export interface SiteSettings {
  currency: string;
  currency_symbol: string;
  date_format: string;
  timezone: string;
  hidden_pages: string[];
}

const DEFAULTS: SiteSettings = {
  currency: 'GBP',
  currency_symbol: '£',
  date_format: 'DD/MM/YYYY',
  timezone: 'Europe/London',
  hidden_pages: [],
};

export function useSiteSettings() {
  const query = useQuery<SiteSettings>({
    queryKey: ['site-settings'],
    queryFn: () =>
      api.get('/settings').then(r => r.data?.data ?? r.data).catch(() => DEFAULTS),
    staleTime: 5 * 60 * 1000,
    placeholderData: DEFAULTS,
  });
  // isSettingsReady is false while the initial fetch is in-flight (placeholderData is active).
  // Sidebar uses this to show a skeleton instead of flashing all-visible items.
  return { ...query, isSettingsReady: !query.isLoading && !query.isPlaceholderData };
}
