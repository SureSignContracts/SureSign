'use client';

import { useEffect, useRef } from 'react';
import Link from 'next/link';
import {
  ArrowRight,
  ArrowUpRight,
  Check,
  FileCheck2,
  FileText,
  FolderOpen,
} from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const ADJUDICATION_URL = 'https://www.adjudicationservices.co.uk/';

const SURESIGN_CAPABILITIES = [
  'AI contract intelligence',
  'Payment workflows',
  'Notices and correspondence',
  'Variations and project records',
  'Programme and delay management',
  'Organised contractual evidence',
];

const ADJUDICATION_CAPABILITIES = [
  'Construction payment disputes',
  'Unpaid sums',
  'Valuation disagreements',
  'Contract entitlement disputes',
  'Adjudication preparation',
  'Case and evidence organisation',
];

const DISPUTE_SIGNALS = [
  {
    title: 'An application remains unpaid',
    detail: 'Outstanding payment may require specialist review when ordinary commercial discussions have not resolved it.',
  },
  {
    title: 'A notice is disputed',
    detail: 'A disputed payment notice or pay less notice can create a time-sensitive contractual issue.',
  },
  {
    title: 'Retention remains outstanding',
    detail: 'Unreleased retention may be relevant where the contractual release conditions appear to have been met.',
  },
  {
    title: 'A valuation is contested',
    detail: 'Differences over measured work, certification, or valuation may develop into a formal dispute.',
  },
  {
    title: 'Entitlement is unclear',
    detail: 'Parties may need specialist support when they disagree about rights or obligations under the contract.',
  },
  {
    title: 'Time issues remain unresolved',
    detail: 'Variations, delay, or extensions of time could require specialist review if agreement cannot be reached.',
  },
  {
    title: 'Negotiations have stalled',
    detail: 'Adjudication may be appropriate when direct discussions no longer offer a practical route forward.',
  },
  {
    title: 'A formal response is required',
    detail: 'A specialist can help assess the next steps where a dispute process is already under way.',
  },
];

const READINESS_STEPS = [
  {
    title: 'Contract administration',
    detail: 'The contract, obligations, dates, and commercial rules are understood and recorded.',
  },
  {
    title: 'Notices and correspondence',
    detail: 'Communications are issued, stored, and kept connected to the matter they concern.',
  },
  {
    title: 'Payment and variation records',
    detail: 'Applications, notices, valuations, changes, and supporting records remain traceable.',
  },
  {
    title: 'Organised project evidence',
    detail: 'A clear document history helps the disputed issues become easier to understand.',
  },
  {
    title: 'Specialist adjudication support',
    detail: 'Adjudication Services provides a separate specialist route when the issue has formally escalated.',
  },
];

function ExternalLink({
  children,
  className,
}: {
  children: React.ReactNode;
  className: string;
}) {
  return (
    <a
      href={ADJUDICATION_URL}
      target="_blank"
      rel="noopener noreferrer"
      className={className}
    >
      <span>{children}</span>
      <ArrowUpRight className="h-4 w-4 shrink-0" strokeWidth={1.5} aria-hidden="true" />
      <span className="sr-only"> Opens the Adjudication Services website in a new tab.</span>
    </a>
  );
}

function RecordsPathway() {
  const documents = [
    { label: 'Payment application', meta: 'Valuation record' },
    { label: 'Pay less notice', meta: 'Contract notice' },
    { label: 'Project correspondence', meta: 'Evidence record' },
  ];

  return (
    <figure
      data-hero-visual
      className="relative mx-auto w-full max-w-xl rounded-2xl border border-border bg-bg-base/85 p-5 shadow-[var(--shadow-deep)] backdrop-blur sm:p-7"
      aria-label="Project records forming a structured route to specialist dispute support"
    >
      <div className="flex items-center justify-between border-b border-border pb-4">
        <div>
          <div className="text-xs font-medium text-text-muted">Project record</div>
          <div className="mt-1 text-sm font-medium text-text-primary">Contract evidence pathway</div>
        </div>
        <FolderOpen className="h-5 w-5 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
      </div>

      <div className="relative py-6">
        <div className="space-y-3">
          {documents.map((document) => (
            <div
              key={document.label}
              className="flex items-center justify-between rounded-xl border border-border bg-bg-surface px-4 py-3.5 shadow-[var(--shadow-card)]"
            >
              <div className="flex items-center gap-3">
                <FileText className="h-4 w-4 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                <span className="text-sm font-medium text-text-primary">{document.label}</span>
              </div>
              <span className="hidden text-xs text-text-muted sm:block">{document.meta}</span>
            </div>
          ))}
        </div>

        <div className="my-5 flex items-center gap-3 pl-10 text-text-muted">
          <span className="h-px flex-1 bg-border-light" />
          <ArrowRight className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
        </div>

        <div className="rounded-xl border border-text-primary bg-accent px-5 py-5 text-accent-fg">
          <div className="flex items-start justify-between gap-5">
            <div>
              <div className="text-xs opacity-65">Separate specialist company</div>
              <div className="mt-1.5 text-lg font-medium tracking-tight">Adjudication Services</div>
              <p className="mt-2 max-w-[32ch] text-xs leading-5 opacity-75">
                A dedicated route for construction disputes and adjudication support.
              </p>
            </div>
            <FileCheck2 className="h-5 w-5 shrink-0 opacity-70" strokeWidth={1.5} aria-hidden="true" />
          </div>
        </div>
      </div>

      <figcaption className="border-t border-border pt-4 text-xs leading-5 text-text-muted">
        A relationship between specialist companies, not an automated transfer between platforms.
      </figcaption>
    </figure>
  );
}

function CapabilityList({ items }: { items: string[] }) {
  return (
    <ul className="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
      {items.map((item) => (
        <li key={item} className="flex items-start gap-3 text-sm leading-6 text-text-secondary">
          <Check className="mt-1 h-3.5 w-3.5 shrink-0 text-text-primary" strokeWidth={1.75} aria-hidden="true" />
          <span>{item}</span>
        </li>
      ))}
    </ul>
  );
}

export function AdjudicationExperience() {
  const rootRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();

  useEffect(() => {
    if (reduced || !rootRef.current) return;

    const { gsap, ScrollTrigger } = getGsap();
    const ctx = gsap.context(() => {
      gsap.fromTo(
        '[data-hero-reveal]',
        { autoAlpha: 0, y: 20 },
        { autoAlpha: 1, y: 0, duration: 0.72, stagger: 0.08, ease: 'power2.out' },
      );
      gsap.fromTo(
        '[data-hero-visual]',
        { autoAlpha: 0, y: 26, rotateX: 3 },
        { autoAlpha: 1, y: 0, rotateX: 0, duration: 0.85, delay: 0.15, ease: 'power2.out' },
      );

      gsap.utils.toArray<HTMLElement>('[data-section-reveal]').forEach((section) => {
        const items = section.querySelectorAll('[data-reveal-item]');
        gsap.fromTo(
          items,
          { autoAlpha: 0, y: 18 },
          {
            autoAlpha: 1,
            y: 0,
            duration: 0.6,
            stagger: 0.06,
            ease: 'power2.out',
            scrollTrigger: { trigger: section, start: 'top 78%', once: true },
          },
        );
      });

      const processLine = rootRef.current?.querySelector('[data-process-line]');
      if (processLine) {
        gsap.fromTo(
          processLine,
          { scaleX: 0 },
          {
            scaleX: 1,
            ease: 'none',
            scrollTrigger: {
              trigger: '[data-process]',
              start: 'top 70%',
              end: 'bottom 65%',
              scrub: 0.45,
            },
          },
        );
      }

      ScrollTrigger.refresh();
    }, rootRef);

    return () => ctx.revert();
  }, [reduced]);

  return (
    <div ref={rootRef}>
      <section className="bg-atmosphere relative overflow-hidden border-b border-border">
        <Container className="grid gap-14 py-20 md:py-24 lg:grid-cols-[1.02fr_0.98fr] lg:items-center lg:gap-20">
          <div className="max-w-3xl">
            <p data-hero-reveal className="text-sm font-medium text-text-muted">
              When contract administration becomes a dispute
            </p>
            <h1
              data-hero-reveal
              className="mt-5 text-5xl font-medium leading-[0.98] tracking-tighter text-text-primary md:text-7xl"
            >
              Specialist construction adjudication support, when you need it.
            </h1>
            <p data-hero-reveal className="mt-7 max-w-[60ch] text-base leading-7 text-text-secondary">
              SureSign helps construction teams manage contracts, notices, payments, and project
              records before issues escalate. When a dispute requires formal adjudication or
              specialist payment recovery support, Adjudication Services provides a dedicated route
              forward.
            </p>
            <div data-hero-reveal className="mt-9 flex flex-col gap-4 sm:flex-row sm:items-center">
              <ExternalLink className="inline-flex items-center justify-center gap-2 rounded-full bg-accent px-7 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px">
                Visit Adjudication Services
              </ExternalLink>
              <Link
                href="/contact"
                className="inline-flex items-center justify-center rounded-full border border-border px-7 py-3.5 text-sm font-medium text-text-primary transition-colors duration-200 hover:border-border-light active:translate-y-px"
              >
                Contact SureSign
              </Link>
            </div>
          </div>

          <RecordsPathway />
        </Container>
      </section>

      <section data-section-reveal className="tone-surface border-b border-border py-24 md:py-32">
        <Container>
          <div data-reveal-item className="max-w-[48ch]">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              Two companies. Two distinct roles.
            </h2>
            <p className="mt-5 text-base leading-7 text-text-secondary">
              Related expertise gives construction businesses a clear route from proactive contract
              administration to specialist support when a dispute escalates.
            </p>
          </div>

          <div className="relative mt-14 grid gap-6 lg:grid-cols-2">
            <article
              data-reveal-item
              className="rounded-2xl border border-border bg-bg-base p-7 shadow-[var(--shadow-card)] md:p-9"
            >
              <div className="flex items-start justify-between gap-6">
                <div>
                  <p className="text-xs font-medium text-text-muted">Proactive administration</p>
                  <h3 className="mt-2 text-2xl font-medium tracking-tight text-text-primary">SureSign</h3>
                </div>
                <span className="font-mono text-xs text-text-muted">Before escalation</span>
              </div>
              <p className="mt-5 max-w-[44ch] text-sm leading-6 text-text-secondary">
                Keeps contract administration, commercial workflows, and project records clear,
                structured, and traceable.
              </p>
              <CapabilityList items={SURESIGN_CAPABILITIES} />
            </article>

            <article
              data-reveal-item
              className="rounded-2xl border border-border-light bg-bg-base p-7 shadow-[var(--shadow-pop)] md:p-9"
            >
              <div className="flex items-start justify-between gap-6">
                <div>
                  <p className="text-xs font-medium text-text-muted">Specialist dispute support</p>
                  <h3 className="mt-2 text-2xl font-medium tracking-tight text-text-primary">
                    Adjudication Services
                  </h3>
                </div>
                <span className="font-mono text-xs text-text-muted">After escalation</span>
              </div>
              <p className="mt-5 max-w-[44ch] text-sm leading-6 text-text-secondary">
                Supports businesses with construction payment and contract disputes through a
                separate specialist service.
              </p>
              <CapabilityList items={ADJUDICATION_CAPABILITIES} />
            </article>

            <div
              aria-hidden="true"
              className="absolute left-1/2 top-1/2 hidden h-11 w-11 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-bg-surface text-text-muted lg:flex"
            >
              <ArrowRight className="h-4 w-4" strokeWidth={1.5} />
            </div>
          </div>
        </Container>
      </section>

      <section data-section-reveal className="border-b border-border py-24 md:py-32">
        <Container>
          <div data-reveal-item className="max-w-[52ch]">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              When adjudication may be relevant
            </h2>
            <p className="mt-5 text-base leading-7 text-text-secondary">
              Every matter depends on its own facts and contract. These are examples of issues that
              could require specialist review.
            </p>
          </div>

          <div className="mt-14 grid gap-4 md:grid-cols-2">
            {DISPUTE_SIGNALS.map((signal) => (
              <article
                key={signal.title}
                data-reveal-item
                className="group rounded-xl border border-border bg-bg-base p-6 transition-[transform,border-color,box-shadow] duration-300 hover:-translate-y-0.5 hover:border-border-light hover:shadow-[var(--shadow-card)]"
              >
                <div className="flex items-start gap-4">
                  <FileText className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                  <div>
                    <h3 className="text-base font-medium text-text-primary">{signal.title}</h3>
                    <p className="mt-2 text-sm leading-6 text-text-secondary">{signal.detail}</p>
                  </div>
                </div>
              </article>
            ))}
          </div>

          <p data-reveal-item className="mt-8 max-w-[70ch] text-xs leading-5 text-text-muted">
            This overview is general information only. Whether adjudication is appropriate depends
            on the contract, the facts, and the circumstances of the dispute.
          </p>
        </Container>
      </section>

      <section
        data-section-reveal
        data-process
        className="bg-draft tone-surface border-b border-border py-24 md:py-36"
      >
        <Container>
          <div data-reveal-item className="max-w-[50ch]">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              From contract records to dispute readiness
            </h2>
            <p className="mt-5 text-base leading-7 text-text-secondary">
              Good administration does not prevent every dispute, but organised records can make
              the issues easier to understand, prepare, and present.
            </p>
          </div>

          <div className="relative mt-16">
            <div className="absolute left-[10%] right-[10%] top-[7px] hidden h-px bg-border md:block">
              <span
                data-process-line
                className="block h-px origin-left bg-text-primary"
              />
            </div>
            <ol className="grid gap-5 md:grid-cols-5">
              {READINESS_STEPS.map((step, index) => (
                <li
                  key={step.title}
                  data-reveal-item
                  className="relative border-l border-border pl-5 md:border-l-0 md:px-3 md:pt-9"
                >
                  <span
                    aria-hidden="true"
                    className="absolute -left-[5px] top-0 h-2.5 w-2.5 rounded-sm border border-text-primary bg-bg-surface md:left-1/2 md:-translate-x-1/2"
                  />
                  <div className="font-mono text-[11px] text-text-muted">
                    {String(index + 1).padStart(2, '0')}
                  </div>
                  <h3 className="mt-2 text-base font-medium leading-6 text-text-primary">{step.title}</h3>
                  <p className="mt-2 text-sm leading-6 text-text-secondary">{step.detail}</p>
                </li>
              ))}
            </ol>
          </div>
        </Container>
      </section>

      <section data-section-reveal className="border-b border-border py-24 md:py-36">
        <Container className="grid gap-14 lg:grid-cols-[0.72fr_1.28fr] lg:gap-24">
          <div data-reveal-item className="lg:sticky lg:top-28 lg:self-start">
            <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              Why the relationship matters
            </h2>
            <p className="mt-5 max-w-[36ch] text-base leading-7 text-text-secondary">
              A clear route to specialist support matters most when time and certainty are already
              under pressure.
            </p>
          </div>

          <div className="border-t border-border">
            {[
              ['Better administration before disputes arise', 'SureSign helps teams keep the contract record organised while the project is live.'],
              ['Project evidence remains traceable', 'Notices, payments, variations, correspondence, and programme records stay easier to locate and understand.'],
              ['A specialist route when matters escalate', 'Customers can approach a known sibling company instead of beginning an unfamiliar search at the point of crisis.'],
            ].map(([title, detail]) => (
              <article key={title} data-reveal-item className="grid gap-3 border-b border-border py-8 sm:grid-cols-[0.9fr_1.1fr] sm:gap-10">
                <h3 className="text-lg font-medium tracking-tight text-text-primary">{title}</h3>
                <p className="text-sm leading-6 text-text-secondary">{detail}</p>
              </article>
            ))}
          </div>
        </Container>
      </section>

      <section data-section-reveal className="tone-surface border-b border-border py-24 md:py-32">
        <Container className="grid gap-12 lg:grid-cols-[1.05fr_0.95fr] lg:items-center lg:gap-24">
          <div data-reveal-item>
            <p className="text-sm font-medium text-text-muted">A separate specialist business</p>
            <h2 className="mt-4 text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              About Adjudication Services
            </h2>
            <p className="mt-5 max-w-[58ch] text-base leading-7 text-text-secondary">
              Adjudication Services focuses on construction and building contract disputes. Its
              work includes payment recovery assistance, adjudication preparation, case document
              organisation, and structured support through the adjudication process.
            </p>
            <ExternalLink className="group mt-8 inline-flex items-center gap-2 text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 transition-colors hover:decoration-text-primary">
              Learn more on the Adjudication Services website
            </ExternalLink>
          </div>

          <div
            data-reveal-item
            className="rounded-2xl border border-border bg-bg-base p-7 shadow-[var(--shadow-card)] md:p-9"
          >
            <div className="flex items-center gap-3 border-b border-border pb-5">
              <FolderOpen className="h-5 w-5 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
              <h3 className="text-base font-medium text-text-primary">Structured dispute support</h3>
            </div>
            <div className="mt-6 space-y-5">
              {[
                'Construction dispute support',
                'Payment recovery assistance',
                'Adjudication preparation',
                'Case document organisation',
                'Support through the adjudication process',
              ].map((item) => (
                <div key={item} className="flex items-center justify-between gap-5 border-b border-border pb-5 last:border-b-0 last:pb-0">
                  <span className="text-sm text-text-secondary">{item}</span>
                  <ArrowRight className="h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                </div>
              ))}
            </div>
          </div>
        </Container>
      </section>

      <section className="border-b border-border py-8">
        <Container>
          <p className="mx-auto max-w-4xl text-center text-xs leading-5 text-text-muted">
            Adjudication Services is a separate specialist service and brand. Selecting an external
            link will take you to the Adjudication Services website. Information on this page is
            general and does not constitute legal advice.
          </p>
        </Container>
      </section>

      <section data-section-reveal className="bg-atmosphere relative overflow-hidden py-28 md:py-40">
        <Container>
          <div data-reveal-item className="mx-auto max-w-3xl text-center">
            <h2 className="text-4xl font-medium tracking-tighter text-text-primary md:text-6xl">
              Has a construction contract issue become a formal dispute?
            </h2>
            <p className="mx-auto mt-6 max-w-[52ch] text-base leading-7 text-text-secondary">
              Visit Adjudication Services to explore specialist support for construction payment
              and contract disputes.
            </p>
            <div className="mt-9 flex flex-col items-center justify-center gap-4 sm:flex-row">
              <ExternalLink className="inline-flex items-center justify-center gap-2 rounded-full bg-accent px-8 py-4 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px">
                Go to Adjudication Services
              </ExternalLink>
              <Link
                href="/contact"
                className="inline-flex items-center justify-center rounded-full border border-border px-8 py-4 text-sm font-medium text-text-primary transition-colors duration-200 hover:border-border-light active:translate-y-px"
              >
                Contact SureSign
              </Link>
            </div>
          </div>
        </Container>
      </section>
    </div>
  );
}
