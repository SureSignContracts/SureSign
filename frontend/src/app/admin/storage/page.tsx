'use client';

import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { HardDrive, Database, FileText, Image, AlertCircle } from 'lucide-react';

function StorageBar({ used, total, color }: { used: number; total: number; color: string }) {
  const pct = total > 0 ? Math.min((used / total) * 100, 100) : 0;
  return (
    <div>
      <div className="h-2 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        <div className="h-full rounded-full transition-all" style={{ width: `${pct}%`, backgroundColor: color }} />
      </div>
      <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{pct.toFixed(1)}% used</p>
    </div>
  );
}

export default function AdminStoragePage() {
  const { data, isLoading } = useQuery({
    queryKey: ['admin-storage'],
    queryFn: () => api.get('/admin/storage').then(r => r.data).catch(() => null),
  });

  const stats = data?.stats;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-7">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Storage</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Platform-wide storage usage and allocation
        </p>
      </div>

      {/* Overall usage */}
      <div
        className="rounded-2xl p-6 space-y-4"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
      >
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-3">
            <HardDrive size={20} style={{ color: 'var(--gold)' }} />
            <span className="font-semibold" style={{ color: 'var(--text-primary)' }}>Total Platform Storage</span>
          </div>
          <span className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>
            {isLoading ? '–' : (stats?.total_used ?? '0 GB')} / {stats?.total_allocated ?? '100 GB'}
          </span>
        </div>
        <StorageBar used={stats?.used_bytes ?? 0} total={stats?.total_bytes ?? 1} color="var(--gold)" />
      </div>

      {/* By type */}
      <div>
        <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Storage by Type</h2>
        <div className="grid grid-cols-2 gap-4">
          {[
            { label: 'Documents (PDF/Word)', icon: FileText, used: stats?.docs_bytes ?? 0, total: stats?.total_bytes ?? 1, color: '#3b82f6' },
            { label: 'Images',               icon: Image,    used: stats?.images_bytes ?? 0, total: stats?.total_bytes ?? 1, color: '#8b5cf6' },
            { label: 'Database',             icon: Database, used: stats?.db_bytes ?? 0,     total: stats?.total_bytes ?? 1, color: '#10b981' },
            { label: 'Other',                icon: HardDrive,used: stats?.other_bytes ?? 0,  total: stats?.total_bytes ?? 1, color: '#f59e0b' },
          ].map(item => (
            <div
              key={item.label}
              className="rounded-xl p-4 space-y-3"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
            >
              <div className="flex items-center gap-2">
                <item.icon size={15} style={{ color: item.color }} />
                <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>{item.label}</span>
              </div>
              <StorageBar used={item.used} total={item.total} color={item.color} />
            </div>
          ))}
        </div>
      </div>

      {/* Per-company */}
      <div>
        <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-secondary)' }}>Storage by Company</h2>
        <div
          className="rounded-2xl overflow-hidden"
          style={{ border: '1px solid var(--border)' }}
        >
          {isLoading ? (
            [...Array(4)].map((_, i) => (
              <div key={i} className="px-5 py-4 animate-pulse" style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
                <div className="h-4 rounded" style={{ backgroundColor: 'var(--bg-elevated)', width: '60%' }} />
              </div>
            ))
          ) : (data?.by_company ?? []).length === 0 ? (
            <p className="px-5 py-8 text-sm text-center" style={{ color: 'var(--text-muted)' }}>No data available</p>
          ) : (data?.by_company ?? []).map((c: any, i: number) => (
            <div
              key={c.id}
              className="flex items-center justify-between px-5 py-4"
              style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}
            >
              <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{c.name}</span>
              <span className="text-sm" style={{ color: 'var(--text-muted)' }}>{c.storage_used ?? '0 MB'}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
