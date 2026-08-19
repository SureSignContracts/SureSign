'use client';

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { Save } from 'lucide-react';
import Toggle from '@/components/ui/Toggle';
import Button from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';

export default function AdminSettingsPage() {
  const qc = useQueryClient();

  // ── General + feature flags state ──
  const [platformName, setPlatformName]   = useState<string | null>(null);
  const [supportEmail, setSupportEmail]   = useState<string | null>(null);
  const [maxUploadMb, setMaxUploadMb]     = useState<number | null>(null);
  const [docGenEnabled, setDocGenEnabled] = useState<boolean | null>(null);
  const [whiteLabelEnabled, setWhiteLabelEnabled] = useState<boolean | null>(null);
  const [selfRegisterEnabled, setSelfRegisterEnabled] = useState<boolean | null>(null);
  const [generalSeeded, setGeneralSeeded] = useState(false);

  // ── Notifications state ──
  const [notificationEvents, setNotificationEvents] = useState<string[]>([]);
  const [notifSeeded, setNotifSeeded]               = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin-settings'],
    queryFn: () => api.get('/admin/settings').then(r => r.data),
    retry: false,
  });

  const { data: suresignData } = useQuery({
    queryKey: ['admin-suresign-settings'],
    queryFn: () => api.get('/admin/suresign-settings').then(r => r.data?.data ?? {}),
  });

  useEffect(() => {
    if (!data || generalSeeded) return;
    setPlatformName((data as any).platform_name ?? '');
    setSupportEmail((data as any).support_email ?? '');
    setMaxUploadMb((data as any).max_upload_mb ?? 50);
    setDocGenEnabled((data as any).doc_gen_enabled ?? true);
    setWhiteLabelEnabled((data as any).white_label_enabled ?? true);
    setSelfRegisterEnabled((data as any).self_register_enabled ?? false);
    setGeneralSeeded(true);
  }, [data]); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    if (!suresignData) return;
    if (!notifSeeded) {
      setNotificationEvents(Array.isArray((suresignData as any).notification_settings) ? (suresignData as any).notification_settings : []);
      setNotifSeeded(true);
    }
  }, [suresignData]); // eslint-disable-line react-hooks/exhaustive-deps

  const generalMutation = useMutation({
    mutationFn: (payload: any) => api.put('/admin/settings', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-settings'] });
      toast.success('General settings saved.');
    },
    onError: (e) => toast.error(getErrorMessage(e, 'Failed to save general settings.')),
  });

  const flagsMutation = useMutation({
    mutationFn: (payload: any) => api.put('/admin/settings', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-settings'] });
      toast.success('Feature flags saved.');
    },
    onError: (e) => toast.error(getErrorMessage(e, 'Failed to save feature flags.')),
  });

  const notifMutation = useMutation({
    mutationFn: (payload: { notification_settings: string[] }) =>
      api.put('/admin/suresign-settings/notifications', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      toast.success('Notification settings saved.');
    },
    onError: (e) => toast.error(getErrorMessage(e, 'Failed to save notification settings.')),
  });

  const NOTIFICATION_EVENTS: { key: string; label: string }[] = [
    { key: 'payment_application.submitted', label: 'New payment application submitted' },
    { key: 'payment_application.certified', label: 'Payment application certified' },
    { key: 'payment_notice.issued',         label: 'Payment Notice issued' },
    { key: 'pay_less_notice.issued',        label: 'Pay Less Notice issued' },
    { key: 'variation.approved',            label: 'Variation approved' },
    { key: 'variation.rejected',            label: 'Variation rejected' },
    { key: 'final_account.signed',                    label: 'Final Account signed' },
    { key: 'final_account.final_certificate_issued',  label: 'Final Account certificate issued' },
    { key: 'final_account.closed',                    label: 'Final Account commercially closed' },
    { key: 'eot.decided',                   label: 'EOT decision recorded (granted or refused)' },
    { key: 'loss_and_expense.decided',      label: 'Loss & Expense decision recorded (agreed or rejected)' },
    { key: 'ai_analysis.completed',          label: 'AI analysis completed (contract or trade package)' },
    { key: 'ai_analysis.failed',             label: 'AI analysis failed (contract or trade package)' },
    { key: 'deadline.reminder',             label: 'Payment deadline approaching (3 days before)' },
  ];

  function toggleNotifEvent(key: string) {
    setNotificationEvents(prev =>
      prev.includes(key) ? prev.filter(k => k !== key) : [...prev, key]
    );
  }

  return (
    <div className="p-6 max-w-3xl mx-auto space-y-8">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>System Settings</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Platform-wide configuration and feature flags
        </p>
      </div>

      {isError && (
        <p className="text-sm px-4 py-3 rounded-lg" style={{ color: '#b91c1c', backgroundColor: 'rgba(220,38,38,0.08)' }}>
          Could not load the current settings. The fields below do not reflect what&rsquo;s actually saved — saving now would overwrite it. Reload the page and try again.
        </p>
      )}

      {/* General */}
      <section className="space-y-4">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>General</h2>
        <Card>
        <CardBody className="space-y-5">
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Platform Name
            </label>
            <input
              type="text"
              value={isLoading ? '' : (platformName ?? '')}
              onChange={e => setPlatformName(e.target.value)}
              placeholder="SureSign Contracts"
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Support Email
            </label>
            <input
              type="email"
              value={isLoading ? '' : (supportEmail ?? '')}
              onChange={e => setSupportEmail(e.target.value)}
              placeholder="support@suresign.io"
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              Shown on the login screen as the contact address for access requests.
            </p>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Max File Upload Size (MB)
            </label>
            <input
              type="number"
              min={1}
              max={2048}
              value={isLoading ? '' : (maxUploadMb ?? 50)}
              onChange={e => setMaxUploadMb(Number(e.target.value))}
              placeholder="50"
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              Applies to contract, document, and trade package file uploads platform-wide.
            </p>
          </div>
        </CardBody>
        </Card>
        <div className="flex justify-end">
          <Button
            onClick={() => generalMutation.mutate({
              platform_name: platformName,
              support_email: supportEmail,
              max_upload_mb: maxUploadMb,
            })}
            disabled={generalMutation.isPending || isError}
            size="lg"
          >
            <Save size={15} />
            {generalMutation.isPending ? 'Saving…' : 'Save General Settings'}
          </Button>
        </div>
      </section>

      {/* Feature flags */}
      <section className="space-y-4">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Feature Flags</h2>
        <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="flex items-center justify-between px-5 py-4" style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Document Generation</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>PDF and Word document generation platform-wide</p>
            </div>
            <Toggle checked={docGenEnabled ?? true} onChange={setDocGenEnabled} />
          </div>
          <div className="flex items-center justify-between px-5 py-4" style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>White-label Branding</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Allow organizations&rsquo; custom logo and colors on generated documents</p>
            </div>
            <Toggle checked={whiteLabelEnabled ?? true} onChange={setWhiteLabelEnabled} />
          </div>
          <div className="px-5 py-4" style={{ backgroundColor: 'var(--bg-surface)' }}>
            <div className="flex items-center justify-between">
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Self-registration</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Allow companies to self-register</p>
              </div>
              <Toggle checked={selfRegisterEnabled ?? false} onChange={setSelfRegisterEnabled} />
            </div>
            <p className="text-xs mt-2 px-2.5 py-1.5 rounded-lg" style={{ color: 'var(--text-muted)', backgroundColor: 'rgba(249,115,22,0.08)' }}>
              This app has no self-registration signup flow yet — this flag is saved for when that flow is built.
            </p>
          </div>
        </div>
        <div className="flex justify-end">
          <Button
            onClick={() => flagsMutation.mutate({
              doc_gen_enabled: docGenEnabled,
              white_label_enabled: whiteLabelEnabled,
              self_register_enabled: selfRegisterEnabled,
            })}
            disabled={flagsMutation.isPending || isError}
            size="lg"
          >
            <Save size={15} />
            {flagsMutation.isPending ? 'Saving…' : 'Save Feature Flags'}
          </Button>
        </div>
      </section>

      {/* ── Notifications ── */}
      <section className="space-y-4">
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Notifications</h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Choose which events trigger an email notification.
            Emails are sent to the address configured in SureSign Contracts settings → Email.
          </p>
        </div>

        <Card>
        <CardBody className="space-y-4">
          {NOTIFICATION_EVENTS.map(ev => {
            const isChecked = (notificationEvents ?? []).includes(ev.key);
            return (
              <div
                key={ev.key}
                className="flex items-center gap-3 select-none"
              >
                <Toggle checked={isChecked} onChange={() => toggleNotifEvent(ev.key)} />
                <span className="text-sm" style={{ color: 'var(--text-primary)' }}>{ev.label}</span>
              </div>
            );
          })}

          <div className="flex justify-end pt-1">
            <button
              type="button"
              onClick={() => notifMutation.mutate({ notification_settings: notificationEvents })}
              disabled={notifMutation.isPending}
              className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Save size={12} />
              {notifMutation.isPending ? 'Saving…' : 'Save Notification Settings'}
            </button>
          </div>
        </CardBody>
        </Card>
      </section>

    </div>
  );
}
