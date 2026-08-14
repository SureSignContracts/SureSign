'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import api from '@/lib/api';
import CountUp from '@/components/ui/CountUp';
import { Building2, Plus, Search, Users, FolderKanban, ArrowUpRight, ChevronRight } from 'lucide-react';

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
  const allCompanies = data?.data ?? [];
  const totalUsers = allCompanies.reduce((total: number, company: any) => total + Number(company.users_count ?? 0), 0);
  const totalProjects = allCompanies.reduce((total: number, company: any) => total + Number(company.projects_count ?? 0), 0);

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <section className="ss-animate-in relative overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_60px_rgba(24,33,29,0.16)]">
        <div className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
        <div className="relative flex flex-wrap items-start justify-between gap-6 px-6 pb-7 pt-6 sm:px-8 sm:pt-8">
          <div>
            <p className="mb-4 text-xs font-semibold uppercase tracking-[0.14em] text-[#9ee5b5]">Platform directory</p>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Companies</h1>
            <p className="mt-3 max-w-xl text-sm text-white/55">Manage the organisations, people and project portfolios operating across SureSign.</p>
          </div>
          <button
            className="group flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:bg-[#b3efc6] active:scale-[0.98]"
          >
            <Plus size={15} /> Add company
            <ArrowUpRight size={14} className="transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5" />
          </button>
        </div>
        <div className="relative grid grid-cols-1 border-t border-white/10 sm:grid-cols-3">
          {[
            { label: 'Companies', value: allCompanies.length, detail: 'on the platform', icon: Building2 },
            { label: 'People', value: totalUsers, detail: 'registered users', icon: Users },
            { label: 'Projects', value: totalProjects, detail: 'across all companies', icon: FolderKanban },
          ].map((item, index) => (
            <div key={item.label} className="ss-animate-in min-h-[112px] border-b border-white/10 px-6 py-4 last:border-b-0 sm:border-b-0 sm:border-r sm:last:border-r-0" style={{ animationDelay: `${index * 70}ms` }}>
              <item.icon size={15} className="text-white/30" />
              <p className="mt-3 text-2xl font-semibold tabular-nums tracking-[-0.04em] text-[#9ee5b5]">{isLoading ? '–' : <CountUp value={item.value} delay={index * 70} />}</p>
              <p className="mt-1 text-xs font-medium text-white/70">{item.label}</p>
              <p className="mt-0.5 text-[11px] text-white/35">{item.detail}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="ss-animate-in" style={{ animationDelay: '220ms' }}>
        <div className="flex flex-wrap items-end justify-between gap-4 border-b pb-4" style={{ borderColor: 'var(--border)' }}>
          <div>
            <p className="text-[10px] font-semibold uppercase tracking-[0.14em]" style={{ color: 'var(--text-muted)' }}>Organisation register</p>
            <h2 className="mt-1 text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Platform companies</h2>
          </div>
          <div className="relative w-full sm:w-80">
            <Search size={15} className="absolute left-0 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search company or workspace…"
              className="w-full border-0 border-b bg-transparent py-2 pl-6 pr-2 text-sm outline-none transition-colors focus:border-[var(--gold)]"
              style={{ borderColor: 'var(--border)', color: 'var(--text-primary)' }} />
          </div>
        </div>

      {isLoading ? (
        <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[...Array(6)].map((_, index) => (
            <div key={index} className="h-48 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />
          ))}
        </div>
      ) : companies.length === 0 ? (
        <div className="mt-5 rounded-2xl p-16 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <Building2 size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>No matching companies</p>
          <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>Try a different company name or workspace slug.</p>
        </div>
      ) : (
        <div className="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {companies.map((c: any, i: number) => (
            <button
              key={c.id}
              onClick={() => router.push(`/admin/companies/${c.id}`)}
              className="group relative min-h-[192px] overflow-hidden rounded-2xl p-5 text-left transition-all duration-300 hover:-translate-y-1 hover:border-[#9ee5b5]/70 hover:shadow-[0_18px_36px_rgba(24,33,29,0.10)] ss-animate-in"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 3px 12px rgba(24,33,29,0.05)', animationDelay: `${Math.min(280 + i * 55, 600)}ms` }}
            >
              <div className="relative flex h-full flex-col">
                <div className="flex items-center justify-between">
                  <span className="font-mono text-[10px] tracking-[0.08em]" style={{ color: 'var(--text-muted)' }}>{String(i + 1).padStart(2, '0')}</span>
                  <span className="inline-flex items-center gap-1.5 text-[11px] font-medium text-[#299a54]"><span className="h-1.5 w-1.5 rounded-full bg-[#35b966]" />Active</span>
                </div>
                <div className="mt-5 flex items-center gap-3.5">
                  <div className="flex h-12 w-12 flex-shrink-0 items-center justify-center overflow-hidden rounded-xl text-base font-bold" style={c.logo_url ? { border: '1px solid var(--border)' } : { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                    {c.logo_url
                      ? <>
                          {/* eslint-disable-next-line @next/next/no-img-element */}
                          <img src={c.logo_url} alt={c.name} className="h-full w-full object-contain p-1" />
                        </>
                      : c.name?.charAt(0)?.toUpperCase()
                    }
                  </div>
                  <div className="min-w-0">
                    <p className="truncate text-base font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>{c.name}</p>
                    <p className="mt-1 truncate text-xs" style={{ color: 'var(--text-muted)' }}>{c.slug || 'Workspace slug not set'}</p>
                  </div>
                </div>
                <div className="mt-auto flex items-center justify-between border-t pt-4" style={{ borderColor: 'var(--border)' }}>
                  <div className="flex items-center gap-5 text-xs" style={{ color: 'var(--text-secondary)' }}>
                    <span className="flex items-center gap-1.5"><Users size={12} className="opacity-45" /><strong className="font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{c.users_count ?? 0}</strong> users</span>
                    <span className="flex items-center gap-1.5"><FolderKanban size={12} className="opacity-45" /><strong className="font-semibold tabular-nums" style={{ color: 'var(--text-primary)' }}>{c.projects_count ?? 0}</strong> projects</span>
                  </div>
                  <span className="flex h-7 w-7 items-center justify-center rounded-full transition-colors duration-200 group-hover:bg-[#9ee5b5]">
                    <ChevronRight size={14} className="transition-transform duration-200 group-hover:translate-x-0.5" style={{ color: 'var(--text-secondary)' }} />
                  </span>
                </div>
              </div>
            </button>
          ))}
        </div>
      )}
      </section>
    </div>
  );
}
