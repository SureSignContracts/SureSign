'use client';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ImageIcon, ArrowUp, ArrowDown, Check, Plus, X } from 'lucide-react';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { formatDate } from '@/lib/utils';

/**
 * Automated Friday Pack, R1B — Site Photographs & Evidence Selection.
 * Candidate discovery is read-only and automatic; inclusion is always an
 * explicit user action — no candidate is ever auto-selected. Caption/
 * location/order belong to the selection itself, never the underlying
 * Site Report/Toolbox Talk evidence (editing here never mutates the
 * source). Mutation is only possible while the pack is a Draft — the
 * backend (FridayPackPhotoSelectionService) is the authoritative
 * enforcement; this component only reflects that.
 */

interface Candidate {
  source_type: string;
  source_id: number;
  source_date: string | null;
  file_upload_id: number;
  file_name: string;
  mime_type: string;
  preview_url: string;
  already_selected: boolean;
}

interface Selection {
  id: number;
  file_upload_id: number;
  preview_url: string;
  source_type: string;
  source_label: string;
  source_id: number;
  source_date: string | null;
  original_file_name: string;
  caption: string | null;
  location: string | null;
  sort_order: number;
}

/** Authenticated thumbnail — reuses the existing preview endpoint via a
 * blob fetch (no raw storage URL is ever used), mirroring
 * DocumentPreviewModal's own established pattern. */
function PhotoThumbnail({ fileUploadId, alt }: { fileUploadId: number; alt: string }) {
  const [objectUrl, setObjectUrl] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    let currentUrl: string | null = null;
    api.get(`/file-uploads/${fileUploadId}/preview`, { responseType: 'blob' }).then(res => {
      if (cancelled) return;
      currentUrl = URL.createObjectURL(res.data as Blob);
      setObjectUrl(currentUrl);
    }).catch(() => {});
    return () => {
      cancelled = true;
      if (currentUrl) URL.revokeObjectURL(currentUrl);
    };
  }, [fileUploadId]);

  return (
    <div className="w-full aspect-video rounded-lg overflow-hidden flex items-center justify-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
      {objectUrl ? (
        // eslint-disable-next-line @next/next/no-img-element -- authenticated blob URL, not a static asset
        <img src={objectUrl} alt={alt} className="w-full h-full object-cover" />
      ) : (
        <ImageIcon size={20} style={{ color: 'var(--text-muted)' }} />
      )}
    </div>
  );
}

function CandidateCard({ candidate, projectId, fridayPackId, canWrite }: {
  candidate: Candidate; projectId: string; fridayPackId: string; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const invalidate = () => {
    qc.invalidateQueries({ queryKey: ['friday-pack-photo-candidates', projectId, fridayPackId] });
    qc.invalidateQueries({ queryKey: ['friday-pack-photo-selections', projectId, fridayPackId] });
    qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
  };

  const selectMutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/photo-selections`, {
      file_upload_id: candidate.file_upload_id,
    }),
    onSuccess: () => { invalidate(); toast.success('Photo selected'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to select photo')),
  });

  return (
    <div className="rounded-xl p-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <PhotoThumbnail fileUploadId={candidate.file_upload_id} alt={candidate.file_name} />
      <p className="text-xs mt-2 truncate" style={{ color: 'var(--text-secondary)' }} title={candidate.file_name}>{candidate.file_name}</p>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
        {candidate.source_date ? formatDate(candidate.source_date) : '—'} &middot; {candidate.source_type === 'site_report' ? 'Site Report' : 'Toolbox Talk'}
      </p>
      {canWrite && !candidate.already_selected && (
        <button
          onClick={() => selectMutation.mutate()}
          disabled={selectMutation.isPending}
          className="mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg py-1.5 text-xs font-medium disabled:opacity-60"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={12} /> {selectMutation.isPending ? 'Including…' : 'Include'}
        </button>
      )}
      {candidate.already_selected && (
        <p className="mt-2 flex items-center justify-center gap-1 text-xs font-medium" style={{ color: '#4ade80' }}>
          <Check size={12} /> Included
        </p>
      )}
    </div>
  );
}

export default function SitePhotographsSection({ projectId, fridayPackId, isDraft, canWrite }: {
  projectId: string; fridayPackId: string; isDraft: boolean; canWrite: boolean;
}) {
  const { data: selections, isLoading: selectionsLoading } = useQuery<Selection[]>({
    queryKey: ['friday-pack-photo-selections', projectId, fridayPackId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/${fridayPackId}/photo-selections`).then(r => r.data),
  });

  const { data: candidateData, isLoading: candidatesLoading } = useQuery<{ candidates: Candidate[]; candidate_count: number; selected_count: number }>({
    queryKey: ['friday-pack-photo-candidates', projectId, fridayPackId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/${fridayPackId}/photo-candidates`).then(r => r.data),
    enabled: isDraft,
  });

  const qc = useQueryClient();
  const moveMutation = useMutation({
    mutationFn: (orderedIds: number[]) => api.put(`/projects/${projectId}/friday-packs/${fridayPackId}/photo-selections/reorder`, { ordered_selection_ids: orderedIds }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack-photo-selections', projectId, fridayPackId] });
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to reorder photos')),
  });

  const orderedSelections = (selections ?? []).slice().sort((a, b) => a.sort_order - b.sort_order);
  const candidates = candidateData?.candidates ?? [];

  function move(index: number, direction: -1 | 1) {
    const target = index + direction;
    if (target < 0 || target >= orderedSelections.length) return;
    const ids = orderedSelections.map(s => s.id);
    [ids[index], ids[target]] = [ids[target], ids[index]];
    moveMutation.mutate(ids);
  }

  return (
    <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between flex-wrap gap-2 mb-4">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Site Photographs</h2>
        {candidateData && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {candidateData.candidate_count} photograph{candidateData.candidate_count === 1 ? '' : 's'} found this reporting week &middot; {candidateData.selected_count} selected
          </p>
        )}
      </div>

      {selectionsLoading ? (
        <div className="h-16 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      ) : orderedSelections.length === 0 ? (
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>No photographs have been selected for this Friday Pack yet.</p>
      ) : (
        <div className="space-y-2 mb-5">
          {orderedSelections.map((selection, i) => (
            <div key={selection.id} className="rounded-xl p-3 flex gap-3" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              <div className="w-32 flex-shrink-0">
                <PhotoThumbnail fileUploadId={selection.file_upload_id} alt={selection.original_file_name} />
              </div>
              <div className="flex-1 min-w-0 space-y-1.5">
                <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>
                  {selection.source_date ? formatDate(selection.source_date) : '—'} &middot; {selection.source_label}
                </p>
                <CaptionLocationFields
                  projectId={projectId} fridayPackId={fridayPackId} selection={selection} canWrite={canWrite && isDraft}
                />
              </div>
              {canWrite && isDraft && (
                <div className="flex flex-col items-center justify-between gap-1">
                  <div className="flex flex-col gap-1">
                    <button onClick={() => move(i, -1)} disabled={i === 0 || moveMutation.isPending} aria-label="Move photo up in order" className="p-1 rounded-md disabled:opacity-30" style={{ backgroundColor: 'var(--bg-surface)' }}>
                      <ArrowUp size={12} style={{ color: 'var(--text-secondary)' }} />
                    </button>
                    <button onClick={() => move(i, 1)} disabled={i === orderedSelections.length - 1 || moveMutation.isPending} aria-label="Move photo down in order" className="p-1 rounded-md disabled:opacity-30" style={{ backgroundColor: 'var(--bg-surface)' }}>
                      <ArrowDown size={12} style={{ color: 'var(--text-secondary)' }} />
                    </button>
                  </div>
                  <RemoveButton projectId={projectId} fridayPackId={fridayPackId} selectionId={selection.id} />
                </div>
              )}
            </div>
          ))}
        </div>
      )}

      {isDraft && canWrite && (
        <>
          <h3 className="text-xs font-semibold mb-2" style={{ color: 'var(--text-secondary)' }}>Available Photographs</h3>
          {candidatesLoading ? (
            <div className="h-24 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ) : candidates.length === 0 ? (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              No site photographs were found for this reporting week. Add photographs to the relevant Site Report or Toolbox Talk to have them appear here.
            </p>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
              {candidates.map(c => (
                <CandidateCard key={c.file_upload_id} candidate={c} projectId={projectId} fridayPackId={fridayPackId} canWrite={canWrite} />
              ))}
            </div>
          )}
        </>
      )}
    </div>
  );
}

function CaptionLocationFields({ projectId, fridayPackId, selection, canWrite }: {
  projectId: string; fridayPackId: string; selection: Selection; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const [caption, setCaption] = useState(selection.caption ?? '');
  const [location, setLocation] = useState(selection.location ?? '');

  const updateMutation = useMutation({
    mutationFn: () => api.put(`/projects/${projectId}/friday-packs/${fridayPackId}/photo-selections/${selection.id}`, { caption, location }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['friday-pack-photo-selections', projectId, fridayPackId] }),
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save caption/location')),
  });

  return (
    <>
      <input
        value={caption}
        onChange={e => setCaption(e.target.value)}
        onBlur={() => canWrite && updateMutation.mutate()}
        disabled={!canWrite}
        placeholder="Caption"
        className="w-full px-2.5 py-1.5 rounded-lg text-xs outline-none disabled:opacity-60"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
      <input
        value={location}
        onChange={e => setLocation(e.target.value)}
        onBlur={() => canWrite && updateMutation.mutate()}
        disabled={!canWrite}
        placeholder="Location"
        className="w-full px-2.5 py-1.5 rounded-lg text-xs outline-none disabled:opacity-60"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
    </>
  );
}

function RemoveButton({ projectId, fridayPackId, selectionId }: { projectId: string; fridayPackId: string; selectionId: number }) {
  const qc = useQueryClient();
  const removeMutation = useMutation({
    mutationFn: () => api.delete(`/projects/${projectId}/friday-packs/${fridayPackId}/photo-selections/${selectionId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack-photo-selections', projectId, fridayPackId] });
      qc.invalidateQueries({ queryKey: ['friday-pack-photo-candidates', projectId, fridayPackId] });
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
      toast.success('Photo removed');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to remove photo')),
  });

  return (
    <button onClick={() => removeMutation.mutate()} disabled={removeMutation.isPending} aria-label="Remove photo from Friday Pack" className="p-1 rounded-md disabled:opacity-50">
      <X size={13} style={{ color: '#f87171' }} />
    </button>
  );
}
