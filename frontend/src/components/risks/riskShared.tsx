'use client';

export function getErrorMessage(error: unknown, fallback: string) {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const resp = (error as Record<string, unknown>).response as Record<string, unknown>;
    if (resp && 'data' in resp) {
      const d = resp.data as Record<string, unknown>;
      if (d && 'message' in d && typeof d.message === 'string') return d.message;
    }
  }
  return fallback;
}

export const INPUT_STYLE = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

export const SEVERITY_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  critical: { label: 'Critical', bg: 'rgba(239,68,68,0.12)', text: '#f87171' },
  high:     { label: 'High',     bg: 'rgba(251,146,60,0.15)', text: '#fb923c' },
  medium:   { label: 'Medium',   bg: 'rgba(250,204,21,0.15)', text: '#facc15' },
  low:      { label: 'Low',      bg: 'rgba(74,222,128,0.12)', text: '#4ade80' },
};

export const STATUS_CONFIG: Record<string, { label: string; bg: string; text: string }> = {
  open:        { label: 'Open',        bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  in_progress: { label: 'In Progress', bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  resolved:    { label: 'Resolved',    bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
};

export const CATEGORY_LABELS: Record<string, string> = {
  commercial: 'Commercial', programme: 'Programme', delay: 'Delay', payment: 'Payment',
  design: 'Design', information: 'Information', procurement: 'Procurement',
  client: 'Client', subcontractor: 'Subcontractor', other: 'Other',
};

export function SeverityBadge({ severity }: { severity: string }) {
  const s = SEVERITY_CONFIG[severity] ?? SEVERITY_CONFIG.medium;
  return <span className="px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap" style={{ backgroundColor: s.bg, color: s.text }}>{s.label}</span>;
}

export function StatusBadge({ status }: { status: string }) {
  const s = STATUS_CONFIG[status] ?? STATUS_CONFIG.open;
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
