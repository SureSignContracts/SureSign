'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { ScrollText, Lock } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import Select from '@/components/ui/Select';
import Modal from '@/components/ui/Modal';
import { formatDate } from '@/lib/utils';

interface Transaction {
  id: number;
  created_at: string;
  organization_id: number;
  organization_name: string | null;
  workflow: string | null;
  transaction_type: string;
  amount: number;
  reason: string;
  actor_type: string;
  actor_id: number | null;
  reference_type: string | null;
  reference_id: number | null;
  idempotency_key: string;
}

interface TransactionsResponse {
  data: Transaction[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

const TRANSACTION_TYPES = [
  'grant', 'reserve', 'settle', 'release', 'adjustment_credit', 'adjustment_debit', 'expiry',
];

export default function AiCreditsTransactionsPage() {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [workflow, setWorkflow] = useState('');
  const [transactionType, setTransactionType] = useState('');
  const [selected, setSelected] = useState<Transaction | null>(null);

  const filters = { workflow: workflow || undefined, transaction_type: transactionType || undefined };

  const { data, isLoading } = useQuery({
    queryKey: ['ai-credits-transactions', filters, page, perPage],
    queryFn: () => api.get<TransactionsResponse>('/admin/ai-credits/transactions', {
      params: { ...filters, page, per_page: perPage },
    }).then(r => r.data),
  });

  const rows = data?.data ?? [];

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <ScrollText size={22} style={{ color: 'var(--gold)' }} />
          Transactions
        </h1>
        <p className="mt-1 text-sm flex items-center gap-1.5" style={{ color: 'var(--text-muted)' }}>
          <Lock size={12} /> Immutable ledger entries. No editing exists anywhere in this system.
        </p>
      </div>

      <div className="flex items-center gap-3 flex-wrap">
        <Select value={workflow} onChange={e => { setWorkflow(e.target.value); setPage(1); }} size="sm">
          <option value="">All workflows</option>
          <option value="contract_analysis">Contract Analysis</option>
          <option value="trade_package_analysis">Trade Package Analysis</option>
        </Select>
        <Select value={transactionType} onChange={e => { setTransactionType(e.target.value); setPage(1); }} size="sm">
          <option value="">All transaction types</option>
          {TRANSACTION_TYPES.map(t => <option key={t} value={t}>{t.replace(/_/g, ' ')}</option>)}
        </Select>
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs font-medium uppercase tracking-wider" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="text-left px-4 py-3">Date</th>
                <th className="text-left px-4 py-3">Organisation</th>
                <th className="text-left px-4 py-3">Workflow</th>
                <th className="text-left px-4 py-3">Type</th>
                <th className="text-right px-4 py-3">Amount</th>
                <th className="text-left px-4 py-3">Reason</th>
              </tr>
            </thead>
            <tbody>
              {isLoading ? (
                [...Array(6)].map((_, i) => (
                  <tr key={i}><td colSpan={6} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} /></td></tr>
                ))
              ) : rows.length === 0 ? (
                <tr><td colSpan={6}><EmptyState icon={ScrollText} title="No transactions found" description="No ledger entries match the current filters." /></td></tr>
              ) : (
                rows.map(t => (
                  <tr
                    key={t.id}
                    onClick={() => setSelected(t)}
                    className="cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
                    style={{ borderBottom: '1px solid var(--border)' }}
                  >
                    <td className="px-4 py-3 tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(t.created_at)}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{t.organization_name ?? '—'}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{t.workflow ?? '—'}</td>
                    <td className="px-4 py-3"><Badge status={t.transaction_type} /></td>
                    <td className="px-4 py-3 text-right tabular-nums font-medium" style={{ color: 'var(--text-primary)' }}>{t.amount}</td>
                    <td className="px-4 py-3 truncate max-w-xs" style={{ color: 'var(--text-muted)' }}>{t.reason}</td>
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

      {selected && (
        <Modal title={`Transaction #${selected.id}`} icon={ScrollText} onClose={() => setSelected(null)}>
          {() => (
            <dl className="space-y-2.5 text-sm">
              <Row label="Date" value={formatDate(selected.created_at)} />
              <Row label="Organisation" value={selected.organization_name ?? '—'} />
              <Row label="Workflow" value={selected.workflow ?? '—'} />
              <Row label="Type" value={<Badge status={selected.transaction_type} />} />
              <Row label="Amount" value={`${selected.amount} credits`} />
              <Row label="Reason" value={selected.reason} />
              <Row label="Actor" value={selected.actor_type === 'user' ? `User #${selected.actor_id}` : 'System'} />
              <Row label="Reference" value={selected.reference_type ? `${selected.reference_type.split('\\').pop()} #${selected.reference_id}` : '—'} />
              <Row label="Idempotency key" value={<code className="text-xs">{selected.idempotency_key}</code>} />
            </dl>
          )}
        </Modal>
      )}
    </div>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-3">
      <dt className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</dt>
      <dd className="text-right" style={{ color: 'var(--text-primary)' }}>{value}</dd>
    </div>
  );
}
