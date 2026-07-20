'use client';

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import { HardHat, MessageSquare, BookOpen, ClipboardList, Clock, Search, type LucideIcon } from 'lucide-react';
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

export default function SiteAdminPage() {
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
    <div className="p-6 max-w-7xl mx-auto">
      <div className="mb-6 ss-animate-in">
        <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Site Admin</h1>
        <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
          RFIs, Site Instructions, Site Diaries, Meeting Minutes and EOTs across every project you can access
        </p>
      </div>

      {/* Module tabs */}
      <div className="flex gap-1 p-1 rounded-lg mb-4 overflow-x-auto" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {MODULES.map(m => (
          <button key={m.id} onClick={() => setActiveModule(m.id)}
            className={`flex items-center gap-1.5 px-3 py-2 rounded-md text-xs font-medium transition-all duration-200 ${EASE} active:scale-[0.97] whitespace-nowrap`}
            style={activeModule === m.id
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <m.icon size={13} />
            {m.label}
          </button>
        ))}
      </div>

      {/* Summary */}
      {summary && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
          {Object.entries(summary).map(([key, value], i) => (
            <div
              key={key}
              className="ss-animate-in rounded-xl p-3 transition-shadow duration-200 hover:shadow-[var(--shadow-card)]"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: staggerDelay(i) }}
            >
              <p className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{key.replace(/_/g, ' ')}</p>
              <p className="text-lg font-bold mt-0.5 tabular-nums" style={{ color: key === 'total' ? 'var(--gold)' : 'var(--text-primary)' }}>{value}</p>
            </div>
          ))}
        </div>
      )}

      {/* Search */}
      <div className="relative mb-5">
        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder={`Search ${currentModule.label.toLowerCase()}...`}
          className="w-full pl-9 pr-4 py-2.5 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {/* Content */}
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
                className={`ss-animate-in flex items-center gap-4 p-4 rounded-xl border cursor-pointer transition-all duration-200 ${EASE} hover:border-[var(--gold)] hover:-translate-y-0.5 hover:shadow-[var(--shadow-card)]`}
                style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', animationDelay: staggerDelay(i) }}
              >
                <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                     style={{ backgroundColor: 'var(--gold-15)' }}>
                  <Icon size={18} style={{ color: 'var(--gold)' }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-3">
                    <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                      {item.title || item.reference}
                    </p>
                    <span className="text-xs px-2 py-0.5 rounded-full flex-shrink-0"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                      {item.status.replace(/_/g, ' ')}
                    </span>
                  </div>
                  <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>
                    {item.project_name}{item.secondary ? ` · ${item.secondary}` : ''}
                  </p>
                </div>
                {item.date && (
                  <span className="text-xs flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
                    {formatDate(item.date)}
                  </span>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
