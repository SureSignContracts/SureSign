/**
 * Thin wrapper around `country-region-data` (ISO 3166-1 country names +
 * ISO 3166-2 subdivisions, zero runtime dependencies) — the sole place this
 * package's tuple shape (`[countryName, countryCode, [regionName, regionCode][]]`)
 * is touched. Everything else in the app imports named helpers from here,
 * never the package directly.
 *
 * Full English country names (e.g. "United States", "United Kingdom",
 * "Philippines") are used as both the UI label AND the submitted value —
 * this matches SureSign's existing free-text storage convention exactly
 * (`organizations.country`/`users.country` are plain nullable strings with
 * no ISO-code contract anywhere in the backend). ISO codes are used
 * internally only, to look up region lists and pick a label override.
 */
// Named import, not the package's own declared `default` export — its
// compiled ESM bundle (`dist/data.js`) has no runtime default export
// despite `dist/data.d.ts` declaring one; a real production build (not
// `tsc`, which only checks the types) is what caught this mismatch.
import { allCountries } from 'country-region-data';
import type { ComboboxOption } from '@/components/ui/Combobox';

// Keyed as plain `string`, not the package's own `CountryName` literal union
// — lookups here must accept arbitrary runtime values (a legacy stored
// string, an unmatched value, a country the dataset covers by name but not
// by that exact literal type), never just the ~249 names TypeScript knows
// about at compile time.
const CODE_BY_NAME: Map<string, string> = new Map(allCountries.map(([name, code]) => [name, code]));
const REGIONS_BY_NAME: Map<string, [string, string][]> = new Map(allCountries.map(([name, , regions]) => [name, regions]));

/** Full country list for the primary Country selector — deterministic, offline, no API call. */
export const COUNTRY_OPTIONS: ComboboxOption[] = allCountries.map(([name]) => ({ value: name, label: name }));

/**
 * V1 allowlist of countries whose `country-region-data` subdivision list has
 * been VERIFIED (Semantic Subdivision Closeout) to represent the same
 * real-world administrative level SureSign's "State / Province / Region"
 * field means for that country's addresses. A non-empty `regions` array in
 * the dataset is deliberately NOT sufficient on its own to enable a
 * dropdown — ISO 3166-2 subdivision levels vary by country, and two
 * countries with real data were checked and found to expose the WRONG
 * level for this field:
 *
 * - Philippines (17 entries: "Bicol", "Calabarzon", "Cordillera
 *   Administrative Region", ...) — these are ISO 3166-2:PH REGIONS, a
 *   level ABOVE the province. A real Philippine address (and this app's
 *   own `users.province`/`organizations.state` fields) means the
 *   province — e.g. "Oriental Mindoro", which sits *inside* the
 *   Calabarzon region and is not itself one of the 17 entries. Building a
 *   province-level dataset by hand is out of scope, so Philippines uses
 *   free text.
 * - United Kingdom (217 entries: "Aberdeen City", "Barking and Dagenham",
 *   "Armagh City, Banbridge and Craigavon", ...) — these are UK
 *   "principal areas" (unitary authorities, London boroughs,
 *   Scottish/Northern-Irish council areas), a different, finer-grained
 *   concept from the traditional "county" this app's own legacy UK data
 *   assumes (e.g. a stored value of "Essex"). Free text.
 *
 * US/CA/AU were each checked and confirmed to list the real administrative
 * level a State/Province/Region field means there (US: the 50 states plus
 * real territories; CA: the actual 10 provinces + 3 territories; AU: the
 * actual 6 states + 2 territories) — hence the only three included below.
 *
 * Every OTHER country also defaults to free text — not because its data
 * was checked and rejected, but because it has not been verified at all.
 * This is a deliberately small, conservative allowlist ("verified safe"),
 * not an "assume correct unless proven otherwise" one — do not add a
 * country here without the same kind of direct data verification US/CA/AU
 * received (see this feature's closeout report for the full method).
 */
const CONTROLLED_SUBDIVISION_COUNTRIES: Record<string, { label: string }> = {
  US: { label: 'State' },
  CA: { label: 'Province' },
  AU: { label: 'State / Territory' },
};
const DEFAULT_REGION_LABEL = 'Region / Province / State';

const POSTAL_LABEL_OVERRIDES: Record<string, string> = {
  US: 'ZIP Code',
  GB: 'Postcode',
  CA: 'Postal Code',
};
const DEFAULT_POSTAL_LABEL = 'Postal Code';

/**
 * `null` means "render free-text Region for this country" — either because
 * it isn't on the verified `CONTROLLED_SUBDIVISION_COUNTRIES` allowlist
 * above, or (defensively) because the dataset has no entries for it even
 * though it is. Never returns a dropdown "because the package happens to
 * have data" — see the allowlist's own docblock.
 */
export function getRegionOptions(countryName?: string | null): ComboboxOption[] | null {
  if (!countryName) return null;
  const code = CODE_BY_NAME.get(countryName);
  if (!code || !CONTROLLED_SUBDIVISION_COUNTRIES[code]) return null;
  const regions = REGIONS_BY_NAME.get(countryName);
  if (!regions || regions.length === 0) return null;
  return regions.map(([name]) => ({ value: name, label: name }));
}

export function getRegionLabel(countryName?: string | null): string {
  const code = countryName ? CODE_BY_NAME.get(countryName) : undefined;
  return (code && CONTROLLED_SUBDIVISION_COUNTRIES[code]?.label) || DEFAULT_REGION_LABEL;
}

export function getPostalLabel(countryName?: string | null): string {
  const code = countryName ? CODE_BY_NAME.get(countryName) : undefined;
  return (code && POSTAL_LABEL_OVERRIDES[code]) || DEFAULT_POSTAL_LABEL;
}

/**
 * Ensures an existing stored value that isn't in `options` (a legacy
 * region/country string, an unrepresented territory, or — confirmed in the
 * real dev database — a pre-existing default-artifact value like a bare
 * "AU") still displays correctly and stays selected, instead of the
 * Combobox falling back to its placeholder because `value` matched
 * nothing. Never mutates the stored value itself — purely a display
 * concern, and the synthetic option is dropped the moment the user picks
 * a real one from the list.
 */
export function withLegacyOption(options: ComboboxOption[], value?: string | null): ComboboxOption[] {
  if (!value || options.some((o) => o.value === value)) return options;
  return [{ value, label: value }, ...options];
}
