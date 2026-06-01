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
  return useQuery<SiteSettings>({
    queryKey: ['site-settings'],
    queryFn: () =>
      api.get('/settings').then(r => r.data?.data ?? r.data).catch(() => DEFAULTS),
    staleTime: 5 * 60 * 1000,
    placeholderData: DEFAULTS,
  });
}
