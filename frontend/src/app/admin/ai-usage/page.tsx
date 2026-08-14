'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { Brain, Download, DollarSign, AlertTriangle, FlaskConical, Activity, Users, Gauge } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import Select from '@/components/ui/Select';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

// ── types ──────────────────────────────────────────────────────────────────

interface WorkflowBreakdown {
  count: number;
  completed: number;
  failed: number;
  total_estimated_cost: number;
}

interface SimulationCandidateSummary {
  workflow: string;
  candidate_policy_key: string;
  charging_strategy: string;
  calculated: number;
  unresolved: number;
  unavailable: number;
  error: number;
  total_hypothetical_credits: number;
  average_hypothetical_credits: number | null;
  average_monthly_hypothetical_credits: number | null;
  months_represented: number;
  organizations_represented: number;
  is_approved_policy: boolean;
}

interface PercentileSummary {
  sample_size: number;
  average: number | null;
  p50: number | null;
  p90: number | null;
  p99: number | null;
}

interface CalibrationSummary {
  completed_executions: number;
  failed_executions: number;
  excluded_from_calibration: number;
  cache_hit_rate: number | null;
  average_provider_cost: number | null;
  total_provider_spend: number;
  average_execution_duration_ms: number | null;
  most_used_workflow: string | null;
  organizations_using_ai: number;
  normalized_input_size: PercentileSummary;
}

interface SummaryResponse {
  total_analyses: number;
  by_status: Record<string, number>;
  provider_called: { true: number; false: number; null: number };
  by_failure_category: Record<string, number>;
  total_estimated_cost: number;
  analyses_missing_cost: number;
  by_workflow: {
    contract_analysis: WorkflowBreakdown;
    trade_package_analysis: WorkflowBreakdown;
  };
  simulation: SimulationCandidateSummary[];
  calibration: CalibrationSummary;
}

interface HealthResponse {
  legacy_records: number;
  incomplete_telemetry: number;
  missing_provider_cost: number;
  missing_normalized_input_or_simulation: number;
  impossible_values: number;
  duplicated_simulations: number;
  simulation_errors: number;
  calibration_eligible_total: number;
}

interface DetailRow {
  id: number;
  workflow: string | null;
  organization_id: number;
  organization_name: string | null;
  status: string;
  provider: string | null;
  model: string | null;
  document_char_count: number | null;
  estimated_cost: number | null;
  failure_category: string | null;
  duration_ms: number | null;
  completed_at: string | null;
  created_at: string;
  simulations: Array<{
    candidate_policy_key: string;
    charging_strategy: string;
    hypothetical_band: string | null;
    hypothetical_credits: number | null;
    simulation_status: string;
  }>;
}

interface DetailResponse {
  data: DetailRow[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

// ── helpers ────────────────────────────────────────────────────────────────

function formatCost(cost: number | null): string {
  if (cost === null) return '—';
  return `$${cost.toFixed(4)}`;
}

function formatPercent(value: number | null): string {
  if (value === null) return '—';
  return `${(value * 100).toFixed(1)}%`;
}

function formatMs(ms: number | null): string {
  if (ms === null) return '—';
  return ms >= 1000 ? `${(ms / 1000).toFixed(1)}s` : `${ms}ms`;
}

function formatChars(chars: number | null): string {
  if (chars === null) return '—';
  return chars.toLocaleString();
}

// ── page ───────────────────────────────────────────────────────────────────

export default function AdminAiUsagePage() {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [workflow, setWorkflow] = useState('');
  const [status, setStatus] = useState('');

  const filters = { workflow: workflow || undefined, status: status || undefined };

  const { data: summary, isLoading: summaryLoading } = useQuery({
    queryKey: ['admin-ai-telemetry-summary', filters],
    queryFn: () => api.get<SummaryResponse>('/admin/ai-telemetry/summary', { params: filters }).then(r => r.data),
  });

  const { data: detail, isLoading: detailLoading } = useQuery({
    queryKey: ['admin-ai-telemetry-detail', filters, page, perPage],
    queryFn: () => api.get<DetailResponse>('/admin/ai-telemetry/detail', {
      params: { ...filters, page, per_page: perPage },
    }).then(r => r.data),
  });

  const { data: health } = useQuery({
    queryKey: ['admin-ai-telemetry-health', filters],
    queryFn: () => api.get<HealthResponse>('/admin/ai-telemetry/health', { params: filters }).then(r => r.data),
  });

  function exportCsv() {
    api.get('/admin/ai-telemetry/export', { params: filters, responseType: 'blob' }).then(res => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a');
      a.href = url;
      a.download = `ai-telemetry-export-${new Date().toISOString().slice(0, 10)}.csv`;
      a.click();
      window.URL.revokeObjectURL(url);
    });
  }

  const rows = detail?.data ?? [];

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero eyebrow="Execution telemetry" title="AI Usage & Cost" description="Inspect provider activity, estimated cost and operational health across SureSign AI workflows." loading={summaryLoading}
        metrics={[
          { label: 'Analyses', value: summary?.total_analyses ?? 0, detail: 'executions recorded', icon: Brain },
          { label: 'Estimated cost', value: formatCost(summary?.total_estimated_cost ?? 0), detail: 'provider spend', icon: DollarSign },
          { label: 'Missing cost', value: summary?.analyses_missing_cost ?? 0, detail: 'requires calibration', icon: AlertTriangle },
          { label: 'Failed', value: summary?.by_status?.failed ?? 0, detail: 'executions requiring review', icon: Activity },
        ]}
        action={<button onClick={exportCsv} className="flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-xs font-semibold text-[#18211d] transition-colors hover:bg-[#b3efc6] active:scale-[0.98]"><Download size={13} />Export CSV</button>}
      />

      {/* ── Commercial calibration cards (Phase G4C.2D) ── */}
      <div>
        <p className="text-xs font-semibold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>
          Commercial Calibration
        </p>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          <SummaryCard label="Cache Hit Rate" value={summaryLoading ? '—' : formatPercent(summary?.calibration?.cache_hit_rate ?? null)} icon={Gauge} />
          <SummaryCard label="Avg Provider Cost" value={summaryLoading ? '—' : formatCost(summary?.calibration?.average_provider_cost ?? null)} icon={DollarSign} />
          <SummaryCard label="Avg Execution Duration" value={summaryLoading ? '—' : formatMs(summary?.calibration?.average_execution_duration_ms ?? null)} icon={Activity} />
          <SummaryCard label="Organisations Using AI" value={summaryLoading ? '—' : String(summary?.calibration?.organizations_using_ai ?? 0)} icon={Users} />
          <SummaryCard label="Most-Used Workflow" value={summaryLoading ? '—' : (summary?.calibration?.most_used_workflow ?? '—')} icon={Brain} />
          <SummaryCard label="Excluded From Calibration" value={summaryLoading ? '—' : String(summary?.calibration?.excluded_from_calibration ?? 0)} icon={AlertTriangle} />
          <SummaryCard label="Avg Normalized Input Size" value={summaryLoading ? '—' : formatChars(summary?.calibration?.normalized_input_size?.average ?? null)} icon={Gauge} />
          <SummaryCard label="P90 Normalized Input Size" value={summaryLoading ? '—' : formatChars(summary?.calibration?.normalized_input_size?.p90 ?? null)} icon={Gauge} />
        </div>
        {!summaryLoading && summary && summary.calibration.normalized_input_size.sample_size > 0 && (
          <p className="mt-2 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Normalized input size sample: {summary.calibration.normalized_input_size.sample_size} document(s) —
            P50 {formatChars(summary.calibration.normalized_input_size.p50)} chars,
            P99 {formatChars(summary.calibration.normalized_input_size.p99)} chars.
          </p>
        )}
      </div>

      {/* ── Telemetry health (Phase G4C.2D) ── */}
      {health && (
        health.legacy_records + health.incomplete_telemetry + health.missing_provider_cost +
        health.missing_normalized_input_or_simulation + health.impossible_values + health.duplicated_simulations +
        health.simulation_errors > 0
      ) && (
        <div className="rounded-2xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2 mb-3">
            <AlertTriangle size={14} style={{ color: 'var(--gold)' }} />
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Telemetry Health</p>
          </div>
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-xs">
            <HealthStat label="Legacy Records" value={health.legacy_records} />
            <HealthStat label="Incomplete Telemetry" value={health.incomplete_telemetry} />
            <HealthStat label="Missing Provider Cost" value={health.missing_provider_cost} />
            <HealthStat label="Missing Simulation" value={health.missing_normalized_input_or_simulation} />
            <HealthStat label="Impossible Values" value={health.impossible_values} />
            <HealthStat label="Duplicated Simulations" value={health.duplicated_simulations} />
            <HealthStat label="Simulation Errors" value={health.simulation_errors} />
          </div>
          <p className="mt-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            Read-only checks. A non-zero &quot;Missing Simulation&quot; count for completed/confirmed analyses can be
            closed with <code>ai:credits:backfill-simulations</code>; other findings require investigation, not an
            automated repair.
          </p>
        </div>
      )}

      {/* ── Non-enforcing simulation summary ── */}
      {!summaryLoading && summary && summary.simulation.length > 0 && (
        <div className="rounded-2xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2 mb-3">
            <FlaskConical size={14} style={{ color: 'var(--gold)' }} />
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              AI Credit Simulation (non-enforcing, informational only)
            </p>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{ color: 'var(--text-muted)' }}>
                  <th className="text-left font-medium pb-2">Workflow</th>
                  <th className="text-left font-medium pb-2">Candidate</th>
                  <th className="text-left font-medium pb-2">Strategy</th>
                  <th className="text-left font-medium pb-2">Approved (internal)</th>
                  <th className="text-right font-medium pb-2">Calculated</th>
                  <th className="text-right font-medium pb-2">Unresolved</th>
                  <th className="text-right font-medium pb-2">Unavailable</th>
                  <th className="text-right font-medium pb-2">Avg Credits</th>
                  <th className="text-right font-medium pb-2">Avg Monthly Credits</th>
                  <th className="text-right font-medium pb-2">Orgs</th>
                </tr>
              </thead>
              <tbody>
                {summary.simulation.map(c => (
                  <tr key={`${c.workflow}-${c.candidate_policy_key}`} style={{ borderTop: '1px solid var(--border)' }}>
                    <td className="py-2" style={{ color: 'var(--text-secondary)' }}>{c.workflow}</td>
                    <td className="py-2" style={{ color: 'var(--text-secondary)' }}>{c.candidate_policy_key}</td>
                    <td className="py-2" style={{ color: 'var(--text-secondary)' }}>{c.charging_strategy}</td>
                    <td className="py-2" style={{ color: c.is_approved_policy ? 'var(--gold)' : 'var(--text-muted)' }}>{c.is_approved_policy ? 'Yes' : 'No'}</td>
                    <td className="py-2 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.calculated}</td>
                    <td className="py-2 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.unresolved}</td>
                    <td className="py-2 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.unavailable}</td>
                    <td className="py-2 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.average_hypothetical_credits ?? '—'}</td>
                    <td className="py-2 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.average_monthly_hypothetical_credits ?? '—'}</td>
                    <td className="py-2 text-right tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.organizations_represented}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
            &quot;Approved&quot; means approved as SureSign&apos;s internal accounting model only — never a customer-facing commercial rate/price, which remains a separate, not-yet-made decision.
          </p>
        </div>
      )}

      {/* ── Filters ── */}
      <div className="flex items-center gap-3 flex-wrap">
        <Select value={workflow} onChange={e => { setWorkflow(e.target.value); setPage(1); }} size="sm">
          <option value="">All workflows</option>
          <option value="contract_analysis">Contract Analysis</option>
          <option value="trade_package_analysis">Trade Package Analysis</option>
        </Select>
        <Select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} size="sm">
          <option value="">All statuses</option>
          <option value="completed">Completed</option>
          <option value="confirmed">Confirmed</option>
          <option value="failed">Failed</option>
          <option value="cancelled">Cancelled</option>
        </Select>
      </div>

      {/* ── Detail table ── */}
      <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs font-medium uppercase tracking-wider"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                <th className="text-left px-4 py-3">Organisation</th>
                <th className="text-left px-4 py-3">Workflow</th>
                <th className="text-left px-4 py-3">Status</th>
                <th className="text-left px-4 py-3">Model</th>
                <th className="text-right px-4 py-3">Chars</th>
                <th className="text-right px-4 py-3">Cost</th>
                <th className="text-left px-4 py-3">Simulated Credits</th>
                <th className="text-left px-4 py-3">Date</th>
              </tr>
            </thead>
            <tbody>
              {detailLoading ? (
                [...Array(5)].map((_, i) => (
                  <tr key={i}><td colSpan={8} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} /></td></tr>
                ))
              ) : rows.length === 0 ? (
                <tr><td colSpan={8}><EmptyState icon={Brain} title="No AI executions found" description="No analyses match the current filters." /></td></tr>
              ) : (
                rows.map(row => (
                  <tr key={`${row.workflow}-${row.id}`} style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{row.organization_name ?? '—'}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{row.workflow ?? '—'}</td>
                    <td className="px-4 py-3"><Badge status={row.status} /></td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-secondary)' }}>{row.model ?? '—'}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{row.document_char_count ?? '—'}</td>
                    <td className="px-4 py-3 text-right tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatCost(row.estimated_cost)}</td>
                    <td className="px-4 py-3" style={{ color: 'var(--text-muted)' }}>
                      {row.simulations.length === 0 ? '—' : row.simulations.map(s => (
                        <span key={s.candidate_policy_key} className="mr-2 inline-block">
                          {s.candidate_policy_key}: {s.simulation_status === 'calculated' ? s.hypothetical_credits : s.simulation_status}
                        </span>
                      ))}
                    </td>
                    <td className="px-4 py-3 tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(row.completed_at ?? row.created_at)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
        {detail && detail.total > 0 && (
          <div className="px-4 py-3">
            <PaginationBar
              page={detail.current_page}
              lastPage={detail.last_page}
              total={detail.total}
              perPage={detail.per_page}
              onPage={setPage}
              onPerPage={n => { setPerPage(n); setPage(1); }}
            />
          </div>
        )}
      </div>
    </div>
  );
}

function HealthStat({ label, value }: { label: string; value: number }) {
  const flagged = value > 0;
  return (
    <div className="rounded-lg p-3" style={{ backgroundColor: 'var(--bg-elevated)', border: `1px solid ${flagged ? 'var(--gold)' : 'var(--border)'}` }}>
      <p style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-base font-bold tabular-nums" style={{ color: flagged ? 'var(--gold)' : 'var(--text-primary)' }}>{value}</p>
    </div>
  );
}

function SummaryCard({ label, value, icon: Icon }: { label: string; value: string; icon: React.ComponentType<{ size?: number; style?: React.CSSProperties }> }) {
  return (
    <div className="rounded-2xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 mb-2">
        <Icon size={14} style={{ color: 'var(--gold)' }} />
        <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{label}</p>
      </div>
      <p className="text-xl font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>{value}</p>
    </div>
  );
}
