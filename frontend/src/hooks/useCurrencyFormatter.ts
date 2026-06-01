import { useSiteSettings } from './useSiteSettings';

/**
 * Returns a formatCurrency function that uses the platform's configured
 * currency code (fetched from /settings). Falls back to GBP while loading.
 */
export function useCurrencyFormatter() {
  const { data: settings } = useSiteSettings();
  const currency = settings?.currency ?? 'GBP';

  return (amount: number | string): string => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount;
    if (isNaN(num)) return '—';
    return new Intl.NumberFormat('en-GB', { style: 'currency', currency }).format(num);
  };
}
