'use client';

import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { Info, Wrench, AlertTriangle, OctagonAlert } from 'lucide-react';
import api from '@/lib/api';
import { SEVERITY_STYLES, SEVERITY_LABELS, AnnouncementSeverity } from '@/lib/announcements';

interface Announcement {
  id: number;
  title: string;
  message: string;
  severity: AnnouncementSeverity;
  link_url: string | null;
}

const SEVERITY_ICONS: Record<AnnouncementSeverity, React.ElementType> = {
  information: Info,
  maintenance: Wrench,
  degraded_service: AlertTriangle,
  outage: OctagonAlert,
};

/** Platform-controlled banner — content is rendered as plain text (never HTML), so there's no injection surface from an announcement's title/message. */
export function EmergencyBanner() {
  const { data } = useQuery({
    queryKey: ['active-announcement'],
    queryFn: () => api.get('/platform-announcements/active').then(r => r.data.data as Announcement | null),
    staleTime: 60 * 1000,
  });

  if (!data) return null;

  const style = SEVERITY_STYLES[data.severity];
  const Icon = SEVERITY_ICONS[data.severity];
  const isInternalLink = data.link_url?.startsWith('/');

  return (
    <div
      role="status"
      className="rounded-2xl p-4 flex items-start gap-3"
      style={{ backgroundColor: style.bg, border: `1px solid ${style.border}` }}
    >
      <Icon size={17} className="flex-shrink-0 mt-0.5" style={{ color: style.text }} />
      <div className="min-w-0">
        <p className="text-xs font-semibold uppercase tracking-wider" style={{ color: style.text }}>
          {SEVERITY_LABELS[data.severity]}
        </p>
        <p className="text-sm font-medium mt-0.5" style={{ color: 'var(--text-primary)' }}>{data.title}</p>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-secondary)' }}>{data.message}</p>
        {data.link_url && (
          isInternalLink ? (
            <Link href={data.link_url} className="text-xs font-medium mt-1.5 inline-block hover:underline" style={{ color: style.text }}>
              Learn more
            </Link>
          ) : (
            <a href={data.link_url} target="_blank" rel="noopener noreferrer" className="text-xs font-medium mt-1.5 inline-block hover:underline" style={{ color: style.text }}>
              Learn more
            </a>
          )
        )}
      </div>
    </div>
  );
}
