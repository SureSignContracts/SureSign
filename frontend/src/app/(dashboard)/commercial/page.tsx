'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useRouter } from 'next/navigation';
import {
  DollarSign, GitBranch, AlertTriangle, Clock, CheckCircle2, ChevronDown,
  Landmark, FolderKanban, RefreshCw, ChevronRight,
} from 'lucide-react';
import api from '@/lib/api';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import EmptyState from '@/components/ui/EmptyState';
import { EASE, staggerDelay } from '@/lib/motion';
import { getErrorMessage as getApiErrorMessage } from '@/lib/getErrorMessage';

// ── Types (mirrors CommercialOverviewService::build()) ──────────────────────

type CashPositionBlock = {
  currency: string;
  certified_total: number;
  paid_total: number;
  outstanding_total: number;
  retention_total: number;
};

type DeadlineItem = {
  type: string;
  label: string;
  project_id: number;
  project_name: string;
  reference: string;
  amount: number | null;
  currency: string;
  due_date: string;
  days: number;
  status: 'overdue' | 'due_today' | 'due_soon' | 'upcoming';
  action_url: string;
};

type ActionItem = {
  project_id: number;
  project_name: string;
  reference: string;
  status: string;
  amount: number;
  currency: string;
  date: string | null;
  action_url: string;
};

type ProjectRow = {
  project_id: number;
  project_name: string;
  currency: string;
  contract_value: number;
  certified: number;
  paid: number;
  outstanding: number;
  retention: number;
  pending_variation_value: number;
  approved_variation_value: number;
  attention_count: number;
  action_url: string;
};

type CommercialOverview = {
  summary: CashPositionBlock[];
  deadlines: {
    due_soon_threshold_days: number;
    overdue: DeadlineItem[];
    due_today: DeadlineItem[];
    due_soon: DeadlineItem[];
    upcoming: DeadlineItem[];
  };
  awaiting_action: {
    payment_applications: {
      awaiting_submission: ActionItem[];
      awaiting_certification: ActionItem[];
      certified_unpaid: ActionItem[];
    };
    variations: {
      awaiting_valuation: ActionItem[];
      awaiting_decision: ActionItem[];
    };
  };
  projects: ProjectRow[];
};

const DEADLINE_STYLE: Record<DeadlineItem['status'], { bg: string; text: string; label: string }> = {
  overdue:    { bg: 'rgba(239,68,68,0.15)',  text: '#f87171', label: 'Overdue' },
  due_today:  { bg: 'rgba(249,115,22,0.15)', text: '#fb923c', label: 'Due Today' },
  due_soon:   { bg: 'rgba(234,179,8,0.15)',  text: '#facc15', label: 'Due Soon' },
  upcoming:   { bg: 'rgba(90,86,82,0.3)',    text: '#9a9490', label: 'Upcoming' },
};

export default function CommercialPage() {
  const formatCurrency = useCurrencyFormatter();
  const router = useRouter();
  const [showUpcoming, setShowUpcoming] = useState(false);

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery<CommercialOverview>({
    queryKey: ['commercial-overview'],
    queryFn: () => api.get('/commercial/overview').then(r => r.data),
  });

  const goto = (url: string) => router.push(url);
  const deadlineCount = data
    ? data.deadlines.overdue.length + data.deadlines.due_today.length + data.deadlines.due_soon.length
    : 0;
  const actionCount = data
    ? Object.values(data.awaiting_action.payment_applications).reduce((total, items) => total + items.length, 0)
      + Object.values(data.awaiting_action.variations).reduce((total, items) => total + items.length, 0)
    : 0;

  return (
    <div className="ss-projects-page ss-workspace-page-in mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:py-9">
      <section className="ss-workspace-hero-in grid overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5] lg:grid-cols-[1.1fr_0.9fr]">
        <div className="ss-workspace-left-in relative overflow-hidden p-7 sm:p-9 lg:p-11">
          <div className="absolute -left-28 -top-32 h-80 w-80 rounded-full border border-[#a5d6b5]/10 transition-transform duration-700 ease-out hover:scale-105" />
          <div className="relative">
            <div className="mb-8 flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
              <Landmark size={20} />
            </div>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Commercial control across every project.</h1>
            <p className="mt-4 max-w-xl text-sm leading-6 text-[#b9c5bf] sm:text-base">
              See cash position, approaching deadlines and decisions that need action across the organisation.
            </p>
          </div>
        </div>

        <div className="ss-workspace-right-in grid grid-cols-3 border-t border-[#a5d6b5]/10 bg-[#202c26] lg:border-l lg:border-t-0">
          {[
            { label: 'Projects', value: data?.projects.length ?? 0, icon: FolderKanban, color: '#f4f7f5' },
            { label: 'At-risk dates', value: deadlineCount, icon: Clock, color: deadlineCount > 0 ? '#fda4a4' : '#9ee5b5' },
            { label: 'Actions', value: actionCount, icon: GitBranch, color: actionCount > 0 ? '#fdba74' : '#9ee5b5' },
          ].map((stat, index) => (
            <div key={stat.label} className="ss-animate-in group/stat flex min-h-44 flex-col justify-between border-r border-[#a5d6b5]/10 p-5 transition-colors duration-300 last:border-r-0 hover:bg-[#26342d] sm:p-6" style={{ animationDelay: `${140 + (index * 70)}ms` }}>
              <stat.icon size={15} className="transition-transform duration-300 group-hover/stat:scale-110" style={{ color: '#91a099' }} />
              <div>
                <p className="text-3xl font-semibold tracking-[-0.04em] tabular-nums" style={{ color: stat.color }}>{isLoading ? '...' : stat.value}</p>
                <p className="mt-1 text-xs text-[#91a099]">{stat.label}</p>
              </div>
            </div>
          ))}
        </div>
      </section>

      {isError && (
        <ErrorState onRetry={() => refetch()} message={getErrorMessage(error)} />
      )}

      {!isError && (
        <>
          <div className="ss-animate-in" style={{ animationDelay: '210ms' }}>
            <CashPositionSection data={data?.summary} isLoading={isLoading} formatCurrency={formatCurrency} />
          </div>
          <div className="grid items-start gap-5 lg:grid-cols-2">
            <div className="ss-animate-in rounded-2xl border p-5 sm:p-6" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '270ms' }}>
              <DeadlinesSection
            deadlines={data?.deadlines}
            isLoading={isLoading}
            formatCurrency={formatCurrency}
            onOpen={goto}
            showUpcoming={showUpcoming}
            setShowUpcoming={setShowUpcoming}
              />
            </div>
            <div className="ss-animate-in rounded-2xl border p-5 sm:p-6" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '330ms' }}>
              <AwaitingActionSection
            awaitingAction={data?.awaiting_action}
            isLoading={isLoading}
            formatCurrency={formatCurrency}
            onOpen={goto}
              />
            </div>
          </div>
          <div className="ss-animate-in" style={{ animationDelay: '390ms' }}>
            <ProjectPositionSection
            projects={data?.projects}
            isLoading={isLoading}
            formatCurrency={formatCurrency}
            onOpen={goto}
            />
          </div>
        </>
      )}

      {isFetching && !isLoading && (
        <p className="flex items-center justify-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}><RefreshCw size={12} className="animate-spin" /> Refreshing data</p>
      )}
    </div>
  );
}

function getErrorMessage(error: unknown): string {
  return getApiErrorMessage(error, 'Could not load the organisation-wide commercial position.');
}

function ErrorState({ onRetry, message }: { onRetry: () => void; message: string }) {
  return (
    <div className="flex flex-col items-center justify-center py-16 gap-3 rounded-xl" style={{ border: '1px solid var(--border)' }}>
      <AlertTriangle size={28} style={{ color: '#f87171' }} />
      <p className="text-sm font-medium" style={{ color: '#f87171' }}>Could not load commercial data</p>
      <p className="text-xs text-center max-w-sm" style={{ color: 'var(--text-muted)' }}>{message}</p>
      <button
        onClick={onRetry}
        className="mt-1 px-3 py-1.5 rounded-lg text-xs font-medium active:scale-[0.98]"
        style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
      >
        Retry
      </button>
    </div>
  );
}

// ── 1. Company Cash Position ─────────────────────────────────────────────

function CashPositionSection({
  data, isLoading, formatCurrency,
}: {
  data?: CashPositionBlock[];
  isLoading: boolean;
  formatCurrency: (amount: number, currency?: string) => string;
}) {
  return (
    <section>
      <div className="mb-4">
        <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Company cash position</h2>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Authoritative totals from payment applications across the portfolio.</p>
      </div>

      {isLoading ? (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : !data || data.length === 0 ? (
        <EmptyState surface icon={DollarSign} title="No commercial data yet" description="Cash position will appear once payment applications exist on your projects." />
      ) : (
        <div className="space-y-4">
          {data.map(block => (
            <div key={block.currency}>
              {data.length > 1 && (
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>{block.currency}</p>
              )}
              <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <StatTile index={0} label="Certified to Date" value={formatCurrency(block.certified_total, block.currency)} color="#3b82f6" />
                <StatTile index={1} label="Paid to Date" value={formatCurrency(block.paid_total, block.currency)} color="#10b981" />
                <StatTile
                  index={2}
                  label="Outstanding"
                  value={formatCurrency(block.outstanding_total, block.currency)}
                  color={block.outstanding_total > 0 ? '#fb923c' : block.outstanding_total < 0 ? '#f87171' : 'var(--text-secondary)'}
                />
                <StatTile index={3} label="Retention Held" value={formatCurrency(block.retention_total, block.currency)} color="#a78bfa" />
              </div>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

function StatTile({ label, value, color, index = 0 }: { label: string; value: string; color: string; index?: number }) {
  return (
    <div
      className="ss-animate-in group rounded-2xl p-5 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)]"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: staggerDelay(index) }}
    >
      <div className="mb-4 flex h-8 w-8 items-center justify-center rounded-lg transition-transform duration-300 group-hover:scale-105" style={{ backgroundColor: `${color}18`, color }}><DollarSign size={14} /></div>
      <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{label}</p>
      {/* Value renders at its exact authoritative figure immediately — only the
          card's own entrance (opacity/translate) animates, never the number
          itself, so a monetary total is never seen mid-count. */}
      <p className="mt-1 text-xl font-semibold tracking-[-0.025em] tabular-nums" style={{ color }}>{value}</p>
    </div>
  );
}

// ── 2. Deadlines at Risk ──────────────────────────────────────────────────

function DeadlinesSection({
  deadlines, isLoading, formatCurrency, onOpen, showUpcoming, setShowUpcoming,
}: {
  deadlines?: CommercialOverview['deadlines'];
  isLoading: boolean;
  formatCurrency: (amount: number, currency?: string) => string;
  onOpen: (url: string) => void;
  showUpcoming: boolean;
  setShowUpcoming: (v: boolean) => void;
}) {
  const totalAtRisk = (deadlines?.overdue.length ?? 0) + (deadlines?.due_today.length ?? 0) + (deadlines?.due_soon.length ?? 0);

  return (
    <section>
      <div className="mb-4 flex items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Deadlines at risk</h2>
          <p className="mt-0.5 text-xs" style={{ color: 'var(--text-muted)' }}>Payment and notice dates needing attention.</p>
        </div>
        <Clock size={17} style={{ color: '#f87171' }} />
      </div>

      {isLoading ? (
        <div className="space-y-2">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : !deadlines || totalAtRisk === 0 ? (
        <EmptyState surface icon={CheckCircle2} title="Nothing overdue" description="No payment notice, pay less notice, or final payment deadlines are overdue or due soon." />
      ) : (
        <div className="space-y-4">
          {(['overdue', 'due_today', 'due_soon'] as const).map(bucket => (
            deadlines[bucket].length > 0 && (
              <div key={bucket}>
                <p className="text-xs font-medium mb-2" style={{ color: DEADLINE_STYLE[bucket].text }}>
                  {DEADLINE_STYLE[bucket].label} ({deadlines[bucket].length})
                </p>
                <div className="space-y-2">
                  {deadlines[bucket].map((item, i) => (
                    <DeadlineRow key={`${item.type}-${item.project_id}-${i}`} index={i} item={item} formatCurrency={formatCurrency} onOpen={onOpen} />
                  ))}
                </div>
              </div>
            )
          ))}

          {deadlines.upcoming.length > 0 && (
            <div>
              <button
                onClick={() => setShowUpcoming(!showUpcoming)}
                aria-expanded={showUpcoming}
                className="flex items-center gap-1.5 text-xs font-medium transition-colors hover:text-[var(--text-secondary)]"
                style={{ color: 'var(--text-muted)' }}
              >
                <ChevronDown size={14} className={`transition-transform duration-200 ${EASE} ${showUpcoming ? '' : '-rotate-90'}`} />
                Upcoming ({deadlines.upcoming.length})
              </button>
              {showUpcoming && (
                <div className="space-y-2 mt-2">
                  {deadlines.upcoming.map((item, i) => (
                    <DeadlineRow key={`${item.type}-${item.project_id}-${i}`} index={i} item={item} formatCurrency={formatCurrency} onOpen={onOpen} />
                  ))}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    </section>
  );
}

function DeadlineRow({
  item, formatCurrency, onOpen, index = 0,
}: {
  item: DeadlineItem;
  formatCurrency: (amount: number, currency?: string) => string;
  onOpen: (url: string) => void;
  index?: number;
}) {
  const s = DEADLINE_STYLE[item.status];
  const dayLabel = item.days < 0 ? `${Math.abs(item.days)}d overdue` : item.days === 0 ? 'Due today' : `${item.days}d remaining`;

  return (
    <div
      onClick={() => onOpen(item.action_url)}
      role="button"
      tabIndex={0}
      onKeyDown={e => e.key === 'Enter' && onOpen(item.action_url)}
      className={`group ss-animate-in flex cursor-pointer items-center gap-4 rounded-xl border p-3.5 transition-all duration-300 ${EASE} hover:-translate-y-0.5 hover:border-[var(--gold)] hover:shadow-[var(--shadow-card)]`}
      style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', animationDelay: staggerDelay(index) }}
    >
      <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: s.bg }}>
        <Clock size={16} style={{ color: s.text }} />
      </div>
      <div className="flex-1 min-w-0">
        <div className="flex items-center gap-3 flex-wrap">
          <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{item.label}: {item.reference}</p>
          <span className="text-xs px-2 py-0.5 rounded-full flex-shrink-0" style={{ backgroundColor: s.bg, color: s.text }}>{dayLabel}</span>
        </div>
        <div className="flex items-center gap-3 mt-0.5">
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{item.project_name}</span>
          {item.amount !== null && (
            <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatCurrency(item.amount, item.currency)}</span>
          )}
        </div>
      </div>
      <ChevronRight size={14} className="flex-shrink-0 transition-transform duration-300 group-hover:translate-x-1" style={{ color: 'var(--gold)' }} />
    </div>
  );
}

// ── 3. Awaiting Action ────────────────────────────────────────────────────

function AwaitingActionSection({
  awaitingAction, isLoading, formatCurrency, onOpen,
}: {
  awaitingAction?: CommercialOverview['awaiting_action'];
  isLoading: boolean;
  formatCurrency: (amount: number, currency?: string) => string;
  onOpen: (url: string) => void;
}) {
  const paGroups = awaitingAction ? [
    { key: 'awaiting_submission', label: 'Awaiting Submission', items: awaitingAction.payment_applications.awaiting_submission },
    { key: 'awaiting_certification', label: 'Awaiting Certification', items: awaitingAction.payment_applications.awaiting_certification },
    { key: 'certified_unpaid', label: 'Certified but Unpaid', items: awaitingAction.payment_applications.certified_unpaid },
  ] : [];

  const varGroups = awaitingAction ? [
    { key: 'awaiting_valuation', label: 'Awaiting Valuation', items: awaitingAction.variations.awaiting_valuation },
    { key: 'awaiting_decision', label: 'Awaiting Decision', items: awaitingAction.variations.awaiting_decision },
  ] : [];

  const totalItems = [...paGroups, ...varGroups].reduce((sum, g) => sum + g.items.length, 0);

  return (
    <section>
      <div className="mb-4 flex items-center justify-between gap-3">
        <div>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Awaiting action</h2>
          <p className="mt-0.5 text-xs" style={{ color: 'var(--text-muted)' }}>Commercial submissions and decisions in progress.</p>
        </div>
        <GitBranch size={17} style={{ color: 'var(--gold)' }} />
      </div>

      {isLoading ? (
        <div className="space-y-2">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : !awaitingAction || totalItems === 0 ? (
        <EmptyState surface icon={CheckCircle2} title="Nothing awaiting action" description="No payment applications or variations currently need a decision." />
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div>
            <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>Payment Applications</p>
            {paGroups.every(g => g.items.length === 0) ? (
              <p className="text-xs py-3" style={{ color: 'var(--text-muted)' }}>No payment applications are awaiting submission, certification, or payment.</p>
            ) : (
              <div className="space-y-3">
                {paGroups.map(g => g.items.length > 0 && (
                  <div key={g.key}>
                    <p className="text-xs mb-1.5" style={{ color: 'var(--text-secondary)' }}>{g.label} ({g.items.length})</p>
                    <div className="space-y-1.5">
                      {g.items.map((item, i) => (
                        <ActionRow key={i} index={i} icon={DollarSign} item={item} formatCurrency={formatCurrency} onOpen={onOpen} />
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div>
            <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>Variations</p>
            {varGroups.every(g => g.items.length === 0) ? (
              <p className="text-xs py-3" style={{ color: 'var(--text-muted)' }}>No variations are awaiting valuation or approval.</p>
            ) : (
              <div className="space-y-3">
                {varGroups.map(g => g.items.length > 0 && (
                  <div key={g.key}>
                    <p className="text-xs mb-1.5" style={{ color: 'var(--text-secondary)' }}>{g.label} ({g.items.length})</p>
                    <div className="space-y-1.5">
                      {g.items.map((item, i) => (
                        <ActionRow key={i} index={i} icon={GitBranch} item={item} formatCurrency={formatCurrency} onOpen={onOpen} />
                      ))}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </div>
      )}
    </section>
  );
}

function ActionRow({
  icon: Icon, item, formatCurrency, onOpen, index = 0,
}: {
  icon: React.ElementType;
  item: ActionItem;
  formatCurrency: (amount: number, currency?: string) => string;
  onOpen: (url: string) => void;
  index?: number;
}) {
  return (
    <div
      onClick={() => onOpen(item.action_url)}
      role="button"
      tabIndex={0}
      onKeyDown={e => e.key === 'Enter' && onOpen(item.action_url)}
      className={`group ss-animate-in flex cursor-pointer items-center gap-3 rounded-xl border p-3 transition-all duration-300 ${EASE} hover:-translate-y-0.5 hover:border-[var(--gold)] hover:shadow-[var(--shadow-card)]`}
      style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', animationDelay: staggerDelay(index) }}
    >
      <div className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--gold-15)' }}>
        <Icon size={14} style={{ color: 'var(--gold)' }} />
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{item.reference}</p>
        <p className="text-xs truncate" style={{ color: 'var(--text-muted)' }}>{item.project_name}</p>
      </div>
      <p className="text-xs tabular-nums flex-shrink-0" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(item.amount, item.currency)}</p>
      <ChevronRight size={13} className="flex-shrink-0 transition-transform duration-300 group-hover:translate-x-1" style={{ color: 'var(--gold)' }} />
    </div>
  );
}

// ── 4. Per-Project Commercial Position ────────────────────────────────────

function ProjectPositionSection({
  projects, isLoading, formatCurrency, onOpen,
}: {
  projects?: ProjectRow[];
  isLoading: boolean;
  formatCurrency: (amount: number, currency?: string) => string;
  onOpen: (url: string) => void;
}) {
  const mixedCurrency = new Set((projects ?? []).map(p => p.currency)).size > 1;

  return (
    <section>
      <div className="mb-4">
        <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Commercial position by project</h2>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Compare value, payment, retention and variation exposure across every project.</p>
      </div>

      <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Project', 'Contract Value', 'Certified', 'Paid', 'Outstanding', 'Retention', 'Variations (Pending / Approved)', 'Attention', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            )}
            {!isLoading && (!projects || projects.length === 0) && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No projects to show.</td></tr>
            )}
            {projects?.map((row, i) => (
              <tr
                key={row.project_id}
                onClick={() => onOpen(row.action_url)}
                className="ss-animate-in cursor-pointer hover:bg-[var(--bg-elevated)] transition-colors"
                style={{ borderBottom: '1px solid var(--border)', animationDelay: staggerDelay(i) }}
              >
                <td className="px-3 py-2.5 font-medium whitespace-nowrap" style={{ color: 'var(--text-primary)' }}>
                  {row.project_name}
                  {mixedCurrency && <span className="ml-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>({row.currency})</span>}
                </td>
                <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.contract_value, row.currency)}</td>
                <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.certified, row.currency)}</td>
                <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.paid, row.currency)}</td>
                <td className="px-3 py-2.5 tabular-nums font-semibold whitespace-nowrap" style={{ color: row.outstanding > 0 ? '#fb923c' : row.outstanding < 0 ? '#f87171' : 'var(--text-secondary)' }}>
                  {formatCurrency(row.outstanding, row.currency)}
                </td>
                <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(row.retention, row.currency)}</td>
                <td className="px-3 py-2.5 tabular-nums whitespace-nowrap" style={{ color: 'var(--text-secondary)' }}>
                  {formatCurrency(row.pending_variation_value, row.currency)} / {formatCurrency(row.approved_variation_value, row.currency)}
                </td>
                <td className="px-3 py-2.5 whitespace-nowrap">
                  {row.attention_count > 0 ? (
                    <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(249,115,22,0.15)', color: '#fb923c' }}>{row.attention_count}</span>
                  ) : (
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>None</span>
                  )}
                </td>
                <td className="px-3 py-2.5 text-right whitespace-nowrap">
                  <span className="inline-flex items-center gap-1 text-xs font-medium" style={{ color: 'var(--gold)' }}>Open <ChevronRight size={11} /></span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
