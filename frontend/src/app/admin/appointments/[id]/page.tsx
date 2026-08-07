'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import toast from 'react-hot-toast';
import {
  ArrowLeft, Clock, CheckCircle2, XCircle, Ban, CalendarClock, UserCog, Trash2, AlertTriangle,
} from 'lucide-react';
import api from '@/lib/api';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import { Card, CardBody } from '@/components/ui/Card';
import TimezoneSelect from '@/components/shared/TimezoneSelect';
import { getErrorMessage } from '@/lib/getErrorMessage';

const STATUS_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'info' | 'danger' | 'accent'> = {
  requested: 'info', pending_confirmation: 'warning', confirmed: 'success',
  declined: 'danger', cancelled: 'neutral', completed: 'success', no_show: 'danger',
};

const TERMINAL_STATUSES = ['declined', 'cancelled', 'completed', 'no_show'];

function Field({ label, value }: { label: string; value?: React.ReactNode }) {
  if (!value) return null;
  return (
    <div>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm mt-0.5" style={{ color: 'var(--text-primary)' }}>{value}</p>
    </div>
  );
}

export default function AdminAppointmentDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;

  const [reasonModal, setReasonModal] = useState<'decline' | 'cancel' | null>(null);
  const [reason, setReason] = useState('');
  const [rescheduleOpen, setRescheduleOpen] = useState(false);
  const [rescheduleForm, setRescheduleForm] = useState({ date: '', start_time: '', timezone: 'Europe/London' });
  const [rescheduleOverride, setRescheduleOverride] = useState(false);
  const [rescheduleOverrideReason, setRescheduleOverrideReason] = useState('');
  const [rescheduleAvailability, setRescheduleAvailability] = useState<{ checked: boolean; available: boolean; reason?: string; checkFailed?: boolean }>({ checked: false, available: true });
  const [assignOpen, setAssignOpen] = useState(false);
  const [assignUserId, setAssignUserId] = useState('');
  const [assignConflict, setAssignConflict] = useState<string | null>(null);
  const [assignOverride, setAssignOverride] = useState(false);
  const [assignOverrideReason, setAssignOverrideReason] = useState('');

  const { data: appointment, isLoading } = useQuery({
    queryKey: ['admin-appointment', id],
    queryFn: () => api.get(`/appointments/${id}`).then(r => r.data),
  });

  function invalidate() {
    qc.invalidateQueries({ queryKey: ['admin-appointment', id] });
    qc.invalidateQueries({ queryKey: ['admin-appointments'] });
  }

  const action = useMutation({
    mutationFn: ({ path, payload }: { path: string; payload?: Record<string, unknown> }) =>
      api.post(`/appointments/${id}/${path}`, payload ?? {}).then(r => r.data),
    onSuccess: (_data, variables) => {
      invalidate();
      toast.success('Appointment updated.');
      setReasonModal(null);
      setReason('');
      setRescheduleOpen(false);
      setRescheduleOverride(false);
      setRescheduleOverrideReason('');
      if (variables.path === 'assign') {
        setAssignOpen(false);
        setAssignConflict(null);
        setAssignOverride(false);
        setAssignOverrideReason('');
      }
    },
    onError: (err: any, variables) => {
      const message = getErrorMessage(err, 'Action failed.');
      // Assign has no live availability preview — surface a conflict inline
      // so a Super Admin can retry with an override, instead of just a toast.
      if (variables.path === 'assign' && err?.response?.status === 409) {
        setAssignConflict(message);
        return;
      }
      toast.error(message);
    },
  });

  const deleteMutation = useMutation({
    mutationFn: () => api.delete(`/appointments/${id}`),
    onSuccess: () => {
      toast.success('Appointment deleted.');
      router.push('/admin/appointments');
    },
    onError: (err: any) => toast.error(getErrorMessage(err, 'Failed to delete appointment.')),
  });

  // Live availability preview while the reschedule modal is open — an
  // internal date/time form with authoritative backend validation, not a
  // public slot picker. Debounced so it doesn't fire on every keystroke.
  useEffect(() => {
    if (!rescheduleOpen || !appointment || !rescheduleForm.date || !rescheduleForm.start_time || !rescheduleForm.timezone) {
      return;
    }
    if (!appointment.assigned_user_id) {
      setRescheduleAvailability({ checked: true, available: true });
      return;
    }

    const t = setTimeout(() => {
      api.post('/appointments/check-availability', {
        appointment_type_id: appointment.appointment_type.id,
        assigned_user_id: appointment.assigned_user_id,
        date: rescheduleForm.date,
        start_time: rescheduleForm.start_time,
        timezone: rescheduleForm.timezone,
        exclude_appointment_id: appointment.id,
      }).then(r => setRescheduleAvailability({ checked: true, available: r.data.available, reason: r.data.reason }))
        // Previously defaulted silently to available: true on any failure
        // (network blip, 500, etc.) — this is only a preview (the actual
        // submit is still re-validated authoritatively server-side), but a
        // failed check has no business looking identical to a genuine
        // "available" result. checkFailed shows a small, non-blocking note
        // instead of silently claiming something unverified.
        .catch(() => setRescheduleAvailability({ checked: false, available: true, checkFailed: true }));
    }, 400);

    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rescheduleOpen, rescheduleForm.date, rescheduleForm.start_time, rescheduleForm.timezone, appointment?.id]);

  if (isLoading) {
    return <div className="h-40 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />;
  }
  if (!appointment) return null;

  const canManage = isSuperAdmin || appointment.assigned_user_id === currentUser?.id;
  const status = appointment.status;

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-5">
      <Link href="/admin/appointments" className="inline-flex items-center gap-1 text-sm transition-colors hover:text-[var(--gold)]" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Appointments
      </Link>

      <div className="flex items-center justify-between flex-wrap gap-3 ss-animate-in">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <CalendarClock size={20} /> {appointment.reference}
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>{appointment.appointment_type?.name}</p>
        </div>
        <Badge status={status} tone={STATUS_TONE[status]} />
      </div>

      {/* Actions */}
      {canManage && !TERMINAL_STATUSES.includes(status) && (
        <div className="flex flex-wrap gap-2 ss-animate-in" style={{ animationDelay: '50ms' }}>
          {(status === 'requested' || status === 'pending_confirmation') && (
            <Button size="sm" className="rounded-full" onClick={() => action.mutate({ path: 'confirm' })}><CheckCircle2 size={14} /> Confirm</Button>
          )}
          {(status === 'requested' || status === 'pending_confirmation') && (
            <Button size="sm" variant="secondary" className="rounded-full" onClick={() => setReasonModal('decline')}><XCircle size={14} /> Decline</Button>
          )}
          {status === 'confirmed' && (
            <>
              <Button size="sm" className="rounded-full" onClick={() => action.mutate({ path: 'complete' })}><CheckCircle2 size={14} /> Complete</Button>
              <Button size="sm" variant="secondary" className="rounded-full" onClick={() => action.mutate({ path: 'no-show' })}><Ban size={14} /> No-show</Button>
            </>
          )}
          <Button
            size="sm"
            variant="secondary"
            className="rounded-full"
            onClick={() => {
              setRescheduleForm({ date: '', start_time: '', timezone: appointment.booking_timezone ?? 'Europe/London' });
              setRescheduleAvailability({ checked: false, available: true });
              setRescheduleOverride(false);
              setRescheduleOverrideReason('');
              setRescheduleOpen(true);
            }}
          >
            <Clock size={14} /> Reschedule
          </Button>
          <Button size="sm" variant="secondary" className="rounded-full" onClick={() => setReasonModal('cancel')}><XCircle size={14} /> Cancel</Button>
          {isSuperAdmin && (
            <Button
              size="sm"
              variant="secondary"
              className="rounded-full"
              onClick={() => {
                setAssignUserId(appointment.assigned_user_id ? String(appointment.assigned_user_id) : '');
                setAssignConflict(null);
                setAssignOverride(false);
                setAssignOverrideReason('');
                setAssignOpen(true);
              }}
            >
              <UserCog size={14} /> Assign
            </Button>
          )}
        </div>
      )}
      {isSuperAdmin && (
        <button
          onClick={() => { if (confirm('Delete this appointment? This cannot be undone.')) deleteMutation.mutate(); }}
          className="inline-flex items-center gap-1 text-xs transition-opacity hover:opacity-75"
          style={{ color: '#f87171' }}
        >
          <Trash2 size={12} /> Delete appointment
        </button>
      )}

      <Card className="ss-animate-in" style={{ animationDelay: '100ms' }}>
        <CardBody className="grid grid-cols-2 gap-5">
          <Field label="When" value={`${formatDateTime(appointment.starts_at, { timeZone: appointment.booking_timezone })} (${appointment.booking_timezone})`} />
          <Field label="Assigned to" value={appointment.assigned_user?.name ?? 'Unassigned'} />
          <Field label="Attendee" value={appointment.attendee_name} />
          <Field label="Email" value={appointment.attendee_email} />
          <Field label="Phone" value={appointment.attendee_phone} />
          <Field label="Company" value={appointment.attendee_company || appointment.company_name} />
          <Field label="Meeting method" value={appointment.meeting_method?.replace(/_/g, ' ')} />
          <Field label="Location / link" value={appointment.meeting_url || appointment.location} />
          <Field label="Booking source" value={appointment.booking_source} />
          <Field label="Created by" value={appointment.created_by?.name} />
        </CardBody>
      </Card>

      {(appointment.attendee_message || appointment.internal_notes) && (
        <Card className="ss-animate-in" style={{ animationDelay: '150ms' }}>
          <CardBody className="space-y-3">
            {appointment.attendee_message && <Field label="Attendee message" value={appointment.attendee_message} />}
            {appointment.internal_notes && <Field label="Internal notes (staff only)" value={appointment.internal_notes} />}
          </CardBody>
        </Card>
      )}

      {(appointment.cancellation_reason || appointment.reschedule_reason || appointment.completion_notes) && (
        <Card className="ss-animate-in" style={{ animationDelay: '200ms' }}>
          <CardBody className="space-y-3">
            {appointment.cancellation_reason && <Field label="Cancellation / decline reason" value={appointment.cancellation_reason} />}
            {appointment.reschedule_reason && <Field label="Reschedule reason" value={appointment.reschedule_reason} />}
            {appointment.completion_notes && <Field label="Completion notes" value={appointment.completion_notes} />}
          </CardBody>
        </Card>
      )}

      {/* Reason modal (decline/cancel) */}
      {reasonModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setReasonModal(null)}>
          <div onClick={e => e.stopPropagation()} className="w-full max-w-sm rounded-2xl p-5 space-y-3 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              {reasonModal === 'decline' ? 'Decline appointment' : 'Cancel appointment'}
            </h2>
            <Input placeholder="Reason (optional)" value={reason} onChange={e => setReason(e.target.value)} />
            <div className="flex gap-2">
              <Button variant="secondary" className="flex-1" onClick={() => setReasonModal(null)}>Back</Button>
              <Button
                className="flex-1"
                onClick={() => action.mutate({ path: reasonModal, payload: { reason: reason || undefined } })}
              >
                Confirm
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Reschedule modal */}
      {rescheduleOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setRescheduleOpen(false)}>
          <div onClick={e => e.stopPropagation()} className="w-full max-w-sm rounded-2xl p-5 space-y-3 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Reschedule</h2>
            <div className="grid grid-cols-2 gap-2">
              <Input type="date" value={rescheduleForm.date} onChange={e => setRescheduleForm(f => ({ ...f, date: e.target.value }))} />
              <Input type="time" value={rescheduleForm.start_time} onChange={e => setRescheduleForm(f => ({ ...f, start_time: e.target.value }))} />
            </div>
            <TimezoneSelect value={rescheduleForm.timezone} onChange={tz => setRescheduleForm(f => ({ ...f, timezone: tz }))} className="w-full px-3 py-2.5 rounded-lg text-sm outline-none" />

            {rescheduleAvailability.checked && !rescheduleAvailability.available && (
              <div className="flex items-start gap-2 p-2.5 rounded-lg text-xs" style={{ backgroundColor: 'rgba(234,179,8,0.1)', color: '#facc15' }}>
                <AlertTriangle size={14} className="flex-shrink-0 mt-0.5" />
                <span>{rescheduleAvailability.reason ?? 'This staff member is not available at this time.'}</span>
              </div>
            )}

            {rescheduleAvailability.checkFailed && (
              <div className="flex items-start gap-2 p-2.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                <AlertTriangle size={14} className="flex-shrink-0 mt-0.5" />
                <span>We couldn&rsquo;t check availability for this time. It will still be validated when you submit.</span>
              </div>
            )}

            {rescheduleAvailability.checked && !rescheduleAvailability.available && isSuperAdmin && (
              <div className="space-y-2 p-2.5 rounded-lg" style={{ border: '1px dashed #facc15' }}>
                <label className="flex items-center gap-2 text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>
                  <input type="checkbox" checked={rescheduleOverride} onChange={e => setRescheduleOverride(e.target.checked)} />
                  Override availability and proceed anyway
                </label>
                {rescheduleOverride && (
                  <Input placeholder="Reason for override (required)" value={rescheduleOverrideReason} onChange={e => setRescheduleOverrideReason(e.target.value)} />
                )}
              </div>
            )}

            <div className="flex gap-2">
              <Button variant="secondary" className="flex-1" onClick={() => setRescheduleOpen(false)}>Cancel</Button>
              <Button
                className="flex-1"
                disabled={rescheduleAvailability.checked && !rescheduleAvailability.available && (!rescheduleOverride || !rescheduleOverrideReason)}
                onClick={() => action.mutate({
                  path: 'reschedule',
                  payload: rescheduleOverride
                    ? { ...rescheduleForm, override: true, override_reason: rescheduleOverrideReason }
                    : rescheduleForm,
                })}
              >
                Save
              </Button>
            </div>
          </div>
        </div>
      )}

      {/* Assign modal */}
      {assignOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setAssignOpen(false)}>
          <div onClick={e => e.stopPropagation()} className="w-full max-w-sm rounded-2xl p-5 space-y-3 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Assign appointment</h2>
            <Input placeholder="User ID (leave blank to unassign)" value={assignUserId} onChange={e => { setAssignUserId(e.target.value); setAssignConflict(null); }} />

            {assignConflict && (
              <div className="flex items-start gap-2 p-2.5 rounded-lg text-xs" style={{ backgroundColor: 'rgba(234,179,8,0.1)', color: '#facc15' }}>
                <AlertTriangle size={14} className="flex-shrink-0 mt-0.5" />
                <span>{assignConflict}</span>
              </div>
            )}
            {assignConflict && (
              <div className="space-y-2 p-2.5 rounded-lg" style={{ border: '1px dashed #facc15' }}>
                <label className="flex items-center gap-2 text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>
                  <input type="checkbox" checked={assignOverride} onChange={e => setAssignOverride(e.target.checked)} />
                  Override availability and proceed anyway
                </label>
                {assignOverride && (
                  <Input placeholder="Reason for override (required)" value={assignOverrideReason} onChange={e => setAssignOverrideReason(e.target.value)} />
                )}
              </div>
            )}

            <div className="flex gap-2">
              <Button variant="secondary" className="flex-1" onClick={() => setAssignOpen(false)}>Cancel</Button>
              <Button
                className="flex-1"
                disabled={assignOverride && !assignOverrideReason}
                onClick={() => action.mutate({
                  path: 'assign',
                  payload: assignOverride
                    ? { assigned_user_id: assignUserId || null, override: true, override_reason: assignOverrideReason }
                    : { assigned_user_id: assignUserId || null },
                })}
              >
                Save
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
