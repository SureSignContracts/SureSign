'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { DollarSign, GitBranch, AlertCircle, Clock, CheckCircle2 } from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';

type Tab = 'payment-applications' | 'variations';

const paStatusColor: Record<string, { bg: string; text: string }> = {
  draft:    { bg: 'rgba(90,86,82,0.3)',    text: '#9a9490' },
  submitted:{ bg: 'rgba(234,179,8,0.15)',  text: '#facc15' },
  approved: { bg: 'rgba(34,197,94,0.15)', text: '#4ade80' },
  rejected: { bg: 'rgba(239,68,68,0.15)', text: '#f87171' },
  paid:     { bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
};
const varStatusColor: Record<string, { bg: string; text: string }> = {
  draft:    { bg: 'rgba(90,86,82,0.3)',    text: '#9a9490' },
  submitted:{ bg: 'rgba(234,179,8,0.15)',  text: '#facc15' },
  approved: { bg: 'rgba(34,197,94,0.15)', text: '#4ade80' },
  rejected: { bg: 'rgba(239,68,68,0.15)', text: '#f87171' },
};

export default function CommercialPage() {
  const formatCurrency = useCurrencyFormatter();
  const [tab, setTab] = useState<Tab>('payment-applications');

  const { data: paData, isLoading: paLoading } = useQuery({
    queryKey: ['payment-applications'],
    queryFn: () => api.get('/payment-applications').then(r => r.data),
    enabled: tab === 'payment-applications',
  });

  const { data: varData, isLoading: varLoading } = useQuery({
    queryKey: ['variations'],
    queryFn: () => api.get('/variations').then(r => r.data),
    enabled: tab === 'variations',
  });

  const paymentApps = paData?.data ?? [];
  const variations = varData?.data ?? [];

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="mb-6">
        <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Commercial</h1>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>Payment applications and contract variations</p>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 p-1 rounded-full mb-6 w-fit" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        {(['payment-applications', 'variations'] as Tab[]).map((t) => (
          <button key={t} onClick={() => setTab(t)}
            className="px-4 py-2 rounded-full text-sm font-medium transition-all active:scale-[0.97]"
            style={tab === t
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            {t === 'payment-applications' ? 'Payment Applications' : 'Variations'}
          </button>
        ))}
      </div>

      {/* Payment Applications */}
      {tab === 'payment-applications' && (
        <>
          {paLoading ? (
            <div className="space-y-3">
              {[...Array(3)].map((_, i) => (
                <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
              ))}
            </div>
          ) : paymentApps.length === 0 ? (
            <EmptyState icon={DollarSign} title="No payment applications" sub="Submitted payment claims will appear here" />
          ) : (
            <div className="space-y-3">
              {paymentApps.map((pa: any) => {
                const s = paStatusColor[pa.status] || paStatusColor.draft;
                return (
                  <div key={pa.id}
                    className="flex items-center gap-4 p-4 rounded-xl border cursor-pointer hover:border-[var(--gold)] transition-colors"
                    style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)' }}
                  >
                    <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                         style={{ backgroundColor: 'var(--gold-15)' }}>
                      <DollarSign size={18} style={{ color: 'var(--gold)' }} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-3">
                        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                          PA #{pa.claim_number} — {pa.period_description || 'Payment Application'}
                        </p>
                        <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: s.bg, color: s.text }}>
                          {pa.status}
                        </span>
                      </div>
                      <div className="flex items-center gap-4 mt-1">
                        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                          Claimed: <span style={{ color: 'var(--gold)' }}>{formatCurrency(pa.claimed_amount)}</span>
                        </span>
                        {pa.claim_date && (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{formatDate(pa.claim_date)}</span>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}

      {/* Variations */}
      {tab === 'variations' && (
        <>
          {varLoading ? (
            <div className="space-y-3">
              {[...Array(3)].map((_, i) => (
                <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
              ))}
            </div>
          ) : variations.length === 0 ? (
            <EmptyState icon={GitBranch} title="No variations" sub="Contract variations will appear here" />
          ) : (
            <div className="space-y-3">
              {variations.map((v: any) => {
                const s = varStatusColor[v.status] || varStatusColor.draft;
                return (
                  <div key={v.id}
                    className="flex items-center gap-4 p-4 rounded-xl border cursor-pointer hover:border-[var(--gold)] transition-colors"
                    style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)' }}
                  >
                    <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                         style={{ backgroundColor: 'var(--gold-15)' }}>
                      <GitBranch size={18} style={{ color: 'var(--gold)' }} />
                    </div>
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-3">
                        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                          {v.variation_number} — {v.title}
                        </p>
                        <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: s.bg, color: s.text }}>
                          {v.status}
                        </span>
                      </div>
                      <div className="flex items-center gap-4 mt-1">
                        {v.amount && (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                            Amount: <span style={{ color: 'var(--gold)' }}>{formatCurrency(v.amount)}</span>
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </>
      )}
    </div>
  );
}

function EmptyState({ icon: Icon, title, sub }: { icon: any; title: string; sub: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-20">
      <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
           style={{ backgroundColor: 'var(--bg-elevated)' }}>
        <Icon size={24} style={{ color: 'var(--text-muted)' }} />
      </div>
      <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>{title}</p>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{sub}</p>
    </div>
  );
}
