export type SystemComponentStatus =
  | 'operational'
  | 'degraded'
  | 'delayed'
  | 'partial_outage'
  | 'major_outage'
  | 'maintenance'
  | 'unavailable';

export const STATUS_LABELS: Record<SystemComponentStatus, string> = {
  operational: 'Operational',
  degraded: 'Degraded',
  delayed: 'Delayed',
  partial_outage: 'Partial Outage',
  major_outage: 'Major Outage',
  maintenance: 'Maintenance',
  unavailable: 'Status Unavailable',
};

export const STATUS_COLORS: Record<SystemComponentStatus, { dot: string; text: string }> = {
  operational:    { dot: '#4ade80', text: '#4ade80' },
  degraded:       { dot: '#facc15', text: '#facc15' },
  delayed:        { dot: '#facc15', text: '#facc15' },
  partial_outage: { dot: '#fb923c', text: '#fb923c' },
  major_outage:   { dot: '#f87171', text: '#f87171' },
  maintenance:    { dot: '#a78bfa', text: '#a78bfa' },
  unavailable:    { dot: '#9a9490', text: 'var(--text-muted)' },
};
