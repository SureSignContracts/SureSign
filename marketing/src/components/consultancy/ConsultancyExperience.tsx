'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { ArrowRight, Check, HeartHandshake, ShieldCheck, X } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { HeroReveal } from '@/components/hero/HeroReveal';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

const TOPICS = [
  'Payment Applications', 'Payment Notices', 'Pay Less Notices', 'Variations',
  'Extensions of Time', 'Delay Events', 'Final Accounts', 'Contract Administration',
  'NEC', 'JCT', 'FIDIC', 'General commercial discussions',
];

const NOT_THIS = [
  'We will not tell you how to win an adjudication.',
  'We will not tell you to sue.',
  'We do not guarantee any claim outcome.',
  'This is not legal representation, dispute resolution, or adjudication services.',
];

interface PublicService {
  code: string;
  display_name: string;
  public_description: string | null;
  duration_minutes: number;
  price_minor_units: number | null;
  currency: string;
  is_introductory: boolean;
}

function formatPrice(s: PublicService): string {
  if (s.price_minor_units === null) return 'Contact us';
  const symbol = s.currency === 'GBP' ? '£' : `${s.currency} `;
  return `${symbol}${(s.price_minor_units / 100).toFixed(2)}`;
}

export function ConsultancyExperience() {
  const [services, setServices] = useState<PublicService[] | null>(null);

  useEffect(() => {
    fetch(`${API_BASE}/public/consultancy-services`, { headers: { Accept: 'application/json' } })
      .then(res => res.json())
      .then(data => setServices(data))
      .catch(() => setServices([]));
  }, []);

  return (
    <>
      <section className="pt-16 pb-12 md:pt-24 md:pb-16">
        <Container className="max-w-[880px]">
          <HeroReveal>
            <div data-reveal className="flex items-center gap-2 text-xs font-medium text-text-muted">
              <ShieldCheck className="h-3.5 w-3.5" strokeWidth={1.5} aria-hidden="true" />
              A real construction professional, not AI.
            </div>
            <h1 data-reveal className="mt-4 text-3xl font-medium tracking-tight text-text-primary sm:text-4xl md:text-5xl">
              SureSign Consultancy
            </h1>
            <p data-reveal className="mt-5 max-w-2xl text-base text-text-secondary md:text-lg">
              Need professional guidance on your construction project? SureSign Consultancy connects you
              with experienced professionals who can review project issues, explain contract administration
              processes, and discuss your project during a private consultation.
            </p>
            <div data-reveal className="mt-8 flex flex-wrap gap-3">
              <Link
                href="#services"
                className="inline-flex items-center gap-2 rounded-full bg-accent px-6 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
              >
                Book a Consultancy Session <ArrowRight className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
              </Link>
            </div>
          </HeroReveal>
        </Container>
      </section>

      <section className="py-12 md:py-16">
        <Container className="max-w-[880px]">
          <HeroReveal>
            <h2 data-reveal className="text-xl font-medium tracking-tight text-text-primary md:text-2xl">
              What you can discuss
            </h2>
            <div data-reveal className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
              {TOPICS.map(topic => (
                <div key={topic} className="flex items-start gap-2 rounded-xl border border-border bg-bg-surface px-4 py-3 text-sm text-text-secondary">
                  <Check className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                  {topic}
                </div>
              ))}
            </div>
          </HeroReveal>
        </Container>
      </section>

      <section className="py-12 md:py-16">
        <Container className="max-w-[880px]">
          <HeroReveal>
            <h2 data-reveal className="text-xl font-medium tracking-tight text-text-primary md:text-2xl">
              What Consultancy is not
            </h2>
            <p data-reveal className="mt-3 max-w-2xl text-sm text-text-secondary">
              SureSign Consultancy provides professional guidance and discussion regarding construction
              contract administration. It is not legal representation, dispute resolution, or adjudication
              services. If your matter has escalated into a formal dispute, see{' '}
              <Link href="/adjudication" className="font-medium text-text-primary underline-offset-2 hover:underline">
                SureSign Adjudication
              </Link>.
            </p>
            <Link
              data-reveal
              href="/services"
              className="mt-5 inline-flex text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 hover:decoration-text-primary"
            >
              View all professional services
            </Link>
            <div data-reveal className="mt-6 space-y-3">
              {NOT_THIS.map(item => (
                <div key={item} className="flex items-start gap-2 text-sm text-text-secondary">
                  <X className="mt-0.5 h-4 w-4 shrink-0 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                  {item}
                </div>
              ))}
            </div>
          </HeroReveal>
        </Container>
      </section>

      <section id="services" className="py-12 md:py-16 scroll-mt-24">
        <Container className="max-w-[880px]">
          <HeroReveal>
            <h2 data-reveal className="text-xl font-medium tracking-tight text-text-primary md:text-2xl">
              Choose a consultation
            </h2>
            <p data-reveal className="mt-3 max-w-2xl text-sm text-text-secondary">
              No account required — book a time that works for you.
            </p>

            <div data-reveal className="mt-6 grid gap-4 sm:grid-cols-3">
              {services === null ? (
                [...Array(3)].map((_, i) => (
                  <div key={i} className="h-40 animate-pulse rounded-2xl border border-border bg-bg-elevated" />
                ))
              ) : services.length === 0 ? (
                <p className="text-sm text-text-secondary sm:col-span-3">
                  Consultancy booking isn&apos;t available right now — please{' '}
                  <Link href="/contact" className="font-medium text-text-primary underline-offset-2 hover:underline">contact us</Link> directly.
                </p>
              ) : services.map(service => (
                <Link
                  key={service.code}
                  href={`/consultancy/book/${service.code}?src=consultancy`}
                  className="group flex flex-col rounded-2xl border border-border bg-bg-surface p-6 shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-text-primary"
                >
                  <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-bg-elevated">
                    <HeartHandshake className="h-5 w-5 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                  </div>
                  <h3 className="mt-4 text-base font-medium text-text-primary">{service.display_name}</h3>
                  {service.public_description && (
                    <p className="mt-1 text-sm text-text-secondary">{service.public_description}</p>
                  )}
                  <div className="mt-4 flex items-center justify-between text-sm">
                    <span className="text-text-muted">{service.duration_minutes} minutes</span>
                    <span className="font-medium text-text-primary">{formatPrice(service)}</span>
                  </div>
                  <span className="mt-4 inline-flex items-center gap-1.5 text-sm font-medium text-text-primary">
                    Book now <ArrowRight className="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5" strokeWidth={1.5} aria-hidden="true" />
                  </span>
                </Link>
              ))}
            </div>
          </HeroReveal>
        </Container>
      </section>

      <section className="py-12 md:py-16">
        <Container className="max-w-[880px]">
          <HeroReveal>
            <div data-reveal className="rounded-2xl border border-border bg-bg-surface p-8 text-center sm:p-10">
              <p className="text-sm text-text-secondary">Already a SureSign customer?</p>
              <p className="mt-1 text-base font-medium text-text-primary">
                Book a Consultation from the Consultancy section in your SureSign account to link it to your project.
              </p>
              <a
                href="https://app.suresigncontracts.app/app/consultations"
                className="mt-5 inline-flex items-center gap-2 rounded-full border border-border px-6 py-3 text-sm font-medium text-text-primary transition-colors hover:border-border-light"
              >
                Go to Consultancy <ArrowRight className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
              </a>
            </div>
          </HeroReveal>
        </Container>
      </section>
    </>
  );
}
