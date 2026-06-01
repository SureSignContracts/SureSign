'use client';

import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { BarChart2, TrendingUp, DollarSign, FileText, AlertCircle, Download } from 'lucide-react';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';

export default function AppReportsPage() {
  const formatCurrency = useCurrencyFormatter();
  const { data, isLoading } = useQuery({
    queryKey: ['reports-summary'],
    queryFn: () => api.get('/reports/summary').then(r => r.data).catch(() => null),
  });

  const reportTypes = [
    { label: 'Commercial Summary',  icon: DollarSign,   description: 'Payment applications, variations, final account status', color: '#10b981' },
    { label: 'Project Status',      icon: BarChart2,    description: 'Progress, milestones, programme updates per project',    color: '#3b82f6' },
    { label: 'RFI Summary',         icon: AlertCircle,  description: 'Open, answered and closed RFIs across all projects',     color: '#f59e0b' },
    { label: 'Document Register',   icon: FileText,     description: 'Full document log with version history',                 color: '#8b5cf6' },
    { label: 'Financial Forecast',  icon: TrendingUp,   description: 'Cash flow projections and payment forecasts',            color: '#ef4444' },
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
          { label: 'Pending Variations',   value: isLoading ? '–' : (data?.pending_variations ?? 0),                 color: '#f59e0b' },
          { label: 'Open RFIs',            value: isLoading ? '–' : (data?.open_rfis ?? 0),                          color: '#ef4444' },
        ].map(stat => (
          <div
            key={stat.label}
            className="rounded-xl p-4"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
          >
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
            <p className="text-xl font-bold mt-1" style={{ color: stat.color }}>{stat.value}</p>
          </div>
        ))}
      </div>

      {/* Report types */}
      <div>
        <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Available Reports</h2>
        <div className="space-y-3">
          {reportTypes.map(report => (
            <div
              key={report.label}
              className="flex items-center justify-between p-4 rounded-xl"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
            >
              <div className="flex items-center gap-4">
                <div className="w-10 h-10 rounded-xl flex items-center justify-center" style={{ backgroundColor: report.color + '15' }}>
                  <report.icon size={18} style={{ color: report.color }} />
                </div>
                <div>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{report.label}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{report.description}</p>
                </div>
              </div>
              <div className="flex gap-2">
                <button
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-80"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                >
                  <Download size={12} /> PDF
                </button>
                <button
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-80"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  Generate
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
