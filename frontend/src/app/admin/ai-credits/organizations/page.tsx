'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { Building2, Search, ChevronRight, Wallet, Brain } from 'lucide-react';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

interface OrganizationRow {
  organization_id: number;
  organization_name: string;
  issued: number;
  consumed: number;
  reserved: number;
  available: number;
  total_analyses: number;
  shadow_sufficient: number;
  shadow_insufficient: number;
  shadow_unresolved: number;
}

interface OrganizationsResponse {
  data: OrganizationRow[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

export default function AiCreditsOrganizationsPage() {
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const { data, isLoading } = useQuery({
    queryKey: ['ai-credits-organizations', search, page, perPage],
    queryFn: () => api.get<OrganizationsResponse>('/admin/ai-credits/organizations', {
      params: { search: search || undefined, page, per_page: perPage },
    }).then(r => r.data),
  });

  const rows = data?.data ?? [];
  const availableCredits = rows.reduce((total, row) => total + row.available, 0);
  const totalAnalyses = rows.reduce((total, row) => total + row.total_analyses, 0);

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero eyebrow="Credit accounts" title="Organisations" description="Compare organisation-level AI Credit capacity and usage, derived live from the immutable ledger." loading={isLoading}
        metrics={[
          { label: 'Organisations', value: data?.total ?? rows.length, detail: 'credit accounts', icon: Building2 },
          { label: 'Available', value: availableCredits, detail: 'in the current view', icon: Wallet },
          { label: 'Analyses', value: totalAnalyses, detail: 'in the current view', icon: Brain },
        ]} />

      <div className="relative max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => { setSearch(e.target.value); setPage(1); }}
          placeholder="Search organisations..."
          className="w-full pl-9 pr-3 py-2 text-sm rounded-lg outline-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs font-medium uppercase tracking-wider" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="text-left px-4 py-3">Organisation</th>
                <th className="text-right px-4 py-3">Available</th>
                <th className="text-right px-4 py-3">Reserved</th>
                <th className="text-right px-4 py-3">Consumed</th>
                <th className="text-right px-4 py-3">Analyses</th>
                <th className="text-right px-4 py-3">Shadow</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                [...Array(5)].map((_, i) => (
                  <tr key={i}><td colSpan={7} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} /></td></tr>
                ))
              ) : rows.length === 0 ? (
                <tr><td colSpan={7}>
                  <EmptyState
                    icon={Building2}
                    title="No organisations found"
                    description={search ? 'No organisations match this search.' : 'No organisations exist yet.'}
                  />
                </td></tr>
              ) : (
                rows.map(row => (
                  <tr key={row.organization_id} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{row.organization_name}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-primary)' }}>{row.available}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{row.reserved}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{row.consumed}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{row.total_analyses}</td>
                    <td className="px-4 py-3 text-right text-xs" style={{ color: 'var(--text-muted)' }}>
                      {row.shadow_sufficient}/{row.shadow_insufficient}/{row.shadow_unresolved}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Link
                        href={`/admin/ai-credits/organizations/${row.organization_id}`}
                        className="inline-flex items-center gap-1 text-xs font-medium transition-colors hover:opacity-80"
                        style={{ color: 'var(--gold)' }}
                      >
                        View <ChevronRight size={12} />
                      </Link>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {data && data.total > 0 && (
          <div className="px-4 py-3">
            <PaginationBar
              page={data.current_page}
              lastPage={data.last_page}
              total={data.total}
              perPage={data.per_page}
              onPage={setPage}
              onPerPage={n => { setPerPage(n); setPage(1); }}
            />
          </div>
        )}
      </div>
    </div>
  );
}
