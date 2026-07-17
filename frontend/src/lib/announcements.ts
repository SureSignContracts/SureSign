export type AnnouncementSeverity = 'information' | 'maintenance' | 'degraded_service' | 'outage';

export const SEVERITY_LABELS: Record<AnnouncementSeverity, string> = {
  information: 'Information',
  maintenance: 'Maintenance',
  degraded_service: 'Degraded Service',
  outage: 'Outage',
};

// Deliberately calm for 'information'/'maintenance' — an ordinary platform
// notice should never look like an incident. Only 'outage' uses red.
export const SEVERITY_STYLES: Record<AnnouncementSeverity, { bg: string; text: string; border: string }> = {
  information:      { bg: 'rgba(96,165,250,0.12)',  text: '#60a5fa', border: 'rgba(96,165,250,0.35)' },
  maintenance:       { bg: 'rgba(167,139,250,0.12)', text: '#a78bfa', border: 'rgba(167,139,250,0.35)' },
  degraded_service:  { bg: 'rgba(234,179,8,0.14)',   text: '#facc15', border: 'rgba(234,179,8,0.4)' },
  outage:            { bg: 'rgba(239,68,68,0.14)',   text: '#f87171', border: 'rgba(239,68,68,0.4)' },
};
