'use client';

import { AlertTriangle } from 'lucide-react';
import { useFeatureAvailability } from '@/hooks/useFeatureAvailability';
import { useAuthStore } from '@/store/authStore';
import { formatDateTime } from '@/lib/dateTime';
import FeatureUnavailableState from './FeatureUnavailableState';

/**
 * Wraps a customer-facing page/module and decides whether to render its
 * normal content, a Maintenance/Coming Soon state, or normal content plus an
 * internal bypass warning. This is the ONE place that decision is made —
 * pages should never duplicate `features[key]?.status === ...` logic
 * themselves.
 *
 * Bypass (Super Admin/Admin always see normal content) is DISPLAY behaviour
 * only, read from the same authoritative frontend role state every other
 * presentation-only role check in this codebase already uses
 * (`user.roles`, populated from the backend's own Spatie roles — never
 * inferred from a client-only flag). It grants no management permission —
 * only the Super-Admin-only backend admin API can ever change availability;
 * this component cannot and does not imply otherwise.
 */
export default function FeatureAvailabilityGate({
  featureKey,
  title,
  backHref,
  backLabel = 'Back',
  children,
}: {
  featureKey: string;
  title: string;
  backHref: string;
  backLabel?: string;
  children: React.ReactNode;
}) {
  const { entryFor } = useFeatureAvailability();
  const user = useAuthStore(s => s.user);
  const isInternal = user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin');

  const entry = entryFor(featureKey);

  if (entry.status === 'active') {
    return <>{children}</>;
  }

  if (!isInternal) {
    return (
      <FeatureUnavailableState
        title={title}
        variant={entry.status}
        message={entry.message}
        availableAt={entry.available_at}
        backHref={backHref}
        backLabel={backLabel}
      />
    );
  }

  const timeZone = user?.effective_timezone;
  const isMaintenance = entry.status === 'maintenance';

  return (
    <div className="space-y-4">
      <div
        className="rounded-2xl p-4 flex items-start gap-3"
        style={{
          border: '1px solid',
          borderColor: isMaintenance ? 'rgba(234,179,8,0.25)' : 'rgba(59,130,246,0.25)',
          backgroundColor: isMaintenance ? 'rgba(234,179,8,0.08)' : 'rgba(59,130,246,0.08)',
        }}
        role="status"
      >
        <AlertTriangle size={18} className="flex-shrink-0 mt-0.5" style={{ color: isMaintenance ? '#facc15' : '#60a5fa' }} />
        <div className="space-y-1">
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>
            {isMaintenance
              ? 'Maintenance mode is currently active for customer users.'
              : 'This feature is currently marked Coming Soon for customer users.'}
          </p>
          {entry.available_at && (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Expected availability: {formatDateTime(entry.available_at, { timeZone })}
            </p>
          )}
        </div>
      </div>
      {children}
    </div>
  );
}
