'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { X, Eye, FileText } from 'lucide-react';
import api from '@/lib/api';
import { formatDateTime } from '@/lib/dateTime';
import { normalizeApiError } from '@/lib/normalizeApiError';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import Combobox, { type ComboboxOption } from '@/components/ui/Combobox';
import DocumentPreviewModal, { type PreviewTarget } from '@/components/documents/DocumentPreviewModal';
import DrawingRevisionPanel from '@/components/drawings/DrawingRevisionPanel';
import { DISCIPLINE_OPTIONS, STATUS_OPTIONS, drawingStatusColor } from '@/components/drawings/drawingConstants';

export type DrawingDocumentSummary = {
  id: number;
  title: string;
  file_name: string | null;
  reference_number: string | null;
  category: string | null;
  type: string | null;
  mime_type: string | null;
};

export type DrawingRevisionSummary = {
  id: number;
  revision_code: string | null;
  status: string | null;
  issued_date: string | null;
  issued_by: string | null;
  notes: string | null;
  created_at: string;
  updated_at: string;
  document: DrawingDocumentSummary;
  creator: { id: number; name: string } | null;
};

export type DrawingRecord = {
  id: number;
  drawing_number: string;
  title: string;
  discipline: string | null;
  status: string | null;
  location_reference: string | null;
  created_at: string;
  updated_at: string;
  // Always the EFFECTIVE document (current revision's, or the legacy
  // fallback) — resolved server-side via Drawing::effectiveDocument()
  // (Phase 4 Part H). Never read document_id/current_revision_id
  // separately to work this out on the frontend.
  document: DrawingDocumentSummary;
  // null for a Drawing that has never had an explicit revision added yet
  // (a freshly-registered or pre-Phase-4 Drawing relying entirely on the
  // legacy document fallback) — genuinely distinct from a revision that
  // exists but has a null revision_code (Part F's "Revision not
  // recorded" case). Never conflate the two in the UI.
  current_revision: DrawingRevisionSummary | null;
  creator: { id: number; name: string } | null;
};

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

/** Includes the record's current value even if it's not one of the fixed
 *  options — discipline/status are free strings on the backend (no DB
 *  enum), so a legacy/custom value must never disappear from the Select
 *  or silently reset when a form re-renders. */
function optionsWithCurrent(options: readonly string[], current: string | null): string[] {
  if (!current || (options as string[]).includes(current)) return [...options];
  return [current, ...options];
}

/**
 * Register Drawing (create, no `drawing` prop) and Drawing details/edit
 * (`drawing` prop provided) — one component, mirroring the existing
 * Snag/QaReport Modal convention of a single create+edit form gated by
 * `isEdit`. Document association is fixed after registration (Phase 1
 * Part N) — the Combobox document selector only ever appears in create
 * mode; edit mode shows the linked Document as read-only context instead.
 */
export default function DrawingModal({
  projectId,
  drawing,
  canOperate,
  onClose,
  onRemove,
}: {
  projectId: string;
  drawing?: DrawingRecord;
  canOperate: boolean;
  onClose: () => void;
  /** Opens the remove-confirmation flow in the parent page — kept there so the confirmation dialog can outlive this modal's own close animation. */
  onRemove?: (drawing: DrawingRecord) => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!drawing;

  const [documentId, setDocumentId] = useState<string>('');
  const [docSearch, setDocSearch] = useState('');
  const [form, setForm] = useState({
    drawing_number: drawing?.drawing_number ?? '',
    title: drawing?.title ?? '',
    discipline: drawing?.discipline ?? '',
    status: drawing?.status ?? '',
    location_reference: drawing?.location_reference ?? '',
  });
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);
  const [showRevisions, setShowRevisions] = useState(false);

  const set = (k: keyof typeof form, v: string) => setForm(f => ({ ...f, [k]: v }));

  // Eligible-documents lookup for the create-mode selector only (Phase 1B
  // Part L) — excludes Documents already actively registered as a Drawing,
  // server-side, via the small additive endpoint added for this purpose.
  const eligibleQuery = useQuery({
    queryKey: ['project-drawings-eligible-documents', projectId, docSearch],
    queryFn: () => api.get(`/projects/${projectId}/drawings/eligible-documents`, {
      params: { search: docSearch || undefined, per_page: 25 },
    }).then(r => r.data),
    enabled: !isEdit,
  });

  const documentOptions: ComboboxOption[] = (eligibleQuery.data?.data ?? []).map((d: {
    id: number; title: string; file_name: string | null; reference_number: string | null;
  }) => ({
    value: String(d.id),
    label: d.title || d.file_name || `Document #${d.id}`,
    description: [d.reference_number, d.file_name].filter(Boolean).join(' · ') || undefined,
  }));

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        drawing_number: form.drawing_number,
        title: form.title,
        discipline: form.discipline || null,
        status: form.status || null,
        location_reference: form.location_reference || null,
      };
      return isEdit
        ? api.put(`/projects/${projectId}/drawings/${drawing.id}`, payload).then(r => r.data)
        : api.post(`/projects/${projectId}/drawings`, { ...payload, document_id: documentId }).then(r => r.data);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Drawing updated' : 'Drawing registered');
      qc.invalidateQueries({ queryKey: ['project-drawings', projectId] });
      // A newly-registered Document is no longer eligible — and one whose
      // Drawing was just removed elsewhere becomes eligible again — so this
      // selector's own cache must never go stale across either transition.
      qc.invalidateQueries({ queryKey: ['project-drawings-eligible-documents', projectId] });
      onClose();
    },
    onError: (e: unknown) => {
      const normalized = normalizeApiError(e, isEdit ? 'Failed to update drawing.' : 'Failed to register drawing.');
      setFieldErrors(normalized.fieldErrors ?? {});
      setFormError(normalized.type === 'validation' ? null : normalized.message);
    },
  });

  const canSubmit = form.drawing_number.trim() && form.title.trim() && (isEdit || documentId);

  return (
    <>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
        <div
          className="ss-animate-in w-full max-w-lg rounded-2xl flex flex-col max-h-[90vh]"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        >
          <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              {isEdit ? 'Drawing Details' : 'Register Drawing'}
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

            {/* Linked Document — fixed after registration; the selector only
                appears in create mode (Phase 1 Part N/R). */}
            {isEdit ? (
              <div className="rounded-xl p-3 space-y-2" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                <div className="flex items-center justify-between">
                  <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                    {drawing.current_revision ? 'Current Revision Document' : 'Linked Document'}
                  </p>
                  <button
                    onClick={() => setShowRevisions(true)}
                    className="text-xs font-medium transition-colors hover:opacity-80"
                    style={{ color: 'var(--gold)' }}
                  >
                    Revisions
                  </button>
                </div>
                {drawing.current_revision && (
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-mono text-xs font-semibold" style={{ color: 'var(--text-primary)' }}>
                      {drawing.current_revision.revision_code ?? 'Revision not recorded'}
                    </span>
                    {drawing.current_revision.status && (
                      <span
                        className="px-2 py-0.5 rounded-full text-xs font-medium"
                        style={{ backgroundColor: drawingStatusColor(drawing.current_revision.status).bg, color: drawingStatusColor(drawing.current_revision.status).text }}
                      >
                        {drawing.current_revision.status}
                      </span>
                    )}
                  </div>
                )}
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0 flex items-start gap-2">
                    <FileText size={15} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--text-muted)' }} />
                    <div className="min-w-0">
                      <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{drawing.document.title}</p>
                      <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>
                        {[drawing.document.file_name, drawing.document.reference_number].filter(Boolean).join(' · ') || 'No additional reference'}
                      </p>
                    </div>
                  </div>
                  {/* A single "Preview" entry point, matching Documents' own
                      convention — DocumentPreviewModal already provides its
                      own secured (Bearer-token, blob-based) Download button
                      inside it, so a second, separately-implemented download
                      affordance here would either duplicate that logic or
                      (as a plain <a href>) silently fail, since this app
                      authenticates via an Authorization header, not cookies. */}
                  <button
                    title="Preview / Download"
                    onClick={() => setPreviewTarget({
                      id: drawing.document.id,
                      name: drawing.document.title,
                      previewEndpoint: `/documents/${drawing.document.id}/preview`,
                      downloadEndpoint: `/documents/${drawing.document.id}/download`,
                    })}
                    className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium flex-shrink-0 transition-colors hover:bg-[var(--bg-hover)]"
                    style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                  >
                    <Eye size={13} /> Preview
                  </button>
                </div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  {drawing.current_revision
                    ? 'Use Revisions above to add a new revision or view revision history.'
                    : 'To link a different document, remove this registration and register the correct document instead.'}
                </p>
              </div>
            ) : (
              <Field label="Document" required error={fieldErrors.document_id?.[0]}>
                <Combobox
                  value={documentId}
                  onValueChange={setDocumentId}
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
                  Only project documents not already registered as a drawing are shown. Upload new files from Documents first.
                </p>
              </Field>
            )}

            <div className="grid grid-cols-2 gap-4">
              <Field label="Drawing Number" required error={fieldErrors.drawing_number?.[0]}>
                <input
                  className="w-full rounded-lg px-3 py-2 text-sm outline-none border focus:border-[var(--gold)] transition-colors duration-200"
                  style={INPUT_STYLE}
                  value={form.drawing_number}
                  onChange={e => set('drawing_number', e.target.value)}
                  maxLength={100}
                  disabled={!canOperate}
                  placeholder="e.g. A101"
                  aria-invalid={!!fieldErrors.drawing_number}
                />
              </Field>
              <Field label="Discipline">
                <Select value={form.discipline} onChange={e => set('discipline', e.target.value)} disabled={!canOperate} className="w-full">
                  <option value="">Not specified</option>
                  {optionsWithCurrent(DISCIPLINE_OPTIONS, drawing?.discipline ?? null).map(d => (
                    <option key={d} value={d}>{d}</option>
                  ))}
                </Select>
              </Field>
            </div>

            <Field label="Drawing Title" required error={fieldErrors.title?.[0]}>
              <input
                className="w-full rounded-lg px-3 py-2 text-sm outline-none border focus:border-[var(--gold)] transition-colors duration-200"
                style={INPUT_STYLE}
                value={form.title}
                onChange={e => set('title', e.target.value)}
                maxLength={255}
                disabled={!canOperate}
                placeholder="e.g. Ground Floor General Arrangement"
                aria-invalid={!!fieldErrors.title}
              />
            </Field>

            <div className="grid grid-cols-2 gap-4">
              <Field label="Status">
                <Select value={form.status} onChange={e => set('status', e.target.value)} disabled={!canOperate} className="w-full">
                  <option value="">Not specified</option>
                  {optionsWithCurrent(STATUS_OPTIONS, drawing?.status ?? null).map(s => (
                    <option key={s} value={s}>{s}</option>
                  ))}
                </Select>
              </Field>
              <Field label="Location Reference">
                <input
                  className="w-full rounded-lg px-3 py-2 text-sm outline-none border focus:border-[var(--gold)] transition-colors duration-200"
                  style={INPUT_STYLE}
                  value={form.location_reference}
                  onChange={e => set('location_reference', e.target.value)}
                  maxLength={255}
                  disabled={!canOperate}
                  placeholder="e.g. Block A – Level 02"
                />
              </Field>
            </div>

            {isEdit && (
              <p className="text-xs pt-1" style={{ color: 'var(--text-muted)' }}>
                Registered by {drawing.creator?.name ?? 'Unknown'} · Updated {formatDateTime(drawing.updated_at)}
              </p>
            )}
          </div>

          <div className="flex items-center justify-between gap-3 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
            {isEdit && canOperate && onRemove ? (
              <button
                onClick={() => onRemove(drawing)}
                className="text-sm font-medium transition-colors hover:opacity-80"
                style={{ color: '#f87171' }}
              >
                Remove Drawing
              </button>
            ) : <span />}
            <div className="flex items-center gap-3">
              <Button variant="ghost" onClick={onClose}>{canOperate ? 'Cancel' : 'Close'}</Button>
              {canOperate && (
                <Button
                  onClick={() => { setFormError(null); setFieldErrors({}); mutation.mutate(); }}
                  disabled={!canSubmit || mutation.isPending}
                >
                  {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Register Drawing'}
                </Button>
              )}
            </div>
          </div>
        </div>
      </div>

      {previewTarget && (
        <DocumentPreviewModal target={previewTarget} onClose={() => setPreviewTarget(null)} />
      )}

      {showRevisions && drawing && (
        <DrawingRevisionPanel
          projectId={projectId}
          drawingId={drawing.id}
          canOperate={canOperate}
          onClose={() => setShowRevisions(false)}
          onRevisionsChanged={() => {
            qc.invalidateQueries({ queryKey: ['project-drawings', projectId] });
            qc.invalidateQueries({ queryKey: ['project-drawing', projectId, String(drawing.id)] });
          }}
        />
      )}
    </>
  );
}
