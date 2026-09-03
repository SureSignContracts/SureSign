'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { formatDate } from '@/lib/utils';
import Button from '@/components/ui/Button';

/**
 * Friday Pack Realignment, R1C — Weekly Summary. Source material is
 * reference only (deterministic Site Report excerpts, never AI-composed);
 * the final text is always human-entered/confirmed and saved separately.
 * Mirrors SitePhotographsSection's structure — a dedicated section
 * component, not folded into the generic Manual Commentary editor.
 */

interface SourceItem {
  date: string;
  day_name: string;
  works_carried_out: string;
}

export default function WeeklySummarySection({ projectId, fridayPackId, initialText, isDraft, canWrite }: {
  projectId: string; fridayPackId: string; initialText: string | null; isDraft: boolean; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const [text, setText] = useState(initialText ?? '');

  const { data, isLoading } = useQuery<{ sources: SourceItem[]; source_site_report_count: number }>({
    queryKey: ['friday-pack-weekly-summary-sources', projectId, fridayPackId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/${fridayPackId}/weekly-summary-sources`).then(r => r.data),
  });

  const saveMutation = useMutation({
    mutationFn: () => api.put(`/projects/${projectId}/friday-packs/${fridayPackId}`, { weekly_summary: text }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
      toast.success('Weekly Summary saved');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save Weekly Summary')),
  });

  const sources = data?.sources ?? [];

  return (
    <div className="rounded-2xl p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Weekly Summary</h2>

      <div>
        <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>
          Source material from this week&rsquo;s Site Reports
        </p>
        {isLoading ? (
          <div className="h-16 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ) : sources.length === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            No Site Reports with recorded works were found for this reporting week.
          </p>
        ) : (
          <div className="space-y-2">
            {sources.map(s => (
              <div key={s.date} className="rounded-lg p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                  {s.day_name} {formatDate(s.date)} &middot; Site Report
                </p>
                <p className="text-xs whitespace-pre-wrap" style={{ color: 'var(--text-muted)' }}>{s.works_carried_out}</p>
              </div>
            ))}
          </div>
        )}
      </div>

      <div>
        <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
          Final Weekly Summary
        </label>
        <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>
          The source material above is reference only — this confirmed text is what appears in the report.
        </p>
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
              {saveMutation.isPending ? 'Saving…' : 'Save Weekly Summary'}
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
