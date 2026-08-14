'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import PlatformPageHero from '@/components/admin/PlatformPageHero';
import { FolderKanban, Search, Building2, Activity, ChevronRight } from 'lucide-react';

const STATUS_COLORS: Record<string, string> = {
  active: '#299a54',
  on_hold: '#b7791f',
  completed: '#4779c7',
  cancelled: '#d25454',
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
  const activeProjects = projects.filter((project: any) => project.status === 'active').length;
  const representedCompanies = new Set(projects.map((project: any) => project.organization?.id).filter(Boolean)).size;

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero
        eyebrow="Portfolio control"
        title="Projects"
        description="A platform-wide view of live construction work, contract value and delivery status."
        metrics={[
          { label: 'Projects', value: data?.total ?? projects.length, detail: 'on the platform', icon: FolderKanban },
          { label: 'Active', value: activeProjects, detail: 'in the current view', icon: Activity },
          { label: 'Companies', value: representedCompanies, detail: 'represented here', icon: Building2 },
        ]}
        loading={isLoading}
      />

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
        <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {['all', 'active', 'on_hold', 'completed', 'cancelled'].map(s => (
            <button
              key={s}
              onClick={() => setStatusFilter(s)}
              className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
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

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[...Array(6)].map((_, index) => <div key={index} className="h-60 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />)}
        </div>
      ) : projects.length === 0 ? (
          <div className="rounded-2xl py-20 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <FolderKanban size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No projects found</p>
          </div>
      ) : (
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {projects.map((p: any, index: number) => {
              const statusColor = STATUS_COLORS[p.status] ?? 'var(--text-muted)';
              return (
                <Link
                  key={p.id}
                  href={`/app/projects/${p.id}/overview`}
                  className="group flex min-h-[236px] flex-col overflow-hidden rounded-2xl p-5 transition-all duration-300 hover:-translate-y-1 hover:border-[#9ee5b5]/70 hover:shadow-[0_18px_36px_rgba(24,33,29,0.10)] ss-animate-in"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 3px 12px rgba(24,33,29,0.05)', animationDelay: `${Math.min(index * 55, 440)}ms` }}
                >
                  <div className="flex items-center justify-between">
                    <span className="font-mono text-[10px] tracking-[0.08em]" style={{ color: 'var(--text-muted)' }}>{String(index + 1).padStart(2, '0')}</span>
                    <span className="inline-flex items-center gap-1.5 text-[11px] font-medium capitalize" style={{ color: statusColor }}>
                      <span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: statusColor }} />
                      {p.status?.replace(/_/g, ' ') || 'Unknown'}
                    </span>
                  </div>
                  <div className="mt-5 flex items-start gap-3.5">
                    <div className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                      <FolderKanban size={18} />
                    </div>
                    <div className="min-w-0">
                      <h2 className="truncate text-base font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>{p.name}</h2>
                      <p className="mt-1 truncate font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>{p.code || 'No project code'}</p>
                    </div>
                  </div>
                  <div className="mt-5 flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
                    <Building2 size={13} className="opacity-45" />
                    <span className="truncate">{p.organization?.name ?? 'Company not assigned'}</span>
                  </div>
                  <div className="mt-auto grid grid-cols-2 border-t pt-4" style={{ borderColor: 'var(--border)' }}>
                    <div className="border-r pr-3" style={{ borderColor: 'var(--border)' }}>
                      <p className="text-[10px] uppercase tracking-[0.08em]" style={{ color: 'var(--text-muted)' }}>Contract</p>
                      <p className="mt-1 truncate text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{p.contract_type || p.type || 'Not set'}</p>
                    </div>
                    <div className="flex items-end justify-between pl-3">
                      <div>
                        <p className="text-[10px] uppercase tracking-[0.08em]" style={{ color: 'var(--text-muted)' }}>Value</p>
                        <p className="mt-1 text-xs font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{p.contract_value ? formatCurrency(p.contract_value) : 'Not set'}</p>
                      </div>
                      <span className="flex h-7 w-7 items-center justify-center rounded-full transition-colors duration-200 group-hover:bg-[#9ee5b5]"><ChevronRight size={14} className="transition-transform group-hover:translate-x-0.5" style={{ color: 'var(--text-secondary)' }} /></span>
                    </div>
                  </div>
                </Link>
              );
            })}
          </div>
      )}

      {data && data.total > data.per_page && (
          <div
            className="flex items-center justify-between border-t px-1 pt-4 text-xs"
            style={{ borderColor: 'var(--border)', color: 'var(--text-muted)' }}
          >
            <span>
              Showing {data.from}–{data.to} of {data.total} projects
            </span>
          </div>
      )}
    </div>
  );
}
