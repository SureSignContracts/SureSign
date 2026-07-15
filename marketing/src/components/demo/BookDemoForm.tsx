'use client';

import { useState, type FormEvent } from 'react';

type Status = 'idle' | 'submitting' | 'success' | 'error';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

export function BookDemoForm() {
  const [status, setStatus] = useState<Status>('idle');
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setStatus('submitting');
    setError(null);

    const form = event.currentTarget;
    const payload = {
      name: (form.elements.namedItem('name') as HTMLInputElement).value,
      company: (form.elements.namedItem('company') as HTMLInputElement).value,
      email: (form.elements.namedItem('email') as HTMLInputElement).value,
      phone: (form.elements.namedItem('phone') as HTMLInputElement).value,
      project_count: (form.elements.namedItem('project_count') as HTMLInputElement).value,
      message: (form.elements.namedItem('message') as HTMLTextAreaElement).value,
    };

    try {
      const res = await fetch(`${API_BASE}/demo-requests`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      if (!res.ok) throw new Error('Request failed');
      setStatus('success');
      form.reset();
    } catch {
      setStatus('error');
      setError("Something went wrong sending that — please try again, or reach us directly.");
    }
  }

  if (status === 'success') {
    return (
      <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center">
        <div className="text-lg font-medium text-text-primary">Thanks — we&apos;ll be in touch.</div>
        <p className="mt-2 text-sm text-text-secondary">
          Someone from the SureSign team will reach out to schedule your demo.
        </p>
      </div>
    );
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-5">
      <div className="grid gap-5 sm:grid-cols-2">
        <Field label="Name" name="name" required />
        <Field label="Company" name="company" required />
      </div>
      <div className="grid gap-5 sm:grid-cols-2">
        <Field label="Work email" name="email" type="email" required />
        <Field label="Phone" name="phone" type="tel" />
      </div>
      <Field label="Number of active projects" name="project_count" type="number" />
      <div className="flex flex-col gap-2">
        <label htmlFor="message" className="text-sm font-medium text-text-primary">
          What would you like us to cover?
        </label>
        <textarea
          id="message"
          name="message"
          rows={4}
          className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light"
        />
      </div>

      {status === 'error' && error && <p className="text-sm text-text-primary">{error}</p>}

      <button
        type="submit"
        disabled={status === 'submitting'}
        className="w-full rounded-full bg-accent px-6 py-3.5 text-sm font-medium text-accent-fg transition-transform active:translate-y-px disabled:opacity-60"
      >
        {status === 'submitting' ? 'Sending…' : 'Book a Demo'}
      </button>
    </form>
  );
}

function Field({
  label,
  name,
  type = 'text',
  required = false,
}: {
  label: string;
  name: string;
  type?: string;
  required?: boolean;
}) {
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={name} className="text-sm font-medium text-text-primary">
        {label}
      </label>
      <input
        id={name}
        name={name}
        type={type}
        required={required}
        className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light"
      />
    </div>
  );
}
