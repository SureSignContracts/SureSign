'use client';

import Link from 'next/link';
import {
  ArrowRight,
  CheckCircle2,
  Clock3,
  LayoutDashboard,
  Sparkles,
  Wrench,
} from 'lucide-react';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';

/**
 * Shared customer-facing state for modules that are under maintenance or
 * not yet released. Dates and custom messages only render when supplied by
 * the availability API, so this surface never invents release information.
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
  const heading = isMaintenance
    ? `${title} is offline for a short interval.`
    : `${title} is being prepared for your workspace.`;
  const defaultMessage = isMaintenance
    ? `We are carrying out maintenance on ${title}. Your existing project information remains in place.`
    : 'This workspace is not available yet. It will appear here when it is ready.';

  return (
    <section
      className="ss-feature-state flex min-h-[calc(100dvh-8rem)] items-center px-4 py-8 sm:px-6 lg:px-10"
      role="status"
      aria-live="polite"
    >
      <div className="mx-auto w-full max-w-[1320px] overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_28px_80px_rgba(23,43,34,0.18)]">
        <div className="grid min-h-[520px] lg:grid-cols-[1.35fr_0.65fr]">
          <div className="ss-feature-state-copy relative flex flex-col justify-between overflow-hidden p-7 sm:p-10 lg:p-14">
            <div className="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full border border-[#9ee5b5]/10" aria-hidden="true" />
            <div className="pointer-events-none absolute -right-8 -top-8 h-40 w-40 rounded-full border border-[#9ee5b5]/10" aria-hidden="true" />

            <div className="relative">
              <div className="mb-10 flex items-center justify-between gap-4">
                <div className="flex items-center gap-3">
                  <span className="ss-feature-state-icon flex h-12 w-12 items-center justify-center rounded-xl bg-[#9ee5b5] text-[#18211d]" aria-hidden="true">
                    <Icon size={22} strokeWidth={1.8} />
                  </span>
                  <span className="text-xs font-semibold uppercase tracking-[0.16em] text-[#9ee5b5]">
                    {isMaintenance ? 'Workspace maintenance' : 'Product release'}
                  </span>
                </div>
                <span className="hidden text-xs text-white/45 sm:block">SureSign workspace</span>
              </div>

              <h1 className="max-w-[720px] text-4xl font-semibold leading-[1.04] tracking-[-0.045em] sm:text-5xl lg:text-[58px]">
                {heading}
              </h1>
              <p className="mt-6 max-w-[640px] text-base leading-7 text-white/62 sm:text-lg">
                {message || defaultMessage}
              </p>
            </div>

            <div className="relative mt-12 flex flex-col gap-5 border-t border-white/10 pt-7 sm:flex-row sm:items-center sm:justify-between">
              <p className="max-w-md text-sm leading-6 text-white/48">
                {isMaintenance
                  ? 'You can continue working in the rest of SureSign while this module is serviced.'
                  : 'Your other SureSign tools remain available while this workspace is prepared.'}
              </p>
              <Link
                href={backHref}
                className="group inline-flex shrink-0 items-center justify-center gap-3 self-start rounded-xl bg-[#9ee5b5] px-5 py-3 text-sm font-semibold text-[#14201a] transition duration-200 hover:bg-[#b1edc4] active:scale-[0.98] sm:self-auto"
              >
                {backLabel}
                <ArrowRight size={16} className="transition-transform duration-200 group-hover:translate-x-0.5" aria-hidden="true" />
              </Link>
            </div>
          </div>

          <aside className="ss-feature-state-details border-t border-white/10 bg-white/[0.035] p-7 sm:p-10 lg:border-l lg:border-t-0 lg:p-12">
            <div className="flex h-full flex-col">
              <div className="flex items-center justify-between gap-4">
                <p className="text-sm font-semibold text-white">Current status</p>
                <span className="inline-flex items-center gap-2 text-xs font-medium text-[#9ee5b5]">
                  <span className="ss-feature-state-signal h-2 w-2 rounded-full bg-[#9ee5b5]" aria-hidden="true" />
                  {isMaintenance ? 'Maintenance in progress' : 'Coming soon'}
                </span>
              </div>

              <div className="mt-10 space-y-8">
                <StatusDetail
                  icon={isMaintenance ? CheckCircle2 : LayoutDashboard}
                  label={isMaintenance ? 'Existing records' : 'Current workspace'}
                  value={isMaintenance ? 'Remain in place' : 'Available as normal'}
                />
                <StatusDetail
                  icon={Clock3}
                  label={isMaintenance ? 'Expected return' : 'Availability'}
                  value={
                    availableAt
                      ? formatDateTime(availableAt, { timeZone })
                      : isMaintenance
                        ? 'Access returns when service work is complete'
                        : 'Release date to be confirmed'
                  }
                />
              </div>

              <div className="mt-auto border-t border-white/10 pt-7">
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-white/36">What to do now</p>
                <p className="mt-3 text-sm leading-6 text-white/62">
                  {isMaintenance
                    ? 'Return to your dashboard and continue with another part of the project.'
                    : 'Return to your dashboard. This link will remain in navigation for when the workspace opens.'}
                </p>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </section>
  );
}

function StatusDetail({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof Clock3;
  label: string;
  value: string;
}) {
  return (
    <div className="flex gap-4">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-white/10 bg-white/[0.04] text-[#9ee5b5]" aria-hidden="true">
        <Icon size={18} strokeWidth={1.7} />
      </span>
      <div className="min-w-0 pt-0.5">
        <p className="text-xs text-white/40">{label}</p>
        <p className="mt-1 text-sm font-medium leading-5 text-white/82">{value}</p>
      </div>
    </div>
  );
}
