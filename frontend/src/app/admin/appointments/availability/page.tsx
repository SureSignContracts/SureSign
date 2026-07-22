'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, CalendarClock, Plus, Trash2, Ban } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Select from '@/components/ui/Select';
import EmptyState from '@/components/ui/EmptyState';

// Display order Mon..Sun; `weekday` values match Carbon's dayOfWeek (0=Sun..6=Sat).
const DAYS: { weekday: number; label: string }[] = [
  { weekday: 1, label: 'Monday' },
  { weekday: 2, label: 'Tuesday' },
  { weekday: 3, label: 'Wednesday' },
  { weekday: 4, label: 'Thursday' },
  { weekday: 5, label: 'Friday' },
  { weekday: 6, label: 'Saturday' },
  { weekday: 0, label: 'Sunday' },
];

interface Window { weekday: number; start_time: string; end_time: string; is_active?: boolean }
interface Override { id: number; local_date: string; is_unavailable: boolean; start_time: string | null; end_time: string | null }
interface BlockedPeriod { id: number; starts_at: string; ends_at: string; timezone: string; reason: string | null }
interface EligibleUser { id: number; name: string; email: string; roles: string[] }

export default function AppointmentAvailabilityPage() {
  const qc = useQueryClient();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;

  const [staffId, setStaffId] = useState<string>('');
  const [windows, setWindows] = useState<Window[]>([]);
  const [overrideForm, setOverrideForm] = useState({ local_date: '', is_unavailable: false, start_time: '09:00', end_time: '17:00' });
  const [blockedForm, setBlockedForm] = useState({ start_date: '', start_time: '09:00', end_date: '', end_time: '17:00', reason: '' });

  const basePath = isSuperAdmin && staffId ? `/appointment-availability/${staffId}` : '/appointment-availability/me';

  const { data: eligibleUsers } = useQuery({
    queryKey: ['appointment-eligible-staff'],
    queryFn: () => api.get('/users', { params: { per_page: 100 } }).then(r => (r.data?.data ?? []) as EligibleUser[]),
    enabled: isSuperAdmin,
  });
  const eligibleStaff = useMemo(
    () => (eligibleUsers ?? []).filter(u => u.roles?.some(r => r === 'Admin' || r === 'Super Admin')),
    [eligibleUsers],
  );

  const { data: weekly, isLoading: weeklyLoading } = useQuery({
    queryKey: ['appointment-availability', basePath],
    queryFn: () => api.get(basePath).then(r => r.data as { user_id: number; timezone: string; windows: Window[] }),
  });

  useEffect(() => {
    setWindows(weekly?.windows?.map(w => ({ weekday: w.weekday, start_time: w.start_time.slice(0, 5), end_time: w.end_time.slice(0, 5) })) ?? []);
  }, [weekly]);

  const { data: overrides } = useQuery({
    queryKey: ['appointment-availability-overrides', basePath],
    queryFn: () => api.get(`${basePath}/overrides`).then(r => r.data as Override[]),
  });

  const { data: blockedPeriods } = useQuery({
    queryKey: ['appointment-blocked-periods', basePath],
    queryFn: () => api.get(`${basePath}/blocked-periods`).then(r => r.data as BlockedPeriod[]),
  });

  const saveWeeklyMutation = useMutation({
    mutationFn: () => api.put(basePath, { windows }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointment-availability', basePath] });
      toast.success('Weekly availability saved.');
    },
    onError: (err: any) => toast.error(err?.response?.data?.message ?? 'Failed to save availability.'),
  });

  const createOverrideMutation = useMutation({
    mutationFn: () => api.post(`${basePath}/overrides`, overrideForm).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointment-availability-overrides', basePath] });
      toast.success('Override saved.');
      setOverrideForm({ local_date: '', is_unavailable: false, start_time: '09:00', end_time: '17:00' });
    },
    onError: (err: any) => toast.error(err?.response?.data?.message ?? 'Failed to save override.'),
  });

  const deleteOverrideMutation = useMutation({
    mutationFn: (overrideId: number) => api.delete(`${basePath}/overrides/${overrideId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointment-availability-overrides', basePath] });
      toast.success('Override removed.');
    },
  });

  const createBlockedMutation = useMutation({
    mutationFn: () => api.post(`${basePath}/blocked-periods`, {
      ...blockedForm, timezone: weekly?.timezone ?? 'Europe/London',
    }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointment-blocked-periods', basePath] });
      toast.success('Blocked period added.');
      setBlockedForm({ start_date: '', start_time: '09:00', end_date: '', end_time: '17:00', reason: '' });
    },
    onError: (err: any) => toast.error(err?.response?.data?.message ?? 'Failed to add blocked period.'),
  });

  const deleteBlockedMutation = useMutation({
    mutationFn: (periodId: number) => api.delete(`${basePath}/blocked-periods/${periodId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointment-blocked-periods', basePath] });
      toast.success('Blocked period removed.');
    },
  });

  function addWindow(weekday: number) {
    setWindows(w => [...w, { weekday, start_time: '09:00', end_time: '17:00' }]);
  }
  function removeWindow(index: number) {
    setWindows(w => w.filter((_, i) => i !== index));
  }
  function updateWindow(index: number, field: 'start_time' | 'end_time', value: string) {
    setWindows(w => w.map((win, i) => (i === index ? { ...win, [field]: value } : win)));
  }

  return (
    <div className="space-y-5 max-w-3xl">
      <Link href="/admin/appointments" className="inline-flex items-center gap-1 text-sm" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Appointments
      </Link>

      <div>
        <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <CalendarClock size={20} /> Availability
        </h1>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
          {weekly?.timezone ? `Times shown in ${weekly.timezone}.` : 'Manage weekly hours, date overrides, and blocked time.'}
        </p>
      </div>

      {isSuperAdmin && (
        <div className="space-y-1">
          <label className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Staff member</label>
          <Select value={staffId} onChange={e => setStaffId(e.target.value)} className="w-full max-w-xs">
            <option value="">Me ({currentUser?.name})</option>
            {eligibleStaff.filter(u => u.id !== currentUser?.id).map(u => (
              <option key={u.id} value={u.id}>{u.name} ({u.roles?.[0]})</option>
            ))}
          </Select>
        </div>
      )}

      {/* Weekly schedule */}
      <div className="rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Weekly schedule</h2>

        {weeklyLoading ? (
          <div className="h-32 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ) : (
          <div className="space-y-3">
            {DAYS.map(day => {
              const dayWindows = windows.map((w, i) => ({ ...w, index: i })).filter(w => w.weekday === day.weekday);
              return (
                <div key={day.weekday} className="flex flex-col sm:flex-row sm:items-start gap-2 py-2" style={{ borderBottom: '1px solid var(--border)' }}>
                  <div className="w-28 flex-shrink-0 text-sm font-medium pt-2" style={{ color: 'var(--text-primary)' }}>{day.label}</div>
                  <div className="flex-1 space-y-2">
                    {dayWindows.length === 0 && (
                      <p className="text-xs pt-2" style={{ color: 'var(--text-muted)' }}>Unavailable</p>
                    )}
                    {dayWindows.map(w => (
                      <div key={w.index} className="flex items-center gap-2">
                        <Input type="time" value={w.start_time} onChange={e => updateWindow(w.index, 'start_time', e.target.value)} className="w-32" />
                        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>to</span>
                        <Input type="time" value={w.end_time} onChange={e => updateWindow(w.index, 'end_time', e.target.value)} className="w-32" />
                        <button onClick={() => removeWindow(w.index)} title="Remove window">
                          <Trash2 size={14} style={{ color: '#f87171' }} />
                        </button>
                      </div>
                    ))}
                    <button
                      onClick={() => addWindow(day.weekday)}
                      className="inline-flex items-center gap-1 text-xs font-medium"
                      style={{ color: 'var(--gold)' }}
                    >
                      <Plus size={12} /> Add window
                    </button>
                  </div>
                </div>
              );
            })}
          </div>
        )}

        <Button disabled={saveWeeklyMutation.isPending} onClick={() => saveWeeklyMutation.mutate()}>
          {saveWeeklyMutation.isPending ? 'Saving…' : 'Save weekly schedule'}
        </Button>
      </div>

      {/* Date overrides */}
      <div className="rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Date overrides</h2>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          A date override fully replaces the weekly schedule for that date — mark a specific date unavailable, or give it custom hours.
        </p>

        {!overrides || overrides.length === 0 ? (
          <EmptyState icon={CalendarClock} title="No date overrides" description="Add one below." />
        ) : (
          <div className="space-y-2">
            {overrides.map(o => (
              <div key={o.id} className="flex items-center justify-between px-3 py-2 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{o.local_date}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    {o.is_unavailable ? 'Unavailable all day' : `${o.start_time?.slice(0, 5)} – ${o.end_time?.slice(0, 5)}`}
                  </p>
                </div>
                <button onClick={() => deleteOverrideMutation.mutate(o.id)}><Trash2 size={14} style={{ color: '#f87171' }} /></button>
              </div>
            ))}
          </div>
        )}

        <div className="flex flex-wrap items-end gap-2">
          <Input type="date" value={overrideForm.local_date} onChange={e => setOverrideForm(f => ({ ...f, local_date: e.target.value }))} />
          <label className="flex items-center gap-1.5 text-xs pb-2.5" style={{ color: 'var(--text-secondary)' }}>
            <input type="checkbox" checked={overrideForm.is_unavailable} onChange={e => setOverrideForm(f => ({ ...f, is_unavailable: e.target.checked }))} />
            Unavailable all day
          </label>
          {!overrideForm.is_unavailable && (
            <>
              <Input type="time" value={overrideForm.start_time} onChange={e => setOverrideForm(f => ({ ...f, start_time: e.target.value }))} className="w-28" />
              <Input type="time" value={overrideForm.end_time} onChange={e => setOverrideForm(f => ({ ...f, end_time: e.target.value }))} className="w-28" />
            </>
          )}
          <Button size="sm" disabled={!overrideForm.local_date || createOverrideMutation.isPending} onClick={() => createOverrideMutation.mutate()}>
            Add override
          </Button>
        </div>
      </div>

      {/* Blocked periods */}
      <div className="rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Blocked periods</h2>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Leave, internal commitments, or any other manually unavailable time — can span multiple days.</p>

        {!blockedPeriods || blockedPeriods.length === 0 ? (
          <EmptyState icon={Ban} title="No blocked periods" description="Add one below." />
        ) : (
          <div className="space-y-2">
            {blockedPeriods.map(p => (
              <div key={p.id} className="flex items-center justify-between px-3 py-2 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                    {new Date(p.starts_at).toLocaleString('en-GB', { timeZone: p.timezone })} – {new Date(p.ends_at).toLocaleString('en-GB', { timeZone: p.timezone })}
                  </p>
                  {p.reason && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{p.reason}</p>}
                </div>
                <button onClick={() => deleteBlockedMutation.mutate(p.id)}><Trash2 size={14} style={{ color: '#f87171' }} /></button>
              </div>
            ))}
          </div>
        )}

        <div className="grid grid-cols-2 gap-2">
          <Input type="date" value={blockedForm.start_date} onChange={e => setBlockedForm(f => ({ ...f, start_date: e.target.value }))} />
          <Input type="time" value={blockedForm.start_time} onChange={e => setBlockedForm(f => ({ ...f, start_time: e.target.value }))} />
          <Input type="date" value={blockedForm.end_date} onChange={e => setBlockedForm(f => ({ ...f, end_date: e.target.value }))} />
          <Input type="time" value={blockedForm.end_time} onChange={e => setBlockedForm(f => ({ ...f, end_time: e.target.value }))} />
        </div>
        <Input placeholder="Reason (optional)" value={blockedForm.reason} onChange={e => setBlockedForm(f => ({ ...f, reason: e.target.value }))} />
        <Button
          size="sm"
          disabled={!blockedForm.start_date || !blockedForm.end_date || createBlockedMutation.isPending}
          onClick={() => createBlockedMutation.mutate()}
        >
          Add blocked period
        </Button>
      </div>
    </div>
  );
}
