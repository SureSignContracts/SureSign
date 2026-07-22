'use client';

export interface ContactFormData {
  attendee_name: string;
  attendee_email: string;
  attendee_phone: string;
  attendee_company: string;
  attendee_job_title: string;
  attendee_message: string;
  consent: boolean;
  website: string;
}

export const EMPTY_CONTACT_FORM: ContactFormData = {
  attendee_name: '', attendee_email: '', attendee_phone: '',
  attendee_company: '', attendee_job_title: '', attendee_message: '',
  consent: false, website: '',
};

function Field({
  label, value, onChange, type = 'text', required = false, id,
}: {
  label: string; value: string; onChange: (v: string) => void; type?: string; required?: boolean; id: string;
}) {
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-sm font-medium text-text-primary">{label}</label>
      <input
        id={id}
        type={type}
        required={required}
        value={value}
        onChange={e => onChange(e.target.value)}
        className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light"
      />
    </div>
  );
}

export function PersonalDetailsStep({
  form,
  onChange,
  onBack,
  onContinue,
}: {
  form: ContactFormData;
  onChange: (patch: Partial<ContactFormData>) => void;
  onBack: () => void;
  onContinue: () => void;
}) {
  return (
    <form
      onSubmit={e => { e.preventDefault(); onContinue(); }}
      className="space-y-6"
      data-reveal
    >
      <div>
        <h2 className="text-lg font-medium text-text-primary">Your details</h2>
        <p className="mt-1 text-sm text-text-secondary">We just need a little information to confirm your appointment.</p>
      </div>

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="attendee_name" label="Name" value={form.attendee_name} onChange={v => onChange({ attendee_name: v })} required />
        <Field id="attendee_email" label="Work email" type="email" value={form.attendee_email} onChange={v => onChange({ attendee_email: v })} required />
        <Field id="attendee_company" label="Company" value={form.attendee_company} onChange={v => onChange({ attendee_company: v })} />
        <Field id="attendee_phone" label="Phone" type="tel" value={form.attendee_phone} onChange={v => onChange({ attendee_phone: v })} />
      </div>

      <div className="flex flex-col gap-2">
        <label htmlFor="attendee_message" className="text-sm font-medium text-text-primary">Anything you&apos;d like us to cover?</label>
        <textarea
          id="attendee_message"
          rows={4}
          value={form.attendee_message}
          onChange={e => onChange({ attendee_message: e.target.value })}
          className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light"
        />
      </div>

      {/* Honeypot — hidden from real visitors via layout, never a tab stop. */}
      <div aria-hidden="true" className="absolute left-[-9999px] h-0 w-0 overflow-hidden">
        <label htmlFor="website">Website</label>
        <input id="website" name="website" type="text" tabIndex={-1} autoComplete="off" value={form.website} onChange={e => onChange({ website: e.target.value })} />
      </div>

      <label className="flex items-start gap-3 text-sm text-text-secondary">
        <input type="checkbox" required checked={form.consent} onChange={e => onChange({ consent: e.target.checked })} className="mt-1" />
        I agree to be contacted by SureSign about this booking.
      </label>

      <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
        <button
          type="button"
          onClick={onBack}
          className="rounded-full border border-border px-6 py-3 text-sm font-medium text-text-primary transition-colors hover:border-border-light"
        >
          Back
        </button>
        <button
          type="submit"
          className="rounded-full bg-accent px-6 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
        >
          Review Booking
        </button>
      </div>
    </form>
  );
}
