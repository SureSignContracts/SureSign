'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import api from '@/lib/api';
import { Building2, Plus, Search, Users, FolderKanban, ChevronRight } from 'lucide-react';

export default function AdminCompaniesPage() {
  const [search, setSearch] = useState('');
  const router = useRouter();

  const { data, isLoading } = useQuery({
    queryKey: ['admin-companies'],
    queryFn: () => api.get('/admin/organizations').then(r => r.data),
  });

  const companies = (data?.data ?? []).filter((c: any) =>
    c.name?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Companies</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>All tenant organisations on the platform</p>
        </div>
        <button
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          Add Company
        </button>
      </div>

      {/* Search */}
      <div className="relative max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search companies…"
          className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {/* Cards */}
      {isLoading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="h-36 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : companies.length === 0 ? (
        <div className="rounded-2xl p-16 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <Building2 size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No companies found</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {companies.map((c: any, i: number) => (
            <button
              key={c.id}
              onClick={() => router.push(`/admin/companies/${c.id}`)}
              className="group text-left rounded-2xl p-5 hover:-translate-y-0.5 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] ss-animate-in"
              style={{
                backgroundColor: 'var(--bg-surface)',
                border: '1px solid var(--border)',
                animationDelay: `${Math.min(i * 45, 360)}ms`,
              }}
            >
              <div className="flex items-start justify-between mb-4">
                <div
                  className="w-11 h-11 rounded-xl flex items-center justify-center text-base font-bold flex-shrink-0 overflow-hidden"
                  style={c.logo_url ? {} : { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
                >
                  {c.logo_url
                    ? <img src={c.logo_url} alt={c.name} className="w-full h-full object-contain" />
                    : c.name?.charAt(0)?.toUpperCase()
                  }
                </div>
                <ChevronRight
                  size={16}
                  className="mt-1 opacity-0 group-hover:opacity-100 transition-opacity"
                  style={{ color: 'var(--text-muted)' }}
                />
              </div>

              <p className="font-semibold text-sm leading-snug" style={{ color: 'var(--text-primary)' }}>{c.name}</p>
              {c.slug && (
                <p className="text-xs mt-0.5 mb-3 truncate" style={{ color: 'var(--text-muted)' }}>{c.slug}</p>
              )}

              <div className="flex items-center gap-4 mt-3">
                <div className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-secondary)' }}>
                  <Users size={12} />
                  <span className="tabular-nums">{c.users_count ?? 0} users</span>
                </div>
                <div className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-secondary)' }}>
                  <FolderKanban size={12} />
                  <span className="tabular-nums">{c.projects_count ?? 0} projects</span>
                </div>
              </div>

              <div className="mt-3 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
                <span
                  className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                  style={{ backgroundColor: 'rgba(34,197,94,0.1)', color: '#4ade80' }}
                >
                  Active
                </span>
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
