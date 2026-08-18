'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link2, CheckCircle2, XCircle, AlertTriangle, RefreshCw, Unplug, PlugZap, Activity } from 'lucide-react';
import toast from '@/lib/toast';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useAuthStore } from '@/store/authStore';
import Button from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

interface ConnectionDiagnostics {
  connected: boolean;
  status: string | null;
  connected_email: string | null;
  google_account_id: string | null;
  scopes: string[];
  connected_at: string | null;
  last_refreshed_at: string | null;
  last_successful_call_at: string | null;
  last_failed_call_at: string | null;
  last_failure_reason: string | null;
  consecutive_refresh_failures: number | null;
  connected_by: string | null;
  disconnected_at: string | null;
}

interface Diagnostics {
  connection: ConnectionDiagnostics;
  health: { state: string; missing_scopes: string[] };
  readiness: { connected: boolean; healthy: boolean; health_state: string; meet_available: boolean; ready: boolean };
}

const HEALTH_LABEL: Record<string, string> = {
  not_connected: 'Not connected',
  connected: 'Connected — not yet verified',
  token_expired: 'Token expired',
  refresh_failed: 'Refresh failed',
  permissions_missing: 'Permissions missing',
  calendar_unavailable: 'Calendar unavailable',
  healthy: 'Healthy',
};

const HEALTH_TONE: Record<string, 'neutral' | 'success' | 'warning' | 'danger' | 'info'> = {
  not_connected: 'neutral',
  connected: 'info',
  token_expired: 'warning',
  refresh_failed: 'danger',
  permissions_missing: 'danger',
  calendar_unavailable: 'warning',
  healthy: 'success',
};

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between py-2" style={{ borderBottom: '1px solid var(--border)' }}>
      <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>{label}</span>
      <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{value}</span>
    </div>
  );
}

function ReadinessRow({ label, ok }: { label: string; ok: boolean }) {
  return (
    <div className="flex items-center justify-between py-2" style={{ borderBottom: '1px solid var(--border)' }}>
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

export default function GoogleIntegrationPage() {
  const qc = useQueryClient();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;
  const [testResult, setTestResult] = useState<Record<string, unknown> | null>(null);

  const { data, isLoading, isError, error, refetch } = useQuery({
    // isError was previously not checked at all — a genuine failure to
    // load diagnostics (network blip, 500, etc.) fell through to the same
    // render path as "no Google account connected", telling an admin their
    // integration was disconnected when the real status was simply
    // unknown. See internal-docs/error-messaging-recovery-ux-audit.md's
    // Batch 6 Google findings.
    queryKey: ['google-integration-diagnostics'],
    queryFn: () => api.get('/admin/google/diagnostics').then(r => r.data as Diagnostics),
    refetchInterval: 30000,
  });

  const connectMutation = useMutation({
    mutationFn: () => api.post('/admin/google/oauth/connect').then(r => r.data as { url: string }),
    onSuccess: (result) => {
      window.location.href = result.url;
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to start the Google connection.')),
  });

  const disconnectMutation = useMutation({
    mutationFn: () => api.post('/admin/google/disconnect').then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['google-integration-diagnostics'] });
      toast.success('Google account disconnected.');
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to disconnect the Google account.')),
  });

  const testMutation = useMutation({
    mutationFn: () => api.post('/admin/google/test-connection').then(r => r.data),
    onSuccess: (result) => {
      setTestResult(result);
      qc.invalidateQueries({ queryKey: ['google-integration-diagnostics'] });
      if (result.healthy) {
        toast.success('Google Calendar connection is healthy.');
      } else {
        toast.error(result.error ?? 'The Google Calendar connection test failed.');
      }
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to test the Google connection.')),
  });

  const connection = data?.connection;
  const health = data?.health;
  const readiness = data?.readiness;
  const healthState = health?.state ?? 'not_connected';

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero eyebrow="Connected systems" title="Google Integration" description="Monitor the platform Google Calendar connection, authorization health and meeting readiness." loading={isLoading}
        metrics={[
          { label: 'Connection', value: connection?.connected ? 'Connected' : 'Offline', detail: connection?.connected_email ?? 'no account connected', icon: Link2 },
          { label: 'Health', value: HEALTH_LABEL[healthState] ?? healthState, detail: health?.missing_scopes?.length ? `${health.missing_scopes.length} missing scopes` : 'authorization state', icon: Activity },
          { label: 'Meet readiness', value: readiness?.meet_available ? 'Ready' : 'Unavailable', detail: readiness?.ready ? 'integration operational' : 'configuration required', icon: CheckCircle2 },
        ]}
      />

      <div className="rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Connection</h2>
          <Badge tone={HEALTH_TONE[healthState] ?? 'neutral'}>{HEALTH_LABEL[healthState] ?? healthState}</Badge>
        </div>

        {isLoading ? (
          <div className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ) : isError ? (
          <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <AlertTriangle size={16} style={{ color: '#f87171' }} className="mt-0.5 flex-shrink-0" />
            <div className="flex-1 min-w-0">
              <p className="text-sm" style={{ color: 'var(--text-primary)' }}>
                We couldn&rsquo;t check the Google connection status. This doesn&rsquo;t necessarily mean it&rsquo;s disconnected.
              </p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
              <button onClick={() => refetch()} className="text-xs font-medium mt-1.5 underline" style={{ color: 'var(--text-secondary)' }}>
                Try again
              </button>
            </div>
          </div>
        ) : connection?.connected ? (
          <div className="space-y-1">
            <InfoRow label="Connected account" value={connection.connected_email ?? '—'} />
            <InfoRow label="Connected by" value={connection.connected_by ?? '—'} />
            <InfoRow label="Connected at" value={connection.connected_at ? new Date(connection.connected_at).toLocaleString() : '—'} />
            <InfoRow label="Last refreshed" value={connection.last_refreshed_at ? new Date(connection.last_refreshed_at).toLocaleString() : 'Never'} />
            <InfoRow label="Last successful check" value={connection.last_successful_call_at ? new Date(connection.last_successful_call_at).toLocaleString() : 'Never'} />
            {connection.last_failure_reason && (
              <InfoRow label="Last failure" value={<span style={{ color: '#f87171' }}>{connection.last_failure_reason}</span>} />
            )}
          </div>
        ) : (
          <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <AlertTriangle size={16} style={{ color: '#facc15' }} className="mt-0.5 flex-shrink-0" />
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              No Google account is connected yet.
            </p>
          </div>
        )}

        {health?.missing_scopes && health.missing_scopes.length > 0 && (
          <div className="flex items-start gap-2 px-3 py-2.5 rounded-lg" style={{ backgroundColor: 'rgba(239,68,68,0.08)' }}>
            <AlertTriangle size={16} style={{ color: '#f87171' }} className="mt-0.5 flex-shrink-0" />
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              Missing granted scopes: {health.missing_scopes.join(', ')}. Reconnect to re-request them.
            </p>
          </div>
        )}

        {isSuperAdmin ? (
          <div className="flex flex-wrap gap-2 pt-1">
            <Button
              size="sm"
              onClick={() => connectMutation.mutate()}
              disabled={connectMutation.isPending}
            >
              <PlugZap size={14} className="mr-1.5" />
              {connection?.connected ? 'Reconnect' : 'Connect Google Account'}
            </Button>
            {connection?.connected && (
              <>
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => testMutation.mutate()}
                  disabled={testMutation.isPending}
                >
                  <Activity size={14} className="mr-1.5" />
                  Test Connection
                </Button>
                <Button
                  size="sm"
                  variant="secondary"
                  onClick={() => {
                    if (window.confirm('Disconnect the Google account? This can be reconnected at any time, but any dependent automation would stop working until then.')) {
                      disconnectMutation.mutate();
                    }
                  }}
                  disabled={disconnectMutation.isPending}
                >
                  <Unplug size={14} className="mr-1.5" />
                  Disconnect
                </Button>
              </>
            )}
          </div>
        ) : (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Only a Super Admin can connect, reconnect, disconnect, or test this connection.</p>
        )}

        {testResult && (
          <div className="rounded-lg px-3 py-2.5 text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            <p><strong>Last test result:</strong> {testResult.healthy ? 'Healthy' : 'Failed'}{typeof testResult.latency_ms === 'number' ? ` (${testResult.latency_ms}ms)` : ''}</p>
            {!!testResult.error && <p className="mt-1">{String(testResult.error)}</p>}
          </div>
        )}
      </div>

      <div className="rounded-2xl p-5 space-y-1" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold mb-2 flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <RefreshCw size={16} /> Automation readiness
        </h2>
        {readiness ? (
          <>
            <ReadinessRow label="Connected" ok={readiness.connected} />
            <ReadinessRow label="Healthy" ok={readiness.healthy} />
            <ReadinessRow label="Meet generation available" ok={readiness.meet_available} />
            <div className="pt-3">
              <Badge tone={readiness.ready ? 'success' : 'neutral'}>{readiness.ready ? 'Ready for future automation' : 'Not yet ready'}</Badge>
            </div>
          </>
        ) : (
          <div className="h-24 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        )}
      </div>
    </div>
  );
}
