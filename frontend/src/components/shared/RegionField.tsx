'use client';

import { useId, useState } from 'react';
import Combobox from '@/components/ui/Combobox';
import { getRegionLabel, getRegionOptions, withLegacyOption } from '@/lib/countryRegionData';

/**
 * Country-aware State/Province/Region field. Metadata-driven, not a
 * per-country branch: `getRegionOptions()`/`getRegionLabel()` (see
 * `lib/countryRegionData.ts`) decide whether a country has a reliable
 * controlled subdivision list at all.
 *
 * - Reliable list exists (US/CA/AU and every other country the dataset
 *   covers with a non-empty region list) → a searchable Combobox, with the
 *   same legacy-value-preservation behaviour as `CountrySelect`.
 * - No reliable list → a plain free-text input. This is the correct,
 *   expected outcome for many countries, not a missing feature.
 *
 * A manual "Enter manually instead" escape hatch is always available even
 * when a controlled list exists — an unusual territory, an administrative
 * area the dataset doesn't represent, or a legacy value the user wants to
 * edit as free text rather than re-select. Toggling it never clears
 * `value`.
 *
 * Changing `country` never clears `value` — this component only
 * re-evaluates which UI (dropdown vs. free text) fits the new country.
 * The caller's existing region value stays exactly as it was; the visible
 * change in field type is what "informs" the user something may now be
 * inconsistent, without ever discarding what they typed.
 */
export default function RegionField({
  country,
  value,
  onChange,
  label,
  error,
  id,
}: {
  country: string;
  value: string;
  onChange: (value: string) => void;
  label?: string;
  error?: string;
  id?: string;
}) {
  const regionOptions = getRegionOptions(country);
  const effectiveLabel = label ?? getRegionLabel(country);
  const generatedId = useId();
  const fieldId = id ?? generatedId;

  const [manualOverride, setManualOverride] = useState(false);
  // A country change may make a previous manual override moot (the new
  // country might have no controlled list at all, in which case there's
  // nothing to "return to" a dropdown for) — reset the toggle rather than
  // leave a stale manual-mode flag pointing at UI that no longer applies.
  // Adjusted during render (React's documented pattern for "reset state
  // when a prop changes"), not in an effect — this repo's lint config
  // forbids a synchronous setState call inside a plain useEffect.
  const [lastCountry, setLastCountry] = useState(country);
  if (country !== lastCountry) {
    setLastCountry(country);
    setManualOverride(false);
  }

  const showFreeText = !regionOptions || manualOverride;

  if (showFreeText) {
    return (
      <div>
        <label htmlFor={fieldId} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
          {effectiveLabel}
        </label>
        <input
          id={fieldId}
          type="text"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          className="w-full px-3 py-2.5 rounded-lg text-sm outline-none transition-colors"
          style={{
            backgroundColor: 'var(--bg-elevated)',
            border: `1px solid ${error ? '#ef4444' : 'var(--border)'}`,
            color: 'var(--text-primary)',
          }}
        />
        {regionOptions && (
          <button
            type="button"
            onClick={() => setManualOverride(false)}
            className="mt-1 text-xs underline"
            style={{ color: 'var(--text-muted)' }}
          >
            Choose from list instead
          </button>
        )}
        {error && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{error}</p>}
      </div>
    );
  }

  const options = withLegacyOption(regionOptions, value);

  return (
    <div>
      <label htmlFor={fieldId} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
        {effectiveLabel}
      </label>
      <Combobox
        id={fieldId}
        value={value}
        onValueChange={onChange}
        options={options}
        placeholder={`Search or select ${effectiveLabel.toLowerCase()}...`}
        searchPlaceholder="Search..."
        emptyMessage="No results found."
        error={!!error}
        clearable
        className="w-full"
        aria-label={effectiveLabel}
      />
      <button
        type="button"
        onClick={() => setManualOverride(true)}
        className="mt-1 text-xs underline"
        style={{ color: 'var(--text-muted)' }}
      >
        Enter manually instead
      </button>
      {error && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{error}</p>}
    </div>
  );
}
