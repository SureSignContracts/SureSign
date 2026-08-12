'use client';

import Button from '@/components/ui/Button';

/**
 * Drawing Phase 6A — "Remove this drawing location?" confirmation (Part I).
 * `linkCount` is wired for Phase 6B (Part AG: mention linked records that
 * will also be removed, without deleting the records themselves) — 0 in 6A
 * since no link model exists yet.
 */
export default function HotspotDeleteConfirmDialog({ linkCount, deleting, onConfirm, onCancel }: {
  linkCount: number;
  deleting: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Remove this drawing location?"
        className="w-full max-w-sm rounded-xl shadow-xl p-5"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
      >
        <h2 className="text-sm font-semibold mb-2" style={{ color: 'var(--text-primary)' }}>Remove this drawing location?</h2>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
          {linkCount > 0
            ? `Its links to ${linkCount} project record${linkCount === 1 ? '' : 's'} will also be removed. The record${linkCount === 1 ? '' : 's'} themselves will not be deleted.`
            : 'This cannot be undone.'}
        </p>
        <div className="flex items-center justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={deleting}>Cancel</Button>
          <Button type="button" variant="danger" size="sm" onClick={onConfirm} disabled={deleting}>
            {deleting ? 'Removing…' : 'Remove'}
          </Button>
        </div>
      </div>
    </div>
  );
}
