import Link from 'next/link';
import { ArrowUpRight, Crown, FileBarChart, LockKeyhole } from 'lucide-react';
import Button from '@/components/ui/Button';

const UPGRADE_HREF = '/app/settings/billing/subscription#plans';

/**
 * Friday Pack Plan Entitlement Enforcement — Entitlement UX phase.
 * Communicates the commercial entitlement state up front, on page load,
 * rather than letting a customer discover it from a failed mutation.
 * Deliberately informational/premium in tone (mirrors `TrialCard`'s own
 * `--gold` accent + Card treatment) — never an alarming red error state.
 *
 * Never mentions FeatureGate, entitlement keys, Super Admin overrides, or
 * any other backend architecture — British English, concise, customer-safe
 * copy only.
 */
export function FridayPackUpgradeBanner() {
  return (
    <section
      className="relative overflow-hidden rounded-2xl"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
      aria-labelledby="friday-pack-upgrade-title"
    >
      <div className="flex flex-col gap-5 p-5 sm:flex-row sm:items-center sm:gap-6 sm:p-6">
        <div aria-hidden="true" className="relative hidden h-24 w-24 flex-none items-center justify-center sm:flex">
          <div className="absolute h-[78px] w-[60px] translate-x-2 rotate-[10deg] rounded-lg border" style={{ backgroundColor: 'var(--bg-elevated)', borderColor: 'var(--border)' }} />
          <div className="relative flex h-[82px] w-[62px] -rotate-[6deg] flex-col justify-between rounded-lg border p-2.5 shadow-sm" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)' }}>
            <FileBarChart size={19} strokeWidth={1.5} style={{ color: 'var(--text-secondary)' }} />
            <div className="space-y-1.5">
              <div className="h-1 w-full rounded-full" style={{ backgroundColor: 'var(--border)' }} />
              <div className="h-1 w-2/3 rounded-full" style={{ backgroundColor: 'var(--border)' }} />
            </div>
          </div>
          <div className="absolute bottom-0 right-0 flex h-8 w-8 items-center justify-center rounded-full border-[3px]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', borderColor: 'var(--bg-surface)' }}>
            <LockKeyhole size={13} strokeWidth={2} />
          </div>
        </div>
        <div className="min-w-0 flex-1">
          <span className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            <LockKeyhole size={11} aria-hidden="true" /> Professional &amp; Enterprise
          </span>
          <h2 id="friday-pack-upgrade-title" className="mt-2.5 text-lg font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>
            Bring the whole week together.
          </h2>
          <p className="mt-1 max-w-xl text-sm leading-6" style={{ color: 'var(--text-secondary)' }}>
            Creating and issuing Friday Packs isn&rsquo;t included in your plan.
          </p>
        </div>
        <Link
          href={UPGRADE_HREF}
          className="group inline-flex h-10 flex-none items-center justify-center gap-3 self-start whitespace-nowrap rounded-lg px-4 text-sm font-semibold transition-opacity hover:opacity-85 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 sm:self-auto"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          View plans
          <ArrowUpRight size={15} aria-hidden="true" className="motion-safe:transition-transform motion-safe:duration-200 motion-safe:group-hover:-translate-y-0.5 motion-safe:group-hover:translate-x-0.5" />
        </Link>
      </div>
      <div className="flex items-center gap-2 border-t px-5 py-2.5 text-xs sm:px-6" style={{ borderColor: 'var(--border)', color: 'var(--text-muted)', backgroundColor: 'var(--bg-elevated)' }}>
        <FileBarChart size={13} className="flex-none" aria-hidden="true" />
        Your existing packs and documents are still available to view and download.
      </div>
    </section>
  );
}

/**
 * Entitlement-aware replacement for the normal "Generate the first Friday
 * Pack" empty state — shown only when the organisation both lacks the
 * entitlement AND has zero historical Friday Packs, so generation is
 * never implied as available.
 */
export function FridayPackUpgradeEmptyState() {
  return (
    <div className="ss-animate-in grid min-h-[270px] overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] shadow-[var(--shadow-card)] md:grid-cols-[0.8fr_1.2fr]">
      <div className="flex items-center justify-center bg-[var(--bg-elevated)] p-8">
        <div className="flex h-24 w-24 items-center justify-center rounded-2xl border border-[var(--border)] bg-[var(--bg-surface)] text-[var(--gold)] shadow-[var(--shadow-card)]">
          <FileBarChart size={38} strokeWidth={1.5} />
        </div>
      </div>
      <div className="flex flex-col items-start justify-center p-8 sm:p-10">
        <div className="flex items-center gap-2">
          <Crown size={16} style={{ color: 'var(--gold)' }} aria-hidden />
          <span className="text-xs font-medium uppercase tracking-wide" style={{ color: 'var(--gold)' }}>Professional &amp; Enterprise</span>
        </div>
        <h2 className="mt-2 text-xl font-semibold tracking-[-0.02em]" style={{ color: 'var(--text-primary)' }}>
          Weekly Friday Packs are available with Professional and Enterprise
        </h2>
        <p className="mt-2 max-w-md text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
          Bring project records, workforce, site photographs, H&amp;S information and weekly reporting together in one structured report.
        </p>
        <Link href={UPGRADE_HREF} className="mt-5">
          <Button size="sm">Upgrade plan</Button>
        </Link>
      </div>
    </div>
  );
}
