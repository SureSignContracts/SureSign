'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation } from '@tanstack/react-query';
import {
  ArrowLeft, HeartHandshake, Check, Sunrise, Sun, Sunset, CalendarDays,
  UserRound, FileText, ShieldCheck, Clock, CheckCircle,
} from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { normalizeApiError } from '@/lib/normalizeApiError';
import { useAuthStore } from '@/store/authStore';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Select from '@/components/ui/Select';
import TimezoneSelect from '@/components/shared/TimezoneSelect';
import { INTERACTIVE, staggerDelay } from '@/lib/motion';

interface BookableService {
  id: number;
  code: string;
  display_name: string;
  public_description: string | null;
  price_minor_units: number | null;
  currency: string;
  is_introductory: boolean;
  appointment_type: { duration_minutes: number };
}

interface ServiceDetail {
  code: string;
  display_name: string;
  public_description: string | null;
  duration_minutes: number;
  min_notice_hours: number;
  max_advance_days: number;
  // The single source of truth for which scheduling UI renders — derived
  // server-side from the linked AppointmentType.assignment_mode, never
  // inferred client-side from the service itself. See
  // internal-docs/commercial/suresign-consultancy-specification-v1.md,
  // "Scheduling mode: manual vs. fixed booking UI".
  scheduling_mode: 'fixed' | 'manual';
}

interface Slot { date: string; time: string }

function formatPrice(s: BookableService): string {
  if (s.price_minor_units === null) return '';
  const symbol = s.currency === 'GBP' ? '£' : `${s.currency} `;
  return `${symbol}${(s.price_minor_units / 100).toFixed(2)}`;
}

function to12Hour(time: string): string {
  const [h, m] = time.split(':').map(Number);
  const period = h >= 12 ? 'PM' : 'AM';
  const hour12 = h % 12 === 0 ? 12 : h % 12;
  return `${hour12}:${String(m).padStart(2, '0')} ${period}`;
}

const SLOT_PERIODS = [
  { key: 'morning', label: 'Morning', icon: Sunrise, from: 0, to: 12 },
  { key: 'afternoon', label: 'Afternoon', icon: Sun, from: 12, to: 17 },
  { key: 'evening', label: 'Evening', icon: Sunset, from: 17, to: 24 },
] as const;

function groupSlotsByPeriod(slots: Slot[]) {
  return SLOT_PERIODS.map(period => ({
    ...period,
    slots: slots.filter(slot => {
      const hour = Number(slot.time.split(':')[0]);
      return hour >= period.from && hour < period.to;
    }),
  })).filter(period => period.slots.length > 0);
}

const EMPTY_ENQUIRY = {
  title: '', description: '', project_stage: '', contract_form: '', preferred_outcome: '',
};

export default function NewConsultationPage() {
  const router = useRouter();
  const currentUser = useAuthStore(s => s.user);

  const [serviceCode, setServiceCode] = useState('');
  const [date, setDate] = useState('');
  const [startTime, setStartTime] = useState('');
  const [timezone, setTimezone] = useState(
    currentUser?.effective_timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
  );
  const [attendeeName, setAttendeeName] = useState(currentUser?.name ?? '');
  const [attendeeEmail, setAttendeeEmail] = useState(currentUser?.email ?? '');
  const [attendeePhone, setAttendeePhone] = useState('');
  const [enquiry, setEnquiry] = useState(EMPTY_ENQUIRY);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data: services, isLoading, isError: servicesError, refetch: refetchServices } = useQuery({
    queryKey: ['consultations', 'bookable-services'],
    queryFn: () => api.get('/consultations/bookable-services').then(r => r.data as BookableService[]),
  });

  const selectedService = services?.find(s => s.code === serviceCode);

  const { data: serviceDetail } = useQuery({
    queryKey: ['consultations', 'service-detail', serviceCode],
    queryFn: () => api.get(`/consultations/services/${serviceCode}`).then(r => r.data as ServiceDetail),
    enabled: !!serviceCode,
  });

  const isFixedMode = serviceDetail?.scheduling_mode === 'fixed';

  const { data: slotsData, isFetching: slotsLoading } = useQuery({
    queryKey: ['consultations', 'slots', serviceCode, date, timezone],
    queryFn: () => api.get(`/consultations/services/${serviceCode}/slots`, { params: { date, timezone } }).then(r => r.data as { slots: Slot[] }),
    enabled: isFixedMode && !!date,
  });
  const slots = slotsData?.slots ?? [];

  const bookMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => api.post('/consultations', payload).then(r => r.data),
    onSuccess: (appointment) => {
      toast.success('Consultation booked.');
      router.push(`/app/consultations/${appointment.id}`);
    },
    onError: (err: unknown) => {
      const normalized = normalizeApiError(err, 'Failed to book that consultation.');
      setErrors(normalized.fieldErrors ?? {});
      // Field errors already render inline next to each input above — only
      // toast when there's nothing field-specific to show (network/server/
      // conflict/permission failures all still surface their own specific
      // message here).
      if (!normalized.fieldErrors) {
        toast.error(normalized.message);
      }
    },
  });

  function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    bookMutation.mutate({
      consultancy_service_code: serviceCode,
      attendee_name: attendeeName,
      attendee_email: attendeeEmail,
      attendee_phone: attendeePhone || undefined,
      attendee_timezone: timezone,
      date,
      start_time: startTime,
      timezone,
      title: enquiry.title,
      description: enquiry.description,
      project_stage: enquiry.project_stage || undefined,
      contract_form: enquiry.contract_form || undefined,
      preferred_outcome: enquiry.preferred_outcome || undefined,
    });
  }

  const canSubmit = !!serviceCode && !!date && !!startTime;

  return (
    <div className="ss-project-setup mx-auto max-w-6xl space-y-6 p-4 sm:p-6 lg:py-9">
      <Link href="/app/consultations" className="ss-animate-in inline-flex items-center gap-1.5 rounded-lg px-1 py-1 text-sm font-medium transition-all duration-200 hover:-translate-x-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style={{ color: 'var(--text-secondary)' }}>
        <ArrowLeft size={14} /> Back to consultancy
      </Link>

      <div className="grid items-start gap-6 lg:grid-cols-[0.72fr_1.28fr]">
        <aside className="ss-animate-in overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5] lg:sticky lg:top-6" style={{ animationDelay: '40ms' }}>
          <div className="relative overflow-hidden p-7 sm:p-9">
            <div className="absolute -right-24 -top-28 h-72 w-72 rounded-full border border-[#a5d6b5]/10" />
            <div className="relative">
              <div className="mb-8 flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
                <HeartHandshake size={21} />
              </div>
              <h1 className="text-3xl font-semibold leading-tight tracking-[-0.04em] sm:text-4xl">Book focused time with a construction professional.</h1>
              <p className="mt-4 text-sm leading-6 text-[#b9c5bf]">
                Share the issue beforehand so your consultant can prepare for a useful, practical conversation.
              </p>

              <div className="mt-9 space-y-5">
                {[
                  ['Choose a session', 'Select the advice format and a suitable time.'],
                  ['Explain the issue', 'Add enough context for the consultant to prepare.'],
                  ['Receive confirmation', 'Your appointment and meeting details stay in SureSign.'],
                ].map(([title, body], index) => (
                  <div key={title} className="ss-animate-in flex gap-3" style={{ animationDelay: `${170 + (index * 65)}ms` }}>
                    <CheckCircle size={17} className="mt-0.5 flex-shrink-0 text-[#9ee5b5]" />
                    <div>
                      <p className="text-sm font-semibold">{title}</p>
                      <p className="mt-0.5 text-xs leading-5 text-[#91a099]">{body}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>

          {selectedService && (
            <div className="ss-animate-in border-t border-[#a5d6b5]/10 bg-[#202c26] p-6" style={{ animationDuration: '300ms' }}>
              <p className="text-xs font-medium text-[#91a099]">Selected consultation</p>
              <p className="mt-2 text-sm font-semibold">{selectedService.display_name}</p>
              <div className="mt-3 flex flex-wrap gap-x-4 gap-y-2 text-xs text-[#b9c5bf]">
                <span className="flex items-center gap-1.5"><Clock size={13} /> {selectedService.appointment_type.duration_minutes} minutes</span>
                {formatPrice(selectedService) && <span>{formatPrice(selectedService)}</span>}
              </div>
            </div>
          )}
        </aside>

        <main>

      {servicesError && (
        <div className="mb-5 flex items-center justify-between gap-3 rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <p className="text-sm" style={{ color: '#f87171' }}>We couldn&apos;t load consultation types. Please try again.</p>
          <Button type="button" variant="secondary" size="sm" onClick={() => refetchServices()}>Retry</Button>
        </div>
      )}

      <form
        onSubmit={handleSubmit}
        className="ss-animate-in overflow-hidden rounded-2xl"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '110ms' }}
      >
        <div className="p-6 sm:p-8">
          <SectionHeading icon={<CalendarDays size={17} />} title="Choose your session" description="Select the type of advice and when you would like to meet." />

          <div className="mt-6 space-y-5">
        <div className="space-y-1.5">
          <label htmlFor="service" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Consultation type</label>
          <Select id="service" value={serviceCode} onChange={e => { setServiceCode(e.target.value); setDate(''); setStartTime(''); }} required className="w-full" disabled={isLoading || servicesError}>
            <option value="">{isLoading ? 'Loading services…' : 'Select a consultation'}</option>
            {services?.map(s => (
              <option key={s.code} value={s.code}>
                {s.display_name}, {s.appointment_type.duration_minutes} min{formatPrice(s) ? `, ${formatPrice(s)}` : ''}
              </option>
            ))}
          </Select>
          {selectedService?.public_description && (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{selectedService.public_description}</p>
          )}
        </div>

        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <label htmlFor="date" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Date</label>
            <Input id="date" type="date" value={date} onChange={e => { setDate(e.target.value); setStartTime(''); }} required disabled={!serviceCode} error={errors.date?.[0]} />
          </div>
          <div className="space-y-1.5">
            <label htmlFor="start_time" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
              {isFixedMode ? 'Available times' : 'Preferred time'}
            </label>
            {isFixedMode ? (
              <div id="start_time" role="group" aria-label="Available times">
                {!date ? (
                  <p className="text-xs pt-2.5" style={{ color: 'var(--text-muted)' }}>Choose a date first.</p>
                ) : slotsLoading ? (
                  <div className="flex flex-wrap gap-2 pt-0.5" aria-busy="true" aria-live="polite">
                    <span className="sr-only">Loading available times…</span>
                    {[...Array(6)].map((_, i) => (
                      <div key={i} className="h-9 w-20 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
                    ))}
                  </div>
                ) : slots.length === 0 ? (
                  <p className="pt-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>No times are available that day. Try another date.</p>
                ) : (
                  <div className="space-y-3">
                    {groupSlotsByPeriod(slots).map((period, groupIdx) => (
                      <div
                        key={period.key}
                        className="ss-animate-in space-y-1.5"
                        style={{ animationDelay: staggerDelay(groupIdx) }}
                      >
                        <div className="flex items-center gap-1.5 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
                          <period.icon size={12} /> {period.label}
                        </div>
                        <div className="flex flex-wrap gap-2">
                          {period.slots.map((slot, slotIdx) => {
                            const selected = startTime === slot.time;
                            return (
                              <button
                                key={slot.time}
                                type="button"
                                aria-pressed={selected}
                                onClick={() => setStartTime(slot.time)}
                                className={`ss-animate-in inline-flex items-center gap-1 px-3 py-2 rounded-lg text-sm font-medium ${INTERACTIVE}`}
                                style={{
                                  border: `1px solid ${selected ? 'var(--gold)' : 'var(--border)'}`,
                                  backgroundColor: selected ? 'var(--gold-15)' : 'var(--bg-elevated)',
                                  color: selected ? 'var(--gold)' : 'var(--text-primary)',
                                  animationDelay: staggerDelay(slotIdx),
                                }}
                              >
                                {selected && <Check size={13} />}
                                {to12Hour(slot.time)}
                              </button>
                            );
                          })}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </div>
            ) : (
              <Input id="start_time" type="time" value={startTime} onChange={e => setStartTime(e.target.value)} required disabled={!serviceCode} error={errors.start_time?.[0]} />
            )}
          </div>
        </div>

        <div className="space-y-1.5">
          <label htmlFor="timezone" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Timezone</label>
          <TimezoneSelect id="timezone" value={timezone} onChange={setTimezone} className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none" />
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {isFixedMode ? 'Times above are shown in this timezone.' : "We'll confirm the exact time with you shortly."}
          </p>
        </div>
          </div>
        </div>

        <div className="border-t p-6 sm:p-8" style={{ borderColor: 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}>
          <SectionHeading icon={<UserRound size={17} />} title="Your contact details" description="We will use these details for appointment updates." />

          <div className="mt-6 space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <label htmlFor="attendee_name" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Your name</label>
            <Input id="attendee_name" value={attendeeName} onChange={e => setAttendeeName(e.target.value)} required error={errors.attendee_name?.[0]} />
          </div>
          <div className="space-y-1.5">
            <label htmlFor="attendee_email" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Your email</label>
            <Input id="attendee_email" type="email" value={attendeeEmail} onChange={e => setAttendeeEmail(e.target.value)} required error={errors.attendee_email?.[0]} />
          </div>
        </div>
        <div className="space-y-1.5">
          <label htmlFor="attendee_phone" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Phone <span style={{ color: 'var(--text-muted)' }}>(optional)</span></label>
          <Input id="attendee_phone" value={attendeePhone} onChange={e => setAttendeePhone(e.target.value)} />
        </div>
          </div>
        </div>

        <div className="border-t p-6 sm:p-8" style={{ borderColor: 'var(--border)' }}>
          <SectionHeading icon={<FileText size={17} />} title="What do you need help with?" description="Give your consultant the context they need to prepare." />

          <div className="mt-6 space-y-4">
        <div className="space-y-1.5">
          <label htmlFor="enquiry_title" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Enquiry title</label>
          <Input id="enquiry_title" placeholder="e.g. Pay less notice dispute" value={enquiry.title} onChange={e => setEnquiry(f => ({ ...f, title: e.target.value }))} required error={errors.title?.[0]} />
        </div>
        <div className="space-y-1.5">
          <label htmlFor="enquiry_description" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>What would you like to discuss?</label>
          <textarea
            id="enquiry_description"
            value={enquiry.description}
            onChange={e => setEnquiry(f => ({ ...f, description: e.target.value }))}
            required
            rows={4}
            className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none resize-none focus:ring-2 focus:ring-[var(--gold)]/30"
            style={{ backgroundColor: 'var(--bg-elevated)', border: `1px solid ${errors.description ? '#ef4444' : 'var(--border)'}`, color: 'var(--text-primary)' }}
          />
          {errors.description?.[0] && <p className="text-xs" style={{ color: '#ef4444' }}>{errors.description[0]}</p>}
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <label htmlFor="project_stage" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Project stage <span style={{ color: 'var(--text-muted)' }}>(optional)</span></label>
            <Input id="project_stage" value={enquiry.project_stage} onChange={e => setEnquiry(f => ({ ...f, project_stage: e.target.value }))} />
          </div>
          <div className="space-y-1.5">
            <label htmlFor="contract_form" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Contract form <span style={{ color: 'var(--text-muted)' }}>(e.g. NEC, JCT)</span></label>
            <Input id="contract_form" value={enquiry.contract_form} onChange={e => setEnquiry(f => ({ ...f, contract_form: e.target.value }))} />
          </div>
        </div>
        <div className="space-y-1.5">
          <label htmlFor="preferred_outcome" className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Preferred outcome <span style={{ color: 'var(--text-muted)' }}>(optional)</span></label>
          <Input id="preferred_outcome" value={enquiry.preferred_outcome} onChange={e => setEnquiry(f => ({ ...f, preferred_outcome: e.target.value }))} />
        </div>
          </div>
        </div>

        <div className="flex flex-col gap-4 border-t p-6 sm:flex-row sm:items-center sm:justify-between sm:px-8" style={{ borderColor: 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}>
          <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
            <ShieldCheck size={15} /> Your enquiry is private to the consultancy team.
          </div>
          <Button type="submit" size="lg" className="min-w-44" disabled={bookMutation.isPending || !canSubmit}>
            {bookMutation.isPending ? 'Booking…' : 'Book consultation'}
          </Button>
        </div>
      </form>
        </main>
      </div>
    </div>
  );
}

function SectionHeading({ icon, title, description }: { icon: React.ReactNode; title: string; description: string }) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>{icon}</span>
      <div>
        <h2 className="text-base font-semibold tracking-[-0.015em]" style={{ color: 'var(--text-primary)' }}>{title}</h2>
        <p className="mt-0.5 text-sm leading-5" style={{ color: 'var(--text-muted)' }}>{description}</p>
      </div>
    </div>
  );
}
