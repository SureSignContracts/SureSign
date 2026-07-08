'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { BarChart2, TrendingUp, DollarSign, FileText, AlertCircle, Download, ChevronDown, ChevronUp } from 'lucide-react';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import Button from '@/components/ui/Button';

type CommercialRow = {
  project_id: number;
  project_name: string;
  contract_value: number;
  certified_to_date: number;
  paid_to_date: number;
  outstanding_balance: number;
  retention_held: number;
  approved_variations_value: number;
  pending_variations_value: number;
};

function CommercialSummaryTable() {
  const formatCurrency = useCurrencyFormatter();
  const { data, isLoading } = useQuery<{ data: CommercialRow[] }>({
    queryKey: ['reports-commercial-summary'],
    queryFn: () => api.get('/reports/commercial-summary').then(r => r.data),
  });

  const rows = data?.data ?? [];

  return (
    <div className="rounded-xl overflow-hidden mt-3" style={{ border: '1px solid var(--border)' }}>
      <table className="w-full text-sm">
        <thead>
          <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
            {['Project', 'Contract Value', 'Certified', 'Paid', 'Outstanding', 'Retention Held', 'Approved Variations'].map(h => (
              <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {isLoading && (
            <tr><td colSpan={7} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
          )}
          {!isLoading && rows.length === 0 && (
            <tr><td colSpan={7} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No projects to report on.</td></tr>
          )}
          {rows.map(row => (
            <tr key={row.project_id} style={{ borderBottom: '1px solid var(--border)' }}>
              <td className="px-3 py-2.5 font-medium" style={{ color: 'var(--text-primary)' }}>{row.project_name}</td>
              <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.contract_value)}</td>
              <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.certified_to_date)}</td>
              <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.paid_to_date)}</td>
              <td className="px-3 py-2.5 tabular-nums font-semibold" style={{ color: row.outstanding_balance > 0 ? '#fb923c' : 'var(--text-secondary)' }}>{formatCurrency(row.outstanding_balance)}</td>
              <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.retention_held)}</td>
              <td className="px-3 py-2.5 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.approved_variations_value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function AppReportsPage() {
  const formatCurrency = useCurrencyFormatter();
  const [expanded, setExpanded] = useState<string | null>(null);
  const { data, isLoading } = useQuery({
    queryKey: ['reports-summary'],
    queryFn: () => api.get('/reports/summary').then(r => r.data).catch(() => null),
  });

  const reportTypes = [
    { key: 'commercial', label: 'Commercial Summary',  icon: DollarSign,   description: 'Payment applications, variations, final account status', color: '#10b981', available: true },
    { key: 'status',     label: 'Project Status',      icon: BarChart2,    description: 'Progress, milestones, programme updates per project',    color: '#3b82f6', available: false },
    { key: 'rfi',        label: 'RFI Summary',         icon: AlertCircle,  description: 'Open, answered and closed RFIs across all projects',     color: '#f59e0b', available: false },
    { key: 'documents',  label: 'Document Register',   icon: FileText,     description: 'Full document log with version history',                 color: '#8b5cf6', available: false },
    { key: 'forecast',   label: 'Financial Forecast',  icon: TrendingUp,   description: 'Cash flow projections and payment forecasts',            color: '#ef4444', available: false },
  ];

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-7">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Reports</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Generate and export reports across all projects
          </p>
        </div>
      </div>

      {/* Summary stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {[
          { label: 'Total Contract Value', value: isLoading ? '–' : formatCurrency(data?.total_contract_value ?? 0), color: '#10b981' },
          { label: 'Certified to Date',    value: isLoading ? '–' : formatCurrency(data?.certified_to_date ?? 0),    color: '#3b82f6' },
          { label: 'Outstanding Balance',  value: isLoading ? '–' : formatCurrency(data?.outstanding_balance ?? 0), color: '#fb923c' },
          { label: 'Open RFIs',            value: isLoading ? '–' : (data?.open_rfis ?? 0),                          color: '#ef4444' },
        ].map((stat, i) => (
          <div
            key={stat.label}
            className="rounded-xl p-4 ss-animate-in"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
          >
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color: stat.color }}>{stat.value}</p>
          </div>
        ))}
      </div>

      {/* Second row — retention + variations, only shown once real data loads */}
      {!isLoading && data && (
        <div className="grid grid-cols-2 lg:grid-cols-3 gap-4">
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Retention Held</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color: '#a78bfa' }}>{formatCurrency(data?.retention_held ?? 0)}</p>
          </div>
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Approved Variations Value</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color: '#4ade80' }}>{formatCurrency(data?.approved_variations_value ?? 0)}</p>
          </div>
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Pending Variations ({data?.pending_variations ?? 0})</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color: '#facc15' }}>{formatCurrency(data?.pending_variations_value ?? 0)}</p>
          </div>
        </div>
      )}

      {/* Report types */}
      <div>
        <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Available Reports</h2>
        <div className="space-y-3">
          {reportTypes.map((report, i) => (
            <div
              key={report.key}
              className="ss-animate-in rounded-xl"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
            >
              <div className="flex items-center justify-between p-4">
                <div className="flex items-center gap-4">
                  <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: report.color + '15' }}>
                    <report.icon size={18} style={{ color: report.color }} />
                  </div>
                  <div>
                    <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{report.label}</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      {report.description}
                      {!report.available && ' — coming soon'}
                    </p>
                  </div>
                </div>
                <div className="flex gap-2">
                  {report.available ? (
                    <Button
                      variant="primary" size="sm" className="gap-1.5"
                      onClick={() => setExpanded(expanded === report.key ? null : report.key)}
                    >
                      {expanded === report.key ? <ChevronUp size={12} /> : <ChevronDown size={12} />}
                      {expanded === report.key ? 'Hide' : 'View'}
                    </Button>
                  ) : (
                    <button
                      disabled
                      className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium opacity-40 cursor-not-allowed"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                    >
                      <Download size={12} /> Coming soon
                    </button>
                  )}
                </div>
              </div>
              {report.key === 'commercial' && expanded === 'commercial' && (
                <div className="px-4 pb-4">
                  <CommercialSummaryTable />
                </div>
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
