'use client';

import Link from 'next/link';
import { Wrench, Sparkles } from 'lucide-react';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';

/**
 * The shared customer-facing Maintenance/Coming Soon state — built entirely
 * from existing SureSign visual primitives (no new design system). Used by
 * FeatureAvailabilityGate; not normally rendered directly by a page.
 *
 * Deliberately restrained: no fake progress bars/percentages, no invented
 * release dates, no unsupported product promises. `available_at` renders
 * only when present (never an empty date area); a custom `message` renders
 * only when present, otherwise restrained default copy is used. Maintenance
 * copy never globally claims "your data is safe" beyond the one truthful,
 * general statement that existing records have not been removed — this
 * component doesn't claim anything more specific than that.
 */
export default function FeatureUnavailableState({
  title,
  variant,
  message,
  availableAt,
  backHref,
  backLabel,
}: {
  title: string;
  variant: 'maintenance' | 'coming_soon';
  message?: string | null;
  availableAt?: string | null;
  backHref: string;
  backLabel: string;
}) {
  const timeZone = useAuthStore.getState().user?.effective_timezone;
  const isMaintenance = variant === 'maintenance';
  const Icon = isMaintenance ? Wrench : Sparkles;

  return (
    <div className="flex flex-col items-center justify-center text-center px-6 py-16 gap-4" role="status">
      <div
        className="w-14 h-14 rounded-2xl flex items-center justify-center"
        style={{ backgroundColor: isMaintenance ? 'rgba(234,179,8,0.12)' : 'rgba(59,130,246,0.12)' }}
        aria-hidden="true"
      >
        <Icon size={26} style={{ color: isMaintenance ? '#eab308' : '#60a5fa' }} />
      </div>

      <div className="space-y-1.5">
        <span
          className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
          style={{
            backgroundColor: isMaintenance ? 'rgba(234,179,8,0.12)' : 'rgba(59,130,246,0.12)',
            color: isMaintenance ? '#eab308' : '#60a5fa',
          }}
        >
          {isMaintenance ? 'Maintenance' : 'Coming soon'}
        </span>
        <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>
          {isMaintenance ? `${title} is temporarily unavailable` : `${title} — Coming soon`}
        </h1>
      </div>

      <div className="max-w-md space-y-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
        <p>
          {isMaintenance
            ? `We're carrying out maintenance on the ${title} workspace. Your existing project information has not been removed.`
            : "We're preparing this workspace for SureSign."}
        </p>
        {message && <p style={{ color: 'var(--text-muted)' }}>{message}</p>}
      </div>

      {isMaintenance && availableAt && (
        <div className="text-xs" style={{ color: 'var(--text-muted)' }}>
          <p className="font-medium" style={{ color: 'var(--text-secondary)' }}>Expected back</p>
          <p>{formatDateTime(availableAt, { timeZone })}</p>
        </div>
      )}

      <Link
        href={backHref}
        className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition-colors"
        style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)', border: '1px solid var(--border)' }}
      >
        {backLabel}
      </Link>
    </div>
  );
}
