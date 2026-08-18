'use client';

import { Sparkles, Wrench } from 'lucide-react';
import { useFeatureAvailability } from '@/hooks/useFeatureAvailability';

/** A compact navigation status marker that preserves the parent link's tone. */
export default function FeatureStatusBadge({ featureKey }: { featureKey: string }) {
  const { statusFor } = useFeatureAvailability();
  const status = statusFor(featureKey);

  if (status === 'active') return null;

  const isMaintenance = status === 'maintenance';
  const Icon = isMaintenance ? Wrench : Sparkles;
  const label = isMaintenance ? 'Maintenance' : 'Coming soon';

  return (
    <span
      className="ss-feature-badge inline-flex items-center gap-1 whitespace-nowrap text-[8px] font-semibold uppercase leading-none tracking-[0.1em] text-current opacity-55 transition-opacity duration-150 group-hover:opacity-85"
      aria-label={label}
      title={label}
    >
      <Icon size={9} strokeWidth={1.8} aria-hidden="true" />
      <span aria-hidden="true">{label}</span>
    </span>
  );
}
