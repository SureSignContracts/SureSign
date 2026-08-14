'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { ScrollText, Search, AlertTriangle, Bug } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

const LEVEL_TONE: Record<string, 'info' | 'warning' | 'danger' | 'neutral'> = {
  info: 'info',
  warning: 'warning',
  error: 'danger',
  debug: 'neutral',
};

export default function AdminSystemLogsPage() {
  const [search, setSearch] = useState('');
  const [level, setLevel] = useState('all');

  const { data, isLoading } = useQuery({
    queryKey: ['admin-system-logs', level],
    queryFn: () => api.get('/admin/system-logs', { params: { level: level !== 'all' ? level : undefined } })
      .then(r => r.data).catch(() => ({ data: [] })),
  });

  const logs = (data?.data ?? []).filter((l: any) =>
    l.message?.toLowerCase().includes(search.toLowerCase()) ||
    l.channel?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-4 sm:p-6 max-w-7xl mx-auto space-y-6">
      <PlatformPageHero
        eyebrow="Runtime intelligence"
        title="System logs"
        description="Inspect application events, warnings and failures across the SureSign platform."
        metrics={[
          { label: 'Visible events', value: logs.length, detail: 'in the current view', icon: ScrollText },
          { label: 'Errors', value: logs.filter((log: any) => log.level === 'error').length, detail: 'require investigation', icon: Bug },
          { label: 'Warnings', value: logs.filter((log: any) => log.level === 'warning').length, detail: 'worth reviewing', icon: AlertTriangle },
        ]}
        loading={isLoading}
      />

      {/* Filters */}
      <div className="flex gap-3 flex-wrap">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search logs…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '240px' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {['all', 'info', 'warning', 'error', 'debug'].map(l => (
            <button
              key={l}
              onClick={() => setLevel(l)}
              className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
              style={level === l
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }
              }
            >
              {l}
            </button>
          ))}
        </div>
      </div>

      {/* Log table */}
      <div
        className="rounded-2xl overflow-hidden font-mono text-xs"
        style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        <div
          className="grid px-5 py-3 text-xs font-medium uppercase tracking-wider"
          style={{
            backgroundColor: 'var(--bg-elevated)',
            borderBottom: '1px solid var(--border)',
            color: 'var(--text-muted)',
            gridTemplateColumns: '160px 70px 120px 1fr',
            gap: '16px',
          }}
        >
          <span>Timestamp</span>
          <span>Level</span>
          <span>Channel</span>
          <span>Message</span>
        </div>
        <div style={{ backgroundColor: 'var(--bg-surface)' }}>
          {isLoading ? (
            [...Array(8)].map((_, i) => (
              <div key={i} className="px-5 py-3 animate-pulse" style={{ borderBottom: '1px solid var(--border)' }}>
                <div className="h-3 rounded" style={{ backgroundColor: 'var(--bg-elevated)', width: '80%' }} />
              </div>
            ))
          ) : logs.length === 0 ? (
            <EmptyState icon={ScrollText} title="No logs found" />
          ) : logs.map((log: any, i: number) => {
            const tone = LEVEL_TONE[log.level] ?? 'neutral';
            return (
              <div
                key={i}
                className="grid px-5 py-2.5 items-start hover:bg-[var(--bg-hover)] transition-colors"
                style={{
                  borderBottom: '1px solid var(--border)',
                  gridTemplateColumns: '160px 70px 120px 1fr',
                  gap: '16px',
                }}
              >
                <span className="tabular-nums" style={{ color: 'var(--text-muted)' }}>{log.timestamp ?? '–'}</span>
                <span>
                  <Badge tone={tone}>{log.level}</Badge>
                </span>
                <span style={{ color: 'var(--text-secondary)' }}>{log.channel ?? 'app'}</span>
                <span className="truncate" style={{ color: 'var(--text-primary)' }}>{log.message}</span>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}
