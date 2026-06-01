'use client';

import { useState, useRef } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { Settings, Save, FolderOpen, CheckCircle, XCircle, Copy, RefreshCw } from 'lucide-react';

export default function AdminSettingsPage() {
  const qc = useQueryClient();
  const [saved, setSaved] = useState(false);

  // ── Mirror settings state ──
  const [mirrorEnabled, setMirrorEnabled] = useState<boolean | null>(null);
  const [mirrorPath, setMirrorPath]       = useState<string | null>(null);
  const [testResult, setTestResult]       = useState<{ ok: boolean; message: string } | null>(null);
  const [copied, setCopied]               = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-settings'],
    queryFn: () => api.get('/admin/settings').then(r => r.data).catch(() => ({})),
  });

  const { data: suresignData, isLoading: suresignLoading } = useQuery({
    queryKey: ['admin-suresign-settings'],
    queryFn: () => api.get('/admin/suresign-settings').then(r => r.data?.data ?? {}),
    onSuccess: (d: any) => {
      if (mirrorEnabled === null) setMirrorEnabled(!!d.local_export_enabled);
      if (mirrorPath === null) setMirrorPath(d.local_export_path ?? '');
    },
  });

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

  const currentMirrorEnabled = mirrorEnabled ?? !!suresignData?.local_export_enabled;
  const currentMirrorPath    = mirrorPath !== null ? mirrorPath : (suresignData?.local_export_path ?? '');

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
                defaultValue={isLoading ? '' : (data?.[field.key] ?? '')}
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
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Feature Flags</h2>        <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          {[
            { label: 'AI Assistant', key: 'ai_enabled', description: 'Enable AI features platform-wide' },
            { label: 'Document Generation', key: 'doc_gen_enabled', description: 'PDF and Word document generation' },
            { label: 'White-label Branding', key: 'white_label_enabled', description: 'Company custom branding' },
            { label: 'Self-registration', key: 'self_register_enabled', description: 'Allow companies to self-register' },
          ].map((flag, i, arr) => (
            <div
              key={flag.key}
              className="flex items-center justify-between px-5 py-4"
              style={{ backgroundColor: 'var(--bg-surface)', borderBottom: i < arr.length - 1 ? '1px solid var(--border)' : 'none' }}
            >
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{flag.label}</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{flag.description}</p>
              </div>
              <label className="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" className="sr-only peer" defaultChecked={!!data?.[flag.key]} />
                <div className="relative w-11 h-6 rounded-full transition-colors duration-200 peer-checked:bg-[var(--gold)] bg-[var(--bg-elevated)] border border-[var(--border)] peer-focus:ring-2 peer-focus:ring-[var(--gold)] peer-focus:ring-offset-1 peer-focus:ring-offset-[var(--bg-surface)] after:content-[''] after:absolute after:top-1/2 after:-translate-y-1/2 after:left-[3px] after:rounded-full after:h-[18px] after:w-[18px] dark:after:bg-white after:bg-black after:shadow after:transition-transform after:duration-200 peer-checked:after:translate-x-5" />
              </label>
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
            <label className="relative inline-flex items-center cursor-pointer">
              <input
                type="checkbox"
                className="sr-only peer"
                checked={currentMirrorEnabled}
                onChange={e => setMirrorEnabled(e.target.checked)}
              />
              <div className="relative w-11 h-6 rounded-full transition-colors duration-200 peer-checked:bg-[var(--gold)] bg-[var(--bg-elevated)] border border-[var(--border)] peer-focus:ring-2 peer-focus:ring-[var(--gold)] peer-focus:ring-offset-1 peer-focus:ring-offset-[var(--bg-surface)] after:content-[''] after:absolute after:top-1/2 after:-translate-y-1/2 after:left-[3px] after:rounded-full after:h-[18px] after:w-[18px] dark:after:bg-white after:bg-black after:shadow after:transition-transform after:duration-200 peer-checked:after:translate-x-5" />
            </label>
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
                placeholder={
                  typeof window !== 'undefined' && navigator.platform.startsWith('Win')
                    ? 'C:/Users/Admin/Documents/SureSign'
                    : '/home/admin/Documents/SureSign'
                }
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
            <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
              Windows: <span className="font-mono">C:/Users/Admin/Documents/SureSign</span> ·
              Mac: <span className="font-mono">/Users/admin/Documents/SureSign</span> ·
              Docker: mount a volume and set the container path
            </p>
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
