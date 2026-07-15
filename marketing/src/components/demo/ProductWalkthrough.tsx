'use client';

import { useState } from 'react';
import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { PaymentAppTable, AiAnalysisReview, TradePackageTree, StatutoryChainScreen } from '@/components/shared/placeholders';

const STEPS = [
  { label: 'Review Findings', detail: 'Confirm the parties, dates, and payment rules SureSign extracted.', screen: AiAnalysisReview },
  { label: 'Create Trade Package', detail: 'Generate a trade package with its standard folders in one action.', screen: TradePackageTree },
  { label: 'Manage Commercial Workflow', detail: 'Raise a payment application against the confirmed contract data.', screen: PaymentAppTable },
  { label: 'Track Deadlines', detail: 'Statutory dates run in the background, calculated, not chased.', screen: StatutoryChainScreen },
];

export function ProductWalkthrough() {
  const [active, setActive] = useState(0);
  const ActiveScreen = STEPS[active].screen;

  return (
    <section className="tone-surface border-b border-border py-28 md:py-36">
      <Container>
        <div className="max-w-[56ch]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            Explore the platform.
          </h2>
          <p className="mt-4 text-text-secondary">
            The same journey from &quot;How SureSign Works&quot; — this time, at your own pace.
          </p>
        </div>

        {/* Progress — which step of the journey this is, not just a tab list. */}
        <div className="mt-10 h-1 w-full overflow-hidden rounded-full bg-border">
          <div
            className="h-full rounded-full bg-accent transition-[width] duration-500 ease-out"
            style={{ width: `${((active + 1) / STEPS.length) * 100}%` }}
          />
        </div>

        <div className="mt-8 grid gap-10 md:grid-cols-[0.7fr_1.3fr] md:gap-16">
          <div
            role="tablist"
            aria-label="Product walkthrough steps"
            className="flex gap-2 overflow-x-auto md:flex-col md:gap-1 md:overflow-visible"
          >
            {STEPS.map((step, i) => (
              <button
                key={step.label}
                role="tab"
                aria-selected={active === i}
                onClick={() => setActive(i)}
                className={`shrink-0 rounded-xl border px-4 py-3 text-left text-sm transition-colors md:shrink ${
                  active === i
                    ? 'border-border-light bg-bg-surface font-medium text-text-primary'
                    : 'border-transparent text-text-secondary hover:text-text-primary'
                }`}
              >
                <div className="flex items-center gap-2">
                  <span className="font-mono text-xs text-text-muted">{String(i + 1).padStart(2, '0')}</span>
                  <span>{step.label}</span>
                </div>
                {active === i && <div className="mt-1.5 pl-6 text-xs font-normal text-text-muted">{step.detail}</div>}
              </button>
            ))}
          </div>

          <div key={active} className="[animation:fade-slide-in_400ms_ease-out]">
            <MockupFrame elevated>
              <ActiveScreen />
            </MockupFrame>
          </div>
        </div>
      </Container>
    </section>
  );
}
