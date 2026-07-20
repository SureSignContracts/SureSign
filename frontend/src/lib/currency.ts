/**
 * Centralised currency support — mirrors backend/app/Services/CurrencyService.php
 * exactly (same symbol map, same 8 supported codes as first-class options).
 * Deliberately does not use `Intl.NumberFormat(locale, { style: 'currency' })`
 * for the symbol: that renders locale-dependent currency *display names*, not
 * the plain symbols this app requires — e.g. `Intl.NumberFormat('en-GB', {
 * style: 'currency', currency: 'USD' })` renders "US$1,234.50", not "$1,234.50",
 * and CAD renders "CA$" instead of the required "C$". Never rely on Intl (or
 * the browser locale) to choose the visible symbol.
 */

export const SUPPORTED_CURRENCIES = ['GBP', 'USD', 'EUR', 'AUD', 'NZD', 'CAD', 'SGD', 'JPY'] as const;

export type SupportedCurrency = typeof SUPPORTED_CURRENCIES[number];

const CURRENCY_SYMBOLS: Record<string, string> = {
  GBP: '£',
  USD: '$',
  EUR: '€',
  AUD: 'A$',
  NZD: 'NZ$',
  CAD: 'C$',
  CHF: 'CHF',
  SGD: 'S$',
  HKD: 'HK$',
  JPY: '¥',
  CNY: '¥',
  INR: '₹',
  ZAR: 'R',
  AED: 'AED',
};

const CURRENCY_LABELS: Record<SupportedCurrency, string> = {
  GBP: 'British Pound',
  USD: 'US Dollar',
  EUR: 'Euro',
  AUD: 'Australian Dollar',
  NZD: 'New Zealand Dollar',
  CAD: 'Canadian Dollar',
  SGD: 'Singapore Dollar',
  JPY: 'Japanese Yen',
};

/** JPY (and a couple of others, none currently in SUPPORTED_CURRENCIES) has no minor unit. */
const ZERO_DECIMAL_CURRENCIES = new Set(['JPY', 'KRW', 'VND']);

export function currencySymbol(code?: string | null): string {
  if (!code) return CURRENCY_SYMBOLS.GBP;
  return CURRENCY_SYMBOLS[code.toUpperCase()] ?? code.toUpperCase();
}

export function currencyLabel(code: string): string {
  return CURRENCY_LABELS[code.toUpperCase() as SupportedCurrency] ?? code.toUpperCase();
}

/**
 * Formats a numeric amount with the correct symbol for its currency —
 * independent of browser locale. Falls back to GBP only if no code is given
 * at all (callers should otherwise always pass a resolved currency, e.g. from
 * `useCurrencyFormatter`, never omit it and hope for a sensible default).
 */
export function formatMoney(amount: number | string, code?: string | null): string {
  const num = typeof amount === 'string' ? parseFloat(amount) : amount;
  if (isNaN(num)) return '—';

  const resolvedCode = (code || 'GBP').toUpperCase();
  const fractionDigits = ZERO_DECIMAL_CURRENCIES.has(resolvedCode) ? 0 : 2;
  const formattedNumber = new Intl.NumberFormat('en-GB', {
    style: 'decimal',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  }).format(num);

  return `${currencySymbol(resolvedCode)}${formattedNumber}`;
}
