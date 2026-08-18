'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useQuery as useTypesQuery } from '@tanstack/react-query';
import { CalendarClock, Search, Plus, X, Settings2, AlertTriangle, UserRound, ChevronRight } from 'lucide-react';
import toast from '@/lib/toast';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Select from '@/components/ui/Select';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import TimezoneSelect from '@/components/shared/TimezoneSelect';
import { getErrorMessage } from '@/lib/getErrorMessage';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

const STATUS_FILTERS = [
  { key: 'all', label: 'All' },
  { key: 'requested', label: 'Requested' },
  { key: 'pending_confirmation', label: 'Pending' },
  { key: 'confirmed', label: 'Confirmed' },
  { key: 'declined', label: 'Declined' },
  { key: 'cancelled', label: 'Cancelled' },
  { key: 'completed', label: 'Completed' },
  { key: 'no_show', label: 'No-show' },
] as const;

const STATUS_COLOR: Record<string, string> = {
  requested: '#4779c7', pending_confirmation: '#b7791f', confirmed: '#299a54',
  declined: '#d25454', cancelled: '#817b76', completed: '#299a54', no_show: '#d25454',
};

function appointmentDate(value: string, timeZone: string) {
  return new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone }).format(new Date(value));
}

function appointmentTime(value: string, timeZone: string) {
  return new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone }).format(new Date(value));
}

interface AppointmentType {
  id: number; name: string; slug: string; duration_minutes: number;
  is_active: boolean; meeting_method: string; default_location: string | null;
}

interface AppointmentRow {
  id: number; reference: string; status: string;
  attendee_name: string; attendee_email: string; attendee_company: string | null; company_name: string | null;
  starts_at: string; ends_at: string; booking_timezone: string;
  appointment_type: AppointmentType;
  assigned_user: { id: number; name: string } | null;
}

const EMPTY_FORM = {
  appointment_type_id: '', attendee_name: '', attendee_email: '', attendee_phone: '',
  attendee_company: '', company_name: '', attendee_timezone: 'Europe/London',
  date: '', start_time: '', timezone: 'Europe/London',
  meeting_method: '', meeting_url: '', location: '', attendee_message: '', internal_notes: '',
  assigned_user_id: '',
};

export default function AdminAppointmentsPage() {
  const qc = useQueryClient();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;

  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebounced] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [typeFilter, setTypeFilter] = useState<string>('all');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [createOpen, setCreateOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});
  const [availability, setAvailability] = useState<{ checked: boolean; available: boolean; reason?: string }>({ checked: false, available: true });
  const [override, setOverride] = useState(false);
  const [overrideReason, setOverrideReason] = useState('');

  useEffect(() => {
    const t = setTimeout(() => { setDebounced(search); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const { data: types } = useTypesQuery({
    queryKey: ['appointment-types', 'active'],
    queryFn: () => api.get('/appointment-types', { params: { active_only: true } }).then(r => r.data as AppointmentType[]),
  });

  const { data, isLoading } = useQuery({
    queryKey: ['admin-appointments', debouncedSearch, statusFilter, typeFilter, page, perPage],
    queryFn: () => {
      const params: Record<string, string | number> = { page, per_page: perPage };
      if (debouncedSearch) params.search = debouncedSearch;
      if (statusFilter !== 'all') params.status = statusFilter;
      if (typeFilter !== 'all') params.appointment_type_id = typeFilter;
      return api.get('/appointments', { params }).then(r => r.data);
    },
    placeholderData: (prev: any) => prev,
  });

  const createMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) => api.post('/appointments', payload).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-appointments'] });
      toast.success('Appointment created.');
      closeCreateModal();
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors ?? {};
      setFormErrors(errors);
      if (!Object.keys(errors).length) {
        toast.error(getErrorMessage(err, 'Failed to create appointment.'));
      }
    },
  });

  const appointments: AppointmentRow[] = data?.data ?? [];
  const meta = data ?? {};
  const confirmedCount = appointments.filter(appointment => appointment.status === 'confirmed').length;
  const pendingCount = appointments.filter(appointment => ['requested', 'pending_confirmation'].includes(appointment.status)).length;

  function closeCreateModal() {
    setCreateOpen(false);
    setForm(EMPTY_FORM);
    setFormErrors({});
    setAvailability({ checked: false, available: true });
    setOverride(false);
    setOverrideReason('');
  }

  // Live availability preview — an internal date/time form with
  // authoritative backend validation, not a public slot picker.
  useEffect(() => {
    if (!createOpen || !form.appointment_type_id || !form.assigned_user_id || !form.date || !form.start_time || !form.timezone) {
      setAvailability({ checked: false, available: true });
      return;
    }
    const t = setTimeout(() => {
      api.post('/appointments/check-availability', {
        appointment_type_id: form.appointment_type_id,
        assigned_user_id: form.assigned_user_id,
        date: form.date, start_time: form.start_time, timezone: form.timezone,
      }).then(r => setAvailability({ checked: true, available: r.data.available, reason: r.data.reason }))
        .catch(() => setAvailability({ checked: false, available: true }));
    }, 400);
    return () => clearTimeout(t);
  }, [createOpen, form.appointment_type_id, form.assigned_user_id, form.date, form.start_time, form.timezone]);

  function submitCreate() {
    const payload: Record<string, unknown> = { ...form };
    if (!payload.assigned_user_id) delete payload.assigned_user_id;
    if (!payload.meeting_method) delete payload.meeting_method;
    if (override) {
      payload.override = true;
      payload.override_reason = overrideReason;
    }
    createMutation.mutate(payload);
  }

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero
        eyebrow="Schedule control"
        title="Appointments"
        description="Coordinate demos, onboarding, training and support conversations from one operational schedule."
        loading={isLoading}
        metrics={[
          { label: 'Appointments', value: meta.total ?? appointments.length, detail: 'in the schedule', icon: CalendarClock },
          { label: 'Confirmed', value: confirmedCount, detail: 'in the current view', icon: Settings2 },
          { label: 'Awaiting action', value: pendingCount, detail: 'requested or pending', icon: AlertTriangle },
        ]}
        action={
          <div className="flex flex-wrap items-center justify-end gap-2">
          <Link href="/admin/appointments/availability" className="flex items-center gap-2 rounded-xl border border-white/10 px-3.5 py-2.5 text-xs font-semibold text-white/70 transition-colors hover:bg-white/[0.06] hover:text-white">
            <CalendarClock size={14} /> Availability
          </Link>
          {isSuperAdmin && (
            <Link href="/admin/appointments/types" className="flex items-center gap-2 rounded-xl border border-white/10 px-3.5 py-2.5 text-xs font-semibold text-white/70 transition-colors hover:bg-white/[0.06] hover:text-white">
              <Settings2 size={14} /> Types
            </Link>
          )}
          <button className="flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-xs font-semibold text-[#18211d] transition-colors hover:bg-[#b3efc6] active:scale-[0.98]" onClick={() => { setForm(EMPTY_FORM); setCreateOpen(true); }}><Plus size={14} /> New appointment</button>
          </div>
        }
      />

      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3 ss-animate-in" style={{ animationDelay: '50ms' }}>
        <div className="flex items-center gap-1 flex-wrap">
          {STATUS_FILTERS.map(f => (
            <button
              key={f.key}
              onClick={() => { setStatusFilter(f.key); setPage(1); }}
              className="px-3.5 py-1.5 rounded-full text-xs font-medium transition-all duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] active:scale-[0.96]"
              style={
                statusFilter === f.key
                  ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', boxShadow: 'var(--shadow-card)' }
                  : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }
              }
              onMouseEnter={e => { if (statusFilter !== f.key) e.currentTarget.style.borderColor = 'var(--border-light)'; }}
              onMouseLeave={e => { if (statusFilter !== f.key) e.currentTarget.style.borderColor = 'var(--border)'; }}
            >
              {f.label}
            </button>
          ))}
        </div>
        <Select value={typeFilter} onChange={e => { setTypeFilter(e.target.value); setPage(1); }} className="rounded-full text-xs">
          <option value="all">All types</option>
          {(types ?? []).map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
        </Select>
        <div className="relative flex-1 min-w-[200px]">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search by attendee, company, or reference…"
            className="w-full pl-9 pr-4 py-2 rounded-full text-sm outline-none transition-colors"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>
      </div>

      {isLoading ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {[...Array(6)].map((_, index) => <div key={index} className="h-64 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />)}
        </div>
      ) : appointments.length === 0 ? (
        <div className="rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <EmptyState icon={CalendarClock} title="No appointments found" description={debouncedSearch || statusFilter !== 'all' || typeFilter !== 'all' ? 'Try adjusting your filters.' : 'Create your first appointment to get started.'} />
        </div>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {appointments.map((appointment, index) => {
            const statusColor = STATUS_COLOR[appointment.status] ?? 'var(--text-muted)';
            return (
              <Link key={appointment.id} href={`/admin/appointments/${appointment.id}`}
                className="group flex min-h-[256px] flex-col overflow-hidden rounded-2xl p-5 transition-all duration-300 hover:-translate-y-1 hover:border-[#9ee5b5]/70 hover:shadow-[0_18px_36px_rgba(24,33,29,0.10)] ss-animate-in"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 3px 12px rgba(24,33,29,0.05)', animationDelay: `${Math.min(index * 45, 405)}ms` }}>
                <div className="flex items-center justify-between">
                  <span className="font-mono text-[10px] tracking-[0.06em]" style={{ color: 'var(--text-muted)' }}>{appointment.reference}</span>
                  <span className="inline-flex items-center gap-1.5 text-[11px] font-medium capitalize" style={{ color: statusColor }}><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: statusColor }} />{appointment.status.replace(/_/g, ' ')}</span>
                </div>
                <div className="mt-5 flex items-end justify-between border-b pb-5" style={{ borderColor: 'var(--border)' }}>
                  <div>
                    <p className="text-2xl font-semibold tabular-nums tracking-[-0.04em]" style={{ color: 'var(--text-primary)' }}>{appointmentTime(appointment.starts_at, appointment.booking_timezone)}</p>
                    <p className="mt-1 text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>{appointmentDate(appointment.starts_at, appointment.booking_timezone)}</p>
                  </div>
                  <p className="max-w-[48%] text-right text-[10px] leading-snug" style={{ color: 'var(--text-muted)' }}>{appointment.booking_timezone}</p>
                </div>
                <div className="mt-4 min-w-0">
                  <p className="truncate text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{appointment.attendee_name}</p>
                  <p className="mt-1 truncate text-xs" style={{ color: 'var(--text-muted)' }}>{appointment.attendee_company || appointment.company_name || appointment.attendee_email}</p>
                  <p className="mt-3 text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>{appointment.appointment_type?.name}</p>
                </div>
                <div className="mt-auto flex items-center justify-between border-t pt-4" style={{ borderColor: 'var(--border)' }}>
                  <span className="flex items-center gap-1.5 text-xs" style={{ color: appointment.assigned_user ? 'var(--text-secondary)' : 'var(--text-muted)' }}><UserRound size={12} className="opacity-45" />{appointment.assigned_user?.name ?? 'Unassigned'}</span>
                  <span className="flex h-7 w-7 items-center justify-center rounded-full transition-colors group-hover:bg-[#9ee5b5]"><ChevronRight size={14} className="transition-transform group-hover:translate-x-0.5" /></span>
                </div>
              </Link>
            );
          })}
        </div>
      )}

      {!isLoading && meta.total > 0 && (
        <PaginationBar page={meta.current_page ?? page} lastPage={meta.last_page ?? 1} total={meta.total ?? 0} perPage={perPage} onPage={setPage} onPerPage={n => { setPerPage(n); setPage(1); }} />
      )}

      {/* Create modal */}
      {createOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setCreateOpen(false)}>
          <div
            onClick={e => e.stopPropagation()}
            className="w-full max-w-lg rounded-2xl p-5 space-y-4 max-h-[90vh] overflow-y-auto ss-animate-in"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
          >
            <div className="flex items-center justify-between">
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Appointment</h2>
              <button onClick={closeCreateModal}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
            </div>

            <div className="space-y-1">
              <label className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Appointment Type</label>
              <Select value={form.appointment_type_id} onChange={e => setForm(f => ({ ...f, appointment_type_id: e.target.value }))} className="w-full">
                <option value="">Select a type…</option>
                {(types ?? []).map(t => <option key={t.id} value={t.id}>{t.name} ({t.duration_minutes} min)</option>)}
              </Select>
              {formErrors.appointment_type_id && <p className="text-xs" style={{ color: '#ef4444' }}>{formErrors.appointment_type_id[0]}</p>}
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Input placeholder="Attendee name" value={form.attendee_name} onChange={e => setForm(f => ({ ...f, attendee_name: e.target.value }))} error={formErrors.attendee_name?.[0]} />
              <Input placeholder="Attendee email" type="email" value={form.attendee_email} onChange={e => setForm(f => ({ ...f, attendee_email: e.target.value }))} error={formErrors.attendee_email?.[0]} />
              <Input placeholder="Phone (optional)" value={form.attendee_phone} onChange={e => setForm(f => ({ ...f, attendee_phone: e.target.value }))} />
              <Input placeholder="Company (optional)" value={form.attendee_company} onChange={e => setForm(f => ({ ...f, attendee_company: e.target.value }))} />
            </div>

            <div className="grid grid-cols-3 gap-3">
              <Input type="date" value={form.date} onChange={e => setForm(f => ({ ...f, date: e.target.value }))} error={formErrors.date?.[0]} />
              <Input type="time" value={form.start_time} onChange={e => setForm(f => ({ ...f, start_time: e.target.value }))} error={formErrors.start_time?.[0]} />
              <TimezoneSelect value={form.timezone} onChange={tz => setForm(f => ({ ...f, timezone: tz, attendee_timezone: tz }))} />
            </div>

            {isSuperAdmin ? (
              <Input
                placeholder="Assign to user ID (optional — leave blank to keep unassigned)"
                value={form.assigned_user_id}
                onChange={e => setForm(f => ({ ...f, assigned_user_id: e.target.value }))}
                error={formErrors.assigned_user_id?.[0]}
              />
            ) : (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                This appointment will be assigned to you ({currentUser?.name}).
              </p>
            )}

            <textarea
              placeholder="Internal notes (optional)"
              value={form.internal_notes}
              onChange={e => setForm(f => ({ ...f, internal_notes: e.target.value }))}
              className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              rows={2}
            />

            {availability.checked && !availability.available && (
              <div className="flex items-start gap-2 p-2.5 rounded-lg text-xs" style={{ backgroundColor: 'rgba(234,179,8,0.1)', color: '#facc15' }}>
                <AlertTriangle size={14} className="flex-shrink-0 mt-0.5" />
                <span>{availability.reason ?? 'This staff member is not available at this time.'}</span>
              </div>
            )}

            {availability.checked && !availability.available && isSuperAdmin && (
              <div className="space-y-2 p-2.5 rounded-lg" style={{ border: '1px dashed #facc15' }}>
                <label className="flex items-center gap-2 text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>
                  <input type="checkbox" checked={override} onChange={e => setOverride(e.target.checked)} />
                  Override availability and proceed anyway
                </label>
                {override && (
                  <Input placeholder="Reason for override (required)" value={overrideReason} onChange={e => setOverrideReason(e.target.value)} />
                )}
              </div>
            )}

            <div className="flex gap-2 pt-2">
              <Button variant="secondary" className="flex-1" onClick={closeCreateModal}>Cancel</Button>
              <Button
                className="flex-1"
                disabled={createMutation.isPending || (availability.checked && !availability.available && (!isSuperAdmin || !override || !overrideReason))}
                onClick={submitCreate}
              >
                {createMutation.isPending ? 'Creating…' : 'Create Appointment'}
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
