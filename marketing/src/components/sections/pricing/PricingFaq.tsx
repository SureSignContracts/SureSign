'use client';

import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { useReducedMotion } from '@/lib/useReducedMotion';
import type { PricingFaq as PricingFaqItem } from '@/lib/pricing';

function AccordionItem({ faq, open, onToggle, index, reduced }: { faq: PricingFaqItem; open: boolean; onToggle: () => void; index: number; reduced: boolean }) {
  const contentId = `pricing-faq-answer-${index}`;
  const buttonId = `pricing-faq-question-${index}`;

  return (
    <div className="rounded-xl border border-border bg-bg-base px-5 transition-[border-color,box-shadow] duration-200 focus-within:border-border-light focus-within:shadow-[var(--shadow-card)] md:px-6">
      <button
        id={buttonId}
        type="button"
        onClick={onToggle}
        aria-expanded={open}
        aria-controls={contentId}
        className="flex w-full items-center justify-between gap-6 py-5 text-left md:py-6"
      >
        <span className="text-base font-medium leading-6 text-text-primary md:text-lg">{faq.question}</span>
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border bg-bg-surface">
          <ChevronDown
            size={17}
            aria-hidden="true"
            className="text-text-muted transition-transform duration-300"
            style={{ transform: open ? 'rotate(180deg)' : 'rotate(0deg)' }}
          />
        </span>
      </button>
      <div
        id={contentId}
        role="region"
        aria-labelledby={buttonId}
        className={reduced ? 'grid' : 'grid transition-[grid-template-rows] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]'}
        style={{ gridTemplateRows: open ? '1fr' : '0fr' }}
      >
        <div className="overflow-hidden">
          <p className="max-w-[64ch] pb-6 text-sm leading-7 text-text-secondary">{faq.answer}</p>
        </div>
      </div>
    </div>
  );
}

export function PricingFaq({ faqs }: { faqs: PricingFaqItem[] }) {
  const [openIndex, setOpenIndex] = useState<number | null>(0);
  const reduced = useReducedMotion();

  if (faqs.length === 0) return null;

  return (
    <section className="tone-surface border-b border-border py-20 md:py-28">
      <Container>
        <div className="mx-auto max-w-[48rem]">
          <h2 className="text-2xl font-medium tracking-tight text-text-primary md:text-3xl">
            Frequently asked questions
          </h2>
          <p className="mt-3 max-w-[52ch] text-base leading-7 text-text-secondary">
            Practical details about choosing and managing your SureSign plan.
          </p>

          <div className="mt-10 space-y-3">
            {faqs.map((faq, i) => (
              <AccordionItem
                key={faq.question}
                faq={faq}
                open={openIndex === i}
                onToggle={() => setOpenIndex(openIndex === i ? null : i)}
                index={i}
                reduced={reduced}
              />
            ))}
          </div>
        </div>
      </Container>
    </section>
  );
}
