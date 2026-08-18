'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from '@/lib/toast';
import { FileText, ImageIcon, Paperclip, Trash2, Upload } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import DocumentPreviewModal, { type PreviewTarget } from './DocumentPreviewModal';

/**
 * Evidence Attachment Foundation (Phase 0) — a single reusable "Evidence"
 * section shared by Snag, RFI, and QA Report record modals. Deliberately
 * NOT a standalone Evidence module/page — it only ever renders inside an
 * existing record's own view/edit experience, scoped to that one record via
 * `attachmentsUrl`. Preview/download reuse the existing generic
 * `DocumentPreviewModal`/`/file-uploads/{id}/{preview,download}` routes
 * unchanged — this component only handles upload/list/delete.
 */

type EvidenceFile = {
  id: number;
  original_name: string;
  mime_type: string | null;
  file_size: number | null;
  created_at: string;
  uploader?: { name: string } | null;
};

function fileIcon(mimeType: string | null) {
  if (mimeType?.startsWith('image/')) return ImageIcon;
  return FileText;
}

function formatSize(bytes: number | null): string {
  if (!bytes) return '';
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export default function EvidenceSection({
  attachmentsUrl,
  queryKey,
  label = 'Evidence',
}: {
  /** e.g. `/projects/${id}/snagging/${snagId}/attachments` */
  attachmentsUrl: string;
  /** React Query cache key for this record's own attachment list. */
  queryKey: (string | number)[];
  label?: string;
}) {
  const qc = useQueryClient();
  const [preview, setPreview] = useState<PreviewTarget | null>(null);
  const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null);

  const { data, isLoading, isError } = useQuery<EvidenceFile[]>({
    queryKey,
    queryFn: () => api.get(attachmentsUrl).then(r => r.data),
  });

  const uploadMutation = useMutation({
    mutationFn: (file: File) => {
      const formData = new FormData();
      formData.append('file', file);
      return api.post(attachmentsUrl, formData, { headers: { 'Content-Type': 'multipart/form-data' } });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey });
      toast.success('Evidence uploaded');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to upload evidence.')),
  });

  const deleteMutation = useMutation({
    mutationFn: (fileId: number) => api.delete(`${attachmentsUrl}/${fileId}`),
    onMutate: (fileId: number) => setPendingDeleteId(fileId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey });
      toast.success('Evidence removed');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to remove evidence.')),
    onSettled: () => setPendingDeleteId(null),
  });

  const handleFileInput = (e: React.ChangeEvent<HTMLInputElement>) => {
    const files = e.target.files;
    if (!files || files.length === 0) return;
    Array.from(files).forEach(file => uploadMutation.mutate(file));
    e.target.value = '';
  };

  const files = data ?? [];

  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <label className="flex items-center gap-1.5 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
          <Paperclip size={12} /> {label}
        </label>
        <label
          className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
          style={{ border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
        >
          <Upload size={11} /> {uploadMutation.isPending ? 'Uploading…' : 'Upload evidence'}
          <input type="file" multiple className="hidden" onChange={handleFileInput} disabled={uploadMutation.isPending} accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt" />
        </label>
      </div>

      {isLoading ? (
        <div className="space-y-1.5">
          {[0, 1].map(i => <div key={i} className="h-9 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
        </div>
      ) : isError ? (
        <p className="text-xs" style={{ color: '#f87171' }}>Could not load evidence.</p>
      ) : files.length === 0 ? (
        <p className="text-xs py-2" style={{ color: 'var(--text-muted)' }}>No evidence attached yet.</p>
      ) : (
        <div className="space-y-1.5">
          {files.map(file => {
            const Icon = fileIcon(file.mime_type);
            const isDeleting = pendingDeleteId === file.id;
            return (
              <div
                key={file.id}
                className="flex items-center gap-2.5 px-3 py-2 rounded-lg"
                style={{ backgroundColor: 'var(--bg-elevated)', opacity: isDeleting ? 0.5 : 1 }}
              >
                <Icon size={14} style={{ color: 'var(--text-muted)' }} className="flex-shrink-0" />
                <button
                  type="button"
                  onClick={() => setPreview({
                    id: file.id,
                    name: file.original_name,
                    mimeType: file.mime_type ?? undefined,
                    previewEndpoint: `/file-uploads/${file.id}/preview`,
                    downloadEndpoint: `/file-uploads/${file.id}/download`,
                  })}
                  className="flex-1 min-w-0 text-left text-xs truncate hover:underline"
                  style={{ color: 'var(--text-primary)' }}
                  title={file.original_name}
                >
                  {file.original_name}
                </button>
                <span className="text-xs flex-shrink-0" style={{ color: 'var(--text-muted)' }}>{formatSize(file.file_size)}</span>
                <button
                  type="button"
                  onClick={() => deleteMutation.mutate(file.id)}
                  disabled={isDeleting}
                  className="p-1 rounded-md flex-shrink-0 transition-colors hover:bg-[var(--bg-hover)] disabled:opacity-50"
                  aria-label={`Delete ${file.original_name}`}
                >
                  <Trash2 size={13} style={{ color: '#f87171' }} />
                </button>
              </div>
            );
          })}
        </div>
      )}

      {preview && <DocumentPreviewModal target={preview} onClose={() => setPreview(null)} />}
    </div>
  );
}
