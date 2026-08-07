'use client';

// Re-exported for callers importing it from this file — see
// lib/normalizeApiError.ts for the canonical implementation (identical
// behaviour, consolidated in Error Messaging & Recovery UX Batch 1).
export { getErrorMessage } from '@/lib/getErrorMessage';

export const INPUT_STYLE = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

export const CATEGORY_LABELS: Record<string, string> = {
  method_statement: 'Method Statement',
  rams: 'RAMS',
  itp: 'ITP',
  lift_plan: 'Lift Plan',
  temporary_works: 'Temporary Works',
  coshh: 'COSHH',
  permit: 'Permit to Work',
  installation_procedure: 'Installation Procedure',
  manufacturer_instruction: 'Manufacturer Instruction',
  task_briefing: 'Task Briefing',
  other: 'Other',
};

export const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  required:      { label: 'Required',      bg: 'rgba(148,163,184,0.15)', text: '#94a3b8' },
  pending:       { label: 'Pending',       bg: 'rgba(249,115,22,0.12)',  text: '#fb923c' },
  submitted:     { label: 'Submitted',     bg: 'rgba(59,130,246,0.15)',  text: '#60a5fa' },
  under_review:  { label: 'Under Review',  bg: 'rgba(250,204,21,0.15)',  text: '#facc15' },
  approved:      { label: 'Approved',      bg: 'rgba(34,197,94,0.12)',   text: '#4ade80' },
  rejected:      { label: 'Rejected',      bg: 'rgba(239,68,68,0.12)',   text: '#f87171' },
  expired:       { label: 'Expired',       bg: 'rgba(239,68,68,0.12)',   text: '#f87171' },
  superseded:    { label: 'Superseded',    bg: 'rgba(148,163,184,0.15)', text: '#94a3b8' },
};

export function StatusBadge({ status }: { status: string }) {
  const s = STATUS_CONFIG[status] ?? STATUS_CONFIG.required;
  return <span className="px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap" style={{ backgroundColor: s.bg, color: s.text }}>{s.label}</span>;
}

export function Field({ label, required, children }: { label: string; required?: boolean; children: React.ReactNode }) {
  return (
    <label className="block">
      <span className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</span>
      {children}
    </label>
  );
}
