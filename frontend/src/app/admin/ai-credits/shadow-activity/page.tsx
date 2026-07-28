'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { Activity } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import { formatDate } from '@/lib/utils';

interface ShadowRow {
  id: number;
  workflow: string;
  organization_id: number;
  organization_name: string | null;
  status: string;
  shadow_enforcement_result: string | null;
  credit_reservation_amount: number | null;
  created_at: string;
}

interface ShadowActivityResponse {
  data: ShadowRow[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

function shadowTone(status: string | null): 'success' | 'warning' | 'neutral' {
  if (status === 'sufficient') return 'success';
  if (status === 'insufficient') return 'warning';
  return 'neutral';
}

export default function AiCreditsShadowActivityPage() {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [workflow, setWorkflow] = useState('');
  const [shadowStatus, setShadowStatus] = useState('');

  const filters = { workflow: workflow || undefined, shadow_status: shadowStatus || undefined };

  const { data, isLoading } = useQuery({
    queryKey: ['ai-credits-shadow-activity', filters, page, perPage],
    queryFn: () => api.get<ShadowActivityResponse>('/admin/ai-credits/shadow-activity', {
      params: { ...filters, page, per_page: perPage },
    }).then(r => r.data),
  });

  const rows = data?.data ?? [];

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <Activity size={22} style={{ color: 'var(--gold)' }} />
          Shadow Activity
        </h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          What a real balance check would have decided for each analysis — never enforced. Helps validate a
          future commercial rollout before enforcement is ever switched on.
        </p>
      </div>

      <div className="flex items-center gap-3 flex-wrap">
        <select value={workflow} onChange={e => { setWorkflow(e.target.value); setPage(1); }}
          className="text-xs px-3 py-2 rounded-lg outline-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
          <option value="">All workflows</option>
          <option value="contract_analysis">Contract Analysis</option>
          <option value="trade_package_analysis">Trade Package Analysis</option>
        </select>
        <select value={shadowStatus} onChange={e => { setShadowStatus(e.target.value); setPage(1); }}
          className="text-xs px-3 py-2 rounded-lg outline-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
          <option value="">All shadow results</option>
          <option value="sufficient">Sufficient</option>
          <option value="insufficient">Insufficient</option>
          <option value="unresolved">Unresolved</option>
        </select>
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs font-medium uppercase tracking-wider" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="text-left px-4 py-3">Date</th>
                <th className="text-left px-4 py-3">Organisation</th>
                <th className="text-left px-4 py-3">Workflow</th>
                <th className="text-left px-4 py-3">Status</th>
                <th className="text-left px-4 py-3">Shadow Result</th>
                <th className="text-right px-4 py-3">Reserved Amount</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                [...Array(6)].map((_, i) => (
                  <tr key={i}><td colSpan={6} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} /></td></tr>
                ))
              ) : rows.length === 0 ? (
                <tr><td colSpan={6}><EmptyState icon={Activity} title="No shadow activity found" description="No analyses match the current filters." /></td></tr>
              ) : (
                rows.map(row => (
                  <tr key={`${row.workflow}-${row.id}`} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3 tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(row.created_at)}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{row.organization_name ?? '—'}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{row.workflow}</td>
                    <td className="px-4 py-3"><Badge status={row.status} /></td>
                    <td className="px-4 py-3">
                      {row.shadow_enforcement_result ? <Badge tone={shadowTone(row.shadow_enforcement_result)}>{row.shadow_enforcement_result}</Badge> : '—'}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{row.credit_reservation_amount ?? '—'}</td>
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
