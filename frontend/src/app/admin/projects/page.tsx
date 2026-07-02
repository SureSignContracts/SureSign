'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { FolderKanban, Search, Building2, Calendar, DollarSign, ChevronRight } from 'lucide-react';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  active:    { bg: 'rgba(34,197,94,0.12)',   text: '#4ade80' },
  on_hold:   { bg: 'rgba(234,179,8,0.12)',   text: '#facc15' },
  completed: { bg: 'rgba(59,130,246,0.12)',  text: '#60a5fa' },
  cancelled: { bg: 'rgba(239,68,68,0.12)',   text: '#f87171' },
};

export default function AdminProjectsPage() {
  const formatCurrency = useCurrencyFormatter();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');

  const { data, isLoading } = useQuery({
    queryKey: ['admin-projects', search, statusFilter],
    queryFn: () =>
      api.get('/admin/projects', {
        params: {
          ...(search ? { search } : {}),
          ...(statusFilter !== 'all' ? { status: statusFilter } : {}),
        },
      }).then(r => r.data),
  });

  const projects = data?.data ?? [];

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>
            All Projects
          </h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Platform-wide view — all projects across all companies
          </p>
        </div>
        <div
          className="text-xs px-3 py-1.5 rounded-full font-medium"
          style={{ backgroundColor: 'rgba(185,149,102,0.12)', color: 'var(--gold)', border: '1px solid rgba(185,149,102,0.3)' }}
        >
          {data?.total ?? 0} total
        </div>
      </div>

      {/* Filters */}
      <div className="flex items-center gap-3 flex-wrap">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search projects…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{
              backgroundColor: 'var(--bg-surface)',
              border: '1px solid var(--border)',
              color: 'var(--text-primary)',
              minWidth: '240px',
            }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {['all', 'active', 'on_hold', 'completed', 'cancelled'].map(s => (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-md text-xs font-medium capitalize transition-all"
              style={
                statusFilter === s
                  ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                  : { color: 'var(--text-secondary)' }
              }
            >
              {s === 'all' ? 'All' : s.replace(/_/g, ' ')}
            </button>
          ))}
        </div>
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        {/* Table header */}
        <div
          className="grid grid-cols-[2fr_1.5fr_1fr_1fr_1fr_40px] gap-4 px-5 py-3 text-xs font-medium uppercase tracking-wider"
          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}
        >
          <span>Project</span>
          <span>Company</span>
          <span>Contract Type</span>
          <span>Value</span>
          <span>Status</span>
          <span />
        </div>

        {isLoading ? (
          <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {[...Array(8)].map((_, i) => (
              <div key={i} className="px-5 py-4 h-14 animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', opacity: 0.5 }} />
            ))}
          </div>
        ) : projects.length === 0 ? (
          <div className="py-20 text-center">
            <FolderKanban size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No projects found</p>
          </div>
        ) : (
          <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
            {projects.map((p: any) => {
              const badge = STATUS_COLORS[p.status] ?? { bg: 'rgba(90,86,82,0.2)', text: '#9a9490' };
              return (
                <Link
                  key={p.id}
                  href={`/app/projects/${p.id}/overview`}
                  className="grid grid-cols-[2fr_1.5fr_1fr_1fr_1fr_40px] gap-4 items-center px-5 py-3.5 hover:bg-[var(--bg-hover)] transition-colors"
                >
                  {/* Project name + code */}
                  <div className="min-w-0">
                    <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                      {p.name}
                    </p>
                    {p.code && (
                      <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{p.code}</p>
                    )}
                  </div>

                  {/* Company */}
                  <div className="flex items-center gap-2 min-w-0">
                    <div
                      className="w-6 h-6 rounded-md flex items-center justify-center text-xs font-bold flex-shrink-0"
                      style={{ backgroundColor: 'rgba(185,149,102,0.15)', color: 'var(--gold)' }}
                    >
                      {p.organization?.name?.charAt(0)?.toUpperCase() ?? '?'}
                    </div>
                    <span className="text-sm truncate" style={{ color: 'var(--text-secondary)' }}>
                      {p.organization?.name ?? '—'}
                    </span>
                  </div>

                  {/* Contract type */}
                  <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                    {p.contract_type || p.type || '—'}
                  </span>

                  {/* Value */}
                  <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                    {p.contract_value ? formatCurrency(p.contract_value) : '—'}
                  </span>

                  {/* Status */}
                  <span
                    className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize w-fit"
                    style={{ backgroundColor: badge.bg, color: badge.text }}
                  >
                    {p.status?.replace(/_/g, ' ')}
                  </span>

                  <ChevronRight size={14} style={{ color: 'var(--text-muted)' }} />
                </Link>
              );
            })}
          </div>
        )}

        {/* Pagination info */}
        {data && data.total > data.per_page && (
          <div
            className="px-5 py-3 flex items-center justify-between text-xs"
            style={{ borderTop: '1px solid var(--border)', color: 'var(--text-muted)' }}
          >
            <span>
              Showing {data.from}–{data.to} of {data.total} projects
            </span>
          </div>
        )}
      </div>
    </div>
  );
}
