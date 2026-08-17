'use client';

/**
 * Phase E — Review & Apply Confirmed Contract Suggestions to Project.
 *
 * Reads `GET /projects/{id}/contracts/{contract}/analyses/{analysis}/project-suggestions`
 * and posts to `POST .../apply-project-suggestions` (both new in this
 * phase — see `App\Services\ProjectContractSetupSyncService`). Deliberately
 * NOT a second Contract editing surface: the user already reviewed and
 * confirmed the Contract itself via `ContractAnalysisReview` (Phase C) —
 * this panel only lets them choose which already-confirmed facts to copy
 * into the Project summary. No value is ever edited here, only selected or
 * not; the backend recomputes every value itself from the confirmed source
 * on apply, so nothing this component sends is trusted as a raw value.
 */

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import { CheckCircle, Loader2, AlertTriangle, ArrowLeft } from 'lucide-react';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import FullscreenDialogPortal from '@/components/ui/FullscreenDialogPortal';

export type SuggestionValue = {
  value: string | number | null;
  currency?: string | null;
  label?: string;
  reason?: string;
  // Project Location only — a pre-ordered, already-filtered array of
  // display lines (address/city/state/postcode/country, blank components
  // omitted). Every other suggestion type leaves this undefined, so their
  // rendering is completely unchanged — see hasLocationLines() below.
  lines?: string[];
};

export type ProjectSuggestion = {
  key: string;
  label: string;
  current: SuggestionValue;
  suggested: SuggestionValue;
  already_matches: boolean;
  default_selected: boolean;
  // Project Location only (Phase 2 — Geoapify geocoding). 'missing' covers
  // no coordinates at all AND an incomplete pair (one set, one null) —
  // never treated as a valid existing pin. map_pin_action_required is true
  // exactly when the text already matches but the pin doesn't — the one
  // case where an already_matches row still has a real, selectable action
  // (a geocode-only "Set map position").
  map_pin_status?: 'set' | 'missing';
  map_pin_action_required?: boolean;
};

type SuggestionsResponse = {
  project_id: number;
  contract_id: number;
  analysis_id: number;
  contract_title: string;
  suggestions: ProjectSuggestion[];
};

function formatValue(v: SuggestionValue | undefined): string {
  if (!v || v.value === null || v.value === undefined || v.value === '') return 'Not set';
  if (v.currency) return `${v.value} ${v.currency}`;
  if (v.label) return v.label; // organization_role suggestion — show the friendly label, not the raw enum value
  return String(v.value);
}

/** Only Project Location populates `lines` — every other suggestion type's rendering is untouched. */
function hasLocationLines(s: ProjectSuggestion): boolean {
  return (s.current.lines?.length ?? 0) > 0 || (s.suggested.lines?.length ?? 0) > 0;
}

export default function ProjectSuggestionsPanel({
  projectId, contractId, analysisId, onClose, onApplied,
}: {
  projectId: string;
  contractId: number;
  analysisId: number;
  onClose: () => void;
  onApplied: () => void;
}) {
  const qc = useQueryClient();
  // null = "the user hasn't touched any checkbox yet" — the effective
  // selection then tracks the server's own default_selected flags exactly.
  // The moment the user toggles anything, this becomes a real Set and fully
  // takes over, so a later suggestions refetch never re-seeds and fights
  // their own choice (same derived-value approach already used for the
  // Contract Type suggestion on the main Setup page — no effect-driven
  // setState here either).
  const [userSelected, setUserSelected] = useState<Set<string> | null>(null);
  const [appliedSummary, setAppliedSummary] = useState<string[] | null>(null);
  const [locationResult, setLocationResult] = useState<{ textual_location_applied: boolean; map_position: string } | null>(null);

  const { data, isLoading, isError } = useQuery<SuggestionsResponse>({
    queryKey: ['project-contract-suggestions', projectId, contractId, analysisId],
    queryFn: () => api.get(`/projects/${projectId}/contracts/${contractId}/analyses/${analysisId}/project-suggestions`).then(r => r.data),
  });

  const suggestions = data?.suggestions ?? [];
  // A row is actionable (gets a checkbox) either because its text doesn't
  // match yet, OR — Project Location's own edge case — the text already
  // matches but the map pin is missing/incomplete (map_pin_action_required).
  const actionable = suggestions.filter(s => !s.already_matches || s.map_pin_action_required);
  const defaultSelected = new Set(suggestions.filter(s => s.default_selected).map(s => s.key));
  const selected = userSelected ?? defaultSelected;

  function toggle(key: string) {
    const next = new Set(selected);
    if (next.has(key)) next.delete(key); else next.add(key);
    setUserSelected(next);
  }

  const applyMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/contracts/${contractId}/analyses/${analysisId}/apply-project-suggestions`, {
      suggestions: Array.from(selected),
    }).then(r => r.data),
    onSuccess: (res: { applied: string[]; message?: string; project_location_result?: { textual_location_applied: boolean; map_position: string } | null }) => {
      qc.invalidateQueries({ queryKey: ['project', projectId] });
      qc.invalidateQueries({ queryKey: ['project-contract-suggestions', projectId, contractId, analysisId] });
      setAppliedSummary(res.applied ?? []);
      setLocationResult(res.project_location_result ?? null);
      setUserSelected(new Set());
      // The backend already builds the exact Part 25 outcome message
      // (reliable match / no match / map-position-only update) — shown
      // verbatim rather than re-deriving the same logic client-side.
      if ((res.applied ?? []).length > 0) {
        toast.success(res.message ?? 'Applied to the Project.');
        onApplied();
      } else {
        toast(res.message ?? 'Nothing to apply — the selected details already match the Project.');
      }
    },
    onError: (err: unknown) => {
      // normalizeApiError deliberately discards a 5xx response's own
      // message (see its docblock) — but a Geoapify provider failure is a
      // deliberate, already-safe 503 from our own controller, not an
      // infrastructure crash. 'code' is the documented escape hatch: show
      // the backend's own message for this specific, known code, and fall
      // back to the generic handling for everything else.
      const data = (err as { response?: { data?: { code?: string; message?: string } } })?.response?.data;
      if (data?.code === 'geocoding_unavailable' && data.message) {
        toast.error(data.message);
        return;
      }
      toast.error(getErrorMessage(err, 'Failed to apply Project suggestions.'));
    },
  });

  return (
    <FullscreenDialogPortal>
      <div
        className="flex w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-[#f2f2f2] shadow-[0_32px_90px_rgba(0,0,0,0.32)] ss-animate-in"
        style={{ maxHeight: '90dvh' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex flex-shrink-0 items-center justify-between bg-[#18211d] px-6 py-6 text-white">
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-[0.18em] text-[#9ee5b5]">Project handover</p>
            <h2 className="mt-2 text-xl font-semibold">Apply confirmed contract details</h2>
            {data?.contract_title && (
              <p className="mt-1 text-xs text-[#b9c5bf]">Source: {data.contract_title}</p>
            )}
          </div>
          {!isLoading && <div className="rounded-xl bg-white/10 px-4 py-2 text-right"><p className="text-xl font-semibold text-[#9ee5b5]">{selected.size}</p><p className="text-[10px] uppercase tracking-wider text-[#b9c5bf]">selected</p></div>}
        </div>

        <div className="flex-1 overflow-y-auto p-5 space-y-3">
          {isLoading && (
            <div className="flex flex-col items-center justify-center py-12 gap-3">
              <Loader2 size={20} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading suggestions…</p>
            </div>
          )}

          {isError && (
            <div className="flex flex-col items-center justify-center py-12 gap-2">
              <AlertTriangle size={20} style={{ color: '#f87171' }} />
              <p className="text-sm" style={{ color: 'var(--text-primary)' }}>Couldn&rsquo;t load Project suggestions.</p>
              <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>
                Your confirmed Contract is unaffected — you can continue to the Project and try again later.
              </p>
            </div>
          )}

          {!isLoading && !isError && appliedSummary !== null && (
            <div className="rounded-xl p-4 flex items-start gap-2" style={{ backgroundColor: 'rgba(74,222,128,0.08)', border: '1px solid rgba(74,222,128,0.2)' }}>
              <CheckCircle size={16} style={{ color: '#4ade80', flexShrink: 0, marginTop: 2 }} />
              <div>
                {appliedSummary.length > 0 ? (
                  <>
                    <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Applied to the Project</p>
                    <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      {appliedSummary.map(k => suggestions.find(s => s.key === k)?.label ?? k).join(', ')}
                    </p>
                    {locationResult && (
                      <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
                        {locationResult.map_position === 'updated' ? (
                          'SureSign found a reliable map position and updated the pin.'
                        ) : locationResult.textual_location_applied ? (
                          <>Since the Project Location changed, any existing map pin was removed. SureSign
                            couldn&rsquo;t confidently determine a new map position — set one via Edit Project,
                            or a future update will do this for you.</>
                        ) : (
                          'SureSign couldn’t confidently determine a map position for the existing address.'
                        )}
                      </p>
                    )}
                  </>
                ) : locationResult ? (
                  // Part 21 — a geocode-only "Set map position" action that
                  // found no reliable match: nothing in the Project actually
                  // changed, so this deliberately isn't in `applied`, but
                  // the attempt itself still needs acknowledging.
                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>SureSign could not confidently determine the map position.</p>
                ) : (
                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>Nothing was applied — the selected details already match the Project.</p>
                )}
              </div>
            </div>
          )}

          {!isLoading && !isError && suggestions.length === 0 && (
            <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>
              No additional Project details are available to apply from this Contract.
            </p>
          )}

          {!isLoading && !isError && suggestions.length > 0 && actionable.length === 0 && appliedSummary === null && (
            <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>
              Your Project details already match this confirmed Contract.
            </p>
          )}

          {!isLoading && !isError && suggestions.map(s => {
            // Project Location's own edge case (Part 20/21): text already
            // matches but the map pin is missing/incomplete — still a real,
            // selectable action ("Set map position"), so it gets a checkbox
            // like any other actionable row, not the static checkmark every
            // other already-matching suggestion gets.
            const showCheckbox = !s.already_matches || s.map_pin_action_required;
            const showMissingPinRow = s.already_matches && s.map_pin_action_required;
            return (
            <div
              key={s.key}
              className={`flex items-start gap-4 rounded-xl bg-white px-5 py-4 shadow-[0_6px_18px_rgba(24,33,29,0.05)] transition-transform duration-200 ${showCheckbox && selected.has(s.key) ? '-translate-y-0.5 ring-2 ring-[#78c993]' : ''}`}
            >
              {showCheckbox ? (
                <input
                  type="checkbox"
                  checked={selected.has(s.key)}
                  onChange={() => toggle(s.key)}
                  className="mt-1 flex-shrink-0"
                  aria-label={showMissingPinRow ? `Set map position for ${s.label}` : `Apply ${s.label}`}
                />
              ) : (
                <CheckCircle size={16} style={{ color: '#4ade80', flexShrink: 0, marginTop: 2 }} />
              )}
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{s.label}</p>
                {showMissingPinRow ? (
                  <div className="mt-1 space-y-0.5">
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Address: Already matches</p>
                    <p className="text-xs" style={{ color: 'var(--gold)' }}>Map position: Not set</p>
                  </div>
                ) : s.already_matches ? (
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Already matches your Project</p>
                ) : hasLocationLines(s) ? (
                  // Project Location's own display — a multi-line address
                  // needs more room than the single-line "Current Project: X"
                  // format every other suggestion type uses below. Same row
                  // shell (checkbox, card, selection ring) — just this one
                  // suggestion's value area is laid out differently.
                  <div className="mt-1.5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                      <p className="text-[10px] font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Current</p>
                      {s.current.lines && s.current.lines.length > 0 ? (
                        s.current.lines.map((line, i) => (
                          <p key={i} className="text-xs" style={{ color: 'var(--text-muted)' }}>{line}</p>
                        ))
                      ) : (
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Not set</p>
                      )}
                    </div>
                    <div>
                      <p className="text-[10px] font-semibold uppercase tracking-wider" style={{ color: 'var(--gold)' }}>Suggested from Contract</p>
                      {(s.suggested.lines ?? []).map((line, i) => (
                        <p key={i} className="text-xs" style={{ color: 'var(--gold)' }}>{line}</p>
                      ))}
                    </div>
                  </div>
                ) : (
                  <div className="mt-1 space-y-0.5">
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Current Project: {formatValue(s.current)}</p>
                    <p className="text-xs" style={{ color: 'var(--gold)' }}>From confirmed Contract: {formatValue(s.suggested)}</p>
                    {s.suggested.reason && (
                      <p className="text-xs italic" style={{ color: 'var(--text-muted)' }}>{s.suggested.reason}</p>
                    )}
                  </div>
                )}
              </div>
            </div>
            );
          })}
        </div>

        <div className="flex flex-shrink-0 items-center justify-between bg-white px-6 py-4">
          <button onClick={onClose} className="text-sm flex items-center gap-1" style={{ color: 'var(--text-muted)' }}>
            <ArrowLeft size={13} /> Back
          </button>
          <div className="flex items-center gap-2">
            <Button variant="ghost" onClick={onClose}>Continue Without Applying</Button>
            {actionable.length > 0 && (
              <Button
                onClick={() => applyMutation.mutate()}
                disabled={selected.size === 0 || applyMutation.isPending}
              >
                {applyMutation.isPending ? 'Applying…' : 'Apply Selected'}
              </Button>
            )}
          </div>
        </div>
      </div>
    </FullscreenDialogPortal>
  );
}
