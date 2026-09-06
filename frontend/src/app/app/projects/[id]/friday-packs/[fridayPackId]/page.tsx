'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { ArrowLeft, FileBarChart, FileDown, RefreshCw, ClipboardCheck, CheckCircle2, Undo2, ShieldCheck, Send, RotateCw } from 'lucide-react';
import toast from '@/lib/toast';
import Button from '@/components/ui/Button';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import { useFridayPackEntitlement } from '@/hooks/useFridayPackEntitlement';
import { FridayPackUpgradeBanner } from '@/components/fridayPacks/FridayPackUpgradeNotice';
import SitePhotographsSection from '@/components/fridayPacks/SitePhotographsSection';
import WeeklySummarySection from '@/components/fridayPacks/WeeklySummarySection';
import WorkforceSection from '@/components/fridayPacks/WorkforceSection';
import ReportInformationSection from '@/components/fridayPacks/ReportInformationSection';
import MaterialsDeliveredSection from '@/components/fridayPacks/MaterialsDeliveredSection';
import SiteIssuesSection from '@/components/fridayPacks/SiteIssuesSection';
import LookAheadSection from '@/components/fridayPacks/LookAheadSection';
import SignOffSection from '@/components/fridayPacks/SignOffSection';
import RamsSection from '@/components/fridayPacks/RamsSection';
import PermitsInspectionsSection from '@/components/fridayPacks/PermitsInspectionsSection';
import SiteInductionsSection from '@/components/fridayPacks/SiteInductionsSection';
import IncidentsSection from '@/components/fridayPacks/IncidentsSection';
import HsInspectionsSection from '@/components/fridayPacks/HsInspectionsSection';
import PlantEquipmentSection from '@/components/fridayPacks/PlantEquipmentSection';
import FridayPackReadinessPanel, { type FridayPackReadiness } from '@/components/fridayPacks/FridayPackReadinessPanel';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:             { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  ready_for_review:  { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  approved:          { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  sent:              { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  failed:            { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};

const SECTION_LABELS: Record<string, string> = {
  executive_summary: 'Executive Summary',
  progress: 'Progress',
  programme: 'Programme / Milestones',
  toolbox_talks: 'Toolbox Talks',
  site_reports: 'Site Reports',
  risks: 'Risks',
  rfis: 'RFIs',
  variations: 'Variations',
  commercial: 'Commercial',
  delays_eot: 'Delays / EOT',
  meetings_actions: 'Meetings / Actions',
  delivery_documents: 'Delivery Documents',
  drawings: 'Drawings',
  qa_snagging: 'QA / Snagging',
  upcoming_actions: 'Upcoming Actions',
};

interface SnapshotSection {
  count?: number;
  items?: unknown[];
  [key: string]: unknown;
}

interface FridayPackDetail {
  id: number;
  week_ending: string;
  period_start: string;
  period_end: string;
  status: string;
  generated_at: string | null;
  generated_by?: { name: string } | null;
  generation_source: string;
  reviewed_at: string | null;
  reviewed_by?: { name: string } | null;
  approved_at: string | null;
  approved_by?: { name: string } | null;
  sent_at: string | null;
  sent_by?: { name: string } | null;
  pdf_document_id: number | null;
  executive_summary: string | null;
  progress_commentary: string | null;
  key_concerns: string | null;
  next_week_priorities: string | null;
  weekly_summary: string | null;
  site_issues_summary: string | null;
  look_ahead: string | null;
  // CRITICAL: this is the ONLY source of report content rendered below —
  // never fetch/read live project module data on this page. See
  // FridayPackSnapshotService (the sole writer) for the shape.
  snapshot_json: {
    schema_version: number;
    project: Record<string, unknown>;
    period: Record<string, unknown>;
    sections: Record<string, SnapshotSection>;
    generated_meta: Record<string, unknown>;
  };
  // R1F.2 — derived, present only for a schema-2 pack. Never a
  // percentage/score — see FridayPackReadinessPanel.
  readiness?: FridayPackReadiness;
}

/** Never rendered as a blank/dash for a scheduled pack — a real,
 * deliberate automation event, not a data gap. */
function generatedByLabel(pack: Pick<FridayPackDetail, 'generation_source' | 'generated_by'>): string {
  return pack.generation_source === 'scheduled' ? 'SureSign Automation' : (pack.generated_by?.name ?? '—');
}

/** True only for a plain nested sub-section shape (e.g. delays_eot's
 * `delay_events`/`eot_requests`, qa_snagging's `qa`/`snagging`) — an
 * object, not an array, not null. Distinguishes this from a scalar
 * summary value so it's never JSON.stringify'd onto the screen. */
function isNestedSubsection(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

/** Renders one item's fields — reused for both a section's own top-level
 * `items` and a nested sub-section's `items`. */
function ItemFields({ item }: { item: Record<string, unknown> }) {
  return (
    <>
      {Object.entries(item).map(([k, v]) => (
        v !== null && v !== undefined && v !== '' && (
          <span key={k} className="mr-3" style={{ color: 'var(--text-secondary)' }}>
            <span style={{ color: 'var(--text-muted)' }}>{k.replace(/_/g, ' ')}:</span>{' '}
            {typeof v === 'object' ? JSON.stringify(v) : String(v)}
          </span>
        )
      ))}
    </>
  );
}

/** A section's scalar summary fields (count, currency, totals, etc.) plus
 * its own `items` list, if any — used both for a top-level section and
 * for a nested sub-section (e.g. `qa`, `snagging`, `delay_events`,
 * `eot_requests`), so neither ever falls back to raw JSON. */
function SectionBody({ data }: { data: Record<string, unknown> }) {
  const items = Array.isArray(data.items) ? data.items : null;
  const scalarEntries = Object.entries(data).filter(([k, v]) => k !== 'items' && !isNestedSubsection(v));

  return (
    <>
      {scalarEntries.length > 0 && (
        <div className="flex flex-wrap gap-4 mb-3">
          {scalarEntries.map(([k, v]) => (
            <div key={k} className="text-xs" style={{ color: 'var(--text-muted)' }}>
              <span className="capitalize">{k.replace(/_/g, ' ')}:</span>{' '}
              <span className="font-medium" style={{ color: 'var(--text-secondary)' }}>{String(v)}</span>
            </div>
          ))}
        </div>
      )}
      {items && items.length === 0 && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No items recorded for this period.</p>
      )}
      {items && items.length > 0 && (
        <div className="space-y-2">
          {items.map((item, i) => (
            <div key={i} className="rounded-lg p-3 text-xs" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <ItemFields item={item as Record<string, unknown>} />
            </div>
          ))}
        </div>
      )}
    </>
  );
}

function SectionCard({ sectionKey, section }: { sectionKey: string; section: SnapshotSection }) {
  const nestedSubsections = Object.entries(section).filter(([, v]) => isNestedSubsection(v));

  return (
    <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h3 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>{SECTION_LABELS[sectionKey] ?? sectionKey}</h3>
      <SectionBody data={section} />
      {nestedSubsections.length > 0 && (
        <div className="space-y-4 mt-1">
          {nestedSubsections.map(([subKey, subData]) => (
            <div key={subKey}>
              <h4 className="text-xs font-semibold mb-2 capitalize" style={{ color: 'var(--text-secondary)' }}>{subKey.replace(/_/g, ' ')}</h4>
              <SectionBody data={subData as Record<string, unknown>} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

const COMMENTARY_FIELDS = ['executive_summary', 'progress_commentary', 'key_concerns', 'next_week_priorities'] as const;
type CommentaryState = Record<(typeof COMMENTARY_FIELDS)[number], string>;

/**
 * Mounts only once `pack` has actually resolved — its form state is
 * initialized directly from `pack` (a lazy useState initializer), never
 * synced in afterward via useEffect. Avoids react-hooks/set-state-in-effect
 * entirely rather than suppressing it (mirrors FridayPackSettingsModal's
 * identical fix).
 */
function ManualCommentaryEditor({ projectId, fridayPackId, pack, canWrite }: {
  projectId: string; fridayPackId: string; pack: FridayPackDetail; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const isDraft = pack.status === 'draft';
  const [commentary, setCommentary] = useState<CommentaryState>(() => ({
    executive_summary: pack.executive_summary ?? '',
    progress_commentary: pack.progress_commentary ?? '',
    key_concerns: pack.key_concerns ?? '',
    next_week_priorities: pack.next_week_priorities ?? '',
  }));

  const saveMutation = useMutation({
    mutationFn: () => api.put(`/projects/${projectId}/friday-packs/${fridayPackId}`, commentary).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
      toast.success('Commentary saved');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save commentary')),
  });

  return (
    <div className="rounded-2xl p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Manual Commentary</h2>
      {COMMENTARY_FIELDS.map(field => (
        <div key={field}>
          <label className="block text-xs font-medium mb-1 capitalize" style={{ color: 'var(--text-muted)' }}>{field.replace(/_/g, ' ')}</label>
          <textarea
            value={commentary[field]}
            onChange={e => setCommentary(c => ({ ...c, [field]: e.target.value }))}
            disabled={!canWrite || !isDraft}
            rows={3}
            className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none disabled:opacity-60"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>
      ))}
      {canWrite && isDraft && (
        <div className="flex justify-end">
          <Button onClick={() => saveMutation.mutate()} size="sm" disabled={saveMutation.isPending}>
            {saveMutation.isPending ? 'Saving…' : 'Save Commentary'}
          </Button>
        </div>
      )}
    </div>
  );
}

/** Mirrors the established blobDownload() pattern used across the app
 * (e.g. delay-eot/page.tsx) — reuses the existing generic Document
 * download endpoint/authorization; no new download mechanism. */
function downloadDocument(documentId: number, fileName?: string) {
  api.get(`/documents/${documentId}/download`, { responseType: 'blob' }).then(res => {
    const url = URL.createObjectURL(res.data);
    const a = window.document.createElement('a');
    a.href = url;
    a.download = fileName ?? 'friday-pack.pdf';
    a.click();
    URL.revokeObjectURL(url);
  });
}

function PdfActions({ projectId, fridayPackId, pack, canWrite }: {
  projectId: string; fridayPackId: string; pack: FridayPackDetail; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const hasCurrentPdf = pack.pdf_document_id != null;

  const generateMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/pdf`).then(r => r.data),
    onSuccess: (result) => {
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
      toast.success(hasCurrentPdf ? 'PDF regenerated' : 'PDF generated');
      downloadDocument(result.document.id, result.document.file_name);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to generate the PDF')),
  });

  return (
    <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>PDF</h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {hasCurrentPdf
              ? 'A current PDF reflects this pack’s frozen snapshot and commentary.'
              : 'No PDF has been generated yet for this pack.'}
          </p>
        </div>
        <div className="flex gap-2">
          {hasCurrentPdf && (
            <button
              onClick={() => downloadDocument(pack.pdf_document_id!)}
              className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-medium"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              <FileDown size={14} /> Download PDF
            </button>
          )}
          {canWrite && pack.status !== 'sent' && (
            <button
              onClick={() => generateMutation.mutate()}
              disabled={generateMutation.isPending}
              className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-4 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0 disabled:opacity-60"
            >
              {hasCurrentPdf ? <RefreshCw size={14} /> : <FileDown size={14} />}
              {generateMutation.isPending ? 'Generating…' : hasCurrentPdf ? 'Regenerate PDF' : 'Generate PDF'}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}

/**
 * V1D — Review/Approval lifecycle actions, status-scoped exactly per the
 * spec: Draft shows Submit for Review; Ready for Review (not yet
 * reviewed) shows Mark Reviewed + Return to Draft; Ready for Review
 * (reviewed) shows Approve + Return to Draft; Approved shows nothing
 * here (no Send yet). Backend remains authoritative for every
 * transition — these buttons are a convenience, not the source of truth.
 */
function LifecycleActions({ projectId, fridayPackId, pack, canWrite }: {
  projectId: string; fridayPackId: string; pack: FridayPackDetail; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const isReviewed = pack.reviewed_at != null;

  const invalidate = () => qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });

  const submitMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/submit-for-review`).then(r => r.data),
    onSuccess: () => { invalidate(); toast.success('Submitted for review'); },
    onError: (e: unknown) => {
      // R1F.2 — a 409 here carries the structured readiness blocker
      // payload; the Readiness panel above already renders it in full,
      // so this toast stays a short pointer rather than duplicating the
      // blocker list.
      invalidate();
      toast.error(getErrorMessage(e, 'This Friday Pack needs attention before it can be submitted for review — see Needs Attention above.'));
    },
  });

  const reviewMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/mark-reviewed`).then(r => r.data),
    onSuccess: () => { invalidate(); toast.success('Marked as reviewed'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to mark as reviewed')),
  });

  const returnMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/return-to-draft`).then(r => r.data),
    onSuccess: () => { invalidate(); toast.success('Returned to draft'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to return to draft')),
  });

  const approveMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/approve`).then(r => r.data),
    onSuccess: () => { invalidate(); toast.success('Friday Pack approved'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to approve')),
  });

  if (!canWrite || pack.status === 'approved' || pack.status === 'sent') return null;

  return (
    <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Review &amp; Approval</h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {pack.status === 'draft' && 'Submit this draft for review once it is ready.'}
            {pack.status === 'ready_for_review' && !isReviewed && 'Mark this pack as reviewed, or return it to draft to make corrections.'}
            {pack.status === 'ready_for_review' && isReviewed && 'This pack has been reviewed and is ready to approve.'}
          </p>
        </div>
        <div className="flex gap-2">
          {pack.status === 'draft' && (
            <button
              onClick={() => submitMutation.mutate()}
              disabled={submitMutation.isPending}
              className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-4 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0 disabled:opacity-60"
            >
              <ClipboardCheck size={14} /> {submitMutation.isPending ? 'Submitting…' : 'Submit for Review'}
            </button>
          )}
          {pack.status === 'ready_for_review' && (
            <>
              <button
                onClick={() => returnMutation.mutate()}
                disabled={returnMutation.isPending}
                className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-medium disabled:opacity-60"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
              >
                <Undo2 size={14} /> Return to Draft
              </button>
              {!isReviewed ? (
                <button
                  onClick={() => reviewMutation.mutate()}
                  disabled={reviewMutation.isPending}
                  className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-4 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0 disabled:opacity-60"
                >
                  <ShieldCheck size={14} /> {reviewMutation.isPending ? 'Saving…' : 'Mark Reviewed'}
                </button>
              ) : (
                <button
                  onClick={() => approveMutation.mutate()}
                  disabled={approveMutation.isPending}
                  className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-4 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0 disabled:opacity-60"
                >
                  <CheckCircle2 size={14} /> {approveMutation.isPending ? 'Approving…' : 'Approve'}
                </button>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}

/** Generated/Reviewed/Approved metadata — only rows that actually apply are shown. */
function LifecycleMetadata({ pack }: { pack: FridayPackDetail }) {
  return (
    <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>History</h2>
      <div className="flex flex-wrap gap-x-8 gap-y-3">
        <div>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Generated</p>
          <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
            {pack.generated_at ? formatDate(pack.generated_at) : '—'} · {generatedByLabel(pack)}
          </p>
        </div>
        {pack.reviewed_at && (
          <div>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Reviewed</p>
            <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
              {formatDate(pack.reviewed_at)}{pack.reviewed_by ? ` · ${pack.reviewed_by.name}` : ''}
            </p>
          </div>
        )}
        {pack.approved_at && (
          <div>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Approved</p>
            <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
              {formatDate(pack.approved_at)}{pack.approved_by ? ` · ${pack.approved_by.name}` : ''}
            </p>
          </div>
        )}
        {pack.sent_at && (
          <div>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Sent</p>
            <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
              {formatDate(pack.sent_at)}{pack.sent_by ? ` · ${pack.sent_by.name}` : ''}
            </p>
          </div>
        )}
      </div>
    </div>
  );
}

interface FridayPackDeliveryRow {
  id: number;
  recipient_name: string | null;
  recipient_email: string;
  status: 'pending' | 'sent' | 'failed';
  failure_reason: string | null;
}

const DELIVERY_STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  pending: { bg: 'rgba(90,86,82,0.2)', text: '#9a9490' },
  sent:    { bg: 'rgba(34,197,94,0.12)', text: '#4ade80' },
  failed:  { bg: 'rgba(239,68,68,0.12)', text: '#f87171' },
};

/**
 * V1F — Send / delivery status. Shown only for an approved-or-sent pack
 * that has a current PDF; Send is available only before any delivery row
 * exists (see backend FridayPackDeliveryService::initiate() — the sole
 * authority on this). Once any delivery row exists, this shows per-
 * recipient status and, if any failed, a "Retry Failed" action — the
 * only way a failed recipient is ever re-attempted; a successful
 * recipient is never resent.
 */
function DeliveryActions({ projectId, fridayPackId, pack, canWrite }: {
  projectId: string; fridayPackId: string; pack: FridayPackDetail; canWrite: boolean;
}) {
  const qc = useQueryClient();

  const { data: deliveries } = useQuery<FridayPackDeliveryRow[]>({
    queryKey: ['friday-pack-deliveries', projectId, fridayPackId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/${fridayPackId}/deliveries`).then(r => r.data),
    enabled: pack.status === 'approved' || pack.status === 'sent',
  });

  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
    qc.invalidateQueries({ queryKey: ['friday-pack-deliveries', projectId, fridayPackId] });
  };

  const sendMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/send`).then(r => r.data),
    onSuccess: () => { invalidate(); toast.success('Friday Pack sending initiated'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to send the Friday Pack')),
  });

  const retryMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/deliveries/retry-failed`).then(r => r.data),
    onSuccess: () => { invalidate(); toast.success('Retrying failed deliveries'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to retry failed deliveries')),
  });

  if (pack.status !== 'approved' && pack.status !== 'sent') return null;

  const hasDeliveries = (deliveries?.length ?? 0) > 0;
  const failedCount = (deliveries ?? []).filter(d => d.status === 'failed').length;

  return (
    <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Delivery</h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            {!hasDeliveries && 'Send this approved Friday Pack to the configured delivery recipients.'}
            {hasDeliveries && failedCount === 0 && 'Sent to every configured recipient.'}
            {hasDeliveries && failedCount > 0 && `${failedCount} recipient(s) could not be delivered.`}
          </p>
        </div>
        <div className="flex gap-2">
          {canWrite && !hasDeliveries && pack.pdf_document_id != null && (
            <button
              onClick={() => sendMutation.mutate()}
              disabled={sendMutation.isPending}
              className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-4 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0 disabled:opacity-60"
            >
              <Send size={14} /> {sendMutation.isPending ? 'Sending…' : 'Send'}
            </button>
          )}
          {canWrite && hasDeliveries && failedCount > 0 && (
            <button
              onClick={() => retryMutation.mutate()}
              disabled={retryMutation.isPending}
              className="flex h-10 items-center gap-2 whitespace-nowrap rounded-xl px-4 text-sm font-medium disabled:opacity-60"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              <RotateCw size={14} /> {retryMutation.isPending ? 'Retrying…' : 'Retry Failed'}
            </button>
          )}
        </div>
      </div>

      {hasDeliveries && (
        <div className="mt-4 space-y-2">
          {deliveries!.map(d => {
            const badge = DELIVERY_STATUS_COLORS[d.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
            return (
              <div key={d.id} className="flex items-center justify-between rounded-lg px-3 py-2" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <div>
                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{d.recipient_name || d.recipient_email}</p>
                  {d.recipient_name && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{d.recipient_email}</p>}
                </div>
                <span className="text-xs px-2 py-0.5 rounded-full capitalize font-medium" style={{ backgroundColor: badge.bg, color: badge.text }}>
                  {d.status}
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}

function ProjectFridayPackDetailPage() {
  const { id, fridayPackId } = useParams<{ id: string; fridayPackId: string }>();
  const router = useRouter();
  const { canManageFridayPacks: canWrite } = useProjectPermissions();
  const { entitled } = useFridayPackEntitlement(id);
  const canMutate = canWrite && entitled;

  const { data: pack, isLoading, isError, error } = useQuery<FridayPackDetail>({
    queryKey: ['friday-pack', id, fridayPackId],
    queryFn: () => api.get(`/projects/${id}/friday-packs/${fridayPackId}`).then(r => r.data),
  });

  if (isLoading) {
    return (
      <div className="ss-projects-page mx-auto max-w-5xl space-y-4 p-4 sm:p-6 lg:p-8">
        {[...Array(4)].map((_, i) => <div key={i} className="h-24 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />)}
      </div>
    );
  }

  if (isError || !pack) {
    return (
      <div className="ss-projects-page mx-auto max-w-5xl p-4 sm:p-6 lg:p-8">
        <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <FileBarChart size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load this Friday Pack</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
        </div>
      </div>
    );
  }

  const badge = STATUS_COLORS[pack.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
  const isSchemaV2 = pack.snapshot_json?.schema_version === 2;
  const enabledSectionKeys = Object.keys(pack.snapshot_json?.sections ?? {})
    // rendered separately: manual commentary above, and (schema 2 only)
    // the dedicated Weekly Summary / Workforce / Site Photographs curation
    // UI below — never the generic raw-JSON SectionCard fallback.
    .filter(k => ![
      'executive_summary', 'progress', 'site_photographs', 'weekly_summary', 'workforce',
      'report_information', 'materials_delivered', 'site_issues', 'look_ahead', 'sign_off',
      'rams', 'permits_inspections', 'site_inductions', 'incidents', 'hs_inspections', 'plant_equipment',
    ].includes(k));

  return (
    <div className="ss-projects-page mx-auto max-w-5xl space-y-6 p-4 sm:p-6 lg:p-8">
      <button onClick={() => router.push(`/app/projects/${id}/friday-packs`)} className="flex items-center gap-1.5 text-xs hover:underline" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Friday Packs
      </button>

      <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between flex-wrap gap-3">
          <div>
            <h1 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>
              Friday Pack — Week Ending {formatDate(pack.week_ending)}
            </h1>
            <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
              Reporting period: {formatDate(pack.period_start)} &rarr; {formatDate(pack.period_end)}
            </p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Generated {pack.generated_at ? formatDate(pack.generated_at) : '—'} by {generatedByLabel(pack)}
            </p>
          </div>
          <span className="text-xs px-3 py-1 rounded-full capitalize font-medium" style={{ backgroundColor: badge.bg, color: badge.text }}>
            {pack.status.replace(/_/g, ' ')}
          </span>
        </div>
      </div>

      {!entitled && <FridayPackUpgradeBanner />}

      {pack.readiness && (
        <FridayPackReadinessPanel projectId={id!} fridayPackId={fridayPackId!} readiness={pack.readiness} isDraft={pack.status === 'draft'} />
      )}

      <LifecycleActions projectId={id!} fridayPackId={fridayPackId!} pack={pack} canWrite={canMutate} />

      <LifecycleMetadata pack={pack} />

      <PdfActions projectId={id!} fridayPackId={fridayPackId!} pack={pack} canWrite={canMutate} />

      <DeliveryActions projectId={id!} fridayPackId={fridayPackId!} pack={pack} canWrite={canMutate} />

      {/* Manual commentary — separate from deterministic snapshot content; the
          backend remains authoritative on when editing is actually allowed. */}
      <ManualCommentaryEditor projectId={id!} fridayPackId={fridayPackId!} pack={pack} canWrite={canMutate} />

      {/* R1D — Report Information. Schema-2 packs only: a legacy
          schema-1 pack has no report_information section contract. */}
      {isSchemaV2 && pack.snapshot_json.sections.report_information && (
        <ReportInformationSection
          projectId={id!} fridayPackId={fridayPackId!}
          // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's report_information shape is defined in ReportInformationSection itself
          info={pack.snapshot_json.sections.report_information as any}
          sentAt={pack.sent_at}
        />
      )}

      {/* R1C — Weekly Summary + Workforce. Schema-2 packs only: a legacy
          schema-1 pack has no weekly_summary/workforce section contract. */}
      {isSchemaV2 && (
        <WeeklySummarySection
          projectId={id!} fridayPackId={fridayPackId!}
          initialText={pack.weekly_summary}
          isDraft={pack.status === 'draft'} canWrite={canMutate}
        />
      )}

      {isSchemaV2 && pack.snapshot_json.sections.workforce && (
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's workforce shape is defined in WorkforceSection itself
        <WorkforceSection workforce={pack.snapshot_json.sections.workforce as any} />
      )}

      {/* R1B — Site Photographs & Evidence Selection. Schema-2 packs only:
          a legacy schema-1 pack has no photo-selection contract at all. */}
      {isSchemaV2 && (
        <SitePhotographsSection
          projectId={id!} fridayPackId={fridayPackId!}
          isDraft={pack.status === 'draft'} canWrite={canMutate}
        />
      )}

      {/* R1D — Materials Delivered, Site Issues, Look Ahead, Sign Off.
          Schema-2 packs only. */}
      {isSchemaV2 && pack.snapshot_json.sections.materials_delivered && (
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's materials_delivered shape is defined in MaterialsDeliveredSection itself
        <MaterialsDeliveredSection items={(pack.snapshot_json.sections.materials_delivered as any).items ?? []} />
      )}

      {isSchemaV2 && (
        <SiteIssuesSection
          projectId={id!} fridayPackId={fridayPackId!}
          initialText={pack.site_issues_summary}
          isDraft={pack.status === 'draft'} canWrite={canMutate}
        />
      )}

      {isSchemaV2 && (
        <LookAheadSection
          projectId={id!} fridayPackId={fridayPackId!}
          initialText={pack.look_ahead}
          isDraft={pack.status === 'draft'} canWrite={canMutate}
        />
      )}

      {isSchemaV2 && <SignOffSection pack={pack} />}

      {/* R1E.1 — RAMS + Permits (existing DeliveryDocument source).
          R1E.2E — Statutory Inspections now populates the previously-empty
          `inspections` sub-array of permits_inspections. */}
      {isSchemaV2 && pack.snapshot_json.sections.rams && (
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's rams shape is defined in RamsSection itself
        <RamsSection items={(pack.snapshot_json.sections.rams as any).items ?? []} />
      )}

      {isSchemaV2 && pack.snapshot_json.sections.permits_inspections && (
        <PermitsInspectionsSection
          // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's permits_inspections shape is defined in PermitsInspectionsSection itself
          permits={(pack.snapshot_json.sections.permits_inspections as any).permits ?? []}
          // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's permits_inspections shape is defined in PermitsInspectionsSection itself
          inspections={(pack.snapshot_json.sections.permits_inspections as any).inspections ?? []}
        />
      )}

      {isSchemaV2 && pack.snapshot_json.sections.site_inductions && (
        <SiteInductionsSection
          // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's site_inductions shape is defined in SiteInductionsSection itself
          items={(pack.snapshot_json.sections.site_inductions as any).items ?? []}
          // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's site_inductions shape is defined in SiteInductionsSection itself
          totalInducted={(pack.snapshot_json.sections.site_inductions as any).total_inducted ?? 0}
        />
      )}

      {isSchemaV2 && pack.snapshot_json.sections.incidents && (
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's incidents shape is defined in IncidentsSection itself
        <IncidentsSection items={(pack.snapshot_json.sections.incidents as any).items ?? []} />
      )}

      {isSchemaV2 && pack.snapshot_json.sections.hs_inspections && (
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's hs_inspections shape is defined in HsInspectionsSection itself
        <HsInspectionsSection items={(pack.snapshot_json.sections.hs_inspections as any).items ?? []} />
      )}

      {isSchemaV2 && pack.snapshot_json.sections.plant_equipment && (
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- the frozen snapshot's plant_equipment shape is defined in PlantEquipmentSection itself
        <PlantEquipmentSection items={(pack.snapshot_json.sections.plant_equipment as any).items ?? []} />
      )}

      {/* Deterministic snapshot content — rendered exclusively from snapshot_json */}
      <div className="space-y-4">
        {enabledSectionKeys.map(key => (
          <SectionCard key={key} sectionKey={key} section={pack.snapshot_json.sections[key]} />
        ))}
      </div>
    </div>
  );
}

export default function GatedProjectFridayPackDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.friday_packs" title="Friday Packs" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <ProjectFridayPackDetailPage />
    </FeatureAvailabilityGate>
  );
}
