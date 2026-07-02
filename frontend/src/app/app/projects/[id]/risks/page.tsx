'use client';

import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import api from '@/lib/api';
import { ShieldAlert, ShieldCheck, AlertTriangle, Info, ChevronDown, ChevronUp, Lightbulb } from 'lucide-react';

type Risk = {
  title: string;
  description?: string;
  severity: 'high' | 'medium' | 'low';
  source?: string;
  recommended_action?: string;
};

const SEV: Record<string, { color: string; bg: string; border: string; label: string; icon: typeof AlertTriangle }> = {
  high:   { color: '#f87171', bg: 'rgba(239,68,68,0.08)',   border: 'rgba(239,68,68,0.25)',   label: 'High',   icon: AlertTriangle },
  medium: { color: '#facc15', bg: 'rgba(234,179,8,0.08)',   border: 'rgba(234,179,8,0.25)',   label: 'Medium', icon: AlertTriangle },
  low:    { color: '#60a5fa', bg: 'rgba(59,130,246,0.08)',  border: 'rgba(59,130,246,0.25)',  label: 'Low',    icon: Info },
};

function RiskCard({ risk }: { risk: Risk }) {
  const [expanded, setExpanded] = useState(false);
  const cfg = SEV[risk.severity] ?? SEV.low;
  const Icon = cfg.icon;

  return (
    <div
      className="rounded-xl overflow-hidden transition-all"
      style={{ border: `1px solid ${cfg.border}`, backgroundColor: cfg.bg }}
    >
      {/* Header row — always visible */}
      <button
        className="w-full flex items-start gap-3 px-4 py-3.5 text-left"
        onClick={() => setExpanded(e => !e)}
      >
        <div className="mt-0.5 flex-shrink-0 w-6 h-6 rounded-lg flex items-center justify-center" style={{ backgroundColor: cfg.color + '22' }}>
          <Icon size={13} style={{ color: cfg.color }} />
        </div>

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <p className="text-sm font-semibold leading-tight" style={{ color: 'var(--text-primary)' }}>{risk.title}</p>
            <span
              className="text-xs px-2 py-0.5 rounded-full font-medium flex-shrink-0"
              style={{ backgroundColor: cfg.color + '22', color: cfg.color }}
            >
              {cfg.label}
            </span>
          </div>
          {risk.source && (
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{risk.source}</p>
          )}
        </div>

        <div className="flex-shrink-0 mt-0.5" style={{ color: 'var(--text-muted)' }}>
          {expanded ? <ChevronUp size={15} /> : <ChevronDown size={15} />}
        </div>
      </button>

      {/* Expanded detail */}
      {expanded && (
        <div className="px-4 pb-4 space-y-3 border-t" style={{ borderColor: cfg.border }}>
          {risk.description && (
            <p className="text-sm pt-3 leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
              {risk.description}
            </p>
          )}
          {risk.recommended_action && (
            <div className="flex gap-2.5 rounded-lg px-3 py-2.5" style={{ backgroundColor: 'rgba(185,149,102,0.08)', border: '1px solid rgba(185,149,102,0.2)' }}>
              <Lightbulb size={14} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--text-gold)' }} />
              <div>
                <p className="text-xs font-semibold mb-0.5" style={{ color: 'var(--text-gold)' }}>Recommended Action</p>
                <p className="text-xs leading-relaxed" style={{ color: 'var(--text-secondary)' }}>{risk.recommended_action}</p>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

type FilterSev = 'all' | 'high' | 'medium' | 'low';

export default function RiskRegisterPage() {
  const { id: projectId } = useParams<{ id: string }>();
  const [filter, setFilter] = useState<FilterSev>('all');

  const { data: contractData, isLoading } = useQuery({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data?.data ?? r.data ?? []),
  });

  // Guard against non-array responses (error payloads, unexpected shapes) so the page
  // never crashes on .flatMap — it just renders empty until valid data arrives.
  const contracts: any[] = Array.isArray(contractData) ? contractData : [];

  // Gather all risks across all contracts, tagging which contract each belongs to
  const allRisks: Array<Risk & { contractTitle: string; contractRef: string }> = contracts.flatMap((c: any) => {
    const risks: Risk[] = c.risks ?? [];
    return risks.map(r => ({
      ...r,
      contractTitle: c.title ?? 'Untitled Contract',
      contractRef:   c.reference_number ?? '',
    }));
  });

  const sorted = [...allRisks].sort((a, b) => {
    const order = { high: 0, medium: 1, low: 2 };
    return (order[a.severity] ?? 3) - (order[b.severity] ?? 3);
  });

  const filtered = filter === 'all' ? sorted : sorted.filter(r => r.severity === filter);

  const high   = allRisks.filter(r => r.severity === 'high').length;
  const medium = allRisks.filter(r => r.severity === 'medium').length;
  const low    = allRisks.filter(r => r.severity === 'low').length;

  const chips: Array<{ key: FilterSev; label: string; count: number; color: string }> = [
    { key: 'all',    label: 'All',    count: allRisks.length, color: 'var(--text-muted)' },
    { key: 'high',   label: 'High',   count: high,            color: '#f87171' },
    { key: 'medium', label: 'Medium', count: medium,          color: '#facc15' },
    { key: 'low',    label: 'Low',    count: low,             color: '#60a5fa' },
  ];

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6 pb-12">

      {/* Header */}
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Risk Register</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Contract risks identified by AI analysis
          </p>
        </div>

        {/* Summary chips */}
        {!isLoading && allRisks.length > 0 && (
          <div className="flex items-center gap-2 flex-wrap">
            {high > 0 && (
              <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}>
                <AlertTriangle size={12} /> {high} High
              </div>
            )}
            {medium > 0 && (
              <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium" style={{ backgroundColor: 'rgba(234,179,8,0.1)', color: '#facc15', border: '1px solid rgba(234,179,8,0.2)' }}>
                <AlertTriangle size={12} /> {medium} Medium
              </div>
            )}
            {low > 0 && (
              <div className="flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-xs font-medium" style={{ backgroundColor: 'rgba(59,130,246,0.1)', color: '#60a5fa', border: '1px solid rgba(59,130,246,0.2)' }}>
                <Info size={12} /> {low} Low
              </div>
            )}
          </div>
        )}
      </div>

      {/* Filter pills */}
      {!isLoading && allRisks.length > 0 && (
        <div className="flex gap-1.5 p-1 rounded-xl w-fit" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {chips.map(c => (
            <button
              key={c.key}
              onClick={() => setFilter(c.key)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium transition-all flex items-center gap-1.5"
              style={
                filter === c.key
                  ? { backgroundColor: 'var(--bg-surface)', color: c.color, boxShadow: '0 1px 3px rgba(0,0,0,0.1)' }
                  : { color: 'var(--text-muted)' }
              }
            >
              {c.label}
              <span
                className="px-1.5 py-0.5 rounded-full text-xs font-bold"
                style={{
                  backgroundColor: filter === c.key ? c.color + '22' : 'transparent',
                  color: filter === c.key ? c.color : 'var(--text-muted)',
                }}
              >
                {c.count}
              </span>
            </button>
          ))}
        </div>
      )}

      {/* Content */}
      {isLoading ? (
        <div className="space-y-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="rounded-xl h-16 animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : allRisks.length === 0 ? (
        <div className="rounded-2xl py-16 text-center" style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
          <ShieldCheck size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>No risks on record</p>
          <p className="text-xs max-w-xs mx-auto" style={{ color: 'var(--text-muted)' }}>
            Run AI analysis on a contract and confirm it — risks will appear here automatically.
          </p>
        </div>
      ) : filtered.length === 0 ? (
        <div className="rounded-2xl py-12 text-center" style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
          <ShieldAlert size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No {filter} risks found.</p>
        </div>
      ) : (
        <div className="space-y-3">
          {/* Group by contract if multiple */}
          {contracts.filter((c: any) => (c.risks ?? []).length > 0).length > 1 ? (
            contracts
              .filter((c: any) => (c.risks ?? []).length > 0)
              .map((c: any) => {
                const cRisks = (c.risks as Risk[])
                  .filter(r => filter === 'all' || r.severity === filter)
                  .sort((a, b) => ({ high: 0, medium: 1, low: 2 }[a.severity] ?? 3) - ({ high: 0, medium: 1, low: 2 }[b.severity] ?? 3));
                if (!cRisks.length) return null;
                return (
                  <div key={c.id}>
                    <p className="text-xs font-semibold uppercase tracking-wider mb-2 px-1" style={{ color: 'var(--text-muted)' }}>
                      {c.title}{c.reference_number ? ` · ${c.reference_number}` : ''}
                    </p>
                    <div className="space-y-2">
                      {cRisks.map((r, i) => <RiskCard key={i} risk={r} />)}
                    </div>
                  </div>
                );
              })
          ) : (
            filtered.map((r, i) => <RiskCard key={i} risk={r} />)
          )}
        </div>
      )}
    </div>
  );
}
