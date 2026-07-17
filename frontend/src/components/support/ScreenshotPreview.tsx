'use client';

import { useEffect, useState } from 'react';
import { Download } from 'lucide-react';
import api from '@/lib/api';
import { formatBytes } from '@/lib/formatBytes';

export interface SupportTicketScreenshot {
  id: number;
  file_size: number;
  mime_type: string;
  preview_url: string;
}

/** Fetches an authenticated support-ticket screenshot as a blob and renders it — the preview_url is not a public/guessable link, so it can't be used directly as an <img src>. */
export function ScreenshotPreview({ ticketId, screenshot }: { ticketId: number; screenshot: SupportTicketScreenshot }) {
  const [objectUrl, setObjectUrl] = useState<string | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    let revoked = false;
    let url: string | null = null;

    api.get(screenshot.preview_url, { responseType: 'blob' })
      .then((res) => {
        if (revoked) return;
        url = URL.createObjectURL(res.data as Blob);
        setObjectUrl(url);
      })
      .catch(() => !revoked && setError(true));

    return () => {
      revoked = true;
      if (url) URL.revokeObjectURL(url);
    };
  }, [screenshot.preview_url]);

  function handleDownload() {
    api.get(screenshot.preview_url, { responseType: 'blob' }).then((res) => {
      const url = URL.createObjectURL(res.data as Blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `support-${ticketId}-screenshot`;
      a.click();
      URL.revokeObjectURL(url);
    });
  }

  if (error) {
    return <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Screenshot could not be loaded.</p>;
  }

  return (
    <div className="space-y-1.5">
      {objectUrl ? (
        // eslint-disable-next-line @next/next/no-img-element -- authenticated blob URL, next/image can't fetch it
        <img src={objectUrl} alt="Support ticket screenshot" className="rounded-lg max-h-64" style={{ border: '1px solid var(--border)' }} />
      ) : (
        <div className="h-24 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      )}
      <button
        onClick={handleDownload}
        className="flex items-center gap-1.5 text-xs font-medium hover:opacity-80"
        style={{ color: 'var(--gold)' }}
      >
        <Download size={12} />
        Download ({formatBytes(screenshot.file_size)})
      </button>
    </div>
  );
}
