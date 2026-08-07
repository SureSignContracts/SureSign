'use client';

import { useMemo, useState } from 'react';
import { useParams, useSearchParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { AlertTriangle, Clock3, Receipt } from 'lucide-react';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import { DelayEventsTab } from './DelayEventsTab';
import { EotRequestsTab } from './EotRequestsTab';
import { LossAndExpenseTab } from './LossAndExpenseTab';
import PageTourButton from '@/components/tours/PageTourButton';

// ─── Shared types (re-exported for the tab components) ────────────────────────

export type ContractOption = { id: number; title: string; reference_number?: string | null };
export type TradePackageOption = { id: number; name: string; package_reference?: string | null };

// Intentionally NOT consolidated onto the shared lib/getErrorMessage —
// this one also falls back to a plain `Error`'s own `.message`, which
// assertDeleteSucceeded below relies on (it synthesizes an Error for a dev
// backend quirk where a failed delete comes back as a 200 with an
// error-shaped body). The shared helper deliberately does not do this
// generally, to avoid ever surfacing an arbitrary/unexpected JS error
// message verbatim elsewhere — see lib/normalizeApiError.ts's own docblock
// and internal-docs/error-messaging-recovery-ux-audit.md's Batch 1 notes.
export function getErrorMessage(error: unknown, fallback: string) {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const resp = (error as Record<string, unknown>).response as Record<string, unknown>;
    if (resp && 'data' in resp) {
      const d = resp.data as Record<string, unknown>;
      if (d && 'message' in d && typeof d.message === 'string') return d.message;
    }
  }
  if (error instanceof Error && error.message) return error.message;
  return fallback;
}

// The local dev backend (php artisan serve) doesn't reliably relay Laravel's
// real HTTP status code — a request that throws inside the controller (or
// otherwise never reaches the intended response()->json(...)) can still come
// back as a 200 with a Laravel exception payload as its body. Axios only
// rejects on a non-2xx status, so without this check a delete mutation's
// onSuccess would fire — and show a success toast — for a delete that never
// actually happened. Treat an error-shaped body as a failure regardless of
// status code.
export function assertDeleteSucceeded(res: { data: unknown }) {
  const body = res.data;
  const looksLikeError =
    typeof body === 'object' && body !== null &&
    ('exception' in body || 'trace' in body || (('errors' in body) && ('message' in body)));
  if (looksLikeError) {
    const message = (body as Record<string, unknown>).message;
    throw new Error(typeof message === 'string' ? message : 'Delete failed');
  }
}

export function blobDownload(doc: { id: number; file_name?: string }) {
  api.get(`/documents/${doc.id}/download`, { responseType: 'blob' }).then(res => {
    const url = URL.createObjectURL(res.data);
    const a = window.document.createElement('a');
    a.href = url;
    a.download = doc.file_name ?? 'document.pdf';
    a.click();
    URL.revokeObjectURL(url);
  });
}

export function sourceLabel(row: { contract?: ContractOption | null; trade_package?: TradePackageOption | null }) {
  if (row.trade_package) return row.trade_package.name;
  if (row.contract) return row.contract.title;
  return '—';
}

// ─── Page ───────────────────────────────────────────────────────────────────

type DelayEotTab = 'delay-events' | 'eot' | 'loss-and-expense';

export default function DelayEotPage() {
  const { id: projectId } = useParams<{ id: string }>();
  const id = projectId!;
  const { canManageDelayEvents, canManageEotRequests, canManageLossAndExpenseClaims } = useProjectPermissions();
  const searchParams = useSearchParams();

  const VALID_TABS: DelayEotTab[] = ['delay-events', 'eot', 'loss-and-expense'];
  const tabParam = searchParams.get('tab') as DelayEotTab | null;
  const initialTab: DelayEotTab = tabParam && VALID_TABS.includes(tabParam) ? tabParam : 'delay-events';
  const [tab, setTab] = useState<DelayEotTab>(initialTab);

  const { data: contractsData } = useQuery<{ data?: ContractOption[] }>({
    queryKey: ['project-contracts', id],
    queryFn: () => api.get(`/projects/${id}/contracts`).then(r => r.data),
  });
  const { data: subData } = useQuery<{ trade_packages?: TradePackageOption[] }>({
    queryKey: ['project-subcontracts', id],
    queryFn: () => api.get(`/projects/${id}/documents/module/subcontracts`).then(r => r.data),
  });

  const contracts = contractsData?.data ?? [];
  const tradePackages = subData?.trade_packages ?? [];

  // Undecided EOTs / open delay events / outstanding claims — pulled once,
  // shared across tab badge counts (Phase 8 — operational UX at a glance).
  const { data: delayData } = useQuery<{ data?: { status: string }[] }>({
    queryKey: ['project-delay-events', id],
    queryFn: () => api.get(`/projects/${id}/delay-events`).then(r => r.data),
  });
  const { data: eotData } = useQuery<{ data?: { status: string }[] }>({
    queryKey: ['project-eot-requests', id],
    queryFn: () => api.get(`/projects/${id}/eot-requests`).then(r => r.data),
  });
  const { data: leData } = useQuery<{ data?: { status: string }[] }>({
    queryKey: ['project-loss-and-expense', id],
    queryFn: () => api.get(`/projects/${id}/loss-and-expense-claims`).then(r => r.data),
  });

  const counts = useMemo(() => {
    const openDelays = (delayData?.data ?? []).filter(d => ['open', 'under_assessment'].includes(d.status)).length;
    const undecidedEots = (eotData?.data ?? []).filter(e => ['draft', 'submitted', 'under_assessment'].includes(e.status)).length;
    const outstandingClaims = (leData?.data ?? []).filter(c => ['draft', 'submitted', 'under_assessment'].includes(c.status)).length;
    return { openDelays, undecidedEots, outstandingClaims };
  }, [delayData, eotData, leData]);

  const TABS = [
    { id: 'delay-events' as DelayEotTab, label: 'Delay Events', icon: AlertTriangle, count: counts.openDelays },
    { id: 'eot' as DelayEotTab, label: 'Extension of Time', icon: Clock3, count: counts.undecidedEots },
    { id: 'loss-and-expense' as DelayEotTab, label: 'Loss & Expense', icon: Receipt, count: counts.outstandingClaims },
  ];

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div>
        <div className="flex items-center gap-1.5">
          <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Delay &amp; EOT</h1>
          <PageTourButton tourKey="page-delay-eot" label="Take a tour of this page" />
        </div>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Delay events, extensions of time, and loss &amp; expense claims for this project
        </p>
      </div>

      <div className="flex gap-1 p-1 rounded-full w-fit overflow-x-auto" data-tour="delay-eot-tabs" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all active:scale-[0.97] whitespace-nowrap"
            style={tab === t.id ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
            <t.icon size={14} />{t.label}
            {t.count > 0 && (
              <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
                style={tab === t.id
                  ? { backgroundColor: 'rgba(0,0,0,0.15)', color: 'inherit' }
                  : { backgroundColor: 'rgba(239,68,68,0.15)', color: '#f87171' }}>
                {t.count}
              </span>
            )}
          </button>
        ))}
      </div>

      {tab === 'delay-events' && (
        <DelayEventsTab projectId={id} contracts={contracts} tradePackages={tradePackages} canWrite={canManageDelayEvents} />
      )}
      {tab === 'eot' && (
        <EotRequestsTab projectId={id} contracts={contracts} tradePackages={tradePackages} canWrite={canManageEotRequests} />
      )}
      {tab === 'loss-and-expense' && (
        <LossAndExpenseTab projectId={id} contracts={contracts} tradePackages={tradePackages} canWrite={canManageLossAndExpenseClaims} />
      )}
    </div>
  );
}
