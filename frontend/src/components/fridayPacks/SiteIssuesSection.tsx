'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { formatDate } from '@/lib/utils';
import Button from '@/components/ui/Button';

/**
 * Friday Pack Realignment, R1D — Site Issues, Delays & Risks. Mirrors
 * WeeklySummarySection's exact structure. Source material is reference
 * only (deterministic Site Report issues, plus optional week-scoped
 * DelayEvent references — never the full DelayEvent history, never the
 * Contract Risk Register); the final text is always human-entered/
 * confirmed. No AI synthesis.
 */

interface SourceItem { date: string; day_name: string; issue: string; }
interface DelayEventReference { id: number; title: string; date_occurred: string | null; cause_category: string | null; }

export default function SiteIssuesSection({ projectId, fridayPackId, initialText, isDraft, canWrite }: {
  projectId: string; fridayPackId: string; initialText: string | null; isDraft: boolean; canWrite: boolean;
}) {
  const qc = useQueryClient();
  const [text, setText] = useState(initialText ?? '');

  const { data, isLoading } = useQuery<{ sources: SourceItem[]; delay_event_references: DelayEventReference[] }>({
    queryKey: ['friday-pack-site-issues-sources', projectId, fridayPackId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/${fridayPackId}/site-issues-sources`).then(r => r.data),
  });

  const saveMutation = useMutation({
    mutationFn: () => api.put(`/projects/${projectId}/friday-packs/${fridayPackId}`, { site_issues_summary: text }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
      toast.success('Site Issues saved');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save Site Issues')),
  });

  const sources = data?.sources ?? [];
  const delayEvents = data?.delay_event_references ?? [];

  return (
    <div className="rounded-2xl p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Site Issues, Delays &amp; Risks</h2>

      <div>
        <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Source material from Site Reports</p>
        {isLoading ? (
          <div className="h-16 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ) : sources.length === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No issues were recorded on a Site Report for this reporting week.</p>
        ) : (
          <div className="space-y-2">
            {sources.map(s => (
              <div key={s.date} className="rounded-lg p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>{s.day_name} {formatDate(s.date)} &middot; Site Report</p>
                <p className="text-xs whitespace-pre-wrap" style={{ color: 'var(--text-muted)' }}>{s.issue}</p>
              </div>
            ))}
          </div>
        )}
      </div>

      {delayEvents.length > 0 && (
        <div>
          <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Relevant Delay Events this week</p>
          <div className="space-y-1">
            {delayEvents.map(d => (
              <p key={d.id} className="text-xs" style={{ color: 'var(--text-muted)' }}>
                {d.date_occurred ? formatDate(d.date_occurred) : ''} &middot; {d.title}{d.cause_category ? ` (${d.cause_category})` : ''}
              </p>
            ))}
          </div>
        </div>
      )}

      <div>
        <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Final Site Issues, Delays &amp; Risks</label>
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
              {saveMutation.isPending ? 'Saving…' : 'Save Site Issues'}
            </Button>
          </div>
        )}
      </div>
    </div>
  );
}
