'use client';

export interface EnquiryFormData {
  title: string;
  description: string;
  project_stage: string;
  contract_form: string;
  preferred_outcome: string;
}

export const EMPTY_ENQUIRY_FORM: EnquiryFormData = {
  title: '', description: '', project_stage: '', contract_form: '', preferred_outcome: '',
};

function Field({
  label, value, onChange, id, textarea = false, required = false,
}: {
  label: string; value: string; onChange: (v: string) => void; id: string; textarea?: boolean; required?: boolean;
}) {
  const className = 'rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light';
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-sm font-medium text-text-primary">{label}</label>
      {textarea ? (
        <textarea id={id} rows={4} required={required} value={value} onChange={e => onChange(e.target.value)} className={className} />
      ) : (
        <input id={id} required={required} value={value} onChange={e => onChange(e.target.value)} className={className} />
      )}
    </div>
  );
}

export function EnquiryStep({
  form,
  onChange,
  onBack,
  onContinue,
}: {
  form: EnquiryFormData;
  onChange: (patch: Partial<EnquiryFormData>) => void;
  onBack: () => void;
  onContinue: () => void;
}) {
  return (
    <form onSubmit={e => { e.preventDefault(); onContinue(); }} className="space-y-6" data-reveal>
      <div>
        <h2 className="text-lg font-medium text-text-primary">Tell us about your enquiry</h2>
        <p className="mt-1 text-sm text-text-secondary">
          A short description helps our consultant prepare for your consultation. This is not a substitute
          for uploading documents or a formal review — just enough for a useful conversation.
        </p>
      </div>

      <Field id="enquiry_title" label="Title" value={form.title} onChange={v => onChange({ title: v })} required />
      <Field id="enquiry_description" label="What would you like to discuss?" value={form.description} onChange={v => onChange({ description: v })} textarea required />

      <div className="grid gap-5 sm:grid-cols-2">
        <Field id="project_stage" label="Project stage (optional)" value={form.project_stage} onChange={v => onChange({ project_stage: v })} />
        <Field id="contract_form" label="Contract form (optional, e.g. NEC, JCT, FIDIC)" value={form.contract_form} onChange={v => onChange({ contract_form: v })} />
      </div>
      <Field id="preferred_outcome" label="Preferred outcome (optional)" value={form.preferred_outcome} onChange={v => onChange({ preferred_outcome: v })} />

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
