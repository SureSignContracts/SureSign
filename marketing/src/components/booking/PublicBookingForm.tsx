'use client';

import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { ShieldCheck, RefreshCw } from 'lucide-react';
import { getIanaTimezones, detectBrowserTimezone } from '@/lib/timezones';
import { addDaysIso } from '@/lib/calendarDate';
import { HeroReveal } from '@/components/hero/HeroReveal';
import { BookingCalendar } from './BookingCalendar';
import { TimeSlotGrid, TimeSlotSkeleton, TimeSlotEmptyState, type Slot } from './TimeSlotGrid';
import { BookingProgress, type BookingStage } from './BookingProgress';
import { BookingSummaryCard } from './BookingSummaryCard';
import { PersonalDetailsStep, EMPTY_CONTACT_FORM, type ContactFormData } from './PersonalDetailsStep';
import { ReviewStep } from './ReviewStep';
import { BookingSuccess } from './BookingSuccess';

const API_BASE = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

// Recognised by the backend (PublicAppointmentController::KNOWN_SOURCES) —
// anything else the visitor's ?src= carries is normalised server-side to
// 'public_booking_page' rather than rejected.
const SOURCE_FROM_PATH: Record<string, string> = {
  home: 'marketing_homepage',
  nav: 'marketing_navigation',
  pricing: 'pricing_page',
  contact: 'contact_page',
};

interface TypeInfo {
  name: string;
  public_title: string;
  public_description: string | null;
  duration_minutes: number;
  meeting_method: string;
  requires_confirmation: boolean;
  min_notice_hours: number;
  max_advance_days: number;
  scheduling_mode: 'fixed' | 'manual';
}

type TypeStatus = 'loading' | 'ready' | 'not_found' | 'network';

export function PublicBookingForm({ slug }: { slug: string }) {
  const searchParams = useSearchParams();

  const [typeStatus, setTypeStatus] = useState<TypeStatus>('loading');
  const [type, setType] = useState<TypeInfo | null>(null);
  const [stage, setStage] = useState<BookingStage>('Choose Time');
  const [result, setResult] = useState<{ status: string } | null>(null);

  const [timezone, setTimezone] = useState(detectBrowserTimezone());
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [schedulingMode, setSchedulingMode] = useState<'fixed' | 'manual' | null>(null);
  const [slotsLoading, setSlotsLoading] = useState(false);
  // The chosen slot's OWN date/time as the backend returned it (already
  // labelled in `timezone`) — authoritative for display and submission.
  // This can differ from `selectedDate` right at a timezone's midnight
  // boundary, which is exactly why it's tracked separately rather than
  // reusing `selectedDate` for the booked date.
  const [selectedSlot, setSelectedSlot] = useState<Slot | null>(null);

  const [bookableDates, setBookableDates] = useState<Set<string>>(new Set());
  const [availabilityLoading, setAvailabilityLoading] = useState(false);
  const availabilityAbortRef = useRef<AbortController | null>(null);
  const lastAvailabilityMonthRef = useRef<{ year: number; month: number } | null>(null);
  // Since the calendar only ever lets a visitor click a date the backend
  // already reported as bookable, the ONLY way `selectedDate` can later
  // resolve to zero slots is a genuine race — someone else booked the last
  // slot between the calendar snapshot and this fetch. Surface that
  // explicitly rather than leaving a stale selection with a silent empty list.
  const [dateBecameUnavailable, setDateBecameUnavailable] = useState(false);

  const [contact, setContact] = useState<ContactFormData>(EMPTY_CONTACT_FORM);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

  const abortRef = useRef<AbortController | null>(null);

  // today/maxDate bound the calendar — new Date()/Date.now() must not be
  // called directly during render, so they're computed inside effects and
  // held as state rather than as plain render-body/useMemo values.
  const [today, setToday] = useState('');
  const [maxDate, setMaxDate] = useState<string | undefined>(undefined);

  useEffect(() => {
    setToday(new Date().toISOString().split('T')[0]);
  }, []);

  useEffect(() => {
    if (!type || !today) { setMaxDate(undefined); return; }
    setMaxDate(addDaysIso(today, type.max_advance_days));
  }, [type, today]);

  function loadType() {
    setTypeStatus('loading');
    fetch(`${API_BASE}/public/appointment-types/${slug}`, { headers: { Accept: 'application/json' } })
      .then(res => { if (!res.ok) throw new Error('not_found'); return res.json(); })
      .then((data: TypeInfo) => { setType(data); setTypeStatus('ready'); })
      .catch(err => setTypeStatus(err instanceof TypeError ? 'network' : 'not_found'));
  }

  useEffect(() => { loadType(); }, [slug]); // eslint-disable-line react-hooks/exhaustive-deps

  function loadSlots(date: string, tz: string) {
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    setSlotsLoading(true);
    setSelectedSlot(null);
    fetch(`${API_BASE}/public/appointment-types/${slug}/slots?date=${date}&timezone=${encodeURIComponent(tz)}`, {
      signal: controller.signal,
      headers: { Accept: 'application/json' },
    })
      .then(res => res.json())
      .then(data => {
        setSchedulingMode(data.scheduling_mode);
        const fetchedSlots: Slot[] = data.slots ?? [];
        setSlots(fetchedSlots);

        if (data.scheduling_mode === 'fixed' && fetchedSlots.length === 0) {
          // The calendar only ever offers dates the backend already
          // confirmed as bookable — an empty result here means availability
          // changed after that snapshot, not a normal "no slots" day.
          setDateBecameUnavailable(true);
          setSelectedDate(null);
          if (lastAvailabilityMonthRef.current) {
            loadAvailability(lastAvailabilityMonthRef.current.year, lastAvailabilityMonthRef.current.month);
          }
        }
      })
      .catch(() => { if (!controller.signal.aborted) setSlots([]); })
      .finally(() => { if (!controller.signal.aborted) setSlotsLoading(false); });
  }

  useEffect(() => {
    if (!type || type.scheduling_mode !== 'fixed' || !selectedDate) { setSlots([]); return; }
    loadSlots(selectedDate, timezone);
    return () => abortRef.current?.abort();
  }, [type, selectedDate, timezone, slug]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (type?.scheduling_mode === 'manual') setSchedulingMode('manual');
  }, [type]);

  // Changing the display timezone changes what a bare "HH:MM" slot label
  // means, so any slot picked under the previous timezone is no longer
  // trustworthy — clear it rather than silently keep a stale selection.
  // (The fixed-mode slots effect above already refetches fresh, correctly
  // labelled slots whenever `timezone` changes; this covers manual mode,
  // where there's no slots fetch to reset it.)
  useEffect(() => {
    if (schedulingMode === 'manual') setSelectedSlot(null);
  }, [timezone]); // eslint-disable-line react-hooks/exhaustive-deps

  function loadAvailability(year: number, month: number) {
    if (!type || type.scheduling_mode !== 'fixed') return;
    lastAvailabilityMonthRef.current = { year, month };

    availabilityAbortRef.current?.abort();
    const controller = new AbortController();
    availabilityAbortRef.current = controller;

    setAvailabilityLoading(true);
    fetch(`${API_BASE}/public/appointment-types/${slug}/availability?year=${year}&month=${month}&timezone=${encodeURIComponent(timezone)}`, {
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

  if (typeStatus === 'loading') {
    return (
      <div className="rounded-2xl border border-border bg-bg-surface p-8 sm:p-10">
        <div className="h-4 w-40 animate-pulse rounded bg-bg-elevated" />
        <div className="mt-6 h-7 w-2/3 animate-pulse rounded bg-bg-elevated" />
        <div className="mt-8 h-64 animate-pulse rounded-xl bg-bg-elevated" />
      </div>
    );
  }
  if (typeStatus === 'not_found') {
    return (
      <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center sm:p-14">
        <div className="text-lg font-medium text-text-primary">This booking page isn&apos;t available.</div>
        <p className="mt-2 text-sm text-text-secondary">Please check the link, or reach us directly.</p>
      </div>
    );
  }
  if (typeStatus === 'network') {
    return (
      <div className="rounded-2xl border border-border bg-bg-surface p-10 text-center sm:p-14">
        <div className="text-lg font-medium text-text-primary">We couldn&apos;t load this booking page.</div>
        <p className="mt-2 text-sm text-text-secondary">Check your connection and try again.</p>
        <button
          type="button"
          onClick={loadType}
          className="mt-6 inline-flex items-center gap-2 rounded-full border border-border px-5 py-2.5 text-sm font-medium text-text-primary transition-colors hover:border-border-light"
        >
          <RefreshCw className="h-4 w-4" strokeWidth={1.5} aria-hidden="true" />
          Try again
        </button>
      </div>
    );
  }
  if (!type) return null;

  // The slot's own date/time is authoritative once one is chosen (it can
  // shift a day from `selectedDate` right at a timezone boundary) — see
  // the `selectedSlot` state doc above.
  const displayDate = selectedSlot?.date ?? selectedDate;
  const displayTime = selectedSlot?.time ?? '';

  if (stage === 'Confirmation' && result) {
    return (
      <HeroReveal>
        <BookingSuccess
          status={result.status}
          title={type.public_title}
          dateIso={displayDate!}
          time={displayTime}
          timezone={timezone}
          durationMinutes={type.duration_minutes}
        />
      </HeroReveal>
    );
  }

  const canContinueSchedule = Boolean(selectedDate && selectedSlot);

  async function handleConfirm() {
    if (!selectedSlot) return;
    setSubmitting(true);
    setSubmitError(null);

    const source = searchParams.get('src') ? SOURCE_FROM_PATH[searchParams.get('src') as string] ?? 'public_booking_page' : 'public_booking_page';

    try {
      const res = await fetch(`${API_BASE}/public/appointment-types/${slug}/book`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({
          appointment_type_slug: slug,
          attendee_name: contact.attendee_name,
          attendee_email: contact.attendee_email,
          attendee_phone: contact.attendee_phone || undefined,
          attendee_company: contact.attendee_company || undefined,
          attendee_job_title: contact.attendee_job_title || undefined,
          attendee_message: contact.attendee_message || undefined,
          attendee_timezone: timezone,
          date: selectedSlot.date, start_time: selectedSlot.time, timezone,
          consent: contact.consent,
          source,
          website: contact.website,
        }),
      });
      const data = await res.json();

      if (res.status === 409) {
        setSubmitError("That time was just taken — please choose another.");
        setStage('Choose Time');
        if (selectedDate) loadSlots(selectedDate, timezone);
        setSubmitting(false);
        return;
      }
      if (!res.ok) {
        setSubmitError(data.message || 'Something went wrong — please try again.');
        setSubmitting(false);
        return;
      }

      setResult(data);
      setStage('Confirmation');
    } catch {
      setSubmitError("Something went wrong sending that — please try again, or reach us directly.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="space-y-8">
      <div data-reveal className="flex items-center gap-2 text-xs font-medium text-text-muted">
        <ShieldCheck className="h-3.5 w-3.5" strokeWidth={1.5} aria-hidden="true" />
        Trusted by construction professionals.
      </div>

      <div data-reveal>
        <BookingProgress current={stage} />
      </div>

      <div className="grid gap-8 lg:grid-cols-[1fr_360px] lg:items-start">
        <div className="order-2 lg:order-1">
          {stage === 'Choose Time' && (
            <HeroReveal>
            <div className="space-y-6">
              <div data-reveal>
                <h1 className="text-lg font-medium text-text-primary">{type.public_title}</h1>
                {type.public_description && <p className="mt-1 text-sm text-text-secondary">{type.public_description}</p>}
              </div>

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
                    onSelectDate={date => { setDateBecameUnavailable(false); setSelectedDate(date); }}
                    onMonthChange={loadAvailability}
                    restrictToBookable={type.scheduling_mode === 'fixed'}
                    bookableDates={bookableDates}
                    loadingAvailability={availabilityLoading}
                  />
                </div>
              )}

              {dateBecameUnavailable && (
                <p role="status" className="rounded-lg border border-border bg-bg-elevated px-4 py-3 text-sm text-text-secondary">
                  That date&apos;s availability just changed — please choose another date.
                </p>
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
                onContinue={() => setStage('Review')}
              />
            </HeroReveal>
          )}

          {stage === 'Review' && displayDate && selectedSlot && (
            <HeroReveal>
              <ReviewStep
                title={type.public_title}
                durationMinutes={type.duration_minutes}
                dateIso={displayDate}
                time={displayTime}
                timezone={timezone}
                contact={contact}
                onEditTime={() => setStage('Choose Time')}
                onEditDetails={() => setStage('Details')}
                onConfirm={handleConfirm}
                submitting={submitting}
                error={submitError}
              />
            </HeroReveal>
          )}
        </div>

        {stage !== 'Confirmation' && (
          <div className="order-1 lg:order-2" data-reveal>
            <BookingSummaryCard
              summary={{
                title: type.public_title,
                description: null,
                durationMinutes: type.duration_minutes,
                dateIso: displayDate,
                time: displayTime || null,
                timezone,
              }}
            />
          </div>
        )}
      </div>
    </div>
  );
}

export function PublicBookingFormReveal({ slug }: { slug: string }) {
  return (
    <HeroReveal>
      <PublicBookingForm slug={slug} />
    </HeroReveal>
  );
}
