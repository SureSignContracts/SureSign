'use client';

import { useEffect, useId, useRef, useState } from 'react';
import * as Popover from '@radix-ui/react-popover';
import { Loader2 } from 'lucide-react';
import api from '@/lib/api';
import { getCountryCode } from '@/lib/countryRegionData';

interface CitySuggestion {
  name: string;
  region: string | null;
  country: string | null;
}

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 2;

/**
 * City / locality field — autocomplete assistance layered onto a normal,
 * always-editable text input, backed by `GET /location-suggestions/cities`
 * (server-side Geoapify; the API key never reaches the browser). Never the
 * shared `Combobox` — that component is fundamentally select-from-a-list
 * (its persisted value must equal one of `options`), which cannot express
 * "the typed text itself is always the value, suggestions are optional." A
 * dedicated, deliberately small component instead of forcing the wrong
 * semantics onto Combobox — see this feature's own brief.
 *
 * The input's value is the ONLY source of truth for what gets submitted.
 * Selecting a suggestion just replaces the input's text with that
 * suggestion's locality name — nothing else about the form, and nothing
 * server-side, is touched (no geocoding, no coordinates, no project/
 * organisation mutation).
 *
 * Country is passed as a full country name (matching this app's own
 * storage convention) and converted to an ISO code here, at the one UI
 * boundary that needs it (`getCountryCode()`) — the backend never sees or
 * needs the full name, and it genuinely narrows results via Geoapify's own
 * country filter.
 *
 * `region` is DELIBERATELY NOT sent to the backend at all — the endpoint's
 * contract no longer accepts it (see `LocationSuggestionController`'s own
 * docblock: a live smoke test proved naive region-based query shaping
 * hurts relevance, so the parameter was removed rather than kept as an
 * unused, misleading part of the API). Region is accepted here purely as
 * a UI CONTEXT prop for one narrow purpose: invalidating/hiding stale
 * suggestions when the user's selected Region changes, via the same
 * `contextKey` mechanism used for Country. Do not start sending it to the
 * endpoint again without first re-verifying relevance live, and without
 * first re-adding it to the backend's own validated contract.
 */
export default function CityAutocomplete({
  value,
  onChange,
  country,
  region,
  label = 'City',
  placeholder,
  error,
  id,
}: {
  value: string;
  onChange: (value: string) => void;
  country?: string | null;
  region?: string | null;
  label?: string;
  placeholder?: string;
  error?: string;
  id?: string;
}) {
  // Suggestions are tagged with the country/region context they were
  // fetched for, and only ever DISPLAYED when that context still matches
  // the current props — a purely render-time derivation, not an
  // imperative reset. This is what makes "stale suggestions from the
  // previous country should disappear" work without a useEffect+setState
  // (forbidden by this repo's lint config) or a ref write during render
  // (also forbidden): a country/region change alone, with no new
  // useEffect and no ref mutation, already makes `suggestions` below
  // resolve to `[]` on the very next render.
  const [suggestionsState, setSuggestionsState] = useState<{ contextKey: string; items: CitySuggestion[] }>({
    contextKey: '',
    items: [],
  });
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);
  const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Guards ONLY against out-of-order async responses for the SAME context
  // (a slower "L" response arriving after a faster "Lo" one already
  // resolved) — mutated exclusively inside the async callback below, never
  // during render or a plain effect, so this ref usage is safe.
  const requestIdRef = useRef(0);
  const generatedId = useId();
  const fieldId = id ?? generatedId;
  const listboxId = `city-autocomplete-listbox-${generatedId}`;
  const countryCode = getCountryCode(country);
  const contextKey = `${countryCode ?? ''}|${region ?? ''}`;
  const suggestions = suggestionsState.contextKey === contextKey ? suggestionsState.items : [];

  useEffect(() => {
    return () => {
      if (debounceRef.current) clearTimeout(debounceRef.current);
    };
  }, []);

  function scheduleSearch(query: string) {
    if (debounceRef.current) clearTimeout(debounceRef.current);

    const trimmed = query.trim();
    if (trimmed.length < MIN_QUERY_LENGTH) {
      setSuggestionsState({ contextKey, items: [] });
      setOpen(false);
      setLoading(false);
      return;
    }

    setLoading(true);
    const requestId = ++requestIdRef.current;
    const searchContextKey = contextKey;
    debounceRef.current = setTimeout(async () => {
      try {
        // `region` is intentionally NOT sent — see this component's own
        // docblock. It only drives `contextKey` (stale-suggestion
        // invalidation), never the actual request.
        const params: Record<string, string> = { query: trimmed };
        if (countryCode) params.country_code = countryCode;
        const res = await api.get('/location-suggestions/cities', { params });
        if (requestId !== requestIdRef.current) return; // a newer keystroke already superseded this
        const results: CitySuggestion[] = res.data?.data ?? [];
        setSuggestionsState({ contextKey: searchContextKey, items: results });
        setOpen(results.length > 0);
        setActiveIndex(0);
      } catch {
        // Provider/network failure — quietly show no suggestions. The
        // field remains a fully usable free-text input regardless; no
        // error is surfaced to avoid toasting on every keystroke.
        if (requestId !== requestIdRef.current) return;
        setSuggestionsState({ contextKey: searchContextKey, items: [] });
        setOpen(false);
      } finally {
        if (requestId === requestIdRef.current) setLoading(false);
      }
    }, DEBOUNCE_MS);
  }

  function commit(suggestion: CitySuggestion) {
    onChange(suggestion.name);
    setSuggestionsState({ contextKey, items: [] });
    setOpen(false);
  }

  function onKeyDown(e: React.KeyboardEvent<HTMLInputElement>) {
    if (!open || suggestions.length === 0) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      setActiveIndex((i) => Math.min(i + 1, suggestions.length - 1));
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      setActiveIndex((i) => Math.max(i - 1, 0));
    } else if (e.key === 'Enter') {
      // Never blocks a plain Enter-to-submit-the-form when the list isn't
      // open — only intercepts Enter while a suggestion is genuinely
      // being navigated.
      e.preventDefault();
      commit(suggestions[activeIndex]);
    } else if (e.key === 'Escape') {
      setOpen(false);
    }
  }

  return (
    <div>
      <label htmlFor={fieldId} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
        {label}
      </label>
      <Popover.Root open={open && suggestions.length > 0} onOpenChange={setOpen}>
        <Popover.Anchor asChild>
          <div className="relative">
            <input
              id={fieldId}
              type="text"
              role="combobox"
              aria-expanded={open && suggestions.length > 0}
              aria-controls={listboxId}
              aria-autocomplete="list"
              aria-activedescendant={open && suggestions[activeIndex] ? `${listboxId}-option-${activeIndex}` : undefined}
              value={value}
              onChange={(e) => {
                onChange(e.target.value);
                scheduleSearch(e.target.value);
              }}
              onFocus={() => {
                if (suggestions.length > 0) setOpen(true);
              }}
              onKeyDown={onKeyDown}
              placeholder={placeholder}
              autoComplete="off"
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none transition-colors"
              style={{
                backgroundColor: 'var(--bg-elevated)',
                border: `1px solid ${error ? '#ef4444' : 'var(--border)'}`,
                color: 'var(--text-primary)',
              }}
            />
            {loading && (
              <Loader2
                size={13}
                className="animate-spin absolute right-3 top-1/2 -translate-y-1/2"
                style={{ color: 'var(--text-muted)' }}
              />
            )}
          </div>
        </Popover.Anchor>

        <Popover.Portal>
          <Popover.Content
            align="start"
            sideOffset={4}
            onOpenAutoFocus={(e) => e.preventDefault()}
            className="ss-menu-pop-in z-50 overflow-hidden rounded-xl border shadow-[var(--shadow-pop)]"
            style={{
              width: 'max(var(--radix-popover-anchor-width), 240px)',
              backgroundColor: 'var(--bg-surface)',
              borderColor: 'var(--border)',
            }}
          >
            <div id={listboxId} role="listbox" className="max-h-56 overflow-y-auto p-1">
              {suggestions.map((s, i) => (
                <div
                  key={`${s.name}-${s.region ?? ''}-${i}`}
                  id={`${listboxId}-option-${i}`}
                  role="option"
                  aria-selected={i === activeIndex}
                  data-index={i}
                  onMouseEnter={() => setActiveIndex(i)}
                  // mousedown (not click/onClick) + preventDefault stops the
                  // input from blurring — and this popover from closing via
                  // onOpenChange — before the selection is ever registered.
                  onMouseDown={(e) => {
                    e.preventDefault();
                    commit(s);
                  }}
                  className="flex flex-col cursor-pointer select-none rounded-lg px-2.5 py-2 text-sm"
                  style={{
                    backgroundColor: i === activeIndex ? 'var(--bg-elevated)' : 'transparent',
                    color: 'var(--text-primary)',
                  }}
                >
                  <span className="truncate">{s.name}</span>
                  {(s.region || s.country) && (
                    <span className="truncate text-xs" style={{ color: 'var(--text-muted)' }}>
                      {[s.region, s.country].filter(Boolean).join(', ')}
                    </span>
                  )}
                </div>
              ))}
            </div>
          </Popover.Content>
        </Popover.Portal>
      </Popover.Root>
      {error && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{error}</p>}
    </div>
  );
}
