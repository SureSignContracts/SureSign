'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { BarChart2, TrendingUp, DollarSign, FileText, AlertCircle, Download, ChevronDown, ChevronUp, FileDown, FileSpreadsheet, Loader2 } from 'lucide-react';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import Button from '@/components/ui/Button';
import { staggerDelay } from '@/lib/motion';

// ── Types (mirrors CommercialReportService::build()) ─────────────────────

type ReportPeriodKey = 'today' | 'last_7_days' | 'this_month' | 'last_month' | 'quarter' | 'year' | 'custom';

const PERIOD_OPTIONS: { key: ReportPeriodKey; label: string }[] = [
  { key: 'today', label: 'Today' },
  { key: 'last_7_days', label: 'Last 7 Days' },
  { key: 'this_month', label: 'This Month' },
  { key: 'last_month', label: 'Last Month' },
  { key: 'quarter', label: 'Quarter' },
  { key: 'year', label: 'Year' },
  { key: 'custom', label: 'Custom Range' },
];

type ReportMetadata = {
  report_type: string;
  organisation: string;
  period: { key: string; label: string; from: string; to: string };
  generated_date: string;
  generated_time: string;
  effective_timezone: string;
  generated_by: string;
  currency_context: string;
};

type CurrencySection = {
  currency: string;
  project_count: number;
  financial_position: { certified_total: number; paid_total: number; outstanding_total: number };
  retention_position: { retention_total: number };
  commercial_pipeline: {
    awaiting_submission: { count: number; value: number };
    awaiting_certification: { count: number; value: number };
    certified_unpaid: { count: number; value: number };
  };
  variation_position: { approved_variation_value: number; pending_variation_value: number };
  narrative: string;
};

type ReportProjectRow = {
  project_id: number;
  project_name: string;
  currency: string;
  status: string;
  contract_value: number;
  certified: number;
  paid: number;
  outstanding: number;
  retention: number;
  approved_variation_value: number;
  pending_variation_value: number;
};

type CommercialSummaryReportData = {
  metadata: ReportMetadata;
  currency_sections: CurrencySection[];
  projects: ReportProjectRow[];
};

function downloadBlob(blob: Blob, fileName: string) {
  const url = URL.createObjectURL(blob);
  const a = window.document.createElement('a');
  a.href = url;
  a.download = fileName;
  a.click();
  URL.revokeObjectURL(url);
}

function CommercialSummaryReport() {
  const formatCurrency = useCurrencyFormatter();
  const [period, setPeriod] = useState<ReportPeriodKey>('this_month');
  const [customFrom, setCustomFrom] = useState('');
  const [customTo, setCustomTo] = useState('');
  const [exporting, setExporting] = useState<'pdf' | 'excel' | null>(null);

  const params = new URLSearchParams({ period });
  if (period === 'custom' && customFrom && customTo) {
    params.set('from', customFrom);
    params.set('to', customTo);
  }

  const { data, isLoading, isError } = useQuery<CommercialSummaryReportData>({
    queryKey: ['reports-commercial-summary-report', period, customFrom, customTo],
    queryFn: () => api.get(`/reports/commercial-summary-report?${params.toString()}`).then(r => r.data),
    enabled: period !== 'custom' || (!!customFrom && !!customTo),
  });

  const handleExport = async (format: 'pdf' | 'excel') => {
    setExporting(format);
    try {
      const res = await api.get(`/reports/commercial-summary-report/export/${format}?${params.toString()}`, { responseType: 'blob' });
      downloadBlob(res.data, `commercial-summary-report.${format === 'pdf' ? 'pdf' : 'xlsx'}`);
    } catch {
      toast.error(`Failed to export ${format.toUpperCase()}`);
    } finally {
      setExporting(null);
    }
  };

  return (
    <div className="mt-3 space-y-6">
      {/* Period selector + export */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 p-1 rounded-full w-fit flex-wrap" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {PERIOD_OPTIONS.map(opt => (
            <button
              key={opt.key}
              onClick={() => setPeriod(opt.key)}
              className="px-3 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
              style={period === opt.key ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}
            >
              {opt.label}
            </button>
          ))}
        </div>
        <div className="flex gap-2">
          <Button variant="secondary" size="sm" className="gap-1.5 transition-opacity" disabled={!data || exporting !== null} onClick={() => handleExport('pdf')}>
            {exporting === 'pdf' ? <Loader2 size={12} className="animate-spin" /> : <FileDown size={12} />}
            {exporting === 'pdf' ? 'Exporting…' : 'Export PDF'}
          </Button>
          <Button variant="secondary" size="sm" className="gap-1.5 transition-opacity" disabled={!data || exporting !== null} onClick={() => handleExport('excel')}>
            {exporting === 'excel' ? <Loader2 size={12} className="animate-spin" /> : <FileSpreadsheet size={12} />}
            {exporting === 'excel' ? 'Exporting…' : 'Export Excel'}
          </Button>
        </div>
      </div>

      {period === 'custom' && (
        <div className="flex items-center gap-3">
          <label className="text-xs" style={{ color: 'var(--text-muted)' }}>
            From <input type="date" value={customFrom} onChange={e => setCustomFrom(e.target.value)}
              className="ml-1.5 px-2 py-1 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </label>
          <label className="text-xs" style={{ color: 'var(--text-muted)' }}>
            To <input type="date" value={customTo} onChange={e => setCustomTo(e.target.value)}
              className="ml-1.5 px-2 py-1 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </label>
        </div>
      )}

      {isLoading && (
        <div className="space-y-3">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      )}

      {isError && (
        <div className="flex items-center justify-center py-6 rounded-xl" style={{ border: '1px solid var(--border)' }}>
          <p className="text-sm" style={{ color: '#f87171' }}>Could not load the report. Please try again.</p>
        </div>
      )}

      {!isLoading && !isError && data && (
        <>
          {/* Report metadata */}
          <div className="grid grid-cols-2 lg:grid-cols-4 gap-3 rounded-xl p-4" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <MetaItem label="Organisation" value={data.metadata.organisation} />
            <MetaItem label="Reporting Period" value={`${data.metadata.period.label} (${data.metadata.period.from} to ${data.metadata.period.to})`} />
            <MetaItem label="Generated" value={`${data.metadata.generated_date} at ${data.metadata.generated_time}`} />
            <MetaItem label="Effective Timezone" value={data.metadata.effective_timezone} />
            <MetaItem label="Generated By" value={data.metadata.generated_by} />
            <MetaItem label="Currency Context" value={data.metadata.currency_context} />
            <MetaItem label="Report Type" value={data.metadata.report_type} />
          </div>

          {data.currency_sections.length === 0 && (
            <div className="ss-animate-in rounded-2xl py-8 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No commercial data yet for this reporting period.</p>
            </div>
          )}

          {data.currency_sections.map(section => (
            <div key={section.currency} className="space-y-4">
              <h3 className="text-sm font-semibold flex items-center gap-2" style={{ color: 'var(--text-secondary)' }}>
                Executive Summary
                {data.currency_sections.length > 1 && (
                  <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>{section.currency}</span>
                )}
              </h3>
              <p className="text-sm rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
                {section.narrative}
              </p>

              <div>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>Financial Position</p>
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
                  <StatTile label="Certified to Date" value={formatCurrency(section.financial_position.certified_total, section.currency)} color="#3b82f6" />
                  <StatTile label="Paid to Date" value={formatCurrency(section.financial_position.paid_total, section.currency)} color="#10b981" />
                  <StatTile label="Outstanding" value={formatCurrency(section.financial_position.outstanding_total, section.currency)} color="#fb923c" />
                  <StatTile label="Retention Held" value={formatCurrency(section.retention_position.retention_total, section.currency)} color="#a78bfa" />
                </div>
              </div>

              <div>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>Commercial Pipeline</p>
                <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                  <table className="w-full text-sm">
                    <thead>
                      <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                        {['Status', 'Count', 'Value'].map(h => (
                          <th key={h} className="text-left px-3 py-2 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {([
                        { label: 'Awaiting Submission', bucket: section.commercial_pipeline.awaiting_submission },
                        { label: 'Awaiting Certification', bucket: section.commercial_pipeline.awaiting_certification },
                        { label: 'Certified but Unpaid', bucket: section.commercial_pipeline.certified_unpaid },
                      ]).map(({ label, bucket }) => (
                        <tr key={label} style={{ borderBottom: '1px solid var(--border)' }}>
                          <td className="px-3 py-2" style={{ color: 'var(--text-primary)' }}>{label}</td>
                          <td className="px-3 py-2 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{bucket.count}</td>
                          <td className="px-3 py-2 tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(bucket.value, section.currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>

              <div>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>Variation Position</p>
                <div className="grid grid-cols-2 gap-3">
                  <StatTile label="Approved Variation Value" value={formatCurrency(section.variation_position.approved_variation_value, section.currency)} color="#4ade80" />
                  <StatTile label="Pending Variation Value" value={formatCurrency(section.variation_position.pending_variation_value, section.currency)} color="#facc15" />
                </div>
              </div>
            </div>
          ))}

          {/* Per Project Summary */}
          <div>
            <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>Per Project Summary</p>
            <div className="rounded-xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
              <table className="w-full text-sm">
                <thead>
                  <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                    {['Project', 'Contract Value', 'Certified', 'Paid', 'Outstanding', 'Retention', 'Approved Var.', 'Pending Var.', 'Status'].map(h => (
                      <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{h}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {data.projects.length === 0 && (
                    <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No projects to report on.</td></tr>
                  )}
                  {data.projects.map(row => (
                    <tr key={row.project_id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-3 py-2.5 font-medium whitespace-nowrap" style={{ color: 'var(--text-primary)' }}>{row.project_name}</td>
                      <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.contract_value, row.currency)}</td>
                      <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.certified, row.currency)}</td>
                      <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.paid, row.currency)}</td>
                      <td className="px-3 py-2.5 tabular-nums font-semibold whitespace-nowrap" style={{ color: row.outstanding > 0 ? '#fb923c' : 'var(--text-secondary)' }}>{formatCurrency(row.outstanding, row.currency)}</td>
                      <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.retention, row.currency)}</td>
                      <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.approved_variation_value, row.currency)}</td>
                      <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.pending_variation_value, row.currency)}</td>
                      <td className="px-3 py-2.5 whitespace-nowrap capitalize" style={{ color: 'var(--text-muted)' }}>{row.status}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}
    </div>
  );
}

function MetaItem({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-xs font-semibold mt-0.5" style={{ color: 'var(--text-primary)' }}>{value}</p>
    </div>
  );
}

function StatTile({ label, value, color }: { label: string; value: string; color: string }) {
  return (
    <div className="rounded-xl p-3.5 transition-shadow duration-200 hover:shadow-[var(--shadow-card)]" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-lg font-bold mt-1 tabular-nums" style={{ color }}>{value}</p>
    </div>
  );
}

function AppReportsPage() {
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
    <div className="ss-projects-page ss-workspace-page-in mx-auto max-w-6xl space-y-6 p-4 sm:p-6 lg:py-9">
      <section className="ss-workspace-hero-in grid overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5] lg:grid-cols-[1.05fr_0.95fr]">
        <div className="ss-workspace-left-in relative overflow-hidden p-7 sm:p-9 lg:p-11">
          <div className="absolute -left-28 -top-32 h-80 w-80 rounded-full border border-[#a5d6b5]/10 transition-transform duration-700 ease-out hover:scale-105" />
          <div className="relative">
            <div className="mb-8 flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
              <BarChart2 size={20} />
            </div>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Turn project records into a clear commercial story.</h1>
            <p className="mt-4 max-w-xl text-sm leading-6 text-[#b9c5bf] sm:text-base">
              Review portfolio performance, choose a reporting period and export an authoritative record.
            </p>
          </div>
        </div>

        <div className="ss-workspace-right-in grid grid-cols-2 border-t border-[#a5d6b5]/10 bg-[#202c26] lg:border-l lg:border-t-0">
          {[
            { label: 'Contract value', value: isLoading ? '...' : formatCurrency(data?.total_contract_value ?? 0), color: '#9ee5b5', icon: DollarSign },
            { label: 'Certified', value: isLoading ? '...' : formatCurrency(data?.certified_to_date ?? 0), color: '#bfdbfe', icon: FileText },
            { label: 'Outstanding', value: isLoading ? '...' : formatCurrency(data?.outstanding_balance ?? 0), color: '#fdba74', icon: TrendingUp },
            { label: 'Open RFIs', value: isLoading ? '...' : (data?.open_rfis ?? 0), color: '#fda4af', icon: AlertCircle },
          ].map((stat, index) => (
            <div key={stat.label} className="ss-animate-in group/stat flex min-h-28 flex-col justify-between border-[#a5d6b5]/10 p-5 transition-colors duration-300 hover:bg-[#26342d] sm:p-6 [&:nth-child(odd)]:border-r [&:nth-child(-n+2)]:border-b" style={{ animationDelay: `${130 + (index * 60)}ms` }}>
              <div className="flex items-center justify-between gap-2"><p className="text-xs text-[#91a099]">{stat.label}</p><stat.icon size={14} className="transition-transform duration-300 group-hover/stat:scale-110" style={{ color: '#91a099' }} /></div>
              <p className="mt-4 text-xl font-semibold tracking-[-0.025em] tabular-nums" style={{ color: stat.color }}>{stat.value}</p>
            </div>
          ))}
        </div>
      </section>

      {/* Second row — retention + variations, only shown once real data loads */}
      {!isLoading && data && (
        <div className="ss-animate-in grid grid-cols-2 gap-4 lg:grid-cols-3" style={{ animationDelay: '210ms' }}>
          <div className="rounded-2xl p-5 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)]" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Retention Held</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color: '#a78bfa' }}>{formatCurrency(data?.retention_held ?? 0)}</p>
          </div>
          <div className="rounded-2xl p-5 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)]" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Approved Variations Value</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color: '#4ade80' }}>{formatCurrency(data?.approved_variations_value ?? 0)}</p>
          </div>
          <div className="col-span-2 rounded-2xl p-5 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)] lg:col-span-1" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Pending Variations ({data?.pending_variations ?? 0})</p>
            <p className="text-xl font-bold mt-1 tabular-nums" style={{ color: '#facc15' }}>{formatCurrency(data?.pending_variations_value ?? 0)}</p>
          </div>
        </div>
      )}

      {/* Report types */}
      <div className="ss-animate-in" style={{ animationDelay: '270ms' }}>
        <div className="mb-4">
          <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Report library</h2>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Open available reports or see what is coming next.</p>
        </div>
        <div className="space-y-3">
          {reportTypes.map((report, i) => (
            <div
              key={report.key}
              className="group ss-animate-in overflow-hidden rounded-2xl transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)]"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: staggerDelay(i) }}
            >
              <div className="flex items-center justify-between p-4">
                <div className="flex items-center gap-4">
                  <div className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl transition-transform duration-300 group-hover:rotate-[-3deg] group-hover:scale-105" style={{ backgroundColor: report.color + '15' }}>
                    <report.icon size={18} style={{ color: report.color }} />
                  </div>
                  <div>
                    <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{report.label}</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      {report.description}
                      {!report.available && ' (coming soon)'}
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
                <div className="ss-animate-in border-t px-5 pb-5" style={{ borderColor: 'var(--border)' }}>
                  <CommercialSummaryReport />
                </div>
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export default function GatedAppReportsPage() {
  return (
    <FeatureAvailabilityGate featureKey="organization.reports" title="Reports" backHref="/app" backLabel="Back to Dashboard">
      <AppReportsPage />
    </FeatureAvailabilityGate>
  );
}
