import Link from 'next/link';
import { ArrowRight, Check } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { RevealGroup } from '@/components/shared/RevealGroup';

const SERVICE_OPTIONS = [
  {
    title: 'Consultancy',
    description:
      'Private, professional guidance on contract administration, commercial matters, and project issues before they become formal disputes.',
    href: '/consultancy',
    cta: 'Explore Consultancy',
  },
  {
    title: 'Adjudication',
    description:
      'A route to specialist support when an existing construction payment or contract dispute requires a formal adjudication process.',
    href: '/adjudication',
    cta: 'Explore Adjudication',
  },
];

const GUIDANCE = [
  {
    title: 'Choose Consultancy when you need',
    items: [
      'Ongoing professional guidance',
      'Contractual or commercial support',
      'Help administering project matters',
      'Early support before issues escalate',
    ],
  },
  {
    title: 'Choose Adjudication when you need',
    items: [
      'A formal dispute-resolution process',
      'Support with an existing construction dispute',
      'A structured route towards an adjudicator’s decision',
    ],
  },
];

const STRENGTHS = [
  ['Construction focus', 'Support grounded in construction contracts, commercial workflows, and project administration.'],
  ['Clear routes', 'A direct path to the right service, with the boundaries between guidance and formal dispute support made clear.'],
  ['Connected context', 'Technology can organise project records, while professional services provide human support when it is needed.'],
];

export function ServicesExperience() {
  return (
    <>
      <section className="bg-atmosphere border-b border-border">
        <Container className="py-20 md:py-24">
          <HeroReveal>
            <p data-reveal className="text-sm font-medium text-text-muted">SureSign Services</p>
            <h1
              data-reveal
              className="mt-5 max-w-5xl text-4xl font-medium leading-[1.02] tracking-tighter text-text-primary text-balance sm:text-5xl md:text-[3.5rem]"
            >
              Professional construction services, supported by better technology.
            </h1>
            <p data-reveal className="mt-6 max-w-[56ch] text-lg leading-8 text-text-secondary">
              Construction-focused software and professional support for contractual,
              commercial, and dispute-related matters.
            </p>
            <div data-reveal className="mt-8">
              <Link
                href="#professional-services"
                className="inline-flex min-h-12 items-center justify-center gap-2 whitespace-nowrap rounded-full bg-accent px-7 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              >
                Explore our services
                <ArrowRight className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
              </Link>
            </div>
          </HeroReveal>
        </Container>
      </section>

      <section id="professional-services" className="scroll-mt-24 border-b border-border py-20 md:py-28">
        <Container>
          <RevealGroup>
            <div data-reveal-item className="max-w-[48ch]">
              <h2 className="text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
                Two services. Two distinct routes.
              </h2>
              <p className="mt-5 text-base leading-7 text-text-secondary">
                Start with the type of support your project needs now.
              </p>
            </div>

            <div className="mt-12 grid gap-6 md:grid-cols-2">
              {SERVICE_OPTIONS.map((service) => (
                <article
                  key={service.href}
                  data-reveal-item
                  className="flex flex-col rounded-2xl border border-border bg-bg-surface p-7 shadow-[var(--shadow-card)] md:p-9"
                >
                  <h3 className="text-2xl font-medium tracking-tight text-text-primary">{service.title}</h3>
                  <p className="mt-4 max-w-[46ch] text-sm leading-6 text-text-secondary">
                    {service.description}
                  </p>
                  <Link
                    href={service.href}
                    className="group mt-8 inline-flex min-h-11 items-center gap-2 self-start text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 transition-colors hover:decoration-text-primary"
                  >
                    {service.cta}
                    <ArrowRight
                      className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5"
                      strokeWidth={1.5}
                      aria-hidden="true"
                    />
                  </Link>
                </article>
              ))}
            </div>
          </RevealGroup>
        </Container>
      </section>

      <section className="tone-surface border-b border-border py-20 md:py-28">
        <Container>
          <RevealGroup>
            <h2 data-reveal-item className="max-w-[20ch] text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
              Which service is right for you?
            </h2>
            <div className="mt-12 grid gap-10 md:grid-cols-2 md:gap-16">
              {GUIDANCE.map((group) => (
                <div key={group.title} data-reveal-item>
                  <h3 className="text-lg font-medium text-text-primary">{group.title}</h3>
                  <ul className="mt-6 space-y-4">
                    {group.items.map((item) => (
                      <li key={item} className="flex items-start gap-3 text-sm leading-6 text-text-secondary">
                        <Check className="mt-1 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                        <span>{item}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
            <p data-reveal-item className="mt-12 text-sm text-text-secondary">
              Not sure which service you need?{' '}
              <Link href="/contact" className="font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary">
                Contact us
              </Link>
              .
            </p>
          </RevealGroup>
        </Container>
      </section>

      <section className="border-b border-border py-20 md:py-28">
        <Container>
          <RevealGroup className="grid gap-12 lg:grid-cols-[0.85fr_1.15fr] lg:items-start lg:gap-20">
            <div data-reveal-item>
              <h2 className="max-w-[15ch] text-3xl font-medium tracking-tight text-text-primary md:text-4xl">
                Platform and professional services
              </h2>
              <p className="mt-5 max-w-[46ch] text-base leading-7 text-text-secondary">
                The SureSign platform supports construction contract administration and
                project workflows. Professional services provide separate human support
                when guidance or formal dispute resolution is required.
              </p>
              <Link
                href="/product"
                className="group mt-7 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary"
              >
                Explore the SureSign platform
                <ArrowRight className="h-4 w-4 transition-transform duration-200 group-hover:translate-x-0.5" strokeWidth={1.5} aria-hidden="true" />
              </Link>
            </div>

            <div data-reveal-item className="border-y border-border">
              {STRENGTHS.map(([title, description]) => (
                <div key={title} className="grid gap-2 border-b border-border py-6 last:border-b-0 sm:grid-cols-[0.38fr_0.62fr] sm:gap-8">
                  <h3 className="font-medium text-text-primary">{title}</h3>
                  <p className="text-sm leading-6 text-text-secondary">{description}</p>
                </div>
              ))}
            </div>
          </RevealGroup>
        </Container>
      </section>

      <section className="bg-atmosphere py-20 md:py-28">
        <Container>
          <RevealGroup className="max-w-3xl">
            <h2 data-reveal-item className="max-w-[18ch] text-4xl font-medium tracking-tighter text-text-primary md:text-5xl">
              Need support with a construction contract or dispute?
            </h2>
            <p data-reveal-item className="mt-5 max-w-[48ch] text-base leading-7 text-text-secondary">
              Tell us what you are dealing with, and we will help you find the appropriate next route.
            </p>
            <Link
              data-reveal-item
              href="/contact"
              className="mt-8 inline-flex min-h-12 items-center justify-center self-start whitespace-nowrap rounded-full bg-accent px-7 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
            >
              Contact us
            </Link>
          </RevealGroup>
        </Container>
      </section>
    </>
  );
}
