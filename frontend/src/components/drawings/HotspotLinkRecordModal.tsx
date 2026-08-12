'use client';

import { useState } from 'react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import Combobox, { type ComboboxOption } from '@/components/ui/Combobox';

const RECORD_TYPES = [
  { value: 'snag', label: 'Snag' },
  { value: 'rfi', label: 'RFI' },
  { value: 'qa_report', label: 'QA Report' },
  { value: 'variation', label: 'Variation' },
];

/**
 * Drawing Phase 6B, Part U — "Link Record". A short, fixed record-type list
 * uses `Select`; the record itself is a dynamic, searchable project-scoped
 * list, so it uses `Combobox` with server-side search against the
 * dedicated `drawing-linkable-records` endpoint (Part U's own reasoning for
 * why that endpoint exists rather than stitching together each module's
 * own, differently-shaped index() endpoint).
 */
export default function HotspotLinkRecordModal({ projectId, onLink, onCancel }: {
  projectId: string;
  onLink: (type: string, recordId: number) => Promise<void>;
  onCancel: () => void;
}) {
  const [type, setType] = useState('snag');
  const [options, setOptions] = useState<ComboboxOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [selectedId, setSelectedId] = useState<string | undefined>(undefined);
  const [linking, setLinking] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function search(query: string, forType: string) {
    setLoading(true);
    try {
      const res = await api.get(`/projects/${projectId}/drawing-linkable-records`, { params: { type: forType, search: query || undefined } });
      const data = (res.data.data ?? []) as Array<{ id: number; label: string }>;
      setOptions(data.map(r => ({ value: String(r.id), label: r.label || `#${r.id}` })));
    } catch {
      setOptions([]);
    } finally {
      setLoading(false);
    }
  }

  function handleTypeChange(next: string) {
    setType(next);
    setSelectedId(undefined);
    setOptions([]);
    search('', next);
  }

  async function handleLink() {
    if (!selectedId) return;
    setLinking(true);
    setError(null);
    try {
      await onLink(type, Number(selectedId));
    } catch (e) {
      setError(getErrorMessage(e, 'Could not link this record.'));
    } finally {
      setLinking(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div
        role="dialog"
        aria-modal="true"
        aria-label="Link a project record"
        className="w-full max-w-sm rounded-xl shadow-xl p-5"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
      >
        <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Link a project record</h2>

        <label className="block mb-3">
          <span className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>Record type</span>
          <Select value={type} onChange={(e) => handleTypeChange(e.target.value)}>
            {RECORD_TYPES.map(t => <option key={t.value} value={t.value}>{t.label}</option>)}
          </Select>
        </label>

        <label className="block mb-4">
          <span className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>Record</span>
          <Combobox
            value={selectedId}
            onValueChange={setSelectedId}
            options={options}
            onSearch={(q) => search(q, type)}
            loading={loading}
            placeholder="Search…"
            emptyMessage="No matching records found."
            aria-label="Record"
          />
        </label>

        {error && <p className="text-xs mb-3" style={{ color: '#f87171' }}>{error}</p>}

        <div className="flex items-center justify-end gap-2">
          <Button type="button" variant="ghost" size="sm" onClick={onCancel} disabled={linking}>Cancel</Button>
          <Button type="button" size="sm" onClick={handleLink} disabled={linking || !selectedId}>
            {linking ? 'Linking…' : 'Link'}
          </Button>
        </div>
      </div>
    </div>
  );
}
