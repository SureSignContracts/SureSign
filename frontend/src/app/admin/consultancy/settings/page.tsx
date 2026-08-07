'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Settings2, CheckCircle2, XCircle, AlertTriangle } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import { Badge } from '@/components/ui/Badge';
import { getErrorMessage } from '@/lib/getErrorMessage';

interface Consultant { id: number; name: string; email: string }
interface ConsultantResponse { consultant: Consultant | null; configured_but_ineligible: boolean }
interface Readiness {
  consultant_configured: boolean;
  availability_configured: boolean;
  active_service_available: boolean;
  ready: boolean;
}
interface NotificationSettings { recipients: 'all_admins' | 'assigned_consultant' }

function ReadinessRow({ label, ok }: { label: string; ok: boolean }) {
  return (
    <div className="flex items-center justify-between py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
      <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>{label}</span>
      {ok ? (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium" style={{ color: '#4ade80' }}>
          <CheckCircle2 size={14} /> Ready
        </span>
      ) : (
        <span className="inline-flex items-center gap-1.5 text-xs font-medium" style={{ color: '#f87171' }}>
          <XCircle size={14} /> Not ready
        </span>
      )}
    </div>
  );
}

export default function ConsultancySettingsPage() {
  const qc = useQueryClient();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;

  const [selectedUserId, setSelectedUserId] = useState<string>('');

  const { data: consultantData, isLoading } = useQuery({
    queryKey: ['consultancy-settings-consultant'],
    queryFn: () => api.get('/admin/consultancy/settings/consultant').then(r => r.data as ConsultantResponse),
  });

  const { data: candidates } = useQuery({
    queryKey: ['consultancy-eligible-consultants'],
    queryFn: () => api.get('/admin/consultancy/settings/eligible-consultants').then(r => r.data as Consultant[]),
    enabled: isSuperAdmin,
  });

  const { data: readiness } = useQuery({
    queryKey: ['consultancy-readiness'],
    queryFn: () => api.get('/admin/consultancy/settings/readiness').then(r => r.data as Readiness),
  });

  const { data: notificationSettings } = useQuery({
    queryKey: ['consultancy-notification-settings'],
    queryFn: () => api.get('/admin/consultancy/settings/notifications').then(r => r.data as NotificationSettings),
  });

  const updateMutation = useMutation({
    mutationFn: (userId: number | null) => api.put('/admin/consultancy/settings/consultant', { user_id: userId }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['consultancy-settings-consultant'] });
      qc.invalidateQueries({ queryKey: ['consultancy-readiness'] });
      toast.success('Consultancy consultant updated. This affects new bookings only — existing appointments keep their original consultant.');
      setSelectedUserId('');
    },
    onError: (err: any) => toast.error(getErrorMessage(err, 'Failed to update the Consultancy consultant.')),
  });

  const updateNotificationsMutation = useMutation({
    mutationFn: (recipients: NotificationSettings['recipients']) =>
      api.put('/admin/consultancy/settings/notifications', { recipients }).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['consultancy-notification-settings'] });
      toast.success('New-booking notification setting updated.');
    },
    onError: (err: any) => toast.error(getErrorMessage(err, 'Failed to update the notification setting.')),
  });

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-5">
      <Link href="/admin/consultancy/dashboard" className="inline-flex items-center gap-1 text-sm" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Consultancy
      </Link>

      <div>
        <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <Settings2 size={20} /> Consultancy Settings
        </h1>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
          Configure the Consultancy consultant and review booking readiness.
        </p>
      </div>

      <div className="rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Consultancy consultant</h2>

        {isLoading ? (
          <div className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ) : consultantData?.consultant ? (
          <div className="flex items-center justify-between px-3 py-2.5 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{consultantData.consultant.name}</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{consultantData.consultant.email}</p>
            </div>
            <Badge tone="success">Configured</Badge>
          </div>
        ) : (
          <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <AlertTriangle size={16} style={{ color: '#facc15' }} className="mt-0.5 flex-shrink-0" />
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              {consultantData?.configured_but_ineligible
                ? 'The previously configured consultant is no longer eligible (inactive, banned, or role changed).'
                : 'No Consultancy consultant is configured yet.'}
            </p>
          </div>
        )}

        {isSuperAdmin ? (
          <div className="flex flex-wrap items-end gap-2 pt-1">
            <div className="space-y-1">
              <label className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Set consultant</label>
              <Select value={selectedUserId} onChange={e => setSelectedUserId(e.target.value)} className="w-full max-w-xs">
                <option value="">Select an eligible Admin or Super Admin</option>
                {(candidates ?? []).map(c => (
                  <option key={c.id} value={c.id}>{c.name} ({c.email})</option>
                ))}
              </Select>
            </div>
            <Button
              size="sm"
              disabled={!selectedUserId || updateMutation.isPending}
              onClick={() => updateMutation.mutate(Number(selectedUserId))}
            >
              Save
            </Button>
            {consultantData?.consultant && (
              <Button
                size="sm"
                variant="secondary"
                disabled={updateMutation.isPending}
                onClick={() => {
                  if (window.confirm('Unconfigure the Consultancy consultant? Consultancy bookings will fall back to a manual date request until a new consultant is configured.')) {
                    updateMutation.mutate(null);
                  }
                }}
              >
                Unconfigure
              </Button>
            )}
          </div>
        ) : (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Only a Super Admin can change the configured consultant.</p>
        )}

        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Changing the consultant only affects new bookings — existing appointments keep their original consultant and are never reassigned automatically.
        </p>

        <Link href="/admin/consultancy/availability" className="inline-flex text-xs font-medium" style={{ color: 'var(--gold)' }}>
          Manage Consultancy availability →
        </Link>
      </div>

      <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>New-booking notifications</h2>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Who gets notified in-app when a customer books a new consultation.
        </p>

        {notificationSettings ? (
          isSuperAdmin ? (
            <div className="flex flex-wrap items-end gap-2">
              <div className="space-y-1">
                <label className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Notify</label>
                <Select
                  value={notificationSettings.recipients}
                  onChange={e => updateNotificationsMutation.mutate(e.target.value as NotificationSettings['recipients'])}
                  disabled={updateNotificationsMutation.isPending}
                  className="w-full max-w-xs"
                >
                  <option value="all_admins">Every Admin &amp; Super Admin</option>
                  <option value="assigned_consultant">Only the assigned consultant</option>
                </Select>
              </div>
            </div>
          ) : (
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              {notificationSettings.recipients === 'all_admins' ? 'Every Admin & Super Admin' : 'Only the assigned consultant'}
            </p>
          )
        ) : (
          <div className="h-9 w-48 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        )}

        {!isSuperAdmin && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Only a Super Admin can change this setting.</p>
        )}
      </div>

      <div className="rounded-2xl p-5 space-y-1" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold mb-2" style={{ color: 'var(--text-primary)' }}>Live booking readiness</h2>
        {readiness ? (
          <>
            <ReadinessRow label="Consultant configured" ok={readiness.consultant_configured} />
            <ReadinessRow label="Availability configured" ok={readiness.availability_configured} />
            <ReadinessRow label="Active service available" ok={readiness.active_service_available} />
            <div className="pt-3">
              <Badge tone={readiness.ready ? 'success' : 'neutral'}>{readiness.ready ? 'Live booking ready' : 'Not yet ready'}</Badge>
            </div>
          </>
        ) : (
          <div className="h-24 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        )}
      </div>
    </div>
  );
}
