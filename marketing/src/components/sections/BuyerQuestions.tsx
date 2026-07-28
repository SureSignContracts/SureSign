'use client';

import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { useReducedMotion } from '@/lib/useReducedMotion';

const QUESTIONS = [
  {
    question: 'Can we use our existing documents and folders?',
    answer:
      'Existing project documents can be uploaded into the project record. SureSign also supports an optional local Windows folder mirror where that forms part of your team’s agreed setup.',
  },
  {
    question: 'Which contract forms can a project record?',
    answer:
      'Projects can record JCT, NEC3, NEC4, FIDIC, bespoke and other contract types. Analysis quality depends on the source document, so extracted information must still be reviewed.',
  },
  {
    question: 'Does the AI act automatically?',
    answer:
      'No. It extracts and organises information for review. A person confirms the result before it becomes operational project data.',
  },
  {
    question: 'Can extracted information be corrected?',
    answer:
      'Yes. The review step exists so the project team can inspect and correct extracted fields before confirming them.',
  },
  {
    question: 'Can we bring an existing live project into SureSign?',
    answer:
      'SureSign can hold existing project and contract records, but the appropriate setup and document migration scope depends on the project. Bring the project to the demo so the team can assess it without making a blanket migration promise.',
  },
  {
    question: 'Does SureSign provide legal advice?',
    answer:
      'No. SureSign supports contract administration and organises project information. It does not replace professional judgement or provide legal advice.',
  },
];

export function BuyerQuestions() {
  const [openIndex, setOpenIndex] = useState<number | null>(0);
  const reduced = useReducedMotion();

  return (
    <section aria-labelledby="buyer-questions-title" className="border-b border-border py-24 md:py-32">
      <Container>
        <div className="grid gap-12 md:grid-cols-[0.75fr_1.25fr] md:gap-20">
          <div>
            <p className="text-sm font-medium text-text-muted">Before you bring a live contract</p>
            <h2 id="buyer-questions-title" className="mt-3 max-w-[16ch] text-3xl font-medium tracking-tight text-text-primary">
              Questions a cautious buyer should ask.
            </h2>
            <p className="mt-5 max-w-[40ch] text-sm leading-6 text-text-secondary">
              Setup times, contractual support commitments, data return at termination and formal onboarding scope require business confirmation before they can be stated publicly.
            </p>
          </div>
          <div className="border-t border-border">
            {QUESTIONS.map((item, index) => {
              const open = openIndex === index;
              const triggerId = `buyer-question-${index}`;
              const panelId = `buyer-answer-${index}`;

              return (
                <div key={item.question} className="border-b border-border">
                  <button
                    id={triggerId}
                    type="button"
                    aria-expanded={open}
                    aria-controls={panelId}
                    onClick={() => setOpenIndex(open ? null : index)}
                    className="group flex min-h-16 w-full items-center justify-between gap-5 py-4 text-left font-medium text-text-primary"
                  >
                    <span>{item.question}</span>
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg border border-border bg-bg-surface transition-colors group-hover:border-border-light">
                      <ChevronDown
                        size={16}
                        strokeWidth={1.7}
                        aria-hidden
                        className={`text-text-muted ${reduced ? '' : 'transition-transform duration-300'} ${open ? 'rotate-180' : ''}`}
                      />
                    </span>
                  </button>
                  <div
                    id={panelId}
                    role="region"
                    aria-labelledby={triggerId}
                    className={`grid ${reduced ? '' : 'transition-[grid-template-rows] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)]'}`}
                    style={{ gridTemplateRows: open ? '1fr' : '0fr' }}
                  >
                    <div className="overflow-hidden">
                      <p className="max-w-[62ch] pb-6 pr-10 text-sm leading-6 text-text-secondary">{item.answer}</p>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </Container>
    </section>
  );
}
