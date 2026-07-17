'use client';

import { useQuery } from '@tanstack/react-query';
import { Activity } from 'lucide-react';
import api from '@/lib/api';
import { STATUS_LABELS, STATUS_COLORS, SystemComponentStatus } from '@/lib/systemStatus';

interface StatusComponent {
  name: string;
  status: SystemComponentStatus;
}

interface StatusResponse {
  checked_at: string;
  components: StatusComponent[];
}

export function SystemStatusSection() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['system-status'],
    queryFn: () => api.get('/system-status').then(r => r.data as StatusResponse),
    staleTime: 60 * 1000,
  });

  return (
    <div id="system-status" className="rounded-2xl overflow-hidden scroll-mt-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div className="px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
        <h2 className="text-sm font-semibold flex items-center gap-1.5" style={{ color: 'var(--text-primary)' }}>
          <Activity size={14} />
          System Status
        </h2>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Current status of SureSign platform components.</p>
      </div>
      <div className="p-5">
        {isError ? (
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Status could not be loaded right now.</p>
        ) : isLoading || !data ? (
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
            {[...Array(6)].map((_, i) => (
              <div key={i} className="h-12 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ))}
          </div>
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
            {data.components.map(c => {
              const colors = STATUS_COLORS[c.status];
              return (
                <div
                  key={c.name}
                  className="rounded-xl p-3"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
                >
                  <p className="text-xs font-medium truncate" style={{ color: 'var(--text-primary)' }}>{c.name}</p>
                  <div className="flex items-center gap-1.5 mt-1.5">
                    <span className="w-1.5 h-1.5 rounded-full flex-shrink-0" style={{ backgroundColor: colors.dot }} />
                    <span className="text-xs" style={{ color: colors.text }}>{STATUS_LABELS[c.status]}</span>
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
}
