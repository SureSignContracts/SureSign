'use client';

import { useFeatureAvailability } from '@/hooks/useFeatureAvailability';

/**
 * A compact, restrained navigation-item status indicator — thin wrapper
 * around the same visual language as `components/ui/Badge.tsx`'s tone
 * system, sized for a sidebar row rather than a settings table. Renders
 * nothing for Active (the common case) so navigation stays uncluttered.
 * Never hides or disables the nav link itself — that decision belongs
 * entirely to the page behind it (FeatureAvailabilityGate), not to this
 * badge.
 */
export default function FeatureStatusBadge({ featureKey }: { featureKey: string }) {
  const { statusFor } = useFeatureAvailability();
  const status = statusFor(featureKey);

  if (status === 'active') return null;

  const isMaintenance = status === 'maintenance';

  return (
    <span
      className="flex-shrink-0 px-1.5 py-0.5 rounded-md text-[10px] font-medium leading-none whitespace-nowrap"
      style={{
        backgroundColor: isMaintenance ? 'rgba(234,179,8,0.15)' : 'rgba(59,130,246,0.15)',
        color: isMaintenance ? '#eab308' : '#60a5fa',
      }}
    >
      {isMaintenance ? 'Maintenance' : 'Coming soon'}
    </span>
  );
}
