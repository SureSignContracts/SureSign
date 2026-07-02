'use client';

import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { Save, CheckCircle, XCircle, Copy, RefreshCw, Sparkles, Eye, EyeOff } from 'lucide-react';
import Toggle from '@/components/ui/Toggle';

export default function AdminSettingsPage() {
  const qc = useQueryClient();
  const [saved, setSaved] = useState(false);

  // ── Mirror settings state ──
  const [mirrorEnabled, setMirrorEnabled] = useState<boolean | null>(null);
  const [mirrorPath, setMirrorPath]       = useState<string | null>(null);
  const [testResult, setTestResult]       = useState<{ ok: boolean; message: string } | null>(null);
  const [copied, setCopied]               = useState(false);

  // ── AI settings state ──
  const [aiEnabled, setAiEnabled]           = useState<boolean | null>(null);
  const [promptsEnabled, setPromptsEnabled] = useState<boolean | null>(null);
  const [aiModel, setAiModel]               = useState<string | null>(null);
  const [anthropicKey, setAnthropicKey]     = useState('');
  const [showAiKey, setShowAiKey]           = useState(false);
  const [aiSaved, setAiSaved]               = useState(false);

  // ── Notifications state ──
  const [notificationEvents, setNotificationEvents] = useState<string[]>([]);
  const [notifSeeded, setNotifSeeded]               = useState(false);
  const [notifSaved, setNotifSaved]                 = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-settings'],
    queryFn: () => api.get('/admin/settings').then(r => r.data).catch(() => ({})),
  });

  const { data: suresignData } = useQuery({
    queryKey: ['admin-suresign-settings'],
    queryFn: () => api.get('/admin/suresign-settings').then(r => r.data?.data ?? {}),
  });

  useEffect(() => {
    if (!suresignData) return;
    if (mirrorEnabled === null) setMirrorEnabled(!!(suresignData as any).local_export_enabled);
    if (mirrorPath === null)    setMirrorPath((suresignData as any).local_export_path ?? '');
    if (aiEnabled === null)       setAiEnabled(!!(suresignData as any).ai_enabled);
    if (promptsEnabled === null)  setPromptsEnabled((suresignData as any).prompts_enabled ?? true);
    if (aiModel === null)         setAiModel((suresignData as any).ai_model ?? 'claude-3-5-sonnet-latest');
    if (!notifSeeded) {
      setNotificationEvents(Array.isArray((suresignData as any).notification_settings) ? (suresignData as any).notification_settings : []);
      setNotifSeeded(true);
    }
  }, [suresignData]); // eslint-disable-line react-hooks/exhaustive-deps

  const saveMutation = useMutation({
    mutationFn: (payload: any) => api.put('/admin/settings', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-settings'] });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  const mirrorMutation = useMutation({
    mutationFn: (payload: { local_export_enabled: boolean; local_export_path: string }) =>
      api.put('/admin/suresign-settings/local-mirror', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
    },
  });

  const testMutation = useMutation({
    mutationFn: (path: string) =>
      api.post('/admin/suresign-settings/test-local-mirror', { path }).then(r => r.data),
    onSuccess: (result: any) => setTestResult(result),
    onError: (err: any) => setTestResult({ ok: false, message: err?.response?.data?.message ?? 'Test failed.' }),
  });

  const aiMutation = useMutation({
    mutationFn: (payload: any) => api.put('/admin/suresign-settings/ai', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      setAnthropicKey('');
      setAiSaved(true);
      setTimeout(() => setAiSaved(false), 2500);
    },
  });

  const notifMutation = useMutation({
    mutationFn: (payload: { notification_settings: string[] }) =>
      api.put('/admin/suresign-settings/notifications', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-suresign-settings'] });
      setNotifSaved(true);
      setTimeout(() => setNotifSaved(false), 2500);
    },
  });

  const currentMirrorEnabled  = mirrorEnabled ?? !!(suresignData as any)?.local_export_enabled;
  const currentMirrorPath     = mirrorPath !== null ? mirrorPath : ((suresignData as any)?.local_export_path ?? '');
  const currentAiEnabled      = aiEnabled ?? !!(suresignData as any)?.ai_enabled;
  const currentPromptsEnabled = promptsEnabled ?? ((suresignData as any)?.prompts_enabled ?? true);
  const currentAiModel        = aiModel !== null ? aiModel : ((suresignData as any)?.ai_model ?? 'claude-3-5-sonnet-latest');
  const hasAnthropicKey       = !!(suresignData as any)?.has_anthropic_key;

  const NOTIFICATION_EVENTS: { key: string; label: string }[] = [
    { key: 'payment_application.submitted', label: 'New payment application submitted' },
    { key: 'payment_application.certified', label: 'Payment application certified' },
    { key: 'pay_less_notice.issued',        label: 'Pay Less Notice issued' },
    { key: 'variation.approved',            label: 'Variation approved' },
    { key: 'variation.rejected',            label: 'Variation rejected' },
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

      {/* General */}
      <section className="space-y-4">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>General</h2>
        <div className="rounded-2xl p-5 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          {[
            { label: 'Platform Name', key: 'platform_name', placeholder: 'SureSign' },
            { label: 'Support Email', key: 'support_email', placeholder: 'support@suresign.io' },
            { label: 'Max File Upload Size (MB)', key: 'max_upload_mb', placeholder: '50', type: 'number' },
          ].map(field => (
            <div key={field.key}>
              <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                {field.label}
              </label>
              <input
                type={field.type ?? 'text'}
                defaultValue={isLoading ? '' : ((data as any)?.[field.key] ?? '')}
                placeholder={field.placeholder}
                className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
            </div>
          ))}
        </div>
      </section>

      {/* Feature flags */}
      <section className="space-y-4">
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Feature Flags</h2>
        <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          {([
            { label: 'Document Generation', key: 'doc_gen_enabled', description: 'PDF and Word document generation' },
            { label: 'White-label Branding', key: 'white_label_enabled', description: 'Company custom branding' },
            { label: 'Self-registration', key: 'self_register_enabled', description: 'Allow companies to self-register' },
          ] as const).map((flag, i, arr) => (
            <div
              key={flag.key}
              className="flex items-center justify-between px-5 py-4"
              style={{ backgroundColor: 'var(--bg-surface)', borderBottom: i < arr.length - 1 ? '1px solid var(--border)' : 'none' }}
            >
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{flag.label}</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{flag.description}</p>
              </div>
              <Toggle
                checked={!!(data as any)?.[flag.key]}
                onChange={() => {}}
              />
            </div>
          ))}
        </div>
      </section>

      <div className="flex justify-end">
        <button
          onClick={() => saveMutation.mutate({})}
          disabled={saveMutation.isPending}
          className="flex items-center gap-2 px-5 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-60"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Save size={15} />
          {saved ? 'Saved!' : saveMutation.isPending ? 'Saving…' : 'Save Settings'}
        </button>
      </div>

      {/* ── AI Assistant ── */}
      <section className="space-y-4">
        <div>
          <h2 className="text-sm font-semibold flex items-center gap-1.5" style={{ color: 'var(--text-secondary)' }}>
            <Sparkles size={13} />
            AI Assistant
          </h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Enable AI-assisted contract analysis. API keys are stored securely and never exposed to the frontend.
          </p>
        </div>

        <div className="rounded-2xl p-5 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>

          {/* Enable AI toggle */}
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Enable AI Analysis</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Show &ldquo;Analyse Contract&rdquo; button when a contract file is uploaded
              </p>
            </div>
            <Toggle checked={currentAiEnabled} onChange={setAiEnabled} />
          </div>

          {/* Enable Prompt Library toggle */}
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Enable Prompt Library</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Show prompt buttons on variations, RFIs, and other records
              </p>
            </div>
            <Toggle checked={currentPromptsEnabled} onChange={setPromptsEnabled} />
          </div>

          {/* Provider (currently only Anthropic) */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Provider
            </label>
            <input
              type="text"
              value="Anthropic (Claude)"
              readOnly
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none opacity-60 cursor-not-allowed"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          {/* Model */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Model
            </label>
            <select
              value={currentAiModel}
              onChange={e => setAiModel(e.target.value)}
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            >
              <option value="claude-sonnet-4-6">claude-sonnet-4-6 (recommended)</option>
              <option value="claude-haiku-4-5-20251001">claude-haiku-4-5-20251001 (faster / lower cost)</option>
              <option value="claude-opus-4-8">claude-opus-4-8 (most capable)</option>
            </select>
          </div>

          {/* Anthropic API Key */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Anthropic API Key
            </label>
            <div className="relative">
              <input
                type={showAiKey ? 'text' : 'password'}
                value={anthropicKey}
                onChange={e => setAnthropicKey(e.target.value)}
                placeholder={hasAnthropicKey ? '••••••••  (key saved — enter new key to replace)' : 'sk-ant-…'}
                autoComplete="new-password"
                className="w-full px-3 py-2.5 pr-10 rounded-lg text-sm outline-none font-mono"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
              <button
                type="button"
                onClick={() => setShowAiKey(v => !v)}
                className="absolute right-3 top-1/2 -translate-y-1/2 opacity-50 hover:opacity-80"
                style={{ color: 'var(--text-muted)' }}
              >
                {showAiKey ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
            </div>
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              API keys are stored encrypted and never sent to the browser.
              {hasAnthropicKey && <span className="ml-1 text-green-600">A key is currently saved.</span>}
            </p>
          </div>

          <div className="flex justify-end pt-1">
            <button
              type="button"
              onClick={() =>
                aiMutation.mutate({
                  ai_enabled: currentAiEnabled,
                  prompts_enabled: currentPromptsEnabled,
                  ai_model: currentAiModel,
                  ...(anthropicKey ? { anthropic_api_key: anthropicKey } : {}),
                })
              }
              disabled={aiMutation.isPending}
              className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Save size={12} />
              {aiSaved ? 'Saved!' : aiMutation.isPending ? 'Saving…' : 'Save AI Settings'}
            </button>
          </div>
        </div>
      </section>

      {/* ── Notifications ── */}
      <section className="space-y-4">
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Notifications</h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Choose which events trigger an email notification.
            Emails are sent to the address configured in SureSign Settings → Email.
          </p>
        </div>

        <div className="rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
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
              className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Save size={12} />
              {notifSaved ? 'Saved!' : notifMutation.isPending ? 'Saving…' : 'Save Notification Settings'}
            </button>
          </div>
        </div>
      </section>

      {/* ── Local Document Mirror ── */}
      <section className="space-y-4">
        <div>
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Local Document Mirror</h2>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Laravel storage remains the source of truth. This mirror folder is a copy used for local
            access by Claude/Cowork or other desktop AI tools.
          </p>
        </div>

        <div className="rounded-2xl p-5 space-y-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>

          {/* Enable toggle */}
          <div className="flex items-center justify-between">
            <div>
              <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Enable Local Mirror</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Copy uploaded files to the path below after each upload
              </p>
            </div>
            <Toggle checked={currentMirrorEnabled} onChange={setMirrorEnabled} />
          </div>

          {/* Mirror path input */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Mirror Path
            </label>
            <div className="flex gap-2">
              <input
                type="text"
                value={currentMirrorPath}
                onChange={e => { setMirrorPath(e.target.value); setTestResult(null); }}
                placeholder="C:/Users/Admin/Documents/SureSign"
                className="flex-1 px-3 py-2.5 rounded-lg text-sm outline-none font-mono"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
              {currentMirrorPath && (
                <button
                  type="button"
                  onClick={() => {
                    navigator.clipboard.writeText(currentMirrorPath);
                    setCopied(true);
                    setTimeout(() => setCopied(false), 1500);
                  }}
                  title="Copy path"
                  className="px-3 rounded-lg text-xs font-medium transition-opacity hover:opacity-80"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
                >
                  {copied ? <CheckCircle size={14} /> : <Copy size={14} />}
                </button>
              )}
            </div>
          </div>

          {/* Test result */}
          {testResult && (
            <div
              className="flex items-start gap-2 rounded-lg px-3 py-2.5 text-xs"
              style={{
                backgroundColor: testResult.ok ? 'rgba(34,197,94,0.1)' : 'rgba(239,68,68,0.1)',
                border: `1px solid ${testResult.ok ? 'rgba(34,197,94,0.3)' : 'rgba(239,68,68,0.3)'}`,
                color: testResult.ok ? '#16a34a' : '#dc2626',
              }}
            >
              {testResult.ok
                ? <CheckCircle size={13} className="mt-0.5 flex-shrink-0" />
                : <XCircle size={13} className="mt-0.5 flex-shrink-0" />}
              {testResult.message}
            </div>
          )}

          {/* Buttons */}
          <div className="flex gap-2 pt-1">
            <button
              type="button"
              onClick={() => testMutation.mutate(currentMirrorPath)}
              disabled={!currentMirrorPath || testMutation.isPending}
              className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50 hover:opacity-80"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              <RefreshCw size={12} className={testMutation.isPending ? 'animate-spin' : ''} />
              {testMutation.isPending ? 'Testing…' : 'Test Path'}
            </button>

            <button
              type="button"
              onClick={() => mirrorMutation.mutate({
                local_export_enabled: currentMirrorEnabled,
                local_export_path: currentMirrorPath,
              })}
              disabled={mirrorMutation.isPending}
              className="flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-medium transition-opacity disabled:opacity-50"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Save size={12} />
              {mirrorMutation.isPending ? 'Saving…' : 'Save Mirror Settings'}
            </button>
          </div>

          {/* Docker note */}
          <div
            className="rounded-lg p-3 text-xs space-y-1"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
          >
            <p className="font-medium" style={{ color: 'var(--text-secondary)' }}>Docker Users</p>
            <p>
              Files are written inside the container. Add a volume in{' '}
              <span className="font-mono">docker-compose.yml</span> to make them appear on your PC:
            </p>
            <pre className="font-mono text-xs mt-1 overflow-x-auto whitespace-pre-wrap" style={{ color: 'var(--text-primary)' }}>
{`# Windows
- "C:/Users/Admin/Documents/SureSign:/var/www/html/storage/app/local-mirror/SureSign"
# Mac/Linux
- "/Users/admin/Documents/SureSign:/var/www/html/storage/app/local-mirror/SureSign"`}
            </pre>
            <p className="mt-1">
              Then set the mirror path to{' '}
              <span className="font-mono">/var/www/html/storage/app/local-mirror/SureSign</span>
            </p>
          </div>
        </div>
      </section>
    </div>
  );
}
