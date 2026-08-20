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
 * Allowlist of countries whose `country-region-data` subdivision list has
 * been VERIFIED (Semantic Subdivision Closeout, its V2 expansion, and the
 * V2 data-completeness correction below) to represent the same real-world
 * administrative level SureSign's "State / Province / Region" field means
 * for that country's addresses. A non-empty `regions` array in the dataset
 * is deliberately NOT sufficient on its own to enable a dropdown —
 * inclusion requires BOTH:
 *
 *   1. semantically correct administrative level; AND
 *   2. sufficiently complete/current dataset coverage.
 *
 * Failing either is enough to defer a country to free text. ISO 3166-2
 * subdivision levels vary by country, and several were checked and found
 * to fail one of the two requirements:
 *
 * - Philippines (17 entries: "Bicol", "Calabarzon", "Cordillera
 *   Administrative Region", ...) — fails (1): these are ISO 3166-2:PH
 *   REGIONS, a level ABOVE the province. A real Philippine address (and
 *   this app's own `users.province`/`organizations.state` fields) means
 *   the province — e.g. "Oriental Mindoro", which sits *inside* the
 *   Calabarzon region and is not itself one of the 17 entries. Free text.
 * - United Kingdom (217 entries: "Aberdeen City", "Barking and Dagenham",
 *   "Armagh City, Banbridge and Craigavon", ...) — fails (1): UK
 *   "principal areas" (unitary authorities, London boroughs,
 *   Scottish/Northern-Irish council areas), a different, finer-grained
 *   concept from the traditional "county" this app's own legacy UK data
 *   assumes (e.g. a stored value of "Essex"). Free text.
 * - Spain (55 entries) — fails (1), and worse than a simple wrong level:
 *   the list mixes two administrative tiers in one flat array — e.g.
 *   "Andalucía" (an autonomous community) sits as a sibling entry
 *   alongside "Almería" (a *province within* Andalucía). Free text.
 * - France (26 entries: "Auvergne-Rhône-Alpes", "Bretagne", "Île-de-
 *   France", ...) — fails (1): the 2016-reform administrative *regions*,
 *   not the ~101 *departments* French postal addressing actually keys on.
 *   Free text.
 * - Italy (20 entries: "Abruzzo", "Lombardia", ...) — internally clean
 *   (exactly Italy's 20 official regions, no mixing), but Italian
 *   addresses commonly cite the *provincia* (e.g. "Milano (MI)"), not the
 *   region — kept conservative under (1) rather than resolve that
 *   ambiguity by assumption. Free text.
 * - Indonesia (33 entries) — passes (1) (they genuinely are provinces,
 *   the correct level) but FAILS (2): real-world Indonesia has ~38
 *   provinces since the 2022 Papua splits ("South Papua" and others
 *   confirmed absent), so this dataset is knowingly incomplete. A
 *   selection method known to be missing current provinces must not be
 *   presented as the primary path even though the level itself is right.
 *   Free text — deferred as a future candidate once the installed
 *   dataset's Indonesia coverage is confirmed current, not by manually
 *   patching the missing provinces in here.
 *
 * Verified and INCLUDED below (V1: US/CA/AU; V2 adds the following 11,
 * each confirmed to be a single, stable, first-order division matching
 * normal address usage, with no tier-mixing AND sufficiently complete
 * coverage):
 * - Germany (16: the 16 Bundesländer, including the city-states Berlin/
 *   Hamburg/Bremen as their own entries).
 * - Japan (47: all 47 prefectures, exact real-world count).
 * - India (36: 28 states + 8 union territories).
 * - United Arab Emirates (7: the 7 emirates).
 * - Switzerland (26: all 26 cantons).
 * - Brazil (27: 26 states + the Distrito Federal).
 * - Mexico (32: 31 states + Ciudad de México).
 * - South Africa (9: the 9 provinces).
 * - Malaysia (16: 13 states + 3 federal territories).
 * - China (33: a mix of provinces, municipalities, and autonomous
 *   regions — which IS the correct real-world first-order division for
 *   China, not a mismatched tier the way Spain's mixing is).
 * - Nigeria (37: 36 states + the Abuja Federal Capital Territory).
 *
 * Every country not listed here defaults to free text — not because its
 * data was checked and rejected, but because it has not been verified at
 * all (or, for the six above, because it WAS checked and found to fail
 * requirement 1 or 2). This is a deliberately conservative allowlist
 * ("verified safe on both semantics and completeness"), not an "assume
 * correct unless proven otherwise" one — do not add a country here
 * without the same direct data verification every entry above received
 * (see this feature's closeout reports for the full method). Ireland and
 * New Zealand looked like plausible future candidates during the V2
 * expansion's discovery but were not verified or added — out of scope for
 * this phase.
 */
const CONTROLLED_SUBDIVISION_COUNTRIES: Record<string, { label: string }> = {
  US: { label: 'State' },
  CA: { label: 'Province' },
  AU: { label: 'State / Territory' },
  DE: { label: 'State' },
  JP: { label: 'Prefecture' },
  IN: { label: 'State / Union Territory' },
  AE: { label: 'Emirate' },
  CH: { label: 'Canton' },
  BR: { label: 'State' },
  MX: { label: 'State' },
  ZA: { label: 'Province' },
  MY: { label: 'State / Federal Territory' },
  CN: { label: 'Province / Municipality / Autonomous Region' },
  NG: { label: 'State' },
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
