'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { X, Plus, Pencil, Eye, ExternalLink, CheckCircle2 } from 'lucide-react';
import api from '@/lib/api';
import { formatDateOnly } from '@/lib/dateTime';
import { normalizeApiError } from '@/lib/normalizeApiError';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import Combobox, { type ComboboxOption } from '@/components/ui/Combobox';
import DocumentPreviewModal, { type PreviewTarget } from '@/components/documents/DocumentPreviewModal';
import { STATUS_OPTIONS, drawingStatusColor } from '@/components/drawings/drawingConstants';
import type { DrawingRevisionSummary } from '@/components/drawings/DrawingModal';

const INPUT_STYLE = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

function Field({ label, required, children, error }: { label: string; required?: boolean; children: React.ReactNode; error?: string }) {
  return (
    <label className="block">
      <span className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</span>
      {children}
      {error && <span className="text-xs mt-1 block" style={{ color: '#f87171' }}>{error}</span>}
    </label>
  );
}

function optionsWithCurrent(options: readonly string[], current: string | null): string[] {
  if (!current || (options as string[]).includes(current)) return [...options];
  return [current, ...options];
}

type FormState = {
  document_id: string;
  revision_code: string;
  status: string;
  issued_date: string;
  issued_by: string;
  notes: string;
};

const EMPTY_FORM: FormState = { document_id: '', revision_code: '', status: '', issued_date: '', issued_by: '', notes: '' };

function toFormState(revision: DrawingRevisionSummary): FormState {
  return {
    document_id: String(revision.document.id),
    revision_code: revision.revision_code ?? '',
    status: revision.status ?? '',
    issued_date: revision.issued_date ?? '',
    issued_by: revision.issued_by ?? '',
    notes: revision.notes ?? '',
  };
}

/**
 * Drawing Revision history (Phase 4 Part M) — a dedicated panel rather than
 * folding this into the already-restrained Viewer header (Part M: "do not
 * turn the Viewer into a giant document management screen"). Opened from
 * both the Viewer and Drawing details.
 */
export default function DrawingRevisionPanel({
  projectId,
  drawingId,
  canOperate,
  onClose,
  onRevisionsChanged,
}: {
  projectId: string;
  drawingId: number;
  canOperate: boolean;
  onClose: () => void;
  /** Called after add/edit so the caller (Register/Viewer) can refresh its own Drawing query. */
  onRevisionsChanged?: () => void;
}) {
  const qc = useQueryClient();
  const router = useRouter();
  const [mode, setMode] = useState<{ kind: 'list' } | { kind: 'add' } | { kind: 'edit'; revision: DrawingRevisionSummary }>({ kind: 'list' });
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [docSearch, setDocSearch] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);

  const set = (k: keyof FormState, v: string) => setForm(f => ({ ...f, [k]: v }));

  const listQueryKey = ['drawing-revisions', projectId, drawingId];
  const { data, isLoading, isError } = useQuery<{ data: DrawingRevisionSummary[]; current_revision_id: number | null }>({
    queryKey: listQueryKey,
    queryFn: () => api.get(`/projects/${projectId}/drawings/${drawingId}/revisions`).then(r => r.data),
  });

  const eligibleQuery = useQuery({
    queryKey: ['drawing-eligible-revision-documents', projectId, drawingId, docSearch],
    queryFn: () => api.get(`/projects/${projectId}/drawings/${drawingId}/eligible-revision-documents`, {
      params: { search: docSearch || undefined, per_page: 25 },
    }).then(r => r.data),
    enabled: mode.kind === 'add',
  });

  const documentOptions: ComboboxOption[] = (eligibleQuery.data?.data ?? []).map((d: {
    id: number; title: string; file_name: string | null; reference_number: string | null;
  }) => ({
    value: String(d.id),
    label: d.title || d.file_name || `Document #${d.id}`,
    description: [d.reference_number, d.file_name].filter(Boolean).join(' · ') || undefined,
  }));

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: listQueryKey });
    qc.invalidateQueries({ queryKey: ['drawing-eligible-revision-documents', projectId, drawingId] });
    onRevisionsChanged?.();
  };

  const addMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/drawings/${drawingId}/revisions`, {
      document_id: form.document_id,
      revision_code: form.revision_code,
      status: form.status || null,
      issued_date: form.issued_date || null,
      issued_by: form.issued_by || null,
      notes: form.notes || null,
    }),
    onSuccess: () => {
      toast.success('Revision added — now the current revision');
      invalidate();
      setMode({ kind: 'list' });
      setForm(EMPTY_FORM);
    },
    onError: (e: unknown) => {
      const normalized = normalizeApiError(e, 'Failed to add revision.');
      setFieldErrors(normalized.fieldErrors ?? {});
      setFormError(normalized.type === 'validation' ? null : normalized.message);
    },
  });

  const editMutation = useMutation({
    mutationFn: (revisionId: number) => api.put(`/projects/${projectId}/drawings/${drawingId}/revisions/${revisionId}`, {
      revision_code: form.revision_code,
      status: form.status || null,
      issued_date: form.issued_date || null,
      issued_by: form.issued_by || null,
      notes: form.notes || null,
    }),
    onSuccess: () => {
      toast.success('Revision updated');
      invalidate();
      setMode({ kind: 'list' });
    },
    onError: (e: unknown) => {
      const normalized = normalizeApiError(e, 'Failed to update revision.');
      setFieldErrors(normalized.fieldErrors ?? {});
      setFormError(normalized.type === 'validation' ? null : normalized.message);
    },
  });

  const revisions = data?.data ?? [];
  const currentRevisionId = data?.current_revision_id ?? null;

  return (
    <>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
        <div
          className="ss-animate-in w-full max-w-2xl rounded-2xl flex flex-col max-h-[85vh]"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        >
          <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              {mode.kind === 'add' ? 'Add Revision' : mode.kind === 'edit' ? 'Edit Revision' : 'Drawing Revisions'}
            </h2>
            <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors" aria-label="Close">
              <X size={16} style={{ color: 'var(--text-muted)' }} />
            </button>
          </div>

          <div className="overflow-y-auto flex-1 px-6 py-5 space-y-4">
            {formError && (
              <div className="px-4 py-3 rounded-lg text-sm" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#ef4444' }}>
                {formError}
              </div>
            )}

            {mode.kind === 'list' && (
              isLoading ? (
                <div className="space-y-2">
                  {[...Array(3)].map((_, i) => (
                    <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
                  ))}
                </div>
              ) : isError ? (
                <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>Failed to load revision history.</p>
              ) : revisions.length === 0 ? (
                <p className="text-sm text-center py-8" style={{ color: 'var(--text-muted)' }}>No revisions have been added yet.</p>
              ) : (
                <div className="space-y-2">
                  {revisions.map(r => {
                    const isCurrent = r.id === currentRevisionId;
                    const statusColor = drawingStatusColor(r.status);
                    return (
                      <div key={r.id} className="rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)', border: isCurrent ? '1px solid var(--gold)' : '1px solid var(--border)' }}>
                        <div className="flex items-start justify-between gap-2">
                          <div className="min-w-0">
                            <div className="flex items-center gap-2 flex-wrap">
                              <span className="font-mono text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                                {r.revision_code ?? 'Revision not recorded'}
                              </span>
                              {isCurrent && (
                                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                                  <CheckCircle2 size={11} /> Current
                                </span>
                              )}
                              {r.status && (
                                <span className="px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: statusColor.bg, color: statusColor.text }}>
                                  {r.status}
                                </span>
                              )}
                            </div>
                            <p className="text-xs mt-1 truncate" style={{ color: 'var(--text-muted)' }}>
                              {r.document.title}
                              {r.issued_date && ` · Issued ${formatDateOnly(r.issued_date)}`}
                              {r.issued_by && ` · ${r.issued_by}`}
                            </p>
                            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                              Added by {r.creator?.name ?? 'Unknown'}
                            </p>
                          </div>
                          <div className="flex items-center gap-1 flex-shrink-0">
                            <button
                              title="Preview"
                              onClick={() => setPreviewTarget({
                                id: r.document.id,
                                name: r.document.title,
                                previewEndpoint: `/documents/${r.document.id}/preview`,
                                downloadEndpoint: `/documents/${r.document.id}/download`,
                              })}
                              className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
                            >
                              <Eye size={13} style={{ color: 'var(--text-secondary)' }} />
                            </button>
                            <button
                              title="Open in Drawing Viewer"
                              onClick={() => {
                                onClose();
                                router.push(isCurrent
                                  ? `/app/projects/${projectId}/drawings/${drawingId}`
                                  : `/app/projects/${projectId}/drawings/${drawingId}?revision=${r.id}`);
                              }}
                              className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
                            >
                              <ExternalLink size={13} style={{ color: 'var(--text-secondary)' }} />
                            </button>
                            {canOperate && (
                              <button
                                title="Edit"
                                onClick={() => { setForm(toFormState(r)); setMode({ kind: 'edit', revision: r }); setFieldErrors({}); setFormError(null); }}
                                className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
                              >
                                <Pencil size={13} style={{ color: 'var(--text-secondary)' }} />
                              </button>
                            )}
                          </div>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )
            )}

            {(mode.kind === 'add' || mode.kind === 'edit') && (
              <div className="space-y-4">
                {mode.kind === 'add' && (
                  <Field label="Document" required error={fieldErrors.document_id?.[0]}>
                    <Combobox
                      value={form.document_id}
                      onValueChange={v => set('document_id', v)}
                      onSearch={setDocSearch}
                      loading={eligibleQuery.isFetching}
                      options={documentOptions}
                      placeholder="Select a project document…"
                      searchPlaceholder="Search documents…"
                      emptyMessage={eligibleQuery.isError ? 'Failed to load documents.' : 'No eligible documents found.'}
                      className="w-full"
                      error={!!fieldErrors.document_id}
                    />
                    <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                      Upload new files from Documents first, then select the file here.
                    </p>
                  </Field>
                )}

                <div className="grid grid-cols-2 gap-4">
                  <Field label="Revision Code" required error={fieldErrors.revision_code?.[0]}>
                    <input
                      className="w-full rounded-lg px-3 py-2 text-sm outline-none border focus:border-[var(--gold)] transition-colors duration-200"
                      style={INPUT_STYLE}
                      value={form.revision_code}
                      onChange={e => set('revision_code', e.target.value)}
                      maxLength={100}
                      placeholder="e.g. P01"
                      aria-invalid={!!fieldErrors.revision_code}
                    />
                  </Field>
                  <Field label="Status">
                    <Select value={form.status} onChange={e => set('status', e.target.value)} className="w-full">
                      <option value="">Not specified</option>
                      {optionsWithCurrent(STATUS_OPTIONS, mode.kind === 'edit' ? mode.revision.status : null).map(s => (
                        <option key={s} value={s}>{s}</option>
                      ))}
                    </Select>
                  </Field>
                </div>

                <div className="grid grid-cols-2 gap-4">
                  <Field label="Issued Date">
                    <input
                      type="date"
                      className="w-full rounded-lg px-3 py-2 text-sm outline-none border focus:border-[var(--gold)] transition-colors duration-200"
                      style={INPUT_STYLE}
                      value={form.issued_date}
                      onChange={e => set('issued_date', e.target.value)}
                    />
                  </Field>
                  <Field label="Issued By">
                    <input
                      className="w-full rounded-lg px-3 py-2 text-sm outline-none border focus:border-[var(--gold)] transition-colors duration-200"
                      style={INPUT_STYLE}
                      value={form.issued_by}
                      onChange={e => set('issued_by', e.target.value)}
                      maxLength={255}
                      placeholder="e.g. J. Smith, ABC Architects"
                    />
                  </Field>
                </div>

                <Field label="Notes">
                  <textarea
                    className="w-full rounded-lg px-3 py-2 text-sm outline-none border focus:border-[var(--gold)] transition-colors duration-200 resize-none"
                    style={INPUT_STYLE}
                    rows={2}
                    value={form.notes}
                    onChange={e => set('notes', e.target.value)}
                    placeholder="Reason for issue, description of changes, etc."
                  />
                </Field>
              </div>
            )}
          </div>

          <div className="flex items-center justify-between gap-3 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
            {mode.kind === 'list' ? (
              <>
                <span />
                <div className="flex items-center gap-3">
                  <Button variant="ghost" onClick={onClose}>Close</Button>
                  {canOperate && (
                    <Button onClick={() => { setForm(EMPTY_FORM); setFieldErrors({}); setFormError(null); setMode({ kind: 'add' }); }}>
                      <Plus size={14} /> Add Revision
                    </Button>
                  )}
                </div>
              </>
            ) : (
              <>
                <span />
                <div className="flex items-center gap-3">
                  <Button variant="ghost" onClick={() => setMode({ kind: 'list' })}>Cancel</Button>
                  <Button
                    onClick={() => {
                      setFormError(null); setFieldErrors({});
                      if (mode.kind === 'add') addMutation.mutate();
                      else editMutation.mutate(mode.revision.id);
                    }}
                    disabled={
                      !form.revision_code.trim() || (mode.kind === 'add' && !form.document_id) ||
                      addMutation.isPending || editMutation.isPending
                    }
                  >
                    {(addMutation.isPending || editMutation.isPending) ? 'Saving…' : mode.kind === 'add' ? 'Add Revision' : 'Save Changes'}
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>
      </div>

      {previewTarget && (
        <DocumentPreviewModal target={previewTarget} onClose={() => setPreviewTarget(null)} />
      )}
    </>
  );
}
