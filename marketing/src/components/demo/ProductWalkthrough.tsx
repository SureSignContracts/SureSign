'use client';

import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { Container } from '@/components/shared/Container';
import { MockupFrame } from '@/components/shared/MockupFrame';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';
import {
  PaymentAppTable,
  AiAnalysisReview,
  ContractExtractionPreview,
  ContractUploadPreview,
  TradePackageTree,
  DocumentsExplorer,
} from '@/components/shared/placeholders';

const STEPS = [
  { label: 'Upload the contract', detail: 'Start with the executed contract and its project context.', screen: ContractUploadPreview },
  { label: 'Extract the rules', detail: 'Identify dates, obligations, payment rules and programme information.', screen: ContractExtractionPreview },
  { label: 'Review and confirm', detail: 'A person checks and corrects the extraction before it is used.', screen: AiAnalysisReview },
  { label: 'Populate workflows', detail: 'Confirmed information becomes usable project and trade package data.', screen: TradePackageTree },
  { label: 'Control obligations', detail: 'Manage applications, notices and deadlines against confirmed rules.', screen: PaymentAppTable },
  { label: 'Keep the record', detail: 'Documents, decisions and events remain connected to the project history.', screen: DocumentsExplorer },
];

export function ProductWalkthrough() {
  const [active, setActive] = useState(0);
  const sectionRef = useRef<HTMLElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();
  const ActiveScreen = STEPS[active].screen;

  useEffect(() => {
    if (reduced || !sectionRef.current) return;

    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      gsap.fromTo(
        '[data-workflow-step]',
        { autoAlpha: 0.35, x: -10 },
        {
          autoAlpha: 1,
          x: 0,
          duration: 0.55,
          stagger: 0.07,
          ease: 'power2.out',
          scrollTrigger: {
            trigger: sectionRef.current,
            start: 'top 70%',
            once: true,
          },
        },
      );
    }, sectionRef);

    return () => ctx.revert();
  }, [reduced]);

  useEffect(() => {
    if (reduced || !panelRef.current) return;

    const { gsap } = getGsap();
    const tween = gsap.fromTo(
      panelRef.current,
      { autoAlpha: 0, y: 10, scale: 0.992 },
      { autoAlpha: 1, y: 0, scale: 1, duration: 0.42, ease: 'power2.out' },
    );
    return () => {
      tween.kill();
    };
  }, [active, reduced]);

  function handleTabKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();

    const next = event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? STEPS.length - 1
        : event.key === 'ArrowLeft' || event.key === 'ArrowUp'
          ? (active - 1 + STEPS.length) % STEPS.length
          : (active + 1) % STEPS.length;

    setActive(next);
    requestAnimationFrame(() => document.getElementById(`workflow-tab-${next}`)?.focus());
  }

  return (
    <section ref={sectionRef} id="how-it-works" className="tone-surface border-b border-border py-24 md:py-32">
      <Container>
        <div className="max-w-[56ch]">
          <p className="text-sm font-medium text-text-muted">One connected workflow</p>
          <h2 className="mt-3 text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
            The contract becomes operational data, with a person in control.
          </h2>
          <p className="mt-4 max-w-[54ch] text-text-secondary">
            Not eight disconnected tools. One traceable sequence from the uploaded
            contract to the records your team administers throughout the project.
          </p>
        </div>

        {/* Progress — which step of the journey this is, not just a tab list. */}
        <div className="mt-10 h-px w-full overflow-hidden bg-border">
          <div
            className="h-full origin-left bg-accent transition-transform duration-500 ease-out"
            style={{ transform: `scaleX(${(active + 1) / STEPS.length})` }}
          />
        </div>

        <div className="mt-8 grid gap-10 md:grid-cols-[0.75fr_1.25fr] md:gap-16">
          <div
            role="tablist"
            aria-label="Product walkthrough steps"
            onKeyDown={handleTabKeyDown}
            className="flex snap-x snap-mandatory gap-2 overflow-x-auto pb-2 md:flex-col md:gap-1 md:overflow-visible md:pb-0"
          >
            {STEPS.map((step, i) => (
              <button
                key={step.label}
                data-workflow-step
                role="tab"
                aria-selected={active === i}
                aria-controls="workflow-panel"
                id={`workflow-tab-${i}`}
                tabIndex={active === i ? 0 : -1}
                onClick={() => setActive(i)}
                className={`min-h-12 w-[calc(100vw-3rem)] max-w-full shrink-0 snap-start rounded-xl border px-4 py-3 text-left text-sm transition-[background-color,border-color,color,transform] duration-200 md:w-auto md:shrink ${
                  active === i
                    ? 'border-border-light bg-bg-base font-medium text-text-primary shadow-[var(--shadow-card)]'
                    : 'border-transparent text-text-secondary hover:translate-x-0.5 hover:text-text-primary'
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

          <div
            ref={panelRef}
            id="workflow-panel"
            role="tabpanel"
            aria-labelledby={`workflow-tab-${active}`}
            tabIndex={0}
          >
            <MockupFrame elevated>
              <ActiveScreen />
            </MockupFrame>
          </div>
        </div>
      </Container>
    </section>
  );
}
