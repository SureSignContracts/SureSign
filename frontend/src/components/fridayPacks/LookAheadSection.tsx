'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { formatDate } from '@/lib/utils';
import Button from '@/components/ui/Button';

/**
 * Friday Pack Realignment, R1D — Look Ahead: Next Week's Programme.
 * Mirrors WeeklySummarySection's exact structure. Source material is a
 * small, deliberately-shaped list of upcoming milestones falling in the
 * next Monday-Friday window only — never the whole Programme. Final text
 * is always human-entered/confirmed; no AI synthesis from milestones.
 */

interface MilestoneItem { id: number; name: string; milestone_type: string | null; upcoming_date: string; status: string | null; }

export default function LookAheadSection({ projectId, fridayPackId, initialText, isDraft, canWrite }: {
  projectId: string; fridayPackId: string; initialText: string | null; isDraft: boolean; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const [text, setText] = useState(initialText ?? '');

  const { data, isLoading } = useQuery<{ window: { start: string; end: string }; milestones: MilestoneItem[] }>({
    queryKey: ['friday-pack-look-ahead-sources', projectId, fridayPackId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/${fridayPackId}/look-ahead-sources`).then(r => r.data),
  });

  const saveMutation = useMutation({
    mutationFn: () => api.put(`/projects/${projectId}/friday-packs/${fridayPackId}`, { look_ahead: text }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
      toast.success('Look Ahead saved');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save Look Ahead')),
  });

  const milestones = data?.milestones ?? [];

  return (
    <div className="rounded-2xl p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Look Ahead — Next Week</h2>
      {data?.window && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {formatDate(data.window.start)} &rarr; {formatDate(data.window.end)}
        </p>
      )}

      <div>
        <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Upcoming programme reference</p>
        {isLoading ? (
          <div className="h-16 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ) : milestones.length === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No programme milestones fall in next week&rsquo;s window.</p>
        ) : (
          <div className="space-y-1">
            {milestones.map(m => (
              <p key={m.id} className="text-xs" style={{ color: 'var(--text-muted)' }}>
                {formatDate(m.upcoming_date)} &middot; {m.name}{m.milestone_type ? ` (${m.milestone_type})` : ''}
              </p>
            ))}
          </div>
        )}
      </div>

      <div>
        <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Final Look Ahead — Next Week</label>
        <textarea
          value={text}
          onChange={e => setText(e.target.value)}
          disabled={!canWrite || !isDraft}
          rows={5}
          className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none disabled:opacity-60"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
        {canWrite && isDraft && (
          <div className="flex justify-end mt-2">
            <Button onClick={() => saveMutation.mutate()} size="sm" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? 'Saving…' : 'Save Look Ahead'}
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
