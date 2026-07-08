'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { HardHat, MessageSquare, BookOpen, ClipboardList, Clock, Plus, Search } from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';

type Tab = 'rfis' | 'site-instructions' | 'site-diaries' | 'meeting-minutes' | 'eots';

const tabs: { id: Tab; label: string; icon: any; endpoint: string }[] = [
  { id: 'rfis',              label: 'RFIs',              icon: MessageSquare, endpoint: '/rfis' },
  { id: 'site-instructions', label: 'Site Instructions', icon: BookOpen,      endpoint: '/site-instructions' },
  { id: 'site-diaries',      label: 'Site Diaries',      icon: ClipboardList, endpoint: '/site-diaries' },
  { id: 'meeting-minutes',   label: 'Meeting Minutes',   icon: HardHat,       endpoint: '/meeting-minutes' },
  { id: 'eots',              label: 'EOTs',              icon: Clock,         endpoint: '/eot-requests' },
];

const rfiStatusColor: Record<string, { bg: string; text: string }> = {
  open:     { bg: 'rgba(234,179,8,0.15)',  text: '#facc15' },
  answered: { bg: 'rgba(34,197,94,0.15)', text: '#4ade80' },
  closed:   { bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  draft:    { bg: 'rgba(90,86,82,0.3)',    text: '#9a9490' },
};

export default function SiteAdminPage() {
  const [activeTab, setActiveTab] = useState<Tab>('rfis');
  const [search, setSearch] = useState('');

  const currentTab = tabs.find(t => t.id === activeTab)!;

  const { data, isLoading } = useQuery({
    queryKey: ['site', activeTab],
    queryFn: () => api.get(currentTab.endpoint).then(r => r.data),
  });

  const items = (data?.data ?? []).filter((item: any) => {
    const term = search.toLowerCase();
    return (
      item.rfi_number?.toLowerCase().includes(term) ||
      item.subject?.toLowerCase().includes(term) ||
      item.title?.toLowerCase().includes(term) ||
      item.description?.toLowerCase().includes(term)
    );
  });

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Site Admin</h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>RFIs, instructions, diaries, minutes and EOTs</p>
        </div>
        <button
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={16} />
          New {currentTab.label.replace(/s$/, '')}
        </button>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 p-1 rounded-lg mb-4 overflow-x-auto" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {tabs.map(t => (
          <button key={t.id} onClick={() => setActiveTab(t.id)}
            className="flex items-center gap-1.5 px-3 py-2 rounded-md text-xs font-medium transition-all whitespace-nowrap"
            style={activeTab === t.id
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <t.icon size={13} />
            {t.label}
          </button>
        ))}
      </div>

      {/* Search */}
      <div className="relative mb-5">
        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder={`Search ${currentTab.label.toLowerCase()}...`}
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
      ) : items.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20">
          <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
               style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <currentTab.icon size={24} style={{ color: 'var(--text-muted)' }} />
          </div>
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>No {currentTab.label.toLowerCase()}</p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {currentTab.label} linked to projects will appear here
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {items.map((item: any) => {
            const s = rfiStatusColor[item.status] || rfiStatusColor.draft;
            const title = item.subject || item.title || item.rfi_number || `#${item.id}`;
            const Icon = currentTab.icon;
            return (
              <div key={item.id}
                className="flex items-center gap-4 p-4 rounded-xl border cursor-pointer hover:border-[var(--gold)] transition-colors"
                style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)' }}
              >
                <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
                     style={{ backgroundColor: 'var(--gold-15)' }}>
                  <Icon size={18} style={{ color: 'var(--gold)' }} />
                </div>
                <div className="flex-1 min-w-0">
                  <div className="flex items-center gap-3">
                    <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{title}</p>
                    {item.status && (
                      <span className="text-xs px-2 py-0.5 rounded-full flex-shrink-0"
                            style={{ backgroundColor: s.bg, color: s.text }}>
                        {item.status}
                      </span>
                    )}
                  </div>
                  {item.description && (
                    <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{item.description}</p>
                  )}
                </div>
                {item.created_at && (
                  <span className="text-xs flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
                    {formatDate(item.created_at)}
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
