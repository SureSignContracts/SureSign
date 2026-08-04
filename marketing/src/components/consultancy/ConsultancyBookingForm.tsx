'use client';

import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { ShieldCheck, RefreshCw } from 'lucide-react';
import { getIanaTimezones, detectBrowserTimezone } from '@/lib/timezones';
import { addDaysIso } from '@/lib/calendarDate';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { BookingCalendar } from '../booking/BookingCalendar';
import { TimeSlotGrid, TimeSlotSkeleton, TimeSlotEmptyState, type Slot } from '../booking/TimeSlotGrid';
import { PersonalDetailsStep, EMPTY_CONTACT_FORM, type ContactFormData } from '../booking/PersonalDetailsStep';
import { BookingSuccess } from '../booking/BookingSuccess';
import { ConsultancyBookingProgress, type ConsultancyBookingStage } from './ConsultancyBookingProgress';
import { ConsultancySummaryCard } from './ConsultancySummaryCard';
import { EnquiryStep, EMPTY_ENQUIRY_FORM, type EnquiryFormData } from './EnquiryStep';
import { ConsultationReviewStep } from './ConsultationReviewStep';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

// Recognised by the backend (PublicConsultationController::KNOWN_SOURCES) —
// anything else the visitor's ?src= carries is normalised server-side.
const SOURCE_FROM_PATH: Record<string, string> = {
  home: 'marketing_homepage',
  nav: 'marketing_navigation',
  pricing: 'pricing_page',
  contact: 'contact_page',
  consultancy: 'consultancy_page',
};

interface ServiceInfo {
  display_name: string;
  public_description: string | null;
  duration_minutes: number;
  meeting_method: string;
  requires_confirmation: boolean;
  min_notice_hours: number;
  max_advance_days: number;
  price_minor_units: number | null;
  currency: string;
  is_introductory: boolean;
  scheduling_mode: 'fixed' | 'manual';
}

type ServiceStatus = 'loading' | 'ready' | 'not_found' | 'network';

function formatPrice(service: ServiceInfo): string | null {
  if (service.price_minor_units === null) return null;
  const symbol = service.currency === 'GBP' ? '£' : `${service.currency} `;
  return `${symbol}${(service.price_minor_units / 100).toFixed(2)}`;
}

export function ConsultancyBookingForm({ code }: { code: string }) {
  const searchParams = useSearchParams();

  const [serviceStatus, setServiceStatus] = useState<ServiceStatus>('loading');
  const [service, setService] = useState<ServiceInfo | null>(null);
  const [stage, setStage] = useState<ConsultancyBookingStage>('Choose Time');
  const [result, setResult] = useState<{ status: string } | null>(null);

  const [timezone, setTimezone] = useState(detectBrowserTimezone());
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [schedulingMode, setSchedulingMode] = useState<'fixed' | 'manual' | null>(null);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [selectedSlot, setSelectedSlot] = useState<Slot | null>(null);

  const [contact, setContact] = useState<ContactFormData>(EMPTY_CONTACT_FORM);
  const [enquiry, setEnquiry] = useState<EnquiryFormData>(EMPTY_ENQUIRY_FORM);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const abortRef = useRef<AbortController | null>(null);
  const [today, setToday] = useState('');

  const [bookableDates, setBookableDates] = useState<Set<string>>(new Set());
  const [availabilityLoading, setAvailabilityLoading] = useState(false);
  const availabilityAbortRef = useRef<AbortController | null>(null);
  const lastAvailabilityMonthRef = useRef<{ year: number; month: number } | null>(null);

  useEffect(() => {
    setToday(new Date().toISOString().split('T')[0]); // eslint-disable-line react-hooks/set-state-in-effect
  }, []);

  const maxDate = service && today ? addDaysIso(today, service.max_advance_days) : undefined;

  function loadService() {
    setServiceStatus('loading');
    fetch(`${API_BASE}/public/consultancy-services/${code}`, { headers: { Accept: 'application/json' } })
      .then(res => { if (!res.ok) throw new Error('not_found'); return res.json(); })
      .then((data: ServiceInfo) => { setService(data); setServiceStatus('ready'); })
      .catch(err => setServiceStatus(err instanceof TypeError ? 'network' : 'not_found'));
  }

  useEffect(() => { loadService(); }, [code]); // eslint-disable-line react-hooks/exhaustive-deps, react-hooks/set-state-in-effect

  function loadSlots(date: string, tz: string) {
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    setSlotsLoading(true);
    setSelectedSlot(null);
    fetch(`${API_BASE}/public/consultancy-services/${code}/slots?date=${date}&timezone=${encodeURIComponent(tz)}`, {
      signal: controller.signal,
      headers: { Accept: 'application/json' },
    })
      .then(res => res.json())
      .then(data => {
        setSchedulingMode(data.scheduling_mode);
        setSlots(data.slots ?? []);
      })
      .catch(() => { if (!controller.signal.aborted) setSlots([]); })
      .finally(() => { if (!controller.signal.aborted) setSlotsLoading(false); });
  }

  useEffect(() => {
    if (!service || service.scheduling_mode !== 'fixed' || !selectedDate) { setSlots([]); return; } // eslint-disable-line react-hooks/set-state-in-effect
    loadSlots(selectedDate, timezone);
    return () => abortRef.current?.abort();
  }, [service, selectedDate, timezone, code]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (service?.scheduling_mode === 'manual') setSchedulingMode('manual'); // eslint-disable-line react-hooks/set-state-in-effect
  }, [service]);

  useEffect(() => {
    if (schedulingMode === 'manual') setSelectedSlot(null); // eslint-disable-line react-hooks/set-state-in-effect
  }, [timezone]); // eslint-disable-line react-hooks/exhaustive-deps

  // The month-level bookable-dates fetch that colours the calendar and
  // gates which dates are selectable at all (see restrictToBookable below)
  // — was previously missing entirely, so `bookableDates` never populated
  // and every date rendered as unavailable regardless of month.
  function loadAvailability(year: number, month: number) {
    if (!service || service.scheduling_mode !== 'fixed') return;
    lastAvailabilityMonthRef.current = { year, month };

    availabilityAbortRef.current?.abort();
    const controller = new AbortController();
    availabilityAbortRef.current = controller;

    setAvailabilityLoading(true);
    fetch(`${API_BASE}/public/consultancy-services/${code}/availability?year=${year}&month=${month}&timezone=${encodeURIComponent(timezone)}`, {
      signal: controller.signal,
      headers: { Accept: 'application/json' },
    })
      .then(res => res.json())
      .then(data => { if (!controller.signal.aborted) setBookableDates(new Set(data.dates ?? [])); })
      .catch(() => { if (!controller.signal.aborted) setBookableDates(new Set()); })
      .finally(() => { if (!controller.signal.aborted) setAvailabilityLoading(false); });
  }

  // Re-fetch the currently-viewed month if the visitor changes timezone —
  // otherwise the calendar's available/unavailable colouring would still
  // reflect the previous timezone's day-boundary shifts.
  useEffect(() => {
    if (lastAvailabilityMonthRef.current) {
      loadAvailability(lastAvailabilityMonthRef.current.year, lastAvailabilityMonthRef.current.month);
    }
  }, [timezone]); // eslint-disable-line react-hooks/exhaustive-deps

  if (serviceStatus === 'loading') {
    return (
      <div className="rounded-2xl border border-border bg-bg-surface p-8 sm:p-10">
        <div className="h-4 w-40 animate-pulse rounded bg-bg-elevated" />
        <div className="mt-6 h-7 w-2/3 animate-pulse rounded bg-bg-elevated" />
        <div className="mt-8 h-64 animate-pulse rounded-xl bg-bg-elevated" />
      </div>
    );
  }
  if (serviceStatus === 'not_found') {
    return (
      <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center sm:p-14">
        <div className="text-lg font-medium text-text-primary">This consultation isn&apos;t available.</div>
        <p className="mt-2 text-sm text-text-secondary">Please check the link, or reach us directly.</p>
      </div>
    );
  }
  if (serviceStatus === 'network') {
    return (
      <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center sm:p-14">
        <div className="text-lg font-medium text-text-primary">We couldn&apos;t load this page.</div>
        <p className="mt-2 text-sm text-text-secondary">Check your connection and try again.</p>
        <button
          type="button"
          onClick={loadService}
          className="mt-6 inline-flex items-center gap-2 rounded-full border border-border px-5 py-2.5 text-sm font-medium text-text-primary transition-colors hover:border-border-light"
        >
          <RefreshCw className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
          Try again
        </button>
      </div>
    );
  }
  if (!service) return null;

  const displayDate = selectedSlot?.date ?? selectedDate;
  const displayTime = selectedSlot?.time ?? '';
  const priceLabel = formatPrice(service);

  if (stage === 'Confirmation' && result) {
    return (
      <HeroReveal>
        <BookingSuccess
          status={result.status}
          title={service.display_name}
          dateIso={displayDate!}
          time={displayTime}
          timezone={timezone}
          durationMinutes={service.duration_minutes}
        />
      </HeroReveal>
    );
  }

  const canContinueSchedule = Boolean(selectedDate && selectedSlot);

  async function handleConfirm() {
    if (!selectedSlot) return;
    setSubmitting(true);
    setSubmitError(null);

    const source = searchParams.get('src') ? SOURCE_FROM_PATH[searchParams.get('src') as string] ?? 'consultancy_page' : 'consultancy_page';

    try {
      const res = await fetch(`${API_BASE}/public/consultancy-services/${code}/book`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          attendee_name: contact.attendee_name,
          attendee_email: contact.attendee_email,
          attendee_phone: contact.attendee_phone || undefined,
          attendee_company: contact.attendee_company || undefined,
          attendee_job_title: contact.attendee_job_title || undefined,
          attendee_timezone: timezone,
          date: selectedSlot.date, start_time: selectedSlot.time, timezone,
          title: enquiry.title,
          description: enquiry.description,
          project_stage: enquiry.project_stage || undefined,
          contract_form: enquiry.contract_form || undefined,
          preferred_outcome: enquiry.preferred_outcome || undefined,
          consent: contact.consent,
          source,
          website: contact.website,
        }),
      });
      const data = await res.json();

      if (res.status === 409) {
        setSubmitError('That time was just taken. Please choose another.');
        setStage('Choose Time');
        if (selectedDate) loadSlots(selectedDate, timezone);
        setSubmitting(false);
        return;
      }
      if (!res.ok) {
        setSubmitError(data.message || 'Something went wrong. Please try again.');
        setSubmitting(false);
        return;
      }

      setResult(data);
      setStage('Confirmation');
    } catch {
      setSubmitError('Something went wrong sending that. Please try again or reach us directly.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-8">
      <div data-reveal className="flex items-center gap-2 text-xs font-medium text-text-muted">
        <ShieldCheck className="h-3.5 w-3.5" strokeWidth={1.5} aria-hidden="true" />
        A real construction professional, not AI.
      </div>

      {/* A persistent h1 — previously only rendered during the "Choose
          Time" stage, leaving every later stage's own <h2> (in
          PersonalDetailsStep/EnquiryStep/ConsultationReviewStep) with no
          preceding h1 at all. Kept visible across every stage instead. */}
      <div data-reveal>
        <h1 className="text-lg font-medium text-text-primary">{service.display_name}</h1>
        {service.public_description && <p className="mt-1 text-sm text-text-secondary">{service.public_description}</p>}
      </div>

      <div data-reveal>
        <ConsultancyBookingProgress current={stage} />
      </div>

      <div className="grid gap-8 lg:grid-cols-[1fr_360px] lg:items-start">
        <div className="order-2 lg:order-1">
          {stage === 'Choose Time' && (
            <HeroReveal>
              <div className="space-y-6">
                <div className="flex flex-col gap-2" data-reveal>
                  <label htmlFor="timezone" className="text-sm font-medium text-text-primary">Timezone</label>
                  <select
                    id="timezone"
                    value={timezone}
                    onChange={e => setTimezone(e.target.value)}
                    className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light sm:max-w-sm"
                  >
                    {getIanaTimezones().map(tz => <option key={tz} value={tz}>{tz}</option>)}
                  </select>
                  <p className="text-xs text-text-secondary">All available times are shown in your local timezone.</p>
                </div>

                {today && (
                  <div data-reveal>
                    <BookingCalendar
                      todayIso={today}
                      maxDateIso={maxDate}
                      selectedDateIso={selectedDate}
                      onSelectDate={date => setSelectedDate(date)}
                      onMonthChange={loadAvailability}
                      restrictToBookable={service.scheduling_mode === 'fixed'}
                      bookableDates={bookableDates}
                      loadingAvailability={availabilityLoading}
                    />
                  </div>
                )}

                {selectedDate && (
                  <div>
                    <label className="text-sm font-medium text-text-primary">
                      Available times <span className="font-normal text-text-muted">({timezone})</span>
                    </label>
                    <div className="mt-3">
                      {schedulingMode === 'manual' ? (
                        <div className="flex flex-col gap-2 sm:max-w-xs">
                          <input
                            type="time"
                            value={selectedSlot?.time ?? ''}
                            onChange={e => setSelectedSlot(e.target.value ? { date: selectedDate, time: e.target.value } : null)}
                            className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light"
                          />
                          <p className="text-xs text-text-secondary">We&apos;ll confirm the exact time with you shortly.</p>
                        </div>
                      ) : slotsLoading ? (
                        <TimeSlotSkeleton />
                      ) : slots.length === 0 ? (
                        <TimeSlotEmptyState />
                      ) : (
                        <TimeSlotGrid slots={slots} selected={selectedSlot} onSelect={setSelectedSlot} referenceDate={selectedDate} />
                      )}
                    </div>
                  </div>
                )}

                <div className="flex justify-end pt-2" data-reveal>
                  <button
                    type="button"
                    disabled={!canContinueSchedule}
                    onClick={() => setStage('Details')}
                    className="rounded-full bg-accent px-6 py-3.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px disabled:pointer-events-none disabled:opacity-40"
                  >
                    Continue
                  </button>
                </div>
              </div>
            </HeroReveal>
          )}

          {stage === 'Details' && (
            <HeroReveal>
              <PersonalDetailsStep
                form={contact}
                onChange={patch => setContact(f => ({ ...f, ...patch }))}
                onBack={() => setStage('Choose Time')}
                onContinue={() => setStage('Enquiry')}
              />
            </HeroReveal>
          )}

          {stage === 'Enquiry' && (
            <HeroReveal>
              <EnquiryStep
                form={enquiry}
                onChange={patch => setEnquiry(f => ({ ...f, ...patch }))}
                onBack={() => setStage('Details')}
                onContinue={() => setStage('Review')}
              />
            </HeroReveal>
          )}

          {stage === 'Review' && displayDate && selectedSlot && (
            <HeroReveal>
              <ConsultationReviewStep
                serviceName={service.display_name}
                durationMinutes={service.duration_minutes}
                dateIso={displayDate}
                time={displayTime}
                timezone={timezone}
                contact={contact}
                enquiry={enquiry}
                onEditTime={() => setStage('Choose Time')}
                onEditDetails={() => setStage('Details')}
                onEditEnquiry={() => setStage('Enquiry')}
                onConfirm={handleConfirm}
                submitting={submitting}
                error={submitError}
              />
            </HeroReveal>
          )}
        </div>

        {stage !== 'Confirmation' && (
          <div className="order-1 lg:order-2" data-reveal>
            <ConsultancySummaryCard
              serviceName={service.display_name}
              durationMinutes={service.duration_minutes}
              priceLabel={priceLabel}
              dateIso={displayDate}
              time={displayTime || null}
              timezone={timezone}
            />
          </div>
        )}
      </div>
    </div>
  );
}

export function ConsultancyBookingFormReveal({ code }: { code: string }) {
  return (
    <HeroReveal>
      <ConsultancyBookingForm code={code} />
    </HeroReveal>
  );
}
