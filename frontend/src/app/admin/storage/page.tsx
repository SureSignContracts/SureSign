'use client';

import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { HardDrive, Building2, Database } from 'lucide-react';
import { formatBytes } from '@/lib/formatBytes';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

interface OrganizationStorage {
  id: number;
  name: string;
  total_bytes: number | null;
}

interface StorageResponse {
  total_bytes: number;
  total_gb: number;
  by_organization: OrganizationStorage[];
}

function StorageBar({ used, total, color }: { used: number; total: number; color: string }) {
  const pct = total > 0 ? Math.min((used / total) * 100, 100) : 0;
  return (
    <div>
      <div className="h-2 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, backgroundColor: color }} />
      </div>
      <p className="text-xs mt-1 tabular-nums" style={{ color: 'var(--text-muted)' }}>{pct.toFixed(1)}% of total</p>
    </div>
  );
}

export default function AdminStoragePage() {
  const { data, isLoading } = useQuery<StorageResponse | null>({
    queryKey: ['admin-storage'],
    queryFn: () => api.get('/admin/storage').then(r => r.data).catch(() => null),
  });

  const byOrganization = data?.by_organization ?? [];
  const totalBytes = data?.total_bytes ?? 0;

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero eyebrow="File infrastructure" title="Storage" description="Track the document footprint held across every organisation on the SureSign platform." loading={isLoading}
        metrics={[
          { label: 'Storage used', value: formatBytes(totalBytes), detail: 'all uploaded files', icon: HardDrive },
          { label: 'Organisations', value: byOrganization.length, detail: 'with storage records', icon: Building2 },
          { label: 'Capacity view', value: data?.total_gb ? `${data.total_gb} GB` : 'Live', detail: 'platform storage', icon: Database },
        ]} />

      {/* By organization */}
      <div>
        <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Storage by Organization</h2>
        <div
          className="rounded-2xl overflow-hidden"
          style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
        >
          {isLoading ? (
            [...Array(4)].map((_, i) => (
              <div key={i} className="px-5 py-4 animate-pulse" style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
                <div className="h-4 rounded" style={{ backgroundColor: 'var(--bg-elevated)', width: '60%' }} />
              </div>
            ))
          ) : byOrganization.length === 0 ? (
            <p className="px-5 py-8 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No data available</p>
          ) : byOrganization.map((org, i) => (
            <div
              key={org.id}
              className="px-5 py-4 space-y-2"
              style={{ backgroundColor: 'var(--bg-surface)', borderBottom: i < byOrganization.length - 1 ? '1px solid var(--border)' : 'none' }}
            >
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{org.name}</span>
                <span className="text-sm tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatBytes(org.total_bytes ?? 0)}</span>
              </div>
              <StorageBar used={org.total_bytes ?? 0} total={totalBytes} color="var(--gold)" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
