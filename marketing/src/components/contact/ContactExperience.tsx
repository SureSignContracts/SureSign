'use client';

import { useEffect, useRef, useState, type FormEvent } from 'react';
import Link from 'next/link';
import { AlertCircle, ArrowUpRight, Check, Clock3, Mail } from 'lucide-react';
import { Container } from '@/components/shared/Container';
import { getGsap } from '@/lib/gsap';
import { useReducedMotion } from '@/lib/useReducedMotion';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

type FieldName = 'name' | 'company' | 'email' | 'phone' | 'subject' | 'message';
type FieldErrors = Partial<Record<FieldName, string>>;
type FormStatus = 'idle' | 'submitting' | 'success' | 'error';

const FIELD_CLASS =
  'w-full rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-[border-color,box-shadow] duration-200 placeholder:text-text-muted focus:border-text-primary focus:shadow-[0_0_0_3px_var(--spotlight)] disabled:cursor-not-allowed disabled:opacity-60';

function validate(form: HTMLFormElement): FieldErrors {
  const data = new FormData(form);
  const errors: FieldErrors = {};
  const value = (field: FieldName) => String(data.get(field) || '').trim();

  if (!value('name')) errors.name = 'Enter your full name.';
  if (!value('company')) errors.company = 'Enter your company name.';

  const email = value('email');
  if (!email) {
    errors.email = 'Enter your email address.';
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = 'Enter a valid email address.';
  }

  if (!value('subject')) errors.subject = 'Tell us what your enquiry is about.';

  const message = value('message');
  if (!message) {
    errors.message = 'Enter a message.';
  } else if (message.length < 10) {
    errors.message = 'Add a little more detail so we can help.';
  }

  return errors;
}

function focusField(form: HTMLFormElement, errors: FieldErrors) {
  const firstName = Object.keys(errors)[0] as FieldName | undefined;
  if (!firstName) return;
  const field = form.elements.namedItem(firstName);
  if (field instanceof HTMLElement) field.focus();
}

export function ContactExperience() {
  const rootRef = useRef<HTMLDivElement>(null);
  const reduced = useReducedMotion();
  const [status, setStatus] = useState<FormStatus>('idle');
  const [errors, setErrors] = useState<FieldErrors>({});
  const [submitError, setSubmitError] = useState<string | null>(null);

  useEffect(() => {
    if (reduced || !rootRef.current) return;

    const { gsap } = getGsap();
    const ctx = gsap.context(() => {
      gsap.fromTo(
        '[data-contact-reveal]',
        { autoAlpha: 0, y: 18 },
        { autoAlpha: 1, y: 0, duration: 0.65, stagger: 0.07, ease: 'power2.out' },
      );
    }, rootRef);

    return () => ctx.revert();
  }, [reduced]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const nextErrors = validate(form);

    setErrors(nextErrors);
    setSubmitError(null);

    if (Object.keys(nextErrors).length > 0) {
      focusField(form, nextErrors);
      return;
    }

    setStatus('submitting');

    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());

    try {
      const response = await fetch(`${API_BASE}/marketing-contact`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await response.json().catch(() => ({}));

      if (response.status === 422 && data.errors) {
        const serverErrors = Object.fromEntries(
          Object.entries(data.errors).map(([key, messages]) => [
            key,
            Array.isArray(messages) ? String(messages[0]) : String(messages),
          ]),
        ) as FieldErrors;
        setErrors(serverErrors);
        setStatus('idle');
        focusField(form, serverErrors);
        return;
      }

      if (!response.ok) {
        throw new Error(
          data.message || 'We could not send your enquiry. Please email us directly.',
        );
      }

      form.reset();
      setStatus('success');
    } catch (error) {
      setSubmitError(
        error instanceof Error
          ? error.message
          : 'We could not send your enquiry. Please email us directly.',
      );
      setStatus('error');
    }
  }

  return (
    <div ref={rootRef}>
      <section className="bg-atmosphere relative overflow-hidden border-b border-border">
        <Container className="grid gap-10 py-20 md:py-24 lg:grid-cols-[1.15fr_0.85fr] lg:items-end">
          <div data-contact-reveal className="max-w-3xl">
            <p className="text-sm font-medium text-text-muted">Contact SureSign</p>
            <h1 className="mt-5 text-5xl font-medium leading-[0.98] tracking-tighter text-text-primary md:text-7xl">
              Let&apos;s talk about what your team needs.
            </h1>
          </div>
          <p
            data-contact-reveal
            className="max-w-[38ch] text-base leading-7 text-text-secondary lg:justify-self-end lg:pb-1"
          >
            Have a question about SureSign, implementation, pricing, or construction contract
            administration? Our team would be happy to help.
          </p>
        </Container>
      </section>

      <section className="tone-surface border-b border-border py-16 md:py-24">
        <Container className="grid gap-12 lg:grid-cols-[0.72fr_1.28fr] lg:gap-20">
          <aside data-contact-reveal className="lg:pt-4">
            <h2 className="text-2xl font-medium tracking-tight text-text-primary">
              Speak with our team
            </h2>
            <p className="mt-4 max-w-[34ch] text-sm leading-6 text-text-secondary">
              Send us the details and we will make sure your enquiry reaches the right person.
            </p>

            <dl className="mt-10 space-y-0 border-y border-border">
              <div className="grid grid-cols-[24px_1fr] gap-4 border-b border-border py-5">
                <Mail className="mt-0.5 h-4 w-4 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                <div>
                  <dt className="text-xs font-medium text-text-muted">Email</dt>
                  <dd className="mt-1.5">
                    <a
                      href="mailto:tech@suresigncontracts.com"
                      className="text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 transition-colors hover:decoration-text-primary"
                    >
                      tech@suresigncontracts.com
                    </a>
                  </dd>
                </div>
              </div>
              <div className="grid grid-cols-[24px_1fr] gap-4 border-b border-border py-5">
                <Clock3 className="mt-0.5 h-4 w-4 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                <div>
                  <dt className="text-xs font-medium text-text-muted">Response time</dt>
                  <dd className="mt-1.5 text-sm leading-6 text-text-secondary">
                    We usually reply within one business day.
                  </dd>
                </div>
              </div>
              <div className="grid grid-cols-[24px_1fr] gap-4 py-5">
                <Clock3 className="mt-0.5 h-4 w-4 text-text-muted" strokeWidth={1.5} aria-hidden="true" />
                <div>
                  <dt className="text-xs font-medium text-text-muted">Office hours</dt>
                  <dd className="mt-1.5 text-sm leading-6 text-text-secondary">
                    Monday to Friday, 9:00am to 5:30pm UK time.
                  </dd>
                </div>
              </div>
            </dl>

            <div className="mt-8">
              <p className="text-sm text-text-secondary">Prefer a guided walkthrough?</p>
              <Link
                href="/book/demo?src=contact"
                className="group mt-2 inline-flex items-center gap-2 text-sm font-medium text-text-primary"
              >
                Book a Demo
                <ArrowUpRight
                  className="h-4 w-4 transition-transform duration-200 group-hover:-translate-y-0.5 group-hover:translate-x-0.5"
                  strokeWidth={1.5}
                  aria-hidden="true"
                />
              </Link>
            </div>
          </aside>

          <div data-contact-reveal>
            <div className="rounded-2xl border border-border bg-bg-base p-6 shadow-[var(--shadow-card)] sm:p-8 md:p-10">
              {status === 'success' ? (
                <div
                  className="flex min-h-[32rem] flex-col items-start justify-center"
                  role="status"
                  aria-live="polite"
                >
                  <span className="inline-flex h-11 w-11 items-center justify-center rounded-full bg-accent text-accent-fg">
                    <Check className="h-5 w-5" strokeWidth={1.75} aria-hidden="true" />
                  </span>
                  <h2 className="mt-7 text-3xl font-medium tracking-tight text-text-primary">
                    Your message is with us.
                  </h2>
                  <p className="mt-3 max-w-[42ch] text-sm leading-6 text-text-secondary">
                    Thanks for contacting SureSign. We will review your enquiry and reply as soon
                    as possible.
                  </p>
                  <button
                    type="button"
                    onClick={() => setStatus('idle')}
                    className="mt-8 text-sm font-medium text-text-primary underline decoration-border-light underline-offset-4 transition-colors hover:decoration-text-primary"
                  >
                    Send another message
                  </button>
                </div>
              ) : (
                <>
                  <div className="mb-8">
                    <h2 className="text-2xl font-medium tracking-tight text-text-primary">
                      Send an enquiry
                    </h2>
                    <p className="mt-2 text-sm leading-6 text-text-secondary">
                      Required fields are marked with an asterisk.
                    </p>
                  </div>

                  <form onSubmit={handleSubmit} noValidate>
                    <div className="grid gap-5 sm:grid-cols-2">
                      <Field
                        label="Full name"
                        name="name"
                        autoComplete="name"
                        required
                        error={errors.name}
                        disabled={status === 'submitting'}
                      />
                      <Field
                        label="Company"
                        name="company"
                        autoComplete="organization"
                        required
                        error={errors.company}
                        disabled={status === 'submitting'}
                      />
                      <Field
                        label="Email address"
                        name="email"
                        type="email"
                        autoComplete="email"
                        required
                        error={errors.email}
                        disabled={status === 'submitting'}
                      />
                      <Field
                        label="Phone number"
                        name="phone"
                        type="tel"
                        autoComplete="tel"
                        hint="Optional"
                        error={errors.phone}
                        disabled={status === 'submitting'}
                      />
                    </div>

                    <div className="mt-5">
                      <Field
                        label="Subject"
                        name="subject"
                        required
                        error={errors.subject}
                        disabled={status === 'submitting'}
                      />
                    </div>

                    <div className="mt-5">
                      <label htmlFor="message" className="text-sm font-medium text-text-primary">
                        Message <span aria-hidden="true">*</span>
                      </label>
                      <textarea
                        id="message"
                        name="message"
                        rows={7}
                        required
                        disabled={status === 'submitting'}
                        aria-invalid={Boolean(errors.message)}
                        aria-describedby={errors.message ? 'message-error' : undefined}
                        className={`${FIELD_CLASS} mt-2 resize-y`}
                      />
                      {errors.message && (
                        <p id="message-error" className="mt-2 text-xs text-text-primary">
                          {errors.message}
                        </p>
                      )}
                    </div>

                    <div className="absolute left-[-10000px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
                      <label htmlFor="website">Website</label>
                      <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" />
                    </div>

                    {submitError && (
                      <div
                        className="mt-6 flex items-start gap-3 rounded-lg border border-border bg-bg-surface p-4 text-sm leading-6 text-text-primary"
                        role="alert"
                      >
                        <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" strokeWidth={1.5} aria-hidden="true" />
                        <span>
                          {submitError}{' '}
                          <a className="font-medium underline underline-offset-4" href="mailto:tech@suresigncontracts.com">
                            Email us directly.
                          </a>
                        </span>
                      </div>
                    )}

                    <button
                      type="submit"
                      disabled={status === 'submitting'}
                      className="mt-7 inline-flex min-w-36 items-center justify-center rounded-full bg-accent px-7 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px disabled:cursor-wait disabled:opacity-60"
                    >
                      {status === 'submitting' ? 'Sending...' : 'Send enquiry'}
                    </button>
                  </form>
                </>
              )}
            </div>
          </div>
        </Container>
      </section>
    </div>
  );
}

function Field({
  label,
  name,
  type = 'text',
  autoComplete,
  hint,
  required = false,
  error,
  disabled = false,
}: {
  label: string;
  name: FieldName;
  type?: 'text' | 'email' | 'tel';
  autoComplete?: string;
  hint?: string;
  required?: boolean;
  error?: string;
  disabled?: boolean;
}) {
  const errorId = `${name}-error`;

  return (
    <div>
      <div className="flex items-baseline justify-between gap-3">
        <label htmlFor={name} className="text-sm font-medium text-text-primary">
          {label} {required && <span aria-hidden="true">*</span>}
        </label>
        {hint && <span className="text-xs text-text-muted">{hint}</span>}
      </div>
      <input
        id={name}
        name={name}
        type={type}
        autoComplete={autoComplete}
        required={required}
        disabled={disabled}
        aria-invalid={Boolean(error)}
        aria-describedby={error ? errorId : undefined}
        className={`${FIELD_CLASS} mt-2`}
      />
      {error && (
        <p id={errorId} className="mt-2 text-xs text-text-primary">
          {error}
        </p>
      )}
    </div>
  );
}
