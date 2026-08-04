'use client';

import { formatFullDate } from '@/lib/calendarDate';
import type { ContactFormData } from '../booking/PersonalDetailsStep';
import type { EnquiryFormData } from './EnquiryStep';

function to12Hour(time: string): string {
  const [h, m] = time.split(':').map(Number);
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h % 12 === 0 ? 12 : h % 12;
  return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
}

function ReviewRow({ label, value }: { label: string; value?: string | null }) {
  if (!value) return null;
  return (
    <div className="flex items-baseline justify-between gap-4 py-2">
      <span className="text-xs text-text-muted">{label}</span>
      <span className="text-right text-sm text-text-primary">{value}</span>
    </div>
  );
}

export function ConsultationReviewStep({
  serviceName,
  durationMinutes,
  dateIso,
  time,
  timezone,
  contact,
  enquiry,
  onEditTime,
  onEditDetails,
  onEditEnquiry,
  onConfirm,
  submitting,
  error,
}: {
  serviceName: string;
  durationMinutes: number;
  dateIso: string;
  time: string;
  timezone: string;
  contact: ContactFormData;
  enquiry: EnquiryFormData;
  onEditTime: () => void;
  onEditDetails: () => void;
  onEditEnquiry: () => void;
  onConfirm: () => void;
  submitting: boolean;
  error: string | null;
}) {
  return (
    <div className="space-y-6" data-reveal>
      <div>
        <h2 className="text-lg font-medium text-text-primary">Review your consultation</h2>
        <p className="mt-1 text-sm text-text-secondary">Take a moment to check everything looks right.</p>
      </div>

      <div className="rounded-xl border border-border bg-bg-elevated p-5">
        <div className="flex items-center justify-between">
          <p className="text-sm font-medium text-text-primary">Consultation</p>
          <button type="button" onClick={onEditTime} className="text-xs font-medium text-text-secondary underline-offset-2 transition-colors hover:text-text-primary hover:underline">
            Edit
          </button>
        </div>
        <div className="mt-1 divide-y divide-border">
          <ReviewRow label="Service" value={serviceName} />
          <ReviewRow label="Date" value={formatFullDate(dateIso)} />
          <ReviewRow label="Time" value={`${to12Hour(time)} (${timezone})`} />
          <ReviewRow label="Duration" value={`${durationMinutes} minutes`} />
        </div>
      </div>

      <div className="rounded-xl border border-border bg-bg-elevated p-5">
        <div className="flex items-center justify-between">
          <p className="text-sm font-medium text-text-primary">Your details</p>
          <button type="button" onClick={onEditDetails} className="text-xs font-medium text-text-secondary underline-offset-2 transition-colors hover:text-text-primary hover:underline">
            Edit
          </button>
        </div>
        <div className="mt-1 divide-y divide-border">
          <ReviewRow label="Name" value={contact.attendee_name} />
          <ReviewRow label="Company" value={contact.attendee_company} />
          <ReviewRow label="Email" value={contact.attendee_email} />
          <ReviewRow label="Phone" value={contact.attendee_phone} />
        </div>
      </div>

      <div className="rounded-xl border border-border bg-bg-elevated p-5">
        <div className="flex items-center justify-between">
          <p className="text-sm font-medium text-text-primary">Your enquiry</p>
          <button type="button" onClick={onEditEnquiry} className="text-xs font-medium text-text-secondary underline-offset-2 transition-colors hover:text-text-primary hover:underline">
            Edit
          </button>
        </div>
        <div className="mt-1 divide-y divide-border">
          <ReviewRow label="Title" value={enquiry.title} />
          <ReviewRow label="Description" value={enquiry.description} />
          <ReviewRow label="Project stage" value={enquiry.project_stage} />
          <ReviewRow label="Contract form" value={enquiry.contract_form} />
          <ReviewRow label="Preferred outcome" value={enquiry.preferred_outcome} />
        </div>
      </div>

      {error && <p role="alert" className="text-sm text-text-primary">{error}</p>}

      <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
        <button
          type="button"
          onClick={onEditEnquiry}
          disabled={submitting}
          className="rounded-full border border-border px-6 py-3 text-sm font-medium text-text-primary transition-colors hover:border-border-light disabled:opacity-60"
        >
          Back
        </button>
        <button
          type="button"
          onClick={onConfirm}
          disabled={submitting}
          className="rounded-full bg-accent px-6 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px disabled:opacity-60"
        >
          {submitting ? 'Booking…' : 'Book Consultation'}
        </button>
      </div>
    </div>
  );
}
