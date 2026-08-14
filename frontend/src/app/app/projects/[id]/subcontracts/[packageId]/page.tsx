'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate, cn } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import GeneratePackageModal from '@/components/documents/GeneratePackageModal';
import SubcontractAiOnboardingModal from '@/components/subcontracts/SubcontractAiOnboardingModal';
import { DelayEventsTab } from '../../delay-eot/DelayEventsTab';
import { EotRequestsTab } from '../../delay-eot/EotRequestsTab';
import { LossAndExpenseTab } from '../../delay-eot/LossAndExpenseTab';
import { FinalAccountTab } from '../../commercial/FinalAccountTab';
import { RisksTab } from './RisksTab';
import { DeliveryDocumentsTab } from './DeliveryDocumentsTab';
import {
  ArrowLeft, Package, Pencil, FileText, Download, Loader2, X,
  Building2, CalendarDays, Receipt, Layers, FileStack,
  ListChecks, AlertTriangle, Sparkles, Clock, Plus, Trash2, ShieldCheck,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import PageTourButton from '@/components/tours/PageTourButton';
import Modal from '@/components/ui/Modal';
import Select from '@/components/ui/Select';

// ─── Types ───────────────────────────────────────────────────────────────────

// Sprint 6F — Compliance is the first area with room for more sub-tabs
// (Insurance, Document Requirements) alongside Risks; the union stays shared
// with Delay & EOT's sub-tab state rather than introducing a second state var.
type WorkspaceTab = 'overview' | 'commercial' | 'programme' | 'delay-eot' | 'compliance' | 'documents' | 'ai-analysis' | 'activity';
type DelaySubTab = 'delay' | 'eot' | 'loss-expense' | 'risks' | 'delivery-documents';

type TradePackage = {
  id: number;
  name: string;
  package_code?: string | null;
  package_reference?: string | null;
  contractor_name?: string | null;
  description?: string | null;
  status?: string | null;
  contract_value?: string | number | null;
  retention_percentage?: string | number | null;
  liquidated_damages?: string | null;
  payment_terms_days?: number | null;
  payment_frequency?: string | null;
  letter_of_intent_date?: string | null;
  award_date?: string | null;
  execution_date?: string | null;
  commencement_date?: string | null;
  completion_date?: string | null;
  defects_liability_end_date?: string | null;
  contractor_contact_name?: string | null;
  contractor_email?: string | null;
  contractor_phone?: string | null;
  contractor_address?: string | null;
  contractor_company_reg_no?: string | null;
  contractor_vat_number?: string | null;
  due_date_offset_days?: number | null;
  final_date_offset_days?: number | null;
  payment_notice_offset_days?: number | null;
  pay_less_notice_offset_days?: number | null;
  is_custom?: boolean;
};

type CommercialSummary = {
  applications_count: number;
  certified_to_date: number;
  paid_to_date: number;
  retention_held: number;
  retention_released: number;
  outstanding_balance: number;
};

type AppRow = {
  id: number;
  application_number: number;
  status: string;
  application_date?: string | null;
  gross_valuation?: string | number | null;
  certified_amount?: string | number | null;
  paid_amount?: string | number | null;
  amount_due?: string | number | null;
};

type WorkspaceResponse = {
  trade_package: TradePackage;
  files_count: number;
  commercial_summary: CommercialSummary;
  applications: AppRow[];
};

// ─── Status presentation ───────────────────────────────────────────────────

const STATUS_META: Record<string, { label: string; bg: string; text: string }> = {
  tendering:        { label: 'Tendering',        bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
  tender_returned:  { label: 'Tender Returned',  bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
  under_review:     { label: 'Under Review',     bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
  awarded:          { label: 'Awarded',          bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  documents_issued: { label: 'Documents Issued', bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  executed:         { label: 'Executed',         bg: 'rgba(167,139,250,0.15)',text: '#a78bfa' },
  active:           { label: 'Active',           bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  completed:        { label: 'Completed',        bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed:           { label: 'Closed',           bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  archived:         { label: 'Archived',         bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  inactive:         { label: 'Inactive',         bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

function StatusBadge({ status }: { status?: string | null }) {
  const meta = STATUS_META[status ?? ''] ?? { label: status ?? '—', bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
  return (
    <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: meta.bg, color: meta.text }}>
      {meta.label}
    </span>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function TradePackageWorkspacePage() {
  const params = useParams();
  const router = useRouter();
  const searchParams = useSearchParams();
  const projectId = params.id as string;
  const packageId = params.packageId as string;

  // Trade Package actions (AI analysis, document generation, edit) were
  // reviewed in Batch 2. The embedded Programme/Delay & EOT/Compliance tabs
  // below cover Batch 3 modules, each reviewed and now on its own
  // canManageX flag rather than the legacy blanket canWrite.
  const {
    canManageTradePackages, canManageProgramme, canManageDelayEvents,
    canManageEotRequests, canManageLossAndExpenseClaims,
    canManageRisks, canManageDeliveryDocuments,
  } = useProjectPermissions();
  const formatCurrency = useCurrencyFormatter();
  const queryClient = useQueryClient();

  const [tab, setTab] = useState<WorkspaceTab>(() => (searchParams.get('tab') as WorkspaceTab | null) ?? 'overview');
  const [subTab, setSubTab] = useState<DelaySubTab>(() => (searchParams.get('subtab') as DelaySubTab | null) ?? 'delay');

  // React to ?tab=/?subtab= changes from ANY navigation source — not just this
  // page's own goToTab (which already updates the URL) — so links from Activity,
  // Calendar, and Documents (built server-side, or via router.push elsewhere)
  // actually move the visible tab, and browser back/forward works correctly
  // (Sprint 6D Phase 4 requirement). useState's initializer alone only runs once
  // on mount, so without this, a same-page URL change would update the address
  // bar but leave the rendered tab exactly where it was.
  useEffect(() => {
    const urlTab = searchParams.get('tab') as WorkspaceTab | null;
    if (urlTab && urlTab !== tab) setTab(urlTab);
    const urlSubtab = searchParams.get('subtab') as DelaySubTab | null;
    if (urlSubtab && urlSubtab !== subTab) setSubTab(urlSubtab);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  const [showEdit, setShowEdit] = useState(searchParams.get('edit') === '1');
  const [showGenerate, setShowGenerate] = useState(false);
  const [showAiOnboarding, setShowAiOnboarding] = useState(false);

  const { data, isLoading } = useQuery<WorkspaceResponse>({
    queryKey: ['trade-package-workspace', projectId, packageId],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${packageId}/workspace`).then(r => r.data),
    enabled: !!projectId && !!packageId,
  });

  const pkg = data?.trade_package;
  const summary = data?.commercial_summary;
  const apps = data?.applications ?? [];

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 size={22} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
      </div>
    );
  }

  if (!pkg) {
    return (
      <div className="p-6 max-w-5xl mx-auto">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Trade package not found.</p>
        <Link href={`/app/projects/${projectId}/contracts`} className="text-sm" style={{ color: 'var(--gold)' }}>
          ← Back to Contracts
        </Link>
      </div>
    );
  }

  const tabs: Array<{ key: WorkspaceTab; label: string; icon: React.ElementType }> = [
    { key: 'overview',    label: 'Overview',    icon: Layers },
    { key: 'commercial',  label: 'Commercial',  icon: Receipt },
    { key: 'programme',   label: 'Programme',   icon: ListChecks },
    { key: 'delay-eot',   label: 'Delay & EOT', icon: AlertTriangle },
    { key: 'compliance',  label: 'Compliance',  icon: ShieldCheck },
    { key: 'documents',   label: 'Documents',   icon: FileStack },
    { key: 'ai-analysis', label: 'AI Analysis', icon: Sparkles },
    { key: 'activity',    label: 'Activity',    icon: Clock },
  ];

  const goToTab = (t: WorkspaceTab, sub?: DelaySubTab) => {
    setTab(t);
    if (sub) setSubTab(sub);
    const suffix = sub ? `&subtab=${sub}` : '';
    router.replace(`/app/projects/${projectId}/subcontracts/${packageId}?tab=${t}${suffix}`);
  };

  // Used for cross-reference links (Activity item -> its record, Document ->
  // the record that generated it, Calendar item -> its tab) — a real `push`
  // rather than `replace` so the browser back button returns to whichever tab
  // the user was on before following the link (Sprint 6D Phase 1-3).
  const navigateToSource = (source: { tab?: string; subtab?: string | null } | null | undefined) => {
    if (!source?.tab) return;
    const suffix = source.subtab ? `&subtab=${source.subtab}` : '';
    router.push(`/app/projects/${projectId}/subcontracts/${packageId}?tab=${source.tab}${suffix}`);
  };

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 pb-12 sm:p-6 lg:p-8">
      {/* Header */}
      <section className="ss-animate-in relative overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_60px_rgba(24,33,29,0.16)]">
        <div className="pointer-events-none absolute -right-20 -top-24 h-72 w-72 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
        <Link
          href={`/app/projects/${projectId}/contracts`}
          className="relative mx-6 mt-6 inline-flex items-center gap-1.5 text-xs text-white/45 transition-colors hover:text-white sm:mx-8"
        >
          <ArrowLeft size={13} /> Subcontracts
        </Link>
        <div className="relative flex flex-wrap items-end justify-between gap-6 px-6 pb-7 pt-6 sm:px-8 sm:pb-8">
          <div className="flex items-start gap-3" data-tour="tp-header">
            <div className="grid h-11 w-11 flex-shrink-0 place-items-center rounded-xl bg-[#9ee5b5]/15 text-[#9ee5b5]">
              <Package size={19} />
            </div>
            <div>
              <div className="flex items-center gap-2.5 flex-wrap">
                <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">{pkg.name}</h1>
                <StatusBadge status={pkg.status} />
                <PageTourButton tourKey="page-trade-package" label="Take a tour of this page" />
              </div>
              <p className="mt-2 font-mono text-xs uppercase tracking-[0.12em] text-[#9ee5b5]">
                {pkg.package_reference ?? pkg.package_code ?? '—'}
              </p>
              <p className="mt-2 text-sm text-white/50">{pkg.contractor_name ?? 'Contractor not assigned'}</p>
            </div>
          </div>
          {canManageTradePackages && (
            <div className="flex items-center gap-2" data-tour="tp-actions">
              <button
                onClick={() => setShowAiOnboarding(true)}
                className="flex items-center gap-1.5 rounded-xl border border-white/10 px-3 py-2.5 text-sm font-medium text-white/70 transition-all duration-200 hover:-translate-y-px hover:bg-white/[0.07] hover:text-white active:translate-y-0"
              >
                <Sparkles size={14} /> AI Analysis
              </button>
              <button
                onClick={() => setShowGenerate(true)}
                className="flex items-center gap-1.5 rounded-xl border border-white/10 px-3 py-2.5 text-sm font-medium text-white/70 transition-all duration-200 hover:-translate-y-px hover:bg-white/[0.07] hover:text-white active:translate-y-0"
              >
                <FileText size={14} /> Generate Documents
              </button>
              <button
                onClick={() => setShowEdit(true)}
                className="flex items-center gap-1.5 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-px hover:bg-[#b4edc6] active:translate-y-0"
              >
                <Pencil size={14} /> Edit Package
              </button>
            </div>
          )}
        </div>
      </section>

      {/* Tabs */}
      <div className="ss-animate-in flex items-center gap-1 overflow-x-auto rounded-2xl p-2" data-tour="tp-tabs" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '70ms' }}>
        {tabs.map(t => {
          const active = tab === t.key;
          return (
            <button
              key={t.key}
              onClick={() => goToTab(
                t.key,
                // subTab is one shared state across Delay & EOT and Compliance
                // (Sprint 6F) — force each tab's own default sub-tab on click so
                // switching between them never leaves a stale value neither
                // tab recognizes (e.g. landing on Compliance with subTab still
                // 'delay' from a prior visit would render no content).
                t.key === 'delay-eot' ? 'delay' : t.key === 'compliance' ? 'risks' : undefined
              )}
              className="relative flex items-center gap-1.5 whitespace-nowrap rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all duration-200 hover:-translate-y-px active:translate-y-0"
              style={active ? { color: '#18211d', backgroundColor: 'var(--gold)', boxShadow: '0 5px 14px rgba(0,0,0,0.08)' } : { color: 'var(--text-muted)' }}
            >
              <t.icon size={14} /> {t.label}
            </button>
          );
        })}
      </div>

      {tab === 'overview'    && <OverviewTab pkg={pkg} projectId={projectId} formatCurrency={formatCurrency} onNavigateTab={goToTab} onNavigateSource={navigateToSource} />}
      {tab === 'commercial'  && <CommercialTab summary={summary} apps={apps} formatCurrency={formatCurrency} pkg={pkg} projectId={projectId} />}
      {tab === 'programme'   && <ProgrammeTab projectId={projectId} tradePackageId={pkg.id} canWrite={canManageProgramme} />}
      {tab === 'delay-eot'   && (
        <DelayEotTab
          projectId={projectId} pkg={pkg}
          canManageDelayEvents={canManageDelayEvents}
          canManageEotRequests={canManageEotRequests}
          canManageLossAndExpenseClaims={canManageLossAndExpenseClaims}
          subTab={subTab} onSubTabChange={(s) => goToTab('delay-eot', s)}
        />
      )}
      {tab === 'compliance'  && (
        <ComplianceTab
          projectId={projectId} pkg={pkg}
          canManageRisks={canManageRisks}
          canManageDeliveryDocuments={canManageDeliveryDocuments}
          subTab={subTab} onSubTabChange={(s) => goToTab('compliance', s)}
        />
      )}
      {tab === 'documents'   && <DocumentsTab projectId={projectId} packageId={packageId} onNavigateSource={navigateToSource} />}
      {tab === 'ai-analysis' && <AiAnalysisTab pkg={pkg} onStartNew={() => setShowAiOnboarding(true)} />}
      {tab === 'activity'    && <ActivityTab projectId={projectId} packageId={packageId} onNavigateSource={navigateToSource} />}

      {showEdit && (
        <EditPackageModal
          projectId={projectId}
          pkg={pkg}
          onClose={() => { setShowEdit(false); if (searchParams.get('edit')) router.replace(`/app/projects/${projectId}/subcontracts/${packageId}`); }}
        />
      )}
      {showGenerate && (
        <GeneratePackageModal
          projectId={projectId}
          tradePackage={{
            id: pkg.id, name: pkg.name, package_code: pkg.package_code,
            package_reference: pkg.package_reference, contractor_name: pkg.contractor_name,
            description: pkg.description,
          }}
          onClose={() => setShowGenerate(false)}
          onViewInPackage={() => {
            setShowGenerate(false);
            goToTab('documents');
            queryClient.invalidateQueries({ queryKey: ['package-files', projectId, packageId] });
          }}
        />
      )}
      {showAiOnboarding && (
        <SubcontractAiOnboardingModal
          isOpen={showAiOnboarding}
          onClose={() => setShowAiOnboarding(false)}
          tradePackage={{ id: pkg.id, name: pkg.name, is_custom: pkg.is_custom }}
          projectId={projectId}
          onConfirmed={() => {
            queryClient.invalidateQueries({ queryKey: ['trade-package-workspace', projectId, packageId] });
            queryClient.invalidateQueries({ queryKey: ['trade-package-programme', pkg.id] });
            // Without these, confirming while already sitting on the AI Analysis tab
            // (tab doesn't change → component doesn't remount → stale cache persists)
            // would show the pre-confirm analysis list/status (Sprint 6D Phase 0 finding).
            queryClient.invalidateQueries({ queryKey: ['trade-package-ai-analyses', pkg.id] });
            queryClient.invalidateQueries({ queryKey: ['trade-package-latest-analysis', pkg.id] });
            goToTab('ai-analysis');
          }}
        />
      )}
    </div>
  );
}

// ─── Overview ────────────────────────────────────────────────────────────────

function InfoCard({ icon: Icon, title, children, delay = 0 }: { icon: React.ElementType; title: string; children: React.ReactNode; delay?: number }) {
  return (
    <div
      className="ss-animate-in border-b border-r p-5 sm:p-6"
      style={{ borderColor: 'var(--border)', animationDelay: `${delay}ms` }}
    >
      <div className="flex items-center gap-2 mb-4">
        <Icon size={15} style={{ color: 'var(--text-muted)' }} />
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h3>
      </div>
      <dl className="space-y-2.5">{children}</dl>
    </div>
  );
}

function Row({ label, value }: { label: string; value?: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4">
      <dt className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</dt>
      <dd className="text-xs text-right font-medium" style={{ color: 'var(--text-secondary)' }}>{value ?? '—'}</dd>
    </div>
  );
}

function StatTile({ label, value, onClick, tone, delay = 0 }: { label: string; value: string | number; onClick?: () => void; tone?: 'default' | 'warning' | 'danger'; delay?: number }) {
  const color = tone === 'danger' ? '#f87171' : tone === 'warning' ? '#facc15' : 'var(--text-primary)';
  return (
    <button
      onClick={onClick}
      disabled={!onClick}
      className={`ss-animate-in min-h-[96px] border-b border-r p-4 text-left transition-colors duration-200 disabled:cursor-default ${onClick ? 'hover:bg-[var(--bg-hover)]' : ''}`}
      style={{ borderColor: 'var(--border)', animationDelay: `${delay}ms` }}
    >
      <p className="text-2xl font-semibold tabular-nums tracking-[-0.04em]" style={{ color }}>{value}</p>
      <p className="mt-2 text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
    </button>
  );
}

function OverviewTab({ pkg, projectId, formatCurrency, onNavigateTab, onNavigateSource }: {
  pkg: TradePackage; projectId: string;
  formatCurrency: (n: number | string) => string;
  onNavigateTab: (t: WorkspaceTab, sub?: DelaySubTab) => void;
  onNavigateSource: (source: ActivitySource) => void;
}) {
  const offsetLabel = (n?: number | null) => (n != null ? `${n} day${n === 1 ? '' : 's'}` : '—');
  const date = (d?: string | null) => (d ? formatDate(d) : '—');

  const { data: programmeData } = useQuery<any[]>({
    queryKey: ['trade-package-programme', pkg.id],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${pkg.id}/programme`).then(r => r.data),
  });
  // Distinct keys from DelayEventsTab/EotRequestsTab/LossAndExpenseTab's
  // ['trade-package-delay-events'/'...-eot-requests'/'...-loss-and-expense', id]
  // deliberately — those wrap the response as { data: [...] }, this resolves
  // it as a bare array; sharing a key with a different queryFn shape caused
  // a cache collision (whichever query ran first "won" the cache for both
  // consumers, crashing or silently emptying the other depending on order).
  const { data: delayData } = useQuery<any[]>({
    queryKey: ['trade-package-delay-events-summary', pkg.id],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${pkg.id}/delay-events`).then(r => r.data),
  });
  const { data: eotData } = useQuery<any[]>({
    queryKey: ['trade-package-eot-requests-summary', pkg.id],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${pkg.id}/eot-requests`).then(r => r.data),
  });
  const { data: leData } = useQuery<any[]>({
    queryKey: ['trade-package-loss-and-expense-summary', pkg.id],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${pkg.id}/loss-and-expense-claims`).then(r => r.data),
  });
  const { data: latestAnalysis } = useQuery<{ data: any }>({
    queryKey: ['trade-package-latest-analysis', pkg.id],
    queryFn: () => api.get(`/trade-packages/${pkg.id}/ai-analysis`).then(r => r.data),
  });
  const { data: calendarItems } = useQuery<{ data: any[] }>({
    queryKey: ['trade-package-calendar', projectId, pkg.id],
    queryFn: () => api.get(`/projects/${projectId}/calendar-events`, { params: { trade_package_id: pkg.id } }).then(r => r.data),
  });
  const { data: recentActivity } = useQuery<{ data: any[] }>({
    queryKey: ['trade-package-activity', projectId, pkg.id],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${pkg.id}/activities`).then(r => r.data),
  });

  const milestones = programmeData ?? [];
  const delayEvents = delayData ?? [];
  const eotRequests = eotData ?? [];
  const leClaims = leData ?? [];
  const openDelays = delayEvents.filter((d: any) => d.status === 'open' || d.status === 'under_assessment').length;
  const pendingEots = eotRequests.filter((e: any) => e.status === 'submitted' || e.status === 'under_assessment').length;
  const openLe = leClaims.filter((c: any) => c.status !== 'agreed' && c.status !== 'rejected').length;
  const completeMilestones = milestones.filter((m: any) => m.status === 'complete').length;
  const upcomingItems = (calendarItems?.data ?? []).filter((e: any) => e.status !== 'completed' && e.status !== 'missed');
  const upcomingCount = upcomingItems.length;
  const analysis = latestAnalysis?.data ?? null;
  const activity = (recentActivity?.data ?? []).slice(0, 5);

  // getItemsForTradePackage() (backing this feed) sorts by event_date ascending,
  // so the first match per category is genuinely the soonest — no extra sort needed.
  const nextPayment = upcomingItems.find((i: any) => i.category === 'payment');
  const nextMilestone = upcomingItems.find((i: any) => i.category === 'programme' && i.type === 'milestone');

  // Calendar events carry a fully-formed workspace URL (?tab=X&subtab=Y) rather
  // than a structured source object — parse it into the same shape ActivityTab/
  // DocumentsTab use so one navigation helper serves all three (Sprint 6D).
  const navigateToCalendarItem = (item: any) => {
    if (!item.action_url) return;
    const q = item.action_url.split('?')[1];
    const params = new URLSearchParams(q);
    onNavigateSource({ type: item.type, id: 0, tab: params.get('tab') ?? 'overview', subtab: params.get('subtab') });
  };

  return (
    <div className="space-y-4">
      <section className="grid grid-cols-2 overflow-hidden rounded-2xl sm:grid-cols-3 lg:grid-cols-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <StatTile
          label="Next Payment Due"
          value={nextPayment ? formatDate(nextPayment.date) : '—'}
          onClick={nextPayment ? () => navigateToCalendarItem(nextPayment) : undefined}
          delay={0}
        />
        <StatTile
          label="Next Milestone"
          value={nextMilestone ? formatDate(nextMilestone.date) : '—'}
          onClick={nextMilestone ? () => navigateToCalendarItem(nextMilestone) : undefined}
          delay={40}
        />
        <StatTile label="Programme" value={milestones.length ? `${completeMilestones}/${milestones.length}` : '—'} onClick={() => onNavigateTab('programme')} delay={80} />
        <StatTile label="Open Delay Events" value={openDelays} tone={openDelays > 0 ? 'warning' : 'default'} onClick={() => onNavigateTab('delay-eot')} delay={100} />
        <StatTile label="Pending EOTs" value={pendingEots} tone={pendingEots > 0 ? 'warning' : 'default'} onClick={() => onNavigateTab('delay-eot')} delay={140} />
        <StatTile label="Open L&E Claims" value={openLe} tone={openLe > 0 ? 'warning' : 'default'} onClick={() => onNavigateTab('delay-eot')} delay={180} />
      </section>

      <section className="grid overflow-hidden rounded-2xl md:grid-cols-2" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <InfoCard icon={Building2} title="Contractor" delay={0}>
          <Row label="Name" value={pkg.contractor_name} />
          <Row label="Contact" value={pkg.contractor_contact_name} />
          <Row label="Email" value={pkg.contractor_email} />
          <Row label="Phone" value={pkg.contractor_phone} />
          <Row label="Address" value={pkg.contractor_address} />
          <Row label="Company Reg No." value={pkg.contractor_company_reg_no} />
          <Row label="VAT Number" value={pkg.contractor_vat_number} />
        </InfoCard>

        <InfoCard icon={Receipt} title="Commercial Terms" delay={60}>
          <Row label="Contract Value" value={pkg.contract_value != null ? formatCurrency(pkg.contract_value) : '—'} />
          <Row label="Retention %" value={pkg.retention_percentage != null ? `${pkg.retention_percentage}%` : '—'} />
          <Row label="Liquidated Damages" value={pkg.liquidated_damages} />
          <Row label="Payment Terms" value={pkg.payment_terms_days != null ? `${pkg.payment_terms_days} days` : '—'} />
          <Row label="Payment Frequency" value={pkg.payment_frequency ? pkg.payment_frequency.charAt(0).toUpperCase() + pkg.payment_frequency.slice(1) : '—'} />
        </InfoCard>

        <InfoCard icon={CalendarDays} title="Key Dates" delay={120}>
          <Row label="Letter of Intent" value={date(pkg.letter_of_intent_date)} />
          <Row label="Award" value={date(pkg.award_date)} />
          <Row label="Execution" value={date(pkg.execution_date)} />
          <Row label="Commencement" value={date(pkg.commencement_date)} />
          <Row label="Completion" value={date(pkg.completion_date)} />
          <Row label="Defects Liability End" value={date(pkg.defects_liability_end_date)} />
        </InfoCard>

        <InfoCard icon={Layers} title="Payment Rules (statutory dates)" delay={180}>
          <Row label="Due Date" value={`Application + ${offsetLabel(pkg.due_date_offset_days)}`} />
          <Row label="Final Date for Payment" value={`Due + ${offsetLabel(pkg.final_date_offset_days)}`} />
          <Row label="Payment Notice" value={`Due + ${offsetLabel(pkg.payment_notice_offset_days)}`} />
          <Row label="Pay Less Notice" value={`Final − ${offsetLabel(pkg.pay_less_notice_offset_days)}`} />
          {pkg.description && (
            <div className="pt-2 mt-2" style={{ borderTop: '1px solid var(--border)' }}>
              <dt className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Description</dt>
              <dd className="text-xs" style={{ color: 'var(--text-secondary)' }}>{pkg.description}</dd>
            </div>
          )}
        </InfoCard>
      </section>

      <section className="grid overflow-hidden rounded-2xl md:grid-cols-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <button
          onClick={() => onNavigateTab('ai-analysis')}
          className="ss-animate-in border-b border-r p-5 text-left transition-colors duration-200 hover:bg-[var(--bg-hover)] md:border-b-0"
          style={{ borderColor: 'var(--border)', animationDelay: '0ms' }}
        >
          <div className="flex items-center gap-2 mb-3">
            <Sparkles size={15} style={{ color: 'var(--gold)' }} />
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>AI Analysis</h3>
          </div>
          {analysis ? (
            <>
              <StatusBadge status={analysis.status} />
              <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
                {analysis.completed_at ? `Completed ${formatDate(analysis.completed_at)}` : 'In progress…'}
              </p>
            </>
          ) : (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No analysis has been run yet.</p>
          )}
        </button>

        <div
          className="ss-animate-in border-b border-r p-5 md:border-b-0"
          style={{ borderColor: 'var(--border)', animationDelay: '60ms' }}
        >
          <div className="flex items-center gap-2 mb-3">
            <CalendarDays size={15} style={{ color: 'var(--text-muted)' }} />
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Upcoming Calendar Items</h3>
          </div>
          {upcomingItems.length === 0 ? (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Nothing upcoming.</p>
          ) : (
            <ul className="space-y-1.5 mb-2">
              {upcomingItems.slice(0, 3).map((item: any) => (
                <li key={item.id}>
                  <button onClick={() => navigateToCalendarItem(item)} className="text-xs truncate text-left transition-colors hover:underline block w-full" style={{ color: 'var(--text-secondary)' }}>
                    {item.title}
                  </button>
                </li>
              ))}
            </ul>
          )}
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            <Link href={`/app/projects/${projectId}/calendar`} className="transition-colors hover:underline" style={{ color: 'var(--gold)' }}>
              View full Calendar ({upcomingCount}) →
            </Link>
          </p>
        </div>

        <div
          className="ss-animate-in p-5"
          style={{ animationDelay: '120ms' }}
        >
          <div className="flex items-center justify-between mb-3">
            <div className="flex items-center gap-2">
              <Clock size={15} style={{ color: 'var(--text-muted)' }} />
              <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent Activity</h3>
            </div>
            <button onClick={() => onNavigateTab('activity')} className="text-xs transition-colors hover:underline" style={{ color: 'var(--gold)' }}>View all →</button>
          </div>
          {activity.length === 0 ? (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No activity recorded yet.</p>
          ) : (
            <ul className="space-y-1.5">
              {activity.slice(0, 3).map((a: any) => (
                <li key={a.id}>
                  {a.source?.tab ? (
                    <button onClick={() => onNavigateSource(a.source)} className="text-xs truncate text-left transition-colors hover:underline block w-full" style={{ color: 'var(--text-secondary)' }}>
                      {a.description}
                    </button>
                  ) : (
                    <p className="text-xs truncate" style={{ color: 'var(--text-secondary)' }}>{a.description}</p>
                  )}
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>
    </div>
  );
}

// ─── Programme ───────────────────────────────────────────────────────────────

const MILESTONE_STATUS: Record<string, { label: string; bg: string; text: string }> = {
  not_started: { label: 'Not Started', bg: 'rgba(90,86,82,0.2)', text: '#9a9490' },
  in_progress: { label: 'In Progress', bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  complete:    { label: 'Complete',    bg: 'rgba(34,197,94,0.12)', text: '#4ade80' },
  delayed:     { label: 'Delayed',     bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
  at_risk:     { label: 'At Risk',     bg: 'rgba(239,68,68,0.12)', text: '#f87171' },
};

function MilestoneModal({ projectId, tradePackageId, onClose }: { projectId: string; tradePackageId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({ name: '', milestone_type: 'other', planned_date: '', notes: '' });

  const mutation = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/trade-packages/${tradePackageId}/programme`, {
      name: form.name,
      milestone_type: form.milestone_type,
      planned_date: form.planned_date || null,
      notes: form.notes || null,
    }),
    onSuccess: () => {
      toast.success('Milestone added');
      qc.invalidateQueries({ queryKey: ['trade-package-programme', tradePackageId] });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to add milestone')),
  });

  return (
    <Modal title="Add Milestone" icon={ListChecks} tone="neutral" onClose={onClose} busy={mutation.isPending}>
      {(close) => (
        <>
          <div className="space-y-3">
            <div>
              <label className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>Name *</label>
              <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} />
            </div>
            <div>
              <label className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>Planned Date</label>
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} value={form.planned_date} onChange={e => setForm({ ...form, planned_date: e.target.value })} />
            </div>
            <div>
              <label className="text-xs font-medium mb-1 block" style={{ color: 'var(--text-muted)' }}>Notes</label>
              <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" rows={2} style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} />
            </div>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button onClick={close} disabled={mutation.isPending} className="px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-80 disabled:opacity-50" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
            <button onClick={() => mutation.mutate()} disabled={!form.name || mutation.isPending} className="px-4 py-2 rounded-lg text-sm font-semibold transition-all active:scale-[0.98] disabled:opacity-50" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {mutation.isPending ? 'Saving…' : 'Add Milestone'}
            </button>
          </div>
        </>
      )}
    </Modal>
  );
}

function ProgrammeTab({ projectId, tradePackageId, canWrite }: { projectId: string; tradePackageId: number; canWrite: boolean }) {
  const qc = useQueryClient();
  const [showAdd, setShowAdd] = useState(false);

  const { data, isLoading } = useQuery<any[]>({
    queryKey: ['trade-package-programme', tradePackageId],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/programme`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/programme/${id}`),
    onSuccess: () => {
      toast.success('Milestone removed');
      qc.invalidateQueries({ queryKey: ['trade-package-programme', tradePackageId] });
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to remove milestone')),
  });

  const milestones = data ?? [];

  return (
    <div className="space-y-4">
      {canWrite && (
        <div className="flex justify-end">
          <button onClick={() => setShowAdd(true)} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            <Plus size={15} /> Add Milestone
          </button>
        </div>
      )}
      <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Milestone', 'Planned', 'Forecast', 'Actual', 'Duration', 'Progress', 'Status', 'Group', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>}
            {!isLoading && milestones.length === 0 && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No programme milestones yet.</td></tr>
            )}
            {milestones.map((m: any) => {
              const meta = MILESTONE_STATUS[m.status] ?? MILESTONE_STATUS.not_started;
              return (
                <tr key={m.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-3 py-2.5">
                    <div className="font-medium" style={{ color: 'var(--text-primary)' }}>{m.name}</div>
                    {m.is_ai_generated && <span className="text-[10px]" style={{ color: 'var(--gold)' }}>AI-extracted</span>}
                  </td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{m.planned_date ? formatDate(m.planned_date) : '—'}</td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{m.forecast_date ? formatDate(m.forecast_date) : '—'}</td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{m.actual_date ? formatDate(m.actual_date) : '—'}</td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{m.duration_days != null ? `${m.duration_days}d` : '—'}</td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{m.progress_pct != null ? `${m.progress_pct}%` : '—'}</td>
                  <td className="px-3 py-2.5"><span className="px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: meta.bg, color: meta.text }}>{meta.label}</span></td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>{m.group_name ?? '—'}</td>
                  <td className="px-3 py-2.5 text-right">
                    {canWrite && (
                      <button onClick={() => deleteMutation.mutate(m.id)} className="p-1.5 rounded hover:bg-white/5" style={{ color: 'var(--text-muted)' }}>
                        <Trash2 size={13} />
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {showAdd && <MilestoneModal projectId={projectId} tradePackageId={tradePackageId} onClose={() => setShowAdd(false)} />}
    </div>
  );
}

// ─── Delay & EOT ─────────────────────────────────────────────────────────────

function DelayEotTab({ projectId, pkg, canManageDelayEvents, canManageEotRequests, canManageLossAndExpenseClaims, subTab, onSubTabChange }: {
  projectId: string; pkg: TradePackage;
  canManageDelayEvents: boolean; canManageEotRequests: boolean; canManageLossAndExpenseClaims: boolean;
  subTab: DelaySubTab; onSubTabChange: (s: DelaySubTab) => void;
}) {
  const tpOption = { id: pkg.id, name: pkg.name, package_reference: pkg.package_reference };

  return (
    <div className="space-y-4">
      <div className="flex gap-1.5">
        {(['delay', 'eot', 'loss-expense'] as const).map(s => (
          <button
            key={s}
            onClick={() => onSubTabChange(s)}
            className="px-3 py-1.5 rounded-full text-xs font-medium"
            style={subTab === s
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
          >
            {s === 'delay' ? 'Delay Events' : s === 'eot' ? 'EOT Requests' : 'Loss & Expense'}
          </button>
        ))}
      </div>
      {subTab === 'delay' && <DelayEventsTab projectId={projectId} contracts={[]} tradePackages={[tpOption]} canWrite={canManageDelayEvents} tradePackageId={pkg.id} />}
      {subTab === 'eot' && <EotRequestsTab projectId={projectId} contracts={[]} tradePackages={[tpOption]} canWrite={canManageEotRequests} tradePackageId={pkg.id} />}
      {subTab === 'loss-expense' && <LossAndExpenseTab projectId={projectId} contracts={[]} tradePackages={[tpOption]} canWrite={canManageLossAndExpenseClaims} tradePackageId={pkg.id} />}
    </div>
  );
}

// ─── Compliance ──────────────────────────────────────────────────────────────
//
// Sprint 6F — first Compliance module (Risk Register). Kept as its own
// sub-tabbed section (mirroring Delay & EOT) so Insurance and Document
// Requirements can slot in as siblings later without redesigning the tab.

function ComplianceTab({ projectId, pkg, canManageRisks, canManageDeliveryDocuments, subTab, onSubTabChange }: {
  projectId: string; pkg: TradePackage;
  canManageRisks: boolean; canManageDeliveryDocuments: boolean;
  subTab: DelaySubTab; onSubTabChange: (s: DelaySubTab) => void;
}) {
  return (
    <div className="space-y-4">
      <div className="flex gap-1.5">
        {(['risks', 'delivery-documents'] as const).map(s => (
          <button
            key={s}
            onClick={() => onSubTabChange(s)}
            className="px-3 py-1.5 rounded-full text-xs font-medium"
            style={subTab === s
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
          >
            {s === 'risks' ? 'Risks' : 'Delivery Documents'}
          </button>
        ))}
      </div>
      {subTab === 'risks' && <RisksTab projectId={projectId} tradePackageId={pkg.id} canWrite={canManageRisks} />}
      {subTab === 'delivery-documents' && <DeliveryDocumentsTab projectId={projectId} tradePackageId={pkg.id} canWrite={canManageDeliveryDocuments} />}
    </div>
  );
}

// ─── Documents ───────────────────────────────────────────────────────────────

const STANDARD_FOLDERS = [
  '01 Tender Enquiry', '02 Schedule of Documents', '03 Drawings', '04 Specifications',
  '05 Pricing Documents', '06 Contract Draft', '07 Correspondence', '08 Returned Tender',
  '09 Executed Contract',
];

function DocumentsTab({ projectId, packageId, onNavigateSource }: {
  projectId: string; packageId: string; onNavigateSource: (source: ActivitySource) => void;
}) {
  const { data, isLoading } = useQuery({
    queryKey: ['package-files', projectId, packageId],
    queryFn: () => api.get(`/projects/${projectId}/documents/module/subcontracts/package/${packageId}`).then(r => r.data),
  });

  const files: any[] = data?.data ?? data?.files ?? [];
  const generatedDocuments: any[] = data?.generated_documents ?? [];
  const apiBase = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api';

  return (
    <div className="space-y-4">
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h3 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Standard Folders</h3>
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
          {STANDARD_FOLDERS.map(f => (
            <div key={f} className="flex items-center gap-2 px-3 py-2 rounded-xl text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              <FileStack size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              {f}
            </div>
          ))}
        </div>
      </div>

      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h3 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Uploaded Files</h3>
        {isLoading ? (
          <div className="flex items-center justify-center py-8"><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        ) : files.length === 0 ? (
          <div className="text-center py-8">
            <FileText size={26} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No files uploaded for this package yet.</p>
          </div>
        ) : (
          <div className="space-y-2">
            {files.map((f: any) => (
              <div key={f.id} className="flex items-center justify-between gap-3 px-4 py-3 rounded-xl" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                <div className="flex items-center gap-3 min-w-0">
                  <FileText size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                  <div className="min-w-0">
                    <p className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{f.original_name}</p>
                    {f.file_size && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{(f.file_size / 1024).toFixed(0)} KB</p>}
                  </div>
                </div>
                <a
                  href={`${apiBase}/file-uploads/${f.id}/download`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-surface)] flex-shrink-0"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <Download size={11} /> Download
                </a>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h3 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Generated Documents</h3>
        {generatedDocuments.length === 0 ? (
          <p className="text-sm py-2" style={{ color: 'var(--text-muted)' }}>No generated notices, statements, or certificates yet.</p>
        ) : (
          <div className="space-y-2">
            {generatedDocuments.map((d: any) => (
              <div key={d.id} className="flex items-center justify-between gap-3 px-4 py-3 rounded-xl" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                <div className="flex items-center gap-3 min-w-0">
                  <FileText size={15} style={{ color: 'var(--gold)', flexShrink: 0 }} />
                  <div className="min-w-0">
                    <p className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{d.title}</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{d.type?.replace(/_/g, ' ')} · {formatDate(d.created_at)}</p>
                    {d.source?.label && (
                      <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                        Generated from: {d.source.tab
                          ? <button onClick={() => onNavigateSource(d.source)} className="hover:underline" style={{ color: 'var(--gold)' }}>{d.source.label}</button>
                          : d.source.label}
                      </p>
                    )}
                  </div>
                </div>
                <a
                  href={`${apiBase}/documents/${d.id}/download`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-surface)] flex-shrink-0"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <Download size={11} /> Download
                </a>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Commercial ──────────────────────────────────────────────────────────────

function CommercialTab({ summary, apps, formatCurrency, pkg, projectId }: {
  summary?: CommercialSummary;
  apps: AppRow[];
  formatCurrency: (n: number | string) => string;
  pkg: TradePackage;
  projectId: string;
}) {
  const stats = [
    { label: 'Applications', value: String(summary?.applications_count ?? 0) },
    { label: 'Certified to Date', value: formatCurrency(summary?.certified_to_date ?? 0) },
    { label: 'Paid to Date', value: formatCurrency(summary?.paid_to_date ?? 0) },
    { label: 'Retention Held', value: formatCurrency(summary?.retention_held ?? 0) },
    { label: 'Outstanding Balance', value: formatCurrency(summary?.outstanding_balance ?? 0) },
  ];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        {stats.map((s, i) => (
          <div key={s.label} className="ss-animate-in rounded-2xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${i * 50}ms` }}>
            <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{s.label}</p>
            <p className="text-lg font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>{s.value}</p>
          </div>
        ))}
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="px-5 py-3 flex items-center justify-between" style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Payment Applications</h3>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Read-only summary</span>
        </div>
        {apps.length === 0 ? (
          <div className="text-center py-8" style={{ backgroundColor: 'var(--bg-surface)' }}>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No payment applications for this package yet.</p>
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['#', 'Date', 'Gross', 'Certified', 'Paid', 'Status'].map(h => (
                  <th key={h} className="text-left px-5 py-2.5 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {apps.map(a => (
                <tr key={a.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-5 py-3 font-mono text-[11px] font-medium" style={{ color: 'var(--text-primary)' }}>{a.application_number}</td>
                  <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{a.application_date ? formatDate(a.application_date) : '—'}</td>
                  <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>{a.gross_valuation != null ? formatCurrency(a.gross_valuation) : '—'}</td>
                  <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>{a.certified_amount != null ? formatCurrency(a.certified_amount) : '—'}</td>
                  <td className="px-5 py-3 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>{a.paid_amount != null ? formatCurrency(a.paid_amount) : '—'}</td>
                  <td className="px-5 py-3"><StatusBadge status={a.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      <div>
        <h3 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Final Account</h3>
        <FinalAccountTab
          contracts={[]}
          tradePackages={[{ id: pkg.id, name: pkg.name, package_reference: pkg.package_reference, contractor_name: pkg.contractor_name }]}
          projectId={projectId}
        />
      </div>
    </div>
  );
}

// ─── AI Analysis ─────────────────────────────────────────────────────────────

function AiAnalysisTab({ pkg, onStartNew }: { pkg: TradePackage; onStartNew: () => void }) {
  const [expanded, setExpanded] = useState<number | null>(null);
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery<{ data: any[] }>({
    queryKey: ['trade-package-ai-analyses', pkg.id],
    queryFn: () => api.get(`/trade-packages/${pkg.id}/ai-analyses`).then(r => r.data),
  });

  const reparseMutation = useMutation({
    mutationFn: (id: number) => api.post(`/trade-package-ai-analyses/${id}/reparse`),
    onSuccess: () => {
      toast.success('Re-parsed using the existing analysis. Your monthly AI usage was not increased.');
      queryClient.invalidateQueries({ queryKey: ['trade-package-ai-analyses', pkg.id] });
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Could not re-parse this analysis.')),
  });

  const analyses = data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{analyses.length} analysis run{analyses.length !== 1 ? 's' : ''} for this trade package.</p>
        <button onClick={onStartNew} className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
          <Sparkles size={14} /> New Analysis
        </button>
      </div>

      {isLoading ? (
        <div className="flex items-center justify-center py-8"><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
      ) : analyses.length === 0 ? (
        <div className="rounded-2xl p-10 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px dashed var(--border)' }}>
          <Sparkles size={26} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No AI analyses yet. Upload an executed subcontract to get started.</p>
        </div>
      ) : (
        <div className="space-y-2">
          {analyses.map((a: any) => (
            <div key={a.id} className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              <button
                onClick={() => setExpanded(expanded === a.id ? null : a.id)}
                className="w-full flex items-center justify-between px-4 py-3 text-left"
                style={{ backgroundColor: 'var(--bg-elevated)' }}
              >
                <div className="flex items-center gap-3">
                  <StatusBadge status={a.status} />
                  <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{a.summary ?? `Analysis #${a.id}`}</span>
                </div>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{a.created_at ? formatDate(a.created_at) : '—'}</span>
              </button>
              {expanded === a.id && (
                <div className="p-4 space-y-3" style={{ backgroundColor: 'var(--bg-surface)' }}>
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs">
                    <Row label="Started" value={a.started_at ? formatDate(a.started_at) : '—'} />
                    <Row label="Completed" value={a.completed_at ? formatDate(a.completed_at) : '—'} />
                    {/* Sprint 6D: only ever surface fields the pipeline actually captures —
                        no clause references, page numbers, or section headings, since the
                        extraction pipeline discards page/clause context before Claude sees
                        the text. Confidence and analysis notes genuinely exist in meta. */}
                    <Row label="Confidence" value={a.raw_response_json?.meta?.confidence ?? '—'} />
                    <Row label="Confirmed" value={a.confirmed_at ? formatDate(a.confirmed_at) : (a.status === 'confirmed' ? 'Yes' : '—')} />
                  </div>
                  {!!a.raw_response_json?.missing_information?.length && (
                    <div>
                      <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-muted)' }}>Missing Information</p>
                      <ul className="space-y-1">
                        {a.raw_response_json.missing_information.map((m: string, i: number) => (
                          <li key={i} className="text-xs flex items-start gap-2" style={{ color: 'var(--text-muted)' }}>
                            <span>•</span><span>{m}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                  {a.confirmed_data_json && (
                    <div>
                      <p className="text-xs font-semibold mb-1" style={{ color: 'var(--text-muted)' }}>Confirmed Data Summary</p>
                      <pre className="text-xs p-3 rounded-lg overflow-x-auto" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                        {JSON.stringify(a.confirmed_data_json, null, 2).slice(0, 800)}
                      </pre>
                    </div>
                  )}
                  {a.status === 'failed' && a.raw_response_text && (
                    <button
                      onClick={() => reparseMutation.mutate(a.id)}
                      disabled={reparseMutation.isPending}
                      className="text-xs px-3 py-1.5 rounded-lg font-medium"
                      style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
                    >
                      {reparseMutation.isPending ? 'Re-parsing…' : 'Re-parse saved response (no cost)'}
                    </button>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Activity ────────────────────────────────────────────────────────────────

type ActivitySource = { type: string; id: number; tab: string; subtab?: string | null } | null;

function ActivityTab({ projectId, packageId, onNavigateSource }: {
  projectId: string; packageId: string; onNavigateSource: (source: ActivitySource) => void;
}) {
  const { data, isLoading } = useQuery<{ data: any[]; total: number }>({
    queryKey: ['trade-package-activity', projectId, packageId],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${packageId}/activities`).then(r => r.data),
  });

  const items = data?.data ?? [];

  return (
    <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      {isLoading ? (
        <div className="flex items-center justify-center py-8"><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
      ) : items.length === 0 ? (
        <div className="text-center py-8">
          <Clock size={26} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No activity recorded for this trade package yet.</p>
        </div>
      ) : (
        <ul className="space-y-3">
          {items.map((item: any) => (
            <li key={item.id} className="flex items-start gap-3 pb-3" style={{ borderBottom: '1px solid var(--border)' }}>
              <div className="w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0" style={{ backgroundColor: 'var(--gold)' }} />
              <div className="min-w-0 flex-1">
                <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{item.description}</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {item.user?.name ?? 'System'} · {item.created_at ? formatDate(item.created_at) : '—'}
                </p>
              </div>
              {item.source?.tab && (
                <button
                  onClick={() => onNavigateSource(item.source)}
                  className="text-xs px-2 py-1 rounded-lg flex-shrink-0 transition-colors hover:bg-[var(--bg-hover)]"
                  style={{ color: 'var(--gold)' }}
                >
                  View →
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

// ─── Edit modal ──────────────────────────────────────────────────────────────

const STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'tendering', label: 'Tendering' },
  { value: 'tender_returned', label: 'Tender Returned' },
  { value: 'under_review', label: 'Under Review' },
  { value: 'awarded', label: 'Awarded' },
  { value: 'documents_issued', label: 'Documents Issued' },
  { value: 'executed', label: 'Executed' },
  { value: 'active', label: 'Active' },
  { value: 'completed', label: 'Completed' },
  { value: 'closed', label: 'Closed' },
  { value: 'archived', label: 'Archived' },
];

const FREQUENCY_OPTIONS = ['weekly', 'fortnightly', 'monthly', 'manual'];

const FIELD_CLS = "w-full px-3 py-2 rounded-lg text-sm";
const FIELD_STYLE = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' } as const;
const LABEL_STYLE = { color: 'var(--text-muted)' } as const;

// Module-level so its identity is stable across renders (otherwise inputs lose focus on each keystroke).
function PkgField({ label, value, onChange, type = 'text' }: {
  label: string; value: string; onChange: (v: string) => void; type?: string;
}) {
  return (
    <div>
      <label className="block text-xs mb-1" style={LABEL_STYLE}>{label}</label>
      <input type={type} value={value} onChange={e => onChange(e.target.value)} className={FIELD_CLS} style={FIELD_STYLE} />
    </div>
  );
}

function EditPackageModal({ projectId, pkg, onClose }: { projectId: string; pkg: TradePackage; onClose: () => void }) {
  const queryClient = useQueryClient();
  // Genuine exit animation before unmount — matches components/ui/Modal.tsx's
  // own close() pattern. This modal stays a custom layout (max-w-3xl,
  // sticky header, scrollable body) rather than migrating to the shared
  // <Modal> component, which is fixed at max-w-md and too narrow for this form.
  const [closing, setClosing] = useState(false);
  const close = () => {
    if (closing) return;
    setClosing(true);
    window.setTimeout(onClose, 150);
  };

  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => { if (e.key === 'Escape') close(); };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const str = (v: unknown) => (v == null ? '' : String(v));
  const [form, setForm] = useState<Record<string, string>>({
    name: str(pkg.name),
    package_code: str(pkg.package_code),
    package_reference: str(pkg.package_reference),
    status: str(pkg.status) || 'active',
    description: str(pkg.description),
    // contractor
    contractor_name: str(pkg.contractor_name),
    contractor_contact_name: str(pkg.contractor_contact_name),
    contractor_email: str(pkg.contractor_email),
    contractor_phone: str(pkg.contractor_phone),
    contractor_address: str(pkg.contractor_address),
    contractor_company_reg_no: str(pkg.contractor_company_reg_no),
    contractor_vat_number: str(pkg.contractor_vat_number),
    // commercial
    contract_value: str(pkg.contract_value),
    retention_percentage: str(pkg.retention_percentage),
    liquidated_damages: str(pkg.liquidated_damages),
    payment_terms_days: str(pkg.payment_terms_days),
    payment_frequency: str(pkg.payment_frequency),
    // dates
    letter_of_intent_date: str(pkg.letter_of_intent_date)?.slice(0, 10),
    award_date: str(pkg.award_date)?.slice(0, 10),
    execution_date: str(pkg.execution_date)?.slice(0, 10),
    commencement_date: str(pkg.commencement_date)?.slice(0, 10),
    completion_date: str(pkg.completion_date)?.slice(0, 10),
    defects_liability_end_date: str(pkg.defects_liability_end_date)?.slice(0, 10),
    // offsets
    due_date_offset_days: str(pkg.due_date_offset_days),
    final_date_offset_days: str(pkg.final_date_offset_days),
    payment_notice_offset_days: str(pkg.payment_notice_offset_days),
    pay_less_notice_offset_days: str(pkg.pay_less_notice_offset_days),
  });

  const set = (k: string, v: string) => setForm(prev => ({ ...prev, [k]: v }));

  const { mutate, isPending } = useMutation({
    mutationFn: () => {
      // Send empty strings as null so clearing a field works; numbers stay numeric.
      const payload: Record<string, unknown> = {};
      Object.entries(form).forEach(([k, v]) => {
        payload[k] = v === '' ? null : v;
      });
      return api.put(`/projects/${projectId}/trade-packages/${pkg.id}`, payload).then(r => r.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trade-package-workspace', projectId, String(pkg.id)] });
      queryClient.invalidateQueries({ queryKey: ['project-subcontracts', projectId] });
      toast.success('Trade package updated');
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to update trade package')),
  });

  const input = FIELD_CLS;
  const inputStyle = FIELD_STYLE;
  const labelCls = "block text-xs mb-1";
  const labelStyle = LABEL_STYLE;

  return (
    <div
      className={cn('fixed inset-0 z-50 flex items-center justify-center p-4', closing ? 'ss-modal-overlay-out' : 'ss-modal-overlay-in')}
      style={{ backgroundColor: 'rgba(10,10,10,0.55)', backdropFilter: 'blur(3px)', WebkitBackdropFilter: 'blur(3px)' }}
      onClick={e => { if (e.target === e.currentTarget) close(); }}
    >
      <div
        className={cn('w-full max-w-3xl rounded-2xl max-h-[90vh] overflow-y-auto', closing ? 'ss-modal-panel-out' : 'ss-modal-panel-in')}
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        <div className="flex items-center justify-between p-5 sticky top-0" style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Edit Trade Package</h2>
          <button onClick={close}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(); }} className="p-5 space-y-6">
          {/* Package */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Package</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Name" value={form.name} onChange={v => set('name', v)} />
              <div>
                <label className={labelCls} style={labelStyle}>Status</label>
                <Select value={form.status} onChange={e => set('status', e.target.value)} className={input}>
                  {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </Select>
              </div>
              <PkgField label="Package Code" value={form.package_code} onChange={v => set('package_code', v)} />
              <PkgField label="Package Reference" value={form.package_reference} onChange={v => set('package_reference', v)} />
              <div className="col-span-2">
                <label className={labelCls} style={labelStyle}>Description</label>
                <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={2} className={input} style={inputStyle} />
              </div>
            </div>
          </section>

          {/* Contractor */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Contractor</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Contractor Name" value={form.contractor_name} onChange={v => set('contractor_name', v)} />
              <PkgField label="Contact Name" value={form.contractor_contact_name} onChange={v => set('contractor_contact_name', v)} />
              <PkgField label="Email" type="email" value={form.contractor_email} onChange={v => set('contractor_email', v)} />
              <PkgField label="Phone" value={form.contractor_phone} onChange={v => set('contractor_phone', v)} />
              <PkgField label="Address" value={form.contractor_address} onChange={v => set('contractor_address', v)} />
              <PkgField label="Company Registration No." value={form.contractor_company_reg_no} onChange={v => set('contractor_company_reg_no', v)} />
              <PkgField label="VAT Number" value={form.contractor_vat_number} onChange={v => set('contractor_vat_number', v)} />
            </div>
          </section>

          {/* Commercial terms */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Commercial Terms</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Contract Value" type="number" value={form.contract_value} onChange={v => set('contract_value', v)} />
              <PkgField label="Retention %" type="number" value={form.retention_percentage} onChange={v => set('retention_percentage', v)} />
              <PkgField label="Liquidated Damages" value={form.liquidated_damages} onChange={v => set('liquidated_damages', v)} />
              <PkgField label="Payment Terms (days)" type="number" value={form.payment_terms_days} onChange={v => set('payment_terms_days', v)} />
              <div>
                <label className={labelCls} style={labelStyle}>Payment Frequency</label>
                <Select value={form.payment_frequency} onChange={e => set('payment_frequency', e.target.value)} className={input}>
                  <option value="">—</option>
                  {FREQUENCY_OPTIONS.map(o => <option key={o} value={o}>{o.charAt(0).toUpperCase() + o.slice(1)}</option>)}
                </Select>
              </div>
            </div>
          </section>

          {/* Subcontract dates */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Subcontract Dates</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Letter of Intent" type="date" value={form.letter_of_intent_date} onChange={v => set('letter_of_intent_date', v)} />
              <PkgField label="Award Date" type="date" value={form.award_date} onChange={v => set('award_date', v)} />
              <PkgField label="Execution Date" type="date" value={form.execution_date} onChange={v => set('execution_date', v)} />
              <PkgField label="Commencement Date" type="date" value={form.commencement_date} onChange={v => set('commencement_date', v)} />
              <PkgField label="Completion Date" type="date" value={form.completion_date} onChange={v => set('completion_date', v)} />
              <PkgField label="Defects Liability End" type="date" value={form.defects_liability_end_date} onChange={v => set('defects_liability_end_date', v)} />
            </div>
          </section>

          {/* Payment rules */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Payment Rules (statutory date offsets)</h3>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Used to calculate due date, final date, and notice deadlines on this package&apos;s payment applications.
            </p>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Due Date offset (days after application)" type="number" value={form.due_date_offset_days} onChange={v => set('due_date_offset_days', v)} />
              <PkgField label="Final Date offset (days after due date)" type="number" value={form.final_date_offset_days} onChange={v => set('final_date_offset_days', v)} />
              <PkgField label="Payment Notice offset (days after due date)" type="number" value={form.payment_notice_offset_days} onChange={v => set('payment_notice_offset_days', v)} />
              <PkgField label="Pay Less Notice offset (days before final date)" type="number" value={form.pay_less_notice_offset_days} onChange={v => set('pay_less_notice_offset_days', v)} />
            </div>
          </section>

          <div className="flex items-center justify-end gap-2 pt-2">
            <button type="button" onClick={close} disabled={isPending} className="text-sm px-4 py-2 rounded-xl transition-opacity hover:opacity-80 disabled:opacity-50" style={{ color: 'var(--text-muted)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="flex items-center gap-1.5 text-sm px-4 py-2 rounded-xl transition-all active:scale-[0.98] disabled:opacity-50" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {isPending && <Loader2 size={14} className="animate-spin" />} Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
