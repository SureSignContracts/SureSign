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

  const { data, isLoading, isError } = useQuery<SuggestionsResponse>({
    queryKey: ['project-contract-suggestions', projectId, contractId, analysisId],
    queryFn: () => api.get(`/projects/${projectId}/contracts/${contractId}/analyses/${analysisId}/project-suggestions`).then(r => r.data),
  });

  const suggestions = data?.suggestions ?? [];
  const actionable = suggestions.filter(s => !s.already_matches);
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
    onSuccess: (res: { applied: string[] }) => {
      qc.invalidateQueries({ queryKey: ['project', projectId] });
      qc.invalidateQueries({ queryKey: ['project-contract-suggestions', projectId, contractId, analysisId] });
      setAppliedSummary(res.applied ?? []);
      setUserSelected(new Set());
      if ((res.applied ?? []).length > 0) {
        // A genuinely-changed Project Location clears any existing map pin
        // server-side (see ProjectContractSetupSyncService's stale-coordinate
        // safety note) — SureSign doesn't yet geocode a text address into a
        // new one automatically, so say so here rather than let the map
        // silently go blank with no explanation.
        toast.success(
          (res.applied ?? []).includes('project_location')
            ? 'Applied to the Project. Any previous map pin was removed — see the note below.'
            : 'Applied to the Project.'
        );
        onApplied();
      } else {
        toast('Nothing to apply — the selected details already match the Project.');
      }
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to apply Project suggestions.')),
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
                    {appliedSummary.includes('project_location') && (
                      <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
                        Since the Project Location changed, any existing map pin was removed. SureSign
                        doesn&rsquo;t yet convert an address into map coordinates automatically — set a new pin
                        via Edit Project, or a future update will do this for you.
                      </p>
                    )}
                  </>
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

          {!isLoading && !isError && suggestions.map(s => (
            <div
              key={s.key}
              className={`flex items-start gap-4 rounded-xl bg-white px-5 py-4 shadow-[0_6px_18px_rgba(24,33,29,0.05)] transition-transform duration-200 ${!s.already_matches && selected.has(s.key) ? '-translate-y-0.5 ring-2 ring-[#78c993]' : ''}`}
            >
              {s.already_matches ? (
                <CheckCircle size={16} style={{ color: '#4ade80', flexShrink: 0, marginTop: 2 }} />
              ) : (
                <input
                  type="checkbox"
                  checked={selected.has(s.key)}
                  onChange={() => toggle(s.key)}
                  className="mt-1 flex-shrink-0"
                  aria-label={`Apply ${s.label}`}
                />
              )}
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{s.label}</p>
                {s.already_matches ? (
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
          ))}
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
