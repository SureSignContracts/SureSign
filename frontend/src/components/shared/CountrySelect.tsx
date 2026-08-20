'use client';

import Combobox from '@/components/ui/Combobox';
import { COUNTRY_OPTIONS, withLegacyOption } from '@/lib/countryRegionData';

/**
 * Searchable, deterministic Country selector — the primary path for every
 * form that previously used a free-text "Country" input. Built on the
 * existing `Combobox` (this app's shared searchable-select convention),
 * not a new dropdown implementation.
 *
 * Submits the same full country-name string the backend already expects
 * (`organizations.country`/`users.country` are plain nullable strings) —
 * no ISO-code migration, no change to the persisted format.
 *
 * A stored value that doesn't match any dataset entry (a legacy value, an
 * unusual spelling, or the confirmed pre-existing "AU" default artifact on
 * some organisation rows) is still shown and kept selected via
 * `withLegacyOption` — never silently blanked.
 */
export default function CountrySelect({
  label = 'Country',
  value,
  onChange,
  required,
  error,
  id,
}: {
  label?: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
  error?: string;
  id?: string;
}) {
  const options = withLegacyOption(COUNTRY_OPTIONS, value);

  return (
    <div>
      <label htmlFor={id} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
        {label}
        {required && <span style={{ color: '#ef4444' }}> *</span>}
      </label>
      <Combobox
        id={id}
        value={value}
        onValueChange={onChange}
        options={options}
        placeholder="Search or select country..."
        searchPlaceholder="Search countries..."
        emptyMessage="No countries found."
        error={!!error}
        clearable
        className="w-full"
        aria-label={label}
      />
      {error && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{error}</p>}
    </div>
  );
}
