'use client';

import { useEffect, useState } from 'react';
import dynamic from 'next/dynamic';
import Link from 'next/link';
import { useParams, useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { ArrowLeft, Download, FileWarning } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { drawingStatusColor } from '@/components/drawings/drawingConstants';
import type { DrawingRecord } from '@/components/drawings/DrawingModal';

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

export default function DrawingViewerPage() {
  const { id: projectId, drawingId } = useParams<{ id: string; drawingId: string }>();
  const router = useRouter();
  // Tracks the *document id* that failed, not a bare boolean — so
  // navigating straight to a different (valid) image Document is never
  // stuck showing the previous document's failure state.
  const [unsupportedDocId, setUnsupportedDocId] = useState<number | null>(null);

  const { data: drawing, isLoading, isError, error } = useQuery<DrawingRecord>({
    queryKey: ['project-drawing', projectId, drawingId],
    queryFn: () => api.get(`/projects/${projectId}/drawings/${drawingId}`).then(r => r.data),
  });

  function handleDownload() {
    if (!drawing) return;
    api.get(`/documents/${drawing.document.id}/download`, { responseType: 'blob' })
      .then((res) => {
        const url = URL.createObjectURL(res.data as Blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = drawing.document.file_name || drawing.document.title;
        a.click();
        URL.revokeObjectURL(url);
      })
      .catch(() => toast.error('Download failed.'));
  }

  const backHref = `/app/projects/${projectId}/drawings`;

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-[60vh]">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading drawing metadata…</p>
      </div>
    );
  }

  if (isError || !drawing) {
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

  const statusColor = drawingStatusColor(drawing.status);
  const fileType = classifyDocument(drawing.document);

  return (
    <div className="flex flex-col h-[calc(100vh-64px)]">
      {/* Header — construction metadata context, not a Document-preview
          header (Part G). Kept deliberately restrained: no revision, no
          hotspot count, no approval percentage, no AI summary. */}
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
              <span className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>
                &middot; {drawing.document.file_name || drawing.document.title}
              </span>
            </div>
          </div>
        </div>

        <button
          onClick={handleDownload}
          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium flex-shrink-0 transition-colors hover:bg-[var(--bg-hover)]"
          style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
        >
          <Download size={13} /> Download
        </button>
      </div>

      {/* Viewer body */}
      <div className="flex-1 min-h-0">
        {fileType === 'pdf' && (
          // key={document.id} forces a fresh instance per Document — this
          // route can navigate from one Drawing straight to another without
          // unmounting (same route pattern, only the drawingId param
          // changes), and DrawingPdfCanvas's own load effect relies on a
          // true remount (not a prop update) to reset to its initial
          // loading state safely.
          <DrawingPdfCanvas key={drawing.document.id} previewEndpoint={`/documents/${drawing.document.id}/preview`} />
        )}

        {fileType === 'image' && unsupportedDocId !== drawing.document.id && (
          <DrawingImageView
            key={drawing.document.id}
            documentId={drawing.document.id}
            alt={drawing.title}
            onUnsupported={() => setUnsupportedDocId(drawing.document.id)}
          />
        )}

        {(fileType === 'unsupported' || unsupportedDocId === drawing.document.id) && (
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
    </div>
  );
}
