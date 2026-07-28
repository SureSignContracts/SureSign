'use client';

import { useEffect, useRef, useState } from 'react';
import { fetchRescheduleSlots, submitReschedule } from '@/lib/publicAppointments';
import { formatDateInZone, formatTimeInZone } from '@/lib/appointmentFormat';
import type { AppointmentPublicView, Slot } from '@/lib/publicAppointments';

type Step = 'pick_date' | 'pick_slot' | 'review' | 'submitting' | 'done';

function slotsEqual(a: Slot | null, b: Slot): boolean {
  return a !== null && a.date === b.date && a.time === b.time;
}

export function RescheduleFlow({
  token,
  searchParams,
  appointment,
  onRescheduled,
}: {
  token: string;
  searchParams: URLSearchParams;
  appointment: AppointmentPublicView;
  onRescheduled: (updated: AppointmentPublicView) => void;
}) {
  const [step, setStep] = useState<Step>('pick_date');
  const [today, setToday] = useState('');
  const [date, setDate] = useState('');
  const [schedulingMode, setSchedulingMode] = useState<'fixed' | 'manual' | null>(null);
  // Reuse the visitor's original booking timezone rather than asking again —
  // and, critically, send it to the backend so returned slot labels are in
  // THIS timezone, not the staff member's (see generateAvailableSlots()'s
  // own doc for why that distinction matters).
  const [slotsTimezone, setSlotsTimezone] = useState(appointment.booking_timezone);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [slotsLoading, setSlotsLoading] = useState(false);
  const [slotsError, setSlotsError] = useState<string | null>(null);
  const [selectedSlot, setSelectedSlot] = useState<Slot | null>(null);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const submittingRef = useRef(false);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    setToday(new Date().toISOString().split('T')[0]); // eslint-disable-line react-hooks/set-state-in-effect
  }, []);

  useEffect(() => {
    if (!date || !appointment.reschedule_slots_url) return;
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    // eslint-disable-next-line react-hooks/set-state-in-effect
    setSlotsLoading(true);
    setSlotsError(null);
    setSelectedSlot(null);

    fetchRescheduleSlots(appointment.reschedule_slots_url, date, appointment.booking_timezone, controller.signal).then(result => {
      if (controller.signal.aborted) return;
      setSlotsLoading(false);
      if (!result.ok) {
        setSlotsError(result.message);
        setSlots([]);
        return;
      }
      setSchedulingMode(result.data.scheduling_mode);
      setSlots(result.data.slots ?? []);
      if (result.data.timezone) setSlotsTimezone(result.data.timezone);
    });

    return () => controller.abort();
  }, [date, appointment.reschedule_slots_url, appointment.booking_timezone]);

  if (!appointment.can_reschedule) {
    return (
      <p role="status" className="rounded-xl border border-border bg-bg-elevated px-5 py-4 text-sm text-text-secondary">
        This appointment can no longer be rescheduled online. Please contact us directly.
      </p>
    );
  }

  if (step === 'done') {
    return (
      <p role="status" className="rounded-xl border border-border bg-bg-elevated px-5 py-4 text-sm text-text-secondary">
        Your appointment has been rescheduled.
      </p>
    );
  }

  async function handleConfirm() {
    if (submittingRef.current || !selectedSlot) return;
    submittingRef.current = true;
    setStep('submitting');
    setSubmitError(null);

    const result = await submitReschedule(token, searchParams, {
      date: selectedSlot.date, start_time: selectedSlot.time, timezone: slotsTimezone,
    });
    submittingRef.current = false;

    if (!result.ok) {
      setSubmitError(result.message);
      setStep(result.kind === 'conflict' ? 'pick_slot' : 'review');
      if (result.kind === 'conflict') setSelectedSlot(null);
      return;
    }

    setStep('done');
    onRescheduled(result.data);
  }

  return (
    <div className="rounded-2xl border border-border bg-bg-elevated p-6 sm:p-8">
      <h2 className="text-base font-medium text-text-primary">Choose a new time</h2>

      <div className="mt-5 flex flex-col gap-2">
        <label htmlFor="reschedule-date" className="text-sm font-medium text-text-primary">Date</label>
        <input
          id="reschedule-date"
          type="date"
          required
          min={today}
          value={date}
          disabled={step === 'submitting'}
          onChange={e => { setDate(e.target.value); setStep('pick_slot'); }}
          className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light disabled:opacity-60 sm:max-w-xs"
        />
      </div>

      {date && (
        <div className="mt-5 space-y-2">
          <label className="text-sm font-medium text-text-primary">
            Available times <span className="font-normal text-text-muted">({slotsTimezone})</span>
          </label>

          {slotsLoading && <p className="text-sm text-text-secondary">Loading times…</p>}

          {!slotsLoading && slotsError && (
            <p role="alert" className="text-sm text-text-primary">{slotsError}</p>
          )}

          {!slotsLoading && !slotsError && schedulingMode === 'fixed' && slots.length === 0 && (
            <p className="text-sm text-text-secondary">No times available on this date. Try another day.</p>
          )}

          {!slotsLoading && !slotsError && schedulingMode === 'fixed' && slots.length > 0 && (
            <div className="flex flex-wrap gap-2" role="group" aria-label="Available times">
              {slots.map(slot => {
                const isSelected = slotsEqual(selectedSlot, slot);
                const crossesDay = slot.date !== date;
                return (
                  <button
                    key={`${slot.date}T${slot.time}`}
                    type="button"
                    aria-pressed={isSelected}
                    onClick={() => { setSelectedSlot(slot); setStep('review'); }}
                    className={`flex flex-col items-center rounded-full border px-4 py-2 text-sm transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-text-primary ${
                      isSelected ? 'border-accent bg-accent text-accent-fg' : 'border-border text-text-primary hover:border-border-light'
                    }`}
                  >
                    {slot.time}
                    {crossesDay && (
                      <span className={`text-[10px] font-normal ${isSelected ? 'text-accent-fg/80' : 'text-text-muted'}`}>
                        {new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(new Date(`${slot.date}T00:00:00`))}
                      </span>
                    )}
                  </button>
                );
              })}
            </div>
          )}

          {!slotsLoading && !slotsError && schedulingMode === 'manual' && (
            <div className="flex flex-col gap-2">
              <label htmlFor="reschedule-time" className="sr-only">Preferred time</label>
              <input
                id="reschedule-time"
                type="time"
                value={selectedSlot?.time ?? ''}
                onChange={e => {
                  const time = e.target.value;
                  setSelectedSlot(time ? { date, time } : null);
                  if (time) setStep('review');
                }}
                className="rounded-lg border border-border bg-bg-base px-4 py-3 text-sm text-text-primary outline-none transition-colors focus:border-border-light sm:max-w-xs"
              />
              <p className="text-xs text-text-secondary">We&apos;ll confirm the exact time with you shortly.</p>
            </div>
          )}
        </div>
      )}

      {step === 'review' && selectedSlot && (
        <div className="mt-6 rounded-xl border border-border bg-bg-base p-5">
          <p className="text-xs font-medium uppercase tracking-wide text-text-muted">Current time</p>
          <p className="mt-1 text-sm text-text-secondary line-through decoration-text-muted">
            {formatDateInZone(appointment.starts_at, appointment.booking_timezone)} at {formatTimeInZone(appointment.starts_at, appointment.booking_timezone)}
          </p>
          <p className="mt-4 text-xs font-medium uppercase tracking-wide text-text-muted">New time</p>
          <p className="mt-1 text-sm font-medium text-text-primary">
            {new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${selectedSlot.date}T00:00:00`))} at {selectedSlot.time} ({slotsTimezone})
          </p>
        </div>
      )}

      {submitError && <p role="alert" className="mt-4 text-sm text-text-primary">{submitError}</p>}

      {step === 'review' && selectedSlot && (
        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row">
          <button
            type="button"
            onClick={() => { setSelectedSlot(null); setStep('pick_slot'); }}
            className="rounded-full border border-border px-6 py-3 text-sm font-medium text-text-primary transition-colors hover:border-border-light"
          >
            Choose another time
          </button>
          <button
            type="button"
            onClick={handleConfirm}
            className="rounded-full bg-accent px-6 py-3 text-sm font-medium text-accent-fg transition-transform active:translate-y-px"
          >
            Confirm new time
          </button>
        </div>
      )}

      {step === 'submitting' && (
        <p aria-live="polite" className="mt-6 text-sm text-text-secondary">Rescheduling…</p>
      )}
    </div>
  );
}
