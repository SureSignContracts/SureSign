'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useQuery as useTypesQuery } from '@tanstack/react-query';
import { CalendarClock, Search, Plus, X, Settings2, AlertTriangle } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Select from '@/components/ui/Select';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import TimezoneSelect from '@/components/shared/TimezoneSelect';

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

const STATUS_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'info' | 'danger' | 'accent'> = {
  requested: 'info', pending_confirmation: 'warning', confirmed: 'success',
  declined: 'danger', cancelled: 'neutral', completed: 'success', no_show: 'danger',
};

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
  const router = useRouter();
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
        toast.error(err?.response?.data?.message ?? 'Failed to create appointment.');
      }
    },
  });

  const appointments: AppointmentRow[] = data?.data ?? [];
  const meta = data ?? {};

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
    <div className="p-6 max-w-7xl mx-auto space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3 ss-animate-in">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <CalendarClock size={20} /> Appointments
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
            Demos, onboarding, training, and support appointments.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Link href="/admin/appointments/availability">
            <Button variant="secondary" size="md" className="rounded-full"><CalendarClock size={14} /> Availability</Button>
          </Link>
          {isSuperAdmin && (
            <Link href="/admin/appointments/types">
              <Button variant="secondary" size="md" className="rounded-full"><Settings2 size={14} /> Appointment Types</Button>
            </Link>
          )}
          <Button className="rounded-full" onClick={() => { setForm(EMPTY_FORM); setCreateOpen(true); }}><Plus size={14} /> New Appointment</Button>
        </div>
      </div>

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

      {/* Table */}
      <div className="rounded-2xl overflow-x-auto ss-animate-in" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '100ms' }}>
        <table className="w-full min-w-[900px]">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Reference', 'Attendee', 'Type', 'When', 'Assigned', 'Status'].map((h, i) => (
                <th key={i} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                  {[...Array(6)].map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: j === 0 ? '60%' : '40%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : appointments.length === 0 ? (
              <tr>
                <td colSpan={6}>
                  <EmptyState
                    icon={CalendarClock}
                    title="No appointments found"
                    description={debouncedSearch || statusFilter !== 'all' || typeFilter !== 'all' ? 'Try adjusting your filters.' : 'Create your first appointment to get started.'}
                  />
                </td>
              </tr>
            ) : appointments.map((a, idx) => (
              <tr
                key={a.id}
                onClick={() => router.push(`/admin/appointments/${a.id}`)}
                className="ss-animate-in cursor-pointer transition-colors hover:bg-[var(--bg-hover)]"
                style={{
                  borderBottom: idx < appointments.length - 1 ? '1px solid var(--border)' : undefined,
                  backgroundColor: 'var(--bg-surface)',
                  animationDelay: `${Math.min(idx * 40, 320)}ms`,
                }}
              >
                <td className="px-4 py-3">
                  <Link
                    href={`/admin/appointments/${a.id}`}
                    onClick={e => e.stopPropagation()}
                    className="text-sm font-medium transition-colors hover:text-[var(--gold)] hover:underline"
                    style={{ color: 'var(--text-primary)' }}
                  >
                    {a.reference}
                  </Link>
                </td>
                <td className="px-4 py-3">
                  <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{a.attendee_name}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{a.attendee_company || a.company_name || a.attendee_email}</p>
                </td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{a.appointment_type?.name}</td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>
                  {formatDateTime(a.starts_at, { timeZone: a.booking_timezone })}
                  <span className="block text-xs" style={{ color: 'var(--text-muted)' }}>{a.booking_timezone}</span>
                </td>
                <td className="px-4 py-3 text-sm" style={{ color: a.assigned_user ? 'var(--text-secondary)' : 'var(--text-muted)' }}>
                  {a.assigned_user?.name ?? 'Unassigned'}
                </td>
                <td className="px-4 py-3"><Badge status={a.status} tone={STATUS_TONE[a.status]} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

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
