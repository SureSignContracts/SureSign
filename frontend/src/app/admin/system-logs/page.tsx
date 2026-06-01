'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { ScrollText, Search, Filter } from 'lucide-react';
import { formatDate } from '@/lib/utils';

const LEVEL_COLORS: Record<string, { bg: string; text: string }> = {
  info:    { bg: 'rgba(59,130,246,0.1)',  text: '#60a5fa' },
  warning: { bg: 'rgba(234,179,8,0.1)',   text: '#facc15' },
  error:   { bg: 'rgba(239,68,68,0.1)',   text: '#f87171' },
  debug:   { bg: 'rgba(90,86,82,0.15)',   text: '#9a9490' },
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
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>System Logs</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Platform-wide application logs and error tracking
        </p>
      </div>

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
        <div className="flex gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {['all', 'info', 'warning', 'error', 'debug'].map(l => (
            <button
              key={l}
              onClick={() => setLevel(l)}
              className="px-3 py-1.5 rounded-md text-xs font-medium capitalize transition-all"
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
        style={{ border: '1px solid var(--border)' }}
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
            <div className="px-5 py-12 text-center">
              <ScrollText size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
              <p style={{ color: 'var(--text-muted)' }}>No logs found</p>
            </div>
          ) : logs.map((log: any, i: number) => {
            const badge = LEVEL_COLORS[log.level] || LEVEL_COLORS.debug;
            return (
              <div
                key={i}
                className="grid px-5 py-2.5 items-start hover:bg-[var(--bg-elevated)] transition-colors"
                style={{
                  borderBottom: '1px solid var(--border)',
                  gridTemplateColumns: '160px 70px 120px 1fr',
                  gap: '16px',
                }}
              >
                <span style={{ color: 'var(--text-muted)' }}>{log.datetime ?? log.created_at ?? '–'}</span>
                <span>
                  <span className="px-1.5 py-0.5 rounded text-xs capitalize" style={{ backgroundColor: badge.bg, color: badge.text }}>
                    {log.level}
                  </span>
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
