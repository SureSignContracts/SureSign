'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { LifeBuoy, Search, Clock, CheckCircle2, AlertCircle, MessageSquare } from 'lucide-react';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  open:        { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  in_progress: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  resolved:    { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed:      { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

export default function AdminSupportPage() {
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['admin-support'],
    queryFn: () => api.get('/admin/support').then(r => r.data).catch(() => ({ data: [] })),
  });

  const tickets = (data?.data ?? []).filter((t: any) =>
    t.subject?.toLowerCase().includes(search.toLowerCase()) ||
    t.company?.name?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Support</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Support tickets across all tenant companies</p>
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-4 gap-4">
        {[
          { label: 'Open',        value: data?.counts?.open ?? 0,        icon: AlertCircle, color: '#facc15' },
          { label: 'In Progress', value: data?.counts?.in_progress ?? 0, icon: Clock,       color: '#60a5fa' },
          { label: 'Resolved',    value: data?.counts?.resolved ?? 0,    icon: CheckCircle2,color: '#4ade80' },
          { label: 'Total',       value: data?.counts?.total ?? 0,       icon: MessageSquare,color: 'var(--gold)' },
        ].map(stat => (
          <div
            key={stat.label}
            className="rounded-xl p-4 flex items-center gap-3"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
          >
            <div className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ backgroundColor: stat.color + '18' }}>
              <stat.icon size={16} style={{ color: stat.color }} />
            </div>
            <div>
              <p className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>{isLoading ? '–' : stat.value}</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{stat.label}</p>
            </div>
          </div>
        ))}
      </div>

      {/* Search */}
      <div className="relative max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search tickets…"
          className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {/* Tickets table */}
      <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full min-w-[640px] text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['#', 'Subject', 'Company', 'Status', 'Created'].map(h => (
                <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(5)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(5)].map((_, j) => (
                    <td key={j} className="px-5 py-4">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : tickets.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-12 text-center">
                  <LifeBuoy size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No support tickets</p>
                </td>
              </tr>
            ) : tickets.map((t: any) => {
              const badge = STATUS_COLORS[t.status] || STATUS_COLORS.closed;
              return (
                <tr key={t.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-5 py-3 font-mono text-xs" style={{ color: 'var(--text-muted)' }}>#{t.id}</td>
                  <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{t.subject}</td>
                  <td className="px-5 py-3" style={{ color: 'var(--text-secondary)' }}>{t.company?.name ?? '–'}</td>
                  <td className="px-5 py-3">
                    <span className="px-2 py-0.5 rounded-full text-xs font-medium capitalize"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                      {t.status?.replace(/_/g, ' ')}
                    </span>
                  </td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{t.created_at ?? '–'}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
