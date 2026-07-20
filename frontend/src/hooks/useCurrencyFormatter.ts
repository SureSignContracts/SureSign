import { useSiteSettings } from './useSiteSettings';
import { formatMoney } from '@/lib/currency';

/**
 * Returns a formatCurrency function that uses the platform's configured
 * currency code (fetched from /settings) by default. Falls back to GBP
 * while loading.
 *
 * Pass an explicit ISO currency code as the second argument to format an
 * amount in its own project currency instead of the platform default —
 * needed anywhere (e.g. Global Commercial) that aggregates figures across
 * projects that may not all share the platform's currency. Never sums
 * amounts across currencies; this only affects display formatting.
 *
 * Formatting itself goes through lib/currency's formatMoney, not
 * `Intl.NumberFormat(locale, { style: 'currency' })` — the latter renders
 * locale-dependent display names (US$, CA$, "SGD 1,234.50"), not the plain
 * symbols this app requires (see lib/currency.ts for the specifics).
 */
export function useCurrencyFormatter() {
  const { data: settings } = useSiteSettings();
  const platformCurrency = settings?.currency ?? 'GBP';

  return (amount: number | string, currencyOverride?: string): string => {
    return formatMoney(amount, currencyOverride || platformCurrency);
  };
}
