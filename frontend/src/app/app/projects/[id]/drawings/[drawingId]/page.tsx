'use client';

import { useEffect, useState } from 'react';
import dynamic from 'next/dynamic';
import Link from 'next/link';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { ArrowLeft, Download, FileWarning, History, MapPin, X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import { drawingStatusColor } from '@/components/drawings/drawingConstants';
import type { DrawingRecord, DrawingDocumentSummary } from '@/components/drawings/DrawingModal';
import DrawingRevisionPanel from '@/components/drawings/DrawingRevisionPanel';
import DrawingHotspotOverlay, { type Hotspot, type HotspotLink, type CreatableRecordType } from '@/components/drawings/DrawingHotspotOverlay';
import HotspotFormModal from '@/components/drawings/HotspotFormModal';
import HotspotDeleteConfirmDialog from '@/components/drawings/HotspotDeleteConfirmDialog';
import HotspotLinkRecordModal from '@/components/drawings/HotspotLinkRecordModal';
import type { DrawingCreationContext } from '@/components/drawings/DrawingCreationContext';
import type { PageGeometry } from '@/components/drawings/DrawingPdfCanvas';
import SnagModal from '@/components/snags/SnagModal';
import NewRfiModal from '@/components/rfis/NewRfiModal';
import QaModal from '@/components/qa/QaModal';

// PDF.js touches `window`/`Worker`/canvas at module scope — client-only,
// loaded only once the viewer actually needs to render (same dynamic-
// import-with-ssr:false convention as SiteLocationMap/ProjectMap for
// Leaflet, which has the identical SSR constraint).
const DrawingPdfCanvas = dynamic(() => import('@/components/drawings/DrawingPdfCanvas'), {
  ssr: false,
  loading: () => (
    <div className="flex items-center justify-center h-full">
      <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading document…</p>
    </div>
  ),
});

/**
 * Mirrors DocumentController::previewDocument()'s own file-type routing
 * exactly (Part D) — a DOCX is still served as `application/pdf` by that
 * endpoint's existing conversion pipeline, so it renders through the same
 * PDF.js path as a native PDF, not a separate "convertible" branch.
 */
function classifyDocument(doc: { mime_type?: string | null; file_name?: string | null }): 'pdf' | 'image' | 'unsupported' {
  const mime = doc.mime_type ?? '';
  const ext = doc.file_name?.split('.').pop()?.toLowerCase();
  if (mime.includes('pdf')) return 'pdf';
  if (mime.includes('wordprocessingml') || ext === 'docx') return 'pdf';
  if (mime.startsWith('image/')) return 'image';
  return 'unsupported';
}

/**
 * Image Documents (Part Z) — fetched via the authenticated api client,
 * exactly like DocumentPreviewModal's own image handling, never a raw
 * `<img src="/api/...">` (that would omit the Bearer Authorization header
 * entirely, the same class of bug already caught once in Phase 1B's
 * download button). Its own component (rather than inline in the page)
 * purely so the caller can mount it with `key={documentId}` — a fresh
 * instance per Document whose own useState initial values (false/null)
 * cover the reset, instead of resetting state synchronously at the top of
 * an effect on a reused instance.
 */
function DrawingImageView({ documentId, alt, onUnsupported }: { documentId: number; alt: string; onUnsupported: () => void }) {
  const [imageUrl, setImageUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let revoked = false;
    api.get(`/documents/${documentId}/preview`, { responseType: 'blob' })
      .then((res) => { if (!revoked) setImageUrl(URL.createObjectURL(res.data as Blob)); })
      .catch(() => { if (!revoked) setFailed(true); });
    return () => {
      revoked = true;
      setImageUrl((prev) => { if (prev) URL.revokeObjectURL(prev); return null; });
    };
  }, [documentId]);

  useEffect(() => {
    if (failed) onUnsupported();
  }, [failed, onUnsupported]);

  if (failed) return null;

  return (
    <div className="flex items-center justify-center h-full overflow-auto p-4" style={{ backgroundColor: 'var(--bg-elevated)' }}>
      {imageUrl ? (
        // A runtime blob: object URL, not a static/remote asset next/image
        // can optimise — DocumentPreviewModal's own image preview uses the
        // same plain <img> for the identical reason.
        // eslint-disable-next-line @next/next/no-img-element
        <img src={imageUrl} alt={alt} className="max-w-full shadow-lg" style={{ backgroundColor: '#fff' }} onError={() => setFailed(true)} />
      ) : (
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading document…</p>
      )}
    </div>
  );
}

type RevisionShowResponse = {
  revision: {
    id: number;
    revision_code: string | null;
    status: string | null;
    document: DrawingDocumentSummary;
  };
  drawing: { id: number; drawing_number: string; title: string };
  is_current: boolean;
};

export default function DrawingViewerPage() {
  const { id: projectId, drawingId } = useParams<{ id: string; drawingId: string }>();
  const router = useRouter();
  const searchParams = useSearchParams();
  const qc = useQueryClient();
  const { canOperate } = useProjectPermissions();
  const revisionParam = searchParams.get('revision');
  // Deep-link params (Part Z/AA) — from a linked construction record's
  // "Open Drawing" action. `page` opens on that page (validated/clamped by
  // DrawingPdfCanvas itself against the real page count); `hotspot`
  // optionally emphasises that one marker once it's on screen — neither
  // changes hotspot data or which revision is current.
  const initialPageParam = searchParams.get('page');
  const highlightHotspotId = searchParams.get('hotspot') ? Number(searchParams.get('hotspot')) : null;

  // Tracks the *document id* that failed, not a bare boolean — so
  // navigating straight to a different (valid) image Document is never
  // stuck showing the previous document's failure state.
  const [unsupportedDocId, setUnsupportedDocId] = useState<number | null>(null);
  const [showRevisions, setShowRevisions] = useState(false);
  const [pageGeometry, setPageGeometry] = useState<PageGeometry | null>(null);

  // Drawing Phase 6A — authoring state (Part J: the Viewer page owns all of
  // this; DrawingHotspotOverlay only turns it into geometry/callbacks).
  const [authoringMode, setAuthoringMode] = useState<'idle' | 'placing' | 'moving'>('idle');
  const [moveHotspotId, setMoveHotspotId] = useState<number | null>(null);
  const [pendingPlacement, setPendingPlacement] = useState<{ x: number; y: number } | null>(null);
  const [editingHotspot, setEditingHotspot] = useState<Hotspot | null>(null);
  const [deletingHotspot, setDeletingHotspot] = useState<Hotspot | null>(null);
  const [savingHotspot, setSavingHotspot] = useState(false);
  const [deletingBusy, setDeletingBusy] = useState(false);

  // Drawing Phase 6B — linking state. `openHotspotId` tracks whichever
  // marker's popover is currently expanded (initialised from the
  // `?hotspot=` deep-link param, Part AA) so this page knows which
  // hotspot's links to fetch; DrawingHotspotOverlay owns the popover's own
  // open/closed UI state independently but calls back here via
  // onDetailsOpen whenever it opens one.
  const [openHotspotId, setOpenHotspotId] = useState<number | null>(highlightHotspotId);
  const [linkingHotspot, setLinkingHotspot] = useState<Hotspot | null>(null);
  // Drawing Phase 7B3, Part 10/30 — which shared create modal is open and
  // for which hotspot. Keyed by the hotspot object itself (not just its id)
  // so drawingCreationContext below never needs a second lookup, and a new
  // create request for a different hotspot always replaces this outright
  // rather than merging into stale state.
  const [createRecordState, setCreateRecordState] = useState<{ type: CreatableRecordType; hotspot: Hotspot } | null>(null);

  const drawingQueryKey = ['project-drawing', projectId, drawingId];
  const { data: drawing, isLoading, isError, error } = useQuery<DrawingRecord>({
    queryKey: drawingQueryKey,
    queryFn: () => api.get(`/projects/${projectId}/drawings/${drawingId}`).then(r => r.data),
  });

  // Opening an older revision (Part N) must NEVER change current_revision_id
  // — this is a pure read, a separate query keyed by the requested revision
  // id, never a mutation of the Drawing's own current-revision state.
  const { data: revisionData, isLoading: isRevisionLoading, isError: isRevisionError } = useQuery<RevisionShowResponse>({
    queryKey: ['drawing-revision', projectId, drawingId, revisionParam],
    queryFn: () => api.get(`/projects/${projectId}/drawings/${drawingId}/revisions/${revisionParam}`).then(r => r.data),
    enabled: !!revisionParam,
  });

  // The exact revision currently being displayed — whichever the URL names
  // historically, else the Drawing's own current revision. `null` when the
  // Drawing has no revision at all yet (legacy/not-yet-revisioned) — Part R
  // is explicit that hotspot functionality is simply unavailable then,
  // never silently attached to Drawing.document_id/effectiveDocument().
  const activeRevisionId = revisionParam ? Number(revisionParam) : (drawing?.current_revision?.id ?? null);

  // Fetched once per revision (Part J) — never refetched merely because
  // zoom/Fit Width/page changes; DrawingHotspotOverlay filters this same
  // already-loaded list by the canvas' own reported active page.
  const { data: hotspotsData } = useQuery<{ data: Hotspot[] }>({
    queryKey: ['drawing-hotspots', projectId, drawingId, activeRevisionId],
    queryFn: () => api.get(`/projects/${projectId}/drawings/${drawingId}/revisions/${activeRevisionId}/hotspots`).then(r => r.data),
    enabled: !!activeRevisionId,
  });
  const hotspots = hotspotsData?.data ?? [];
  const hotspotsQueryKey = ['drawing-hotspots', projectId, drawingId, activeRevisionId];
  const hotspotsBaseUrl = `/projects/${projectId}/drawings/${drawingId}/revisions/${activeRevisionId}/hotspots`;

  // Drawing Phase 6B — fetched only for whichever hotspot's popover is
  // currently open (Part J: never all hotspots' links up front). Keyed by
  // hotspot id so switching between two open markers never shows stale data.
  const linksQueryKey = ['drawing-hotspot-links', projectId, drawingId, activeRevisionId, openHotspotId];
  const { data: linksData, isLoading: linksLoading } = useQuery<{ data: HotspotLink[] }>({
    queryKey: linksQueryKey,
    queryFn: () => api.get(`${hotspotsBaseUrl}/${openHotspotId}/links`).then(r => r.data),
    enabled: !!activeRevisionId && !!openHotspotId,
  });
  const openHotspotLinks = linksData?.data ?? [];

  function cancelAuthoring() {
    setAuthoringMode('idle');
    setMoveHotspotId(null);
    setPendingPlacement(null);
  }

  function toggleAddLocation() {
    if (authoringMode === 'placing') {
      cancelAuthoring();
    } else {
      setAuthoringMode('placing');
      setPendingPlacement(null);
    }
  }

  function handlePlaceClick(x: number, y: number) {
    setPendingPlacement({ x, y });
  }

  async function confirmPlacement(label: string) {
    if (!pendingPlacement || !activeRevisionId || !pageGeometry) return;
    setSavingHotspot(true);
    try {
      await api.post(hotspotsBaseUrl, {
        page_number: pageGeometry.pageNumber,
        x: pendingPlacement.x,
        y: pendingPlacement.y,
        label: label || null,
      });
      await qc.invalidateQueries({ queryKey: hotspotsQueryKey });
      toast.success('Drawing location added.');
      cancelAuthoring();
    } catch (e) {
      toast.error(getErrorMessage(e, 'Could not save this location.'));
    } finally {
      setSavingHotspot(false);
    }
  }

  function handleStartMove(hotspot: Hotspot) {
    setAuthoringMode('moving');
    setMoveHotspotId(hotspot.id);
  }

  async function handleMoveClick(hotspotId: number, x: number, y: number) {
    if (!activeRevisionId) return;
    try {
      await api.put(`${hotspotsBaseUrl}/${hotspotId}`, { x, y });
      await qc.invalidateQueries({ queryKey: hotspotsQueryKey });
      toast.success('Drawing location moved.');
    } catch (e) {
      toast.error(getErrorMessage(e, 'Could not move this location.'));
    } finally {
      cancelAuthoring();
    }
  }

  async function confirmLabelEdit(label: string) {
    if (!editingHotspot || !activeRevisionId) return;
    setSavingHotspot(true);
    try {
      await api.put(`${hotspotsBaseUrl}/${editingHotspot.id}`, { label: label || null });
      await qc.invalidateQueries({ queryKey: hotspotsQueryKey });
      toast.success('Label updated.');
      setEditingHotspot(null);
    } catch (e) {
      toast.error(getErrorMessage(e, 'Could not update the label.'));
    } finally {
      setSavingHotspot(false);
    }
  }

  async function confirmDelete() {
    if (!deletingHotspot || !activeRevisionId) return;
    setDeletingBusy(true);
    try {
      await api.delete(`${hotspotsBaseUrl}/${deletingHotspot.id}`);
      await qc.invalidateQueries({ queryKey: hotspotsQueryKey });
      toast.success('Drawing location removed.');
      setDeletingHotspot(null);
    } catch (e) {
      toast.error(getErrorMessage(e, 'Could not remove this location.'));
    } finally {
      setDeletingBusy(false);
    }
  }

  async function handleLinkRecord(type: string, recordId: number) {
    if (!linkingHotspot || !activeRevisionId) return;
    await api.post(`${hotspotsBaseUrl}/${linkingHotspot.id}/links`, { type, record_id: recordId });
    await qc.invalidateQueries({ queryKey: ['drawing-hotspot-links', projectId, drawingId, activeRevisionId, linkingHotspot.id] });
    toast.success('Record linked.');
    setLinkingHotspot(null);
  }

  async function handleUnlink(link: HotspotLink) {
    if (!openHotspotId || !activeRevisionId) return;
    try {
      await api.delete(`${hotspotsBaseUrl}/${openHotspotId}/links/${link.id}`);
      await qc.invalidateQueries({ queryKey: ['drawing-hotspot-links', projectId, drawingId, activeRevisionId, openHotspotId] });
      toast.success('Link removed.');
    } catch (e) {
      toast.error(getErrorMessage(e, 'Could not remove this link.'));
    }
  }

  // Drawing Phase 7B3, Part 2/11 — the popover only reports the user's
  // choice; this page decides which shared modal to open. Deliberately
  // does NOT touch openHotspotId/setOpenHotspotId — the underlying hotspot
  // popover stays exactly as it was (Part 11/14).
  function handleCreateRecord(hotspot: Hotspot, type: CreatableRecordType) {
    setCreateRecordState({ type, hotspot });
  }

  // Drawing Phase 7B3, Part 12 — the ONE targeted invalidation this phase
  // adds. `hotspot` is captured from createRecordState at call time, not
  // read from openHotspotId, so this is correct even if openHotspotId were
  // ever to change while the modal is open.
  function handleRecordCreated(hotspot: Hotspot, showToast: boolean) {
    if (activeRevisionId) {
      qc.invalidateQueries({ queryKey: ['drawing-hotspot-links', projectId, drawingId, activeRevisionId, hotspot.id] });
    }
    // Snag/QA modals have no success toast of their own (unlike NewRfiModal,
    // which already shows "RFI raised") — Part 12's "optionally show
    // existing success toast if the modal does not already show one".
    if (showToast) toast.success('Record created and linked to this Drawing location.');
    setCreateRecordState(null);
  }

  function handleDownload(document: DrawingDocumentSummary) {
    api.get(`/documents/${document.id}/download`, { responseType: 'blob' })
      .then((res) => {
        const url = URL.createObjectURL(res.data as Blob);
        const a = window.document.createElement('a');
        a.href = url;
        a.download = document.file_name || document.title;
        a.click();
        URL.revokeObjectURL(url);
      })
      .catch(() => toast.error('Download failed.'));
  }

  const backHref = `/app/projects/${projectId}/drawings`;

  if (isLoading || (revisionParam && isRevisionLoading)) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading drawing metadata…</p>
      </div>
    );
  }

  if (isError || !drawing || (revisionParam && isRevisionError)) {
    return (
      <div className="flex flex-col items-center justify-center h-[60vh] gap-3 text-center px-6">
        <FileWarning size={28} style={{ color: '#f87171' }} />
        <p className="text-sm" style={{ color: 'var(--text-primary)' }}>
          {getErrorMessage(error, 'This drawing could not be loaded.')}
        </p>
        <Link href={backHref} className="text-sm font-medium mt-2" style={{ color: 'var(--gold)' }}>
          Back to Drawing Register
        </Link>
      </div>
    );
  }

  // Viewing a specific (possibly historical) revision resolves its OWN
  // document; otherwise the Drawing's already-effective document (current
  // revision's, or the legacy fallback) is used — one variable the rest of
  // the page renders from, so the PDF/image branches below never need to
  // know which case they're in.
  const viewingRevision = revisionParam ? revisionData?.revision : null;
  const activeDocument = viewingRevision?.document ?? drawing.document;
  const isHistorical = !!revisionParam && revisionData?.is_current === false;

  const statusColor = drawingStatusColor(drawing.status);
  const fileType = classifyDocument(activeDocument);

  const currentRevisionLabel = drawing.current_revision
    ? (drawing.current_revision.revision_code ?? 'Revision not recorded')
    : null;
  const viewingRevisionLabel = viewingRevision
    ? (viewingRevision.revision_code ?? 'Revision not recorded')
    : null;

  // Drawing Phase 6A, Part D/E — authoring (place/edit/move/delete) is only
  // ever available on the Drawing's current revision, to an operator. A
  // historical revision (isHistorical) and a Drawing with no revision at all
  // (activeRevisionId null) are both always read-only, never a fallback to
  // Drawing.document_id/effectiveDocument().
  const editable = canOperate && !!activeRevisionId && !isHistorical;
  // Drawing Phase 7B3, Part 3 — construction-record relationship actions
  // (Create Record, Link Existing, Unlink) are intentionally NOT gated on
  // !isHistorical, unlike `editable` above — Phase 7B1 deliberately relaxed
  // this on the backend for exactly this reason. A Drawing with no revision
  // at all still has nothing to manage links against, so that check stays.
  const canManageLinks = canOperate && !!activeRevisionId;
  const noCurrentRevision = !revisionParam && !drawing.current_revision;

  // Drawing Phase 7B3, Part 4/20 — built from the ACTIVE (possibly
  // historical) revision being viewed, never drawing.current_revision;
  // Part 20 is explicit that a historical hotspot's context header must
  // show its own historical revision label, not the current one.
  const drawingCreationContext: DrawingCreationContext | null = createRecordState ? {
    hotspotId: createRecordState.hotspot.id,
    drawingNumber: drawing.drawing_number,
    revisionLabel: viewingRevisionLabel,
    pageNumber: createRecordState.hotspot.page_number,
    hotspotLabel: createRecordState.hotspot.label,
  } : null;

  return (
    <div className="flex flex-col h-[calc(100vh-64px)]">
      {/* Header — construction metadata context, not a Document-preview
          header (Part G). Kept deliberately restrained: no hotspot count,
          no approval percentage, no AI summary. */}
      <div
        className="flex items-center justify-between gap-3 px-4 py-3 flex-wrap flex-shrink-0"
        style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}
      >
        <div className="flex items-center gap-3 min-w-0">
          <button
            onClick={() => router.push(backHref)}
            aria-label="Back to Drawing Register"
            className="p-2 rounded-lg flex-shrink-0 transition-colors hover:bg-[var(--bg-hover)]"
          >
            <ArrowLeft size={16} style={{ color: 'var(--text-secondary)' }} />
          </button>
          <div className="min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="font-mono text-sm font-semibold" style={{ color: 'var(--gold)' }}>{drawing.drawing_number}</span>
              <h1 className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{drawing.title}</h1>
            </div>
            <div className="flex items-center gap-2 flex-wrap mt-0.5">
              {drawing.discipline && (
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{drawing.discipline}</span>
              )}
              {drawing.status && (
                <span
                  className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                  style={{ backgroundColor: statusColor.bg, color: statusColor.text }}
                >
                  {drawing.status}
                </span>
              )}
              {drawing.location_reference && (
                <span className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{drawing.location_reference}</span>
              )}
              {/* Revision context — real metadata only, never an invented
                  label for a migrated/unrecorded revision (Part Q/R). */}
              {viewingRevisionLabel ? (
                <span className="text-xs font-medium" style={{ color: isHistorical ? '#fb923c' : 'var(--gold)' }}>
                  Revision {viewingRevisionLabel} {isHistorical ? '· Historical' : '· Current'}
                </span>
              ) : currentRevisionLabel ? (
                <span className="text-xs font-medium" style={{ color: 'var(--gold)' }}>
                  Revision {currentRevisionLabel} · Current
                </span>
              ) : null}
              <span className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>
                &middot; {activeDocument.file_name || activeDocument.title}
              </span>
            </div>
          </div>
        </div>

        <div className="flex items-center gap-2 flex-shrink-0">
          {fileType === 'pdf' && editable && (
            <button
              onClick={toggleAddLocation}
              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
              style={authoringMode === 'placing'
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              {authoringMode === 'placing' ? <X size={13} /> : <MapPin size={13} />}
              {authoringMode === 'placing' ? 'Cancel' : 'Add Location'}
            </button>
          )}
          <button
            onClick={() => setShowRevisions(true)}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:bg-[var(--bg-hover)]"
            style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
          >
            <History size={13} /> Revisions
          </button>
          <button
            onClick={() => handleDownload(activeDocument)}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors hover:bg-[var(--bg-hover)]"
            style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
          >
            <Download size={13} /> Download
          </button>
        </div>
      </div>

      {/* Authoring instruction banner (Part C/H) — only while actively
          placing or moving a location. */}
      {authoringMode !== 'idle' && (
        <div
          className="flex items-center justify-between gap-3 px-4 py-2 flex-wrap flex-shrink-0"
          style={{ backgroundColor: 'rgba(212,175,55,0.1)', borderBottom: '1px solid rgba(212,175,55,0.3)' }}
        >
          <p className="text-xs font-medium" style={{ color: 'var(--gold)' }}>
            {authoringMode === 'placing'
              ? 'Click the drawing to place a location.'
              : 'Click a new position on this page.'}
          </p>
          <button onClick={cancelAuthoring} className="text-xs font-medium flex-shrink-0" style={{ color: 'var(--gold)', textDecoration: 'underline' }}>
            Cancel
          </button>
        </div>
      )}

      {/* No current revision yet (Part E) — authoring stays honestly
          unavailable; never a fallback to the legacy document. */}
      {canOperate && noCurrentRevision && fileType === 'pdf' && (
        <div
          className="flex items-center px-4 py-2 flex-shrink-0"
          style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}
        >
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Add a drawing revision before recording drawing locations.
          </p>
        </div>
      )}

      {/* Historical banner — never let historical content look current
          (Part N, mandatory). */}
      {isHistorical && (
        <div
          className="flex items-center justify-between gap-3 px-4 py-2 flex-wrap flex-shrink-0"
          style={{ backgroundColor: 'rgba(249,115,22,0.1)', borderBottom: '1px solid rgba(249,115,22,0.3)' }}
        >
          <p className="text-xs font-medium" style={{ color: '#fb923c' }}>
            Viewing revision {viewingRevisionLabel} — not the current revision.
            {currentRevisionLabel && ` Current revision: ${currentRevisionLabel}.`}
          </p>
          <button
            onClick={() => router.push(`/app/projects/${projectId}/drawings/${drawingId}`)}
            className="text-xs font-medium flex-shrink-0"
            style={{ color: '#fb923c', textDecoration: 'underline' }}
          >
            View current revision
          </button>
        </div>
      )}

      {/* Viewer body */}
      <div className="flex-1 min-h-0">
        {fileType === 'pdf' && (
          // key={document.id} forces a fresh instance per Document — this
          // route can navigate from one Drawing/revision straight to
          // another without unmounting (same route pattern, only the
          // drawingId/revision param changes), and DrawingPdfCanvas's own
          // load effect relies on a true remount (not a prop update) to
          // reset to its initial loading state safely.
          <DrawingPdfCanvas
            key={activeDocument.id}
            previewEndpoint={`/documents/${activeDocument.id}/preview`}
            initialPage={initialPageParam ? Number(initialPageParam) : undefined}
            onPageGeometryChange={setPageGeometry}
          >
            <DrawingHotspotOverlay
              hotspots={hotspots}
              pageGeometry={pageGeometry}
              editable={editable}
              canManageLinks={canManageLinks}
              placementMode={authoringMode === 'placing'}
              moveHotspotId={authoringMode === 'moving' ? moveHotspotId : null}
              pendingPlacement={pendingPlacement}
              initialOpenHotspotId={highlightHotspotId}
              links={openHotspotLinks}
              linksLoading={linksLoading}
              onPlace={handlePlaceClick}
              onMove={handleMoveClick}
              onEditLabel={setEditingHotspot}
              onStartMove={handleStartMove}
              onDelete={setDeletingHotspot}
              onDetailsOpen={setOpenHotspotId}
              onLinkRecord={setLinkingHotspot}
              onCreateRecord={handleCreateRecord}
              onUnlink={handleUnlink}
              onOpenLink={(url) => router.push(url)}
            />
          </DrawingPdfCanvas>
        )}

        {fileType === 'image' && unsupportedDocId !== activeDocument.id && (
          <DrawingImageView
            key={activeDocument.id}
            documentId={activeDocument.id}
            alt={drawing.title}
            onUnsupported={() => setUnsupportedDocId(activeDocument.id)}
          />
        )}

        {(fileType === 'unsupported' || unsupportedDocId === activeDocument.id) && (
          <div className="flex flex-col items-center justify-center h-full gap-3 text-center px-6">
            <FileWarning size={28} style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-primary)' }}>
              This document cannot be previewed here.
            </p>
            <p className="text-xs max-w-xs" style={{ color: 'var(--text-muted)' }}>
              Use Download above to open the file locally.
            </p>
          </div>
        )}
      </div>

      {showRevisions && (
        <DrawingRevisionPanel
          projectId={projectId}
          drawingId={Number(drawingId)}
          canOperate={canOperate}
          onClose={() => setShowRevisions(false)}
          onRevisionsChanged={() => qc.invalidateQueries({ queryKey: drawingQueryKey })}
        />
      )}

      {/* Confirm a freshly placed location (Part C) — Cancel discards the
          pending marker without ever calling the API. */}
      {pendingPlacement && (
        <HotspotFormModal
          title="Add drawing location"
          initialLabel=""
          saving={savingHotspot}
          onSave={confirmPlacement}
          onCancel={cancelAuthoring}
        />
      )}

      {editingHotspot && (
        <HotspotFormModal
          title="Edit location label"
          initialLabel={editingHotspot.label ?? ''}
          saving={savingHotspot}
          onSave={confirmLabelEdit}
          onCancel={() => setEditingHotspot(null)}
        />
      )}

      {deletingHotspot && (
        <HotspotDeleteConfirmDialog
          linkCount={deletingHotspot.id === openHotspotId ? openHotspotLinks.length : 0}
          deleting={deletingBusy}
          onConfirm={confirmDelete}
          onCancel={() => setDeletingHotspot(null)}
        />
      )}

      {linkingHotspot && (
        <HotspotLinkRecordModal
          projectId={projectId}
          onLink={handleLinkRecord}
          onCancel={() => setLinkingHotspot(null)}
        />
      )}

      {/* Drawing Phase 7B3, Part 11/14 — the SAME shared, authoritative
          creation components every module page already uses (7B2), never a
          Drawing-specific form. onClose alone (Cancel/backdrop/Escape) never
          calls any API — Part 14's "no API call, no record, no link" is
          simply the shared modal's own existing Cancel behaviour, unchanged. */}
      {createRecordState?.type === 'snag' && (
        <SnagModal
          projectId={projectId}
          drawingContext={drawingCreationContext ?? undefined}
          onClose={() => setCreateRecordState(null)}
          onCreated={() => handleRecordCreated(createRecordState.hotspot, true)}
        />
      )}
      {createRecordState?.type === 'rfi' && (
        <NewRfiModal
          projectId={projectId}
          drawingContext={drawingCreationContext ?? undefined}
          onClose={() => setCreateRecordState(null)}
          // NewRfiModal already shows its own "RFI raised" toast.
          onCreated={() => handleRecordCreated(createRecordState.hotspot, false)}
        />
      )}
      {createRecordState?.type === 'qa_report' && (
        <QaModal
          projectId={projectId}
          drawingContext={drawingCreationContext ?? undefined}
          onClose={() => setCreateRecordState(null)}
          onCreated={() => handleRecordCreated(createRecordState.hotspot, true)}
        />
      )}
    </div>
  );
}
