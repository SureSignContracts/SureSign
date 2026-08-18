'use client';

import { useEffect, useState } from 'react';
import { Download, ExternalLink, X } from 'lucide-react';
import api from '@/lib/api';
import toast from '@/lib/toast';
import Button from '@/components/ui/Button';

export type PreviewTarget = {
  id: number;
  name: string;
  mimeType?: string;
  /** API endpoint to fetch the file for preview */
  previewEndpoint: string;
  /** API endpoint to download the file */
  downloadEndpoint: string;
  /** Optional label shown below the filename */
  subtitle?: string;
  /** Optional revision / version string */
  revision?: string;
};

type PreviewType = 'pdf' | 'image' | 'html' | 'unsupported' | 'loading';

const MIME_LABELS: Record<string, string> = {
  'application/pdf': 'PDF',
  'image/png': 'PNG',
  'image/jpeg': 'JPEG',
  'image/jpg': 'JPEG',
  'image/webp': 'WEBP',
  'image/gif': 'GIF',
  'image/svg+xml': 'SVG',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
  'application/msword': 'DOC',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'XLSX',
  'application/vnd.ms-excel': 'XLS',
};

function mimeLabel(mimeType?: string): string {
  if (!mimeType) return '';
  return MIME_LABELS[mimeType] ?? mimeType.split('/')[1]?.toUpperCase() ?? mimeType.toUpperCase();
}

export default function DocumentPreviewModal({
  target,
  onClose,
}: {
  target: PreviewTarget;
  onClose: () => void;
}) {
  const [previewType, setPreviewType] = useState<PreviewType>('loading');
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [htmlContent, setHtmlContent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let revoked = false;
    setPreviewType('loading');
    setError(null);
    setHtmlContent(null);
    setObjectUrl(null);

    api
      .get(target.previewEndpoint, { responseType: 'blob' })
      .then(async (res) => {
        if (revoked) return;
        const ct: string = (res.headers['content-type'] as string) || '';

        if (ct.includes('text/html')) {
          const html = await (res.data as Blob).text();
          if (!revoked) {
            setHtmlContent(html);
            setPreviewType('html');
          }
          return;
        }

        const url = URL.createObjectURL(res.data as Blob);
        if (!revoked) {
          setObjectUrl(url);
          if (ct.includes('pdf')) setPreviewType('pdf');
          else if (ct.startsWith('image/')) setPreviewType('image');
          else setPreviewType('unsupported');
        }
      })
      .catch(() => {
        if (!revoked) {
          setError('Could not load preview. The file may be missing or inaccessible.');
          setPreviewType('unsupported');
        }
      });

    return () => {
      revoked = true;
      setObjectUrl((prev) => {
        if (prev) URL.revokeObjectURL(prev);
        return null;
      });
    };
  }, [target.previewEndpoint]);

  function handleDownload() {
    api
      .get(target.downloadEndpoint, { responseType: 'blob' })
      .then((res) => {
        const url = URL.createObjectURL(res.data as Blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = target.name;
        a.click();
        URL.revokeObjectURL(url);
      })
      .catch(() => toast.error('Download failed.'));
  }

  function handleOpenNewTab() {
    if (objectUrl) window.open(objectUrl, '_blank');
    else if (htmlContent) {
      const blob = new Blob([htmlContent], { type: 'text/html' });
      const url = URL.createObjectURL(blob);
      window.open(url, '_blank');
      setTimeout(() => URL.revokeObjectURL(url), 5000);
    }
  }

  const canOpenNewTab = !!objectUrl || !!htmlContent;
  const bodyHeight = 'calc(90vh - 148px)';

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        className="flex flex-col w-full max-w-4xl rounded-2xl overflow-hidden"
        style={{
          backgroundColor: 'var(--bg-surface)',
          border: '1px solid var(--border)',
          maxHeight: '90vh',
        }}
      >
        {/* Header */}
        <div
          className="flex items-start justify-between gap-4 px-5 py-4 flex-shrink-0"
          style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-elevated)' }}
        >
          <div className="min-w-0">
            <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>
              {target.name}
            </p>
            <div className="flex flex-wrap items-center gap-3 mt-1">
              {target.subtitle && (
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{target.subtitle}</span>
              )}
              {target.revision && (
                <span
                  className="text-xs px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: 'rgba(99,102,241,0.12)', color: '#818cf8' }}
                >
                  Rev {target.revision}
                </span>
              )}
              {target.mimeType && (
                <span className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                  {mimeLabel(target.mimeType)}
                </span>
              )}
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg flex-shrink-0 transition-colors hover:bg-[var(--bg-surface)]"
          >
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-hidden bg-[var(--bg-elevated)]" style={{ minHeight: 0 }}>
          {previewType === 'loading' && (
            <div className="flex items-center justify-center" style={{ height: bodyHeight }}>
              <div className="h-8 w-8 animate-spin rounded-full border-2 border-[var(--gold)] border-t-transparent" />
            </div>
          )}

          {previewType === 'unsupported' && (
            <div className="flex flex-col items-center justify-center gap-3 px-6" style={{ height: bodyHeight }}>
              {error ? (
                <p className="text-sm text-center" style={{ color: 'var(--text-muted)' }}>{error}</p>
              ) : (
                <>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                    Preview not available for this file type
                  </p>
                  <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>
                    This file type cannot be previewed in the browser. Use the Download button to open it locally.
                  </p>
                </>
              )}
            </div>
          )}

          {previewType === 'html' && htmlContent && (
            <iframe
              srcDoc={htmlContent}
              title={target.name}
              sandbox="allow-same-origin"
              className="w-full bg-white"
              style={{ height: bodyHeight, border: 'none' }}
            />
          )}

          {previewType === 'image' && objectUrl && (
            <div
              className="flex items-center justify-center p-4 overflow-auto"
              style={{ height: bodyHeight }}
            >
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={objectUrl}
                alt={target.name}
                className="max-w-full max-h-full object-contain rounded-lg"
              />
            </div>
          )}

          {previewType === 'pdf' && objectUrl && (
            <iframe
              src={objectUrl}
              title={target.name}
              className="w-full"
              style={{ height: bodyHeight, border: 'none' }}
            />
          )}
        </div>

        {/* Footer */}
        <div
          className="flex items-center justify-between gap-3 px-5 py-3 flex-shrink-0"
          style={{ borderTop: '1px solid var(--border)', backgroundColor: 'var(--bg-elevated)' }}
        >
          <div className="flex items-center gap-2">
            <Button onClick={handleDownload}>
              <Download size={14} />
              Download
            </Button>
            {canOpenNewTab && (
              <button
                onClick={handleOpenNewTab}
                className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-colors hover:bg-[var(--bg-hover)]"
                style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
              >
                <ExternalLink size={14} />
                Open in New Tab
              </button>
            )}
          </div>
          <button
            onClick={onClose}
            className="rounded-lg px-4 py-2 text-sm font-medium transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-muted)' }}
          >
            Close
          </button>
        </div>
      </div>
    </div>
  );
}
