'use client';

import { useState } from 'react';
import Button from '@/components/ui/Button';

const INPUT_STYLE = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

/**
 * Drawing Phase 6A — the one small form used both to confirm a freshly
 * placed location (Part C) and to edit an existing location's label (Part
 * G). Deliberately a single optional text field — a hotspot stays
 * lightweight spatial metadata, never rich text/category/status/severity.
 */
export default function HotspotFormModal({ title, initialLabel, saving, onSave, onCancel }: {
  title: string;
  initialLabel: string;
  saving: boolean;
  onSave: (label: string) => void;
  onCancel: () => void;
}) {
  const [label, setLabel] = useState(initialLabel);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label={title}
        className="w-full max-w-sm rounded-xl shadow-xl p-5"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
      >
        <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>{title}</h2>
        <label className="block mb-4">
          <span className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>Label (optional)</span>
          <input
            type="text"
            autoFocus
            value={label}
            onChange={(e) => setLabel(e.target.value)}
            maxLength={255}
            placeholder="e.g. North stair core"
            className="w-full px-3 py-2 rounded-lg text-sm"
            style={INPUT_STYLE}
            onKeyDown={(e) => { if (e.key === 'Enter') onSave(label.trim()); }}
          />
        </label>
        <div className="flex items-center justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={saving}>Cancel</Button>
          <Button type="button" size="sm" onClick={() => onSave(label.trim())} disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </Button>
        </div>
      </div>
    </div>
  );
}
