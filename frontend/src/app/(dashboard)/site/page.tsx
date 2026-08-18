'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { HardHat, MessageSquare, BookOpen, ClipboardList, Clock, Search, ChevronRight, type LucideIcon } from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { EASE, staggerDelay } from '@/lib/motion';

type ModuleKey = 'rfis' | 'site_instructions' | 'site_diaries' | 'meetings' | 'eot_requests';

type Row = {
  id: number;
  module: string;
  project_id: number;
  project_name: string;
  reference: string;
  title: string | null;
  status: string;
  date: string | null;
  secondary: string | null;
  action_url: string;
};

type Overview = {
  summary: Record<ModuleKey, Record<string, number>>;
  rfis: Row[];
  site_instructions: Row[];
  site_diaries: Row[];
  meetings: Row[];
  eot_requests: Row[];
};

const MODULES: { id: ModuleKey; label: string; icon: LucideIcon }[] = [
  { id: 'rfis',              label: 'RFIs',              icon: MessageSquare },
  { id: 'site_instructions', label: 'Site Instructions', icon: BookOpen      },
  { id: 'site_diaries',      label: 'Site Diaries',      icon: ClipboardList },
  { id: 'meetings',          label: 'Meeting Minutes',   icon: HardHat       },
  { id: 'eot_requests',      label: 'EOTs',              icon: Clock         },
];

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:              { bg: 'rgba(234,179,8,0.15)',  text: '#facc15' },
  pending_response:  { bg: 'rgba(249,115,22,0.15)', text: '#fb923c' },
  responded:         { bg: 'rgba(34,197,94,0.15)',  text: '#4ade80' },
  submitted:         { bg: 'rgba(234,179,8,0.15)',  text: '#facc15' },
  under_assessment:  { bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  granted:           { bg: 'rgba(34,197,94,0.15)',  text: '#4ade80' },
  refused:           { bg: 'rgba(239,68,68,0.15)',  text: '#f87171' },
  approved:          { bg: 'rgba(34,197,94,0.15)',  text: '#4ade80' },
  issued:            { bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  closed:            { bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  draft:             { bg: 'rgba(90,86,82,0.3)',    text: '#9a9490' },
};

function SiteAdminPage() {
  const router = useRouter();
  const [activeModule, setActiveModule] = useState<ModuleKey>('rfis');
  const [search, setSearch] = useState('');

  const currentModule = MODULES.find(m => m.id === activeModule)!;

  const { data, isLoading } = useQuery<Overview>({
    queryKey: ['site-administration-overview'],
    queryFn: () => api.get('/site-administration/overview').then(r => r.data),
  });

  const rows = useMemo(() => {
    const all = data?.[activeModule] ?? [];
    const term = search.trim().toLowerCase();
    if (!term) return all;
    return all.filter(item =>
      item.title?.toLowerCase().includes(term) ||
      item.reference?.toLowerCase().includes(term) ||
      item.project_name?.toLowerCase().includes(term)
    );
  }, [data, activeModule, search]);

  const summary = data?.summary?.[activeModule];

  return (
    <div className="ss-projects-page ss-workspace-page-in mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:py-9">
      <section className="ss-workspace-hero-in grid overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5] lg:grid-cols-[1.1fr_0.9fr]">
        <div className="ss-workspace-left-in relative overflow-hidden p-7 sm:p-9 lg:p-11">
          <div className="absolute -left-28 -top-32 h-80 w-80 rounded-full border border-[#a5d6b5]/10 transition-transform duration-700 ease-out hover:scale-105" />
          <div className="relative">
            <div className="mb-8 flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
              <HardHat size={20} />
            </div>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Keep every site record within reach.</h1>
            <p className="mt-4 max-w-xl text-sm leading-6 text-[#b9c5bf] sm:text-base">
              Review RFIs, instructions, diaries, meeting minutes and extension requests across your projects.
            </p>
          </div>
        </div>

        <div key={`summary-${activeModule}`} className="ss-workspace-right-in grid grid-cols-2 border-t border-[#a5d6b5]/10 bg-[#202c26] lg:border-l lg:border-t-0">
          {summary ? Object.entries(summary).map(([key, value], index) => (
            <div
              key={key}
              className="ss-animate-in group/stat flex min-h-28 flex-col justify-between border-[#a5d6b5]/10 p-5 transition-colors duration-300 hover:bg-[#26342d] sm:p-6 [&:nth-child(odd)]:border-r [&:nth-child(-n+2)]:border-b"
              style={{ animationDelay: `${130 + (index * 60)}ms` }}
            >
              <p className="text-xs capitalize text-[#91a099]">{key.replace(/_/g, ' ')}</p>
              <p className="mt-4 text-3xl font-semibold tracking-[-0.04em] tabular-nums" style={{ color: key === 'total' ? '#9ee5b5' : '#f4f7f5' }}>{value}</p>
            </div>
          )) : [...Array(4)].map((_, index) => (
            <div key={index} className="min-h-28 animate-pulse border-[#a5d6b5]/10 bg-white/[0.02] [&:nth-child(odd)]:border-r [&:nth-child(-n+2)]:border-b" />
          ))}
        </div>
      </section>

      {/* Module tabs */}
      <div className="ss-animate-in rounded-2xl border p-3" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '210ms' }}>
        <div className="flex gap-1 overflow-x-auto pb-1">
          {MODULES.map(m => (
            <button key={m.id} onClick={() => setActiveModule(m.id)}
              className={`flex items-center gap-2 whitespace-nowrap rounded-xl px-3.5 py-2.5 text-xs font-semibold transition-all duration-200 ${EASE} active:scale-[0.97]`}
              style={activeModule === m.id
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', boxShadow: 'var(--shadow-card)' }
                : { color: 'var(--text-secondary)' }
              }
            >
              <m.icon size={14} />
              {m.label}
            </button>
          ))}
        </div>
        <div className="relative mt-2 border-t pt-3" style={{ borderColor: 'var(--border)' }}>
          <Search size={15} className="absolute left-3 top-[calc(50%+6px)] -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder={`Search ${currentModule.label.toLowerCase()}...`}
            className="w-full rounded-xl border border-[var(--border)] py-2.5 pl-9 pr-4 text-sm outline-none transition-all duration-200 focus:border-[var(--gold)] focus:ring-2 focus:ring-[var(--gold)]/10"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' }}
          />
        </div>
      </div>

      {/* Content */}
      <section key={activeModule} className="ss-animate-in" style={{ animationDelay: '260ms' }}>
      <div className="mb-4 flex items-end justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>{currentModule.label}</h2>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>{isLoading ? 'Loading records...' : `${rows.length} record${rows.length === 1 ? '' : 's'} across accessible projects.`}</p>
        </div>
      </div>
      {isLoading ? (
        <div className="space-y-3">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="h-18 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', height: '72px' }} />
          ))}
        </div>
      ) : rows.length === 0 ? (
        <div className="ss-animate-in flex flex-col items-center justify-center py-20 rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
               style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <currentModule.icon size={24} style={{ color: 'var(--text-muted)' }} />
          </div>
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>No {currentModule.label.toLowerCase()}</p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {currentModule.label} are raised from within each project, open a project&rsquo;s workspace to add one
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {rows.map((item, i) => {
            const badge = STATUS_COLORS[item.status] || STATUS_COLORS.draft;
            const Icon = currentModule.icon;
            return (
              <div key={item.id}
                onClick={() => router.push(item.action_url)}
                role="button"
                tabIndex={0}
                onKeyDown={e => e.key === 'Enter' && router.push(item.action_url)}
                className={`group ss-animate-in flex cursor-pointer items-center gap-4 rounded-2xl border p-4 transition-all duration-300 ${EASE} hover:-translate-y-0.5 hover:border-[var(--gold)] hover:shadow-[var(--shadow-pop)]`}
                style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', animationDelay: staggerDelay(i) }}
              >
                <div className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl transition-transform duration-300 group-hover:rotate-[-3deg] group-hover:scale-105"
                     style={{ backgroundColor: 'var(--gold-15)' }}>
                  <Icon size={18} style={{ color: 'var(--gold)' }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-3">
                    <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                      {item.title || item.reference}
                    </p>
                    <span className="flex-shrink-0 rounded-lg px-2.5 py-1 text-[11px] font-semibold"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                      {item.status.replace(/_/g, ' ')}
                    </span>
                  </div>
                  <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>
                    {item.project_name}{item.secondary ? ` / ${item.secondary}` : ''}
                  </p>
                </div>
                {item.date && (
                  <span className="hidden flex-shrink-0 text-xs sm:block" style={{ color: 'var(--text-muted)' }}>
                    {formatDate(item.date)}
                  </span>
                )}
                <ChevronRight size={15} className="flex-shrink-0 transition-transform duration-300 group-hover:translate-x-1" style={{ color: 'var(--gold)' }} />
              </div>
            );
          })}
        </div>
      )}
      </section>
    </div>
  );
}

export default function GatedSiteAdminPage() {
  return (
    <FeatureAvailabilityGate featureKey="organization.site_admin" title="Site Admin" backHref="/app" backLabel="Back to Dashboard">
      <SiteAdminPage />
    </FeatureAvailabilityGate>
  );
}
