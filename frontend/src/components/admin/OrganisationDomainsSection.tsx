'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Globe2, X, RefreshCw } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge, Tone } from '@/components/ui/Badge';
import { formatDateTime } from '@/lib/dateTime';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';

const REASON_MIN_LENGTH = 10;

interface OrganizationDomain {
  id: number;
  hostname: string;
  status: 'pending' | 'awaiting_dns' | 'verified' | 'active' | 'disabled' | 'failed' | 'removed';
  verification_token: string;
  verification_method: string;
  last_checked_at: string | null;
  last_check_result: string | null;
  verified_at: string | null;
  activated_at: string | null;
}

const STATUS_TONE: Record<string, Tone> = {
  pending: 'neutral',
  awaiting_dns: 'warning',
  verified: 'info',
  active: 'success',
  disabled: 'neutral',
  failed: 'danger',
  removed: 'neutral',
};

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
    const fieldError = response?.data?.errors?.hostname?.[0];
    if (fieldError) return fieldError;
    if (response?.data?.message) return response.data.message;
  }
  return fallback;
}

function ReasonDialog({
  title,
  organizationName,
  confirmLabel,
  confirmColor,
  onClose,
  onSubmit,
  submitting,
  children,
}: {
  title: string;
  organizationName: string;
  confirmLabel: string;
  confirmColor: string;
  onClose: () => void;
  onSubmit: (reason: string) => void;
  submitting: boolean;
  children?: React.ReactNode;
}) {
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-md rounded-2xl p-6 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-start justify-between mb-4">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{organizationName}</p>
          </div>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        {children}

        <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
          Reason <span style={{ color: '#ef4444' }}>*</span>
        </label>
        <textarea
          value={reason}
          onChange={e => setReason(e.target.value)}
          rows={3}
          className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none mb-3"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />

        <label className="flex items-start gap-2 text-xs mb-5" style={{ color: 'var(--text-secondary)' }}>
          <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
          I confirm this action for {organizationName}.
        </label>

        <div className="flex gap-3">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => onSubmit(reason.trim())}
            disabled={!reasonValid || !confirmed || submitting}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: confirmColor, color: '#fff' }}
          >
            {submitting ? 'Working…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * Organisation URL Branding, Phase 2 — Super Admin management of
 * customer-owned domains (Bring Your Own Domain). Read access (the list)
 * is visible to Admin too; every mutation is gated Super Admin only,
 * mirroring OrganisationUrlBrandingSection's own pattern.
 */
export default function OrganisationDomainsSection({
  organizationId,
  organizationName,
}: {
  organizationId: string | number;
  organizationName: string;
}) {
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;
  const queryClient = useQueryClient();

  const [addOpen, setAddOpen] = useState(false);
  const [actionDialog, setActionDialog] = useState<{ domain: OrganizationDomain; action: 'activate' | 'disable' | 'remove' } | null>(null);
  const [hostnameInput, setHostnameInput] = useState('');
  const [fieldError, setFieldError] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['org-domains', String(organizationId)],
    queryFn: () => api.get(`/organizations/${organizationId}/domains`).then(r => r.data?.data ?? []) as Promise<OrganizationDomain[]>,
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['org-domains', String(organizationId)] });

  const addMutation = useMutation({
    mutationFn: (payload: { hostname: string; reason: string; confirmed: true }) =>
      api.post(`/organizations/${organizationId}/domains`, payload).then(r => r.data),
    onSuccess: () => {
      toast.success('Domain registered — verification instructions below.');
      invalidate();
      setAddOpen(false);
      setHostnameInput('');
      setFieldError(null);
    },
    onError: (e: unknown) => setFieldError(extractErrorMessage(e, 'Failed to register domain.')),
  });

  const verifyMutation = useMutation({
    mutationFn: (domainId: number) => api.post(`/organizations/${organizationId}/domains/${domainId}/verify`).then(r => r.data),
    onSuccess: (data) => {
      const status = data?.data?.status;
      toast[status === 'verified' ? 'success' : 'error'](status === 'verified' ? 'Domain verified.' : 'Verification did not succeed yet — check the DNS records.');
      invalidate();
    },
    onError: (e: unknown) => toast.error(extractErrorMessage(e, 'Verification check failed.')),
  });

  const actionMutation = useMutation({
    mutationFn: ({ domain, action, reason }: { domain: OrganizationDomain; action: string; reason: string }) =>
      api.post(`/organizations/${organizationId}/domains/${domain.id}/${action}`, { reason, confirmed: true }).then(r => r.data),
    onSuccess: () => {
      toast.success('Domain updated.');
      invalidate();
      setActionDialog(null);
    },
    onError: (e: unknown) => toast.error(extractErrorMessage(e, 'Failed to update domain.')),
  });

  const txtPrefix = process.env.NEXT_PUBLIC_ORGANISATION_DOMAIN_VERIFICATION_TXT_PREFIX || '_suresign-verify';
  const cnameTarget = process.env.NEXT_PUBLIC_ORGANISATION_DOMAIN_CNAME_TARGET;

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Globe2 size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Customer-Owned Domains</CardTitle>
        </div>
        {isSuperAdmin && (
          <button
            onClick={() => setAddOpen(true)}
            className="px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            Add Domain
          </button>
        )}
      </CardHeader>
      <CardBody className="space-y-3">
        {isLoading && <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</p>}
        {!isLoading && (!data || data.length === 0) && (
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No customer-owned domains registered.</p>
        )}
        {data?.map(domain => (
          <div key={domain.id} className="rounded-xl p-3.5" style={{ border: '1px solid var(--border)' }}>
            <div className="flex items-center justify-between gap-2 flex-wrap">
              <span className="text-sm font-mono" style={{ color: 'var(--text-primary)' }}>{domain.hostname}</span>
              <Badge tone={STATUS_TONE[domain.status] ?? 'neutral'}>{domain.status.replace(/_/g, ' ')}</Badge>
            </div>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Last checked: {domain.last_checked_at ? formatDateTime(domain.last_checked_at) : 'never'}
              {domain.last_check_result ? ` — ${domain.last_check_result}` : ''}
            </p>

            {(domain.status === 'pending' || domain.status === 'awaiting_dns' || domain.status === 'failed') && (
              <div className="mt-2 rounded-lg p-3 text-xs space-y-1" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                <p className="font-medium" style={{ color: 'var(--text-primary)' }}>Verification instructions</p>
                <p>TXT record: <span className="font-mono">{txtPrefix}.{domain.hostname}</span></p>
                <p>TXT value: <span className="font-mono">suresign-domain-verify={domain.verification_token}</span></p>
                <p>CNAME record: <span className="font-mono">{domain.hostname}</span> → <span className="font-mono">{cnameTarget || '(configure ORGANISATION_DOMAIN_CNAME_TARGET)'}</span></p>
              </div>
            )}

            {isSuperAdmin && (
              <div className="flex gap-2 mt-3 flex-wrap">
                {domain.status !== 'removed' && domain.status !== 'active' && (
                  <button
                    onClick={() => verifyMutation.mutate(domain.id)}
                    disabled={verifyMutation.isPending}
                    className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  >
                    <RefreshCw size={11} /> Verify Now
                  </button>
                )}
                {domain.status === 'verified' && (
                  <button
                    onClick={() => setActionDialog({ domain, action: 'activate' })}
                    className="px-2.5 py-1 rounded-lg text-xs font-medium"
                    style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                  >
                    Activate
                  </button>
                )}
                {domain.status === 'active' && (
                  <button
                    onClick={() => setActionDialog({ domain, action: 'disable' })}
                    className="px-2.5 py-1 rounded-lg text-xs font-medium"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  >
                    Disable
                  </button>
                )}
                {domain.status !== 'removed' && (
                  <button
                    onClick={() => setActionDialog({ domain, action: 'remove' })}
                    className="px-2.5 py-1 rounded-lg text-xs font-medium"
                    style={{ backgroundColor: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171' }}
                  >
                    Remove
                  </button>
                )}
              </div>
            )}
          </div>
        ))}
      </CardBody>

      {addOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setAddOpen(false)}>
          <div
            className="w-full max-w-md rounded-2xl p-6 ss-animate-in"
            style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-start justify-between mb-5">
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Add Customer Domain</h2>
              <button onClick={() => setAddOpen(false)}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
            </div>

            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Hostname</label>
            <input
              value={hostnameInput}
              onChange={e => setHostnameInput(e.target.value)}
              placeholder="contracts.customer.com"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none mb-1 font-mono"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            {fieldError && <p className="text-xs mb-3" style={{ color: '#f87171' }}>{fieldError}</p>}

            <AddDomainReasonForm
              organizationName={organizationName}
              submitting={addMutation.isPending}
              onClose={() => setAddOpen(false)}
              onSubmit={reason => {
                setFieldError(null);
                addMutation.mutate({ hostname: hostnameInput.trim(), reason, confirmed: true });
              }}
            />
          </div>
        </div>
      )}

      {actionDialog?.action === 'activate' && (
        <ReasonDialog
          title="Activate Domain"
          organizationName={organizationName}
          confirmLabel="Activate"
          confirmColor="var(--gold)"
          onClose={() => setActionDialog(null)}
          submitting={actionMutation.isPending}
          onSubmit={reason => actionMutation.mutate({ domain: actionDialog.domain, action: 'activate', reason })}
        >
          <p className="text-xs mb-4" style={{ color: 'var(--text-secondary)' }}>
            Only activate once the real production origin/certificate coverage for <span className="font-mono">{actionDialog.domain.hostname}</span> is
            genuinely ready — activation immediately makes this the organisation's top-priority public hostname.
          </p>
        </ReasonDialog>
      )}
      {actionDialog?.action === 'disable' && (
        <ReasonDialog
          title="Disable Domain"
          organizationName={organizationName}
          confirmLabel="Disable"
          confirmColor="#ef4444"
          onClose={() => setActionDialog(null)}
          submitting={actionMutation.isPending}
          onSubmit={reason => actionMutation.mutate({ domain: actionDialog.domain, action: 'disable', reason })}
        />
      )}
      {actionDialog?.action === 'remove' && (
        <ReasonDialog
          title="Remove Domain"
          organizationName={organizationName}
          confirmLabel="Remove"
          confirmColor="#ef4444"
          onClose={() => setActionDialog(null)}
          submitting={actionMutation.isPending}
          onSubmit={reason => actionMutation.mutate({ domain: actionDialog.domain, action: 'remove', reason })}
        >
          <p className="text-xs mb-4" style={{ color: 'var(--text-secondary)' }}>
            This hostname is then permanently retired — it can never be re-registered by this or any other organisation.
          </p>
        </ReasonDialog>
      )}
    </Card>
  );
}

function AddDomainReasonForm({
  organizationName,
  submitting,
  onClose,
  onSubmit,
}: {
  organizationName: string;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (reason: string) => void;
}) {
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;

  return (
    <>
      <label className="block text-xs font-medium mb-1.5 mt-3" style={{ color: 'var(--text-secondary)' }}>
        Reason <span style={{ color: '#ef4444' }}>*</span>
      </label>
      <textarea
        value={reason}
        onChange={e => setReason(e.target.value)}
        rows={3}
        placeholder="Explain why this domain is being registered…"
        className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none mb-3"
        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />

      <label className="flex items-start gap-2 text-xs mb-5" style={{ color: 'var(--text-secondary)' }}>
        <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
        I confirm registering this domain for {organizationName}.
      </label>

      <div className="flex gap-3">
        <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
          Cancel
        </button>
        <button
          onClick={() => onSubmit(reason.trim())}
          disabled={!reasonValid || !confirmed || submitting}
          className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          {submitting ? 'Registering…' : 'Register Domain'}
        </button>
      </div>
    </>
  );
}
