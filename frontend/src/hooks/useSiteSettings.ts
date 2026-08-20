import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';

export interface SiteSettings {
  currency: string;
  currency_symbol: string;
  date_format: string;
  timezone: string;
  hidden_pages: string[];
  // Notification Sound System — the one, platform-wide, Super Admin/Admin
  // -uploaded notification audio asset (see CLAUDE.md's "Notification
  // Sound System" section). Null until an operator uploads one. Read here
  // (not a second request) since useSiteSettings is already fetched at the
  // authenticated shell level.
  notification_sound_url: string | null;
}

const DEFAULTS: SiteSettings = {
  currency: 'GBP',
  currency_symbol: '£',
  date_format: 'DD/MM/YYYY',
  timezone: 'Europe/London',
  hidden_pages: [],
  notification_sound_url: null,
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
