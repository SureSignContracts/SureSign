'use client';

import { ChevronDown, ChevronUp } from 'lucide-react';

export default function Section({ title, open, onToggle, children }: { title: string; open: boolean; onToggle: () => void; children: React.ReactNode }) {
  return (
    <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
      <button
        type="button"
        onClick={onToggle}
        className="w-full flex items-center justify-between px-4 py-3 text-left"
        style={{ backgroundColor: 'var(--bg-elevated)' }}
      >
        <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{title}</span>
        {open ? <ChevronUp size={14} style={{ color: 'var(--text-muted)' }} /> : <ChevronDown size={14} style={{ color: 'var(--text-muted)' }} />}
      </button>
      {open && (
        <div className="p-4" style={{ backgroundColor: 'var(--bg-surface)' }}>
          {children}
        </div>
      )}
    </div>
  );
}
