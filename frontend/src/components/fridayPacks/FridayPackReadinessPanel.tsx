'use client';

import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, X } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import toast from '@/lib/toast';

/**
 * Friday Pack Realignment, R1F.2 — Content Readiness + Weekly
 * Declarations. Deliberately restrained: two pack-level states ("Ready
 * for review"/"Needs attention"), a deterministic blocker list, and one
 * explicit declaration action per blocker — no dashboard, no score ring,
 * no progress percentage. This should read as report workflow
 * assistance, not SaaS analytics.
 */

export interface FridayPackReadinessBlocker {
  section_key: string;
  subsection_key: string | null;
  label: string;
  reason: string;
  allowed_declarations: string[];
  declaration_statements: Record<string, string | null>;
}

export interface FridayPackReadiness {
  ready: boolean;
  blockers: FridayPackReadinessBlocker[];
  sections: Record<string, unknown>;
}

const DECLARATION_LABELS: Record<string, string> = {
  confirmed_none: 'Confirm none to report',
  not_applicable: 'Mark not applicable',
};

function DeclareDialog({ projectId, fridayPackId, blocker, declarationType, onClose }: {
  projectId: string; fridayPackId: string; blocker: FridayPackReadinessBlocker; declarationType: string; onClose: () => void;
}) {
  const qc = useQueryClient();
  const [note, setNote] = useState('');
  const statement = blocker.declaration_statements[declarationType] ?? '';

  const mutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/friday-packs/${fridayPackId}/section-declarations`, {
      section_key: blocker.section_key,
      subsection_key: blocker.subsection_key,
      declaration: declarationType,
      note: note.trim() || undefined,
    }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['friday-pack', projectId, fridayPackId] });
      toast.success('Declaration saved');
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save declaration')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-md rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{blocker.label}</h2>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <div className="p-6 space-y-4">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>You are confirming:</p>
          <p className="text-sm rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' }}>&ldquo;{statement}&rdquo;</p>
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Note (optional)</label>
            <textarea value={note} onChange={e => setNote(e.target.value)} rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          {mutation.isError && <p className="text-xs text-red-400">{getErrorMessage(mutation.error, 'Failed to save. Please try again.')}</p>}
          <div className="flex justify-end gap-3 pt-1">
            <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
              className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : 'Confirm'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}

export default function FridayPackReadinessPanel({ projectId, fridayPackId, readiness, isDraft }: {
  projectId: string; fridayPackId: string; readiness: FridayPackReadiness; isDraft: boolean;
}) {
  const [dialog, setDialog] = useState<{ blocker: FridayPackReadinessBlocker; declarationType: string } | null>(null);

  return (
    <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      {dialog && (
        <DeclareDialog projectId={projectId} fridayPackId={fridayPackId} blocker={dialog.blocker} declarationType={dialog.declarationType} onClose={() => setDialog(null)} />
      )}
      <div className="flex items-center gap-2">
        {readiness.ready ? (
          <CheckCircle2 size={16} style={{ color: '#4ade80' }} />
        ) : (
          <AlertTriangle size={16} style={{ color: '#facc15' }} />
        )}
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
          {readiness.ready ? 'Ready for review' : 'Needs attention'}
        </h2>
      </div>
      {!readiness.ready && (
        <ul className="mt-4 space-y-2">
          {readiness.blockers.map((blocker, i) => (
            <li key={i} className="flex items-center justify-between gap-3 rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{blocker.label}</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{blocker.reason}</p>
              </div>
              {isDraft && blocker.allowed_declarations.length > 0 && (
                <div className="flex gap-2 shrink-0">
                  {blocker.allowed_declarations.map(declarationType => (
                    <button
                      key={declarationType}
                      onClick={() => setDialog({ blocker, declarationType })}
                      className="text-xs px-3 py-1.5 rounded-lg font-medium whitespace-nowrap"
                      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
                    >
                      {DECLARATION_LABELS[declarationType] ?? declarationType}
                    </button>
                  ))}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
