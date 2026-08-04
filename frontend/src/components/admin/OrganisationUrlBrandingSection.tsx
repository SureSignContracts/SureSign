'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Globe, X } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { formatDateTime } from '@/lib/dateTime';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';

const REASON_MIN_LENGTH = 10;

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
    const fieldError = response?.data?.errors?.url_slug?.[0];
    if (fieldError) return fieldError;
    if (response?.data?.message) return response.data.message;
  }
  return fallback;
}

function previewUrl(slug: string): string | null {
  const rootDomain = process.env.NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN;
  if (!rootDomain) return null;
  return `https://${slug}.${rootDomain}`;
}

/**
 * Organisation URL Branding, Phase 1 — Super Admin ONLY (mirrors
 * OrganizationSubscriptionSection's own Super-Admin-gated mutation pattern
 * and the backend's `role:Super Admin` route group for these two
 * endpoints). Read-only display (current slug + preview URL) is visible to
 * anyone who can reach this page — only the Set/Change/Remove actions are
 * gated.
 */
export default function OrganisationUrlBrandingSection({
  organizationId,
  organizationName,
  urlSlug,
}: {
  organizationId: string | number;
  organizationName: string;
  urlSlug: string | null;
}) {
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;
  const queryClient = useQueryClient();

  const [dialogOpen, setDialogOpen] = useState<'set' | 'remove' | null>(null);
  const [slugInput, setSlugInput] = useState(urlSlug ?? '');
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [fieldError, setFieldError] = useState<string | null>(null);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['admin-company', String(organizationId)] });

  const updateMutation = useMutation({
    mutationFn: (payload: { url_slug: string | null; reason: string; confirmed: true }) =>
      api.put(`/organizations/${organizationId}/url-slug`, payload).then(r => r.data),
    onSuccess: () => {
      toast.success('Organisation URL branding updated.');
      invalidate();
      closeDialog();
    },
    onError: (e: unknown) => setFieldError(extractErrorMessage(e, 'Failed to update URL branding.')),
  });

  const removeMutation = useMutation({
    mutationFn: (payload: { reason: string; confirmed: true }) =>
      api.delete(`/organizations/${organizationId}/url-slug`, { data: payload }).then(r => r.data),
    onSuccess: () => {
      toast.success('Organisation URL branding removed.');
      invalidate();
      closeDialog();
    },
    onError: (e: unknown) => setFieldError(extractErrorMessage(e, 'Failed to remove URL branding.')),
  });

  const closeDialog = () => {
    setDialogOpen(null);
    setReason('');
    setConfirmed(false);
    setFieldError(null);
    setSlugInput(urlSlug ?? '');
  };

  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;
  const preview = previewUrl(urlSlug ?? (slugInput || 'example'));

  const { data: history } = useQuery({
    queryKey: ['org-url-slug-history', String(organizationId)],
    queryFn: () => api.get(`/organizations/${organizationId}/url-slug-history`).then(r => r.data?.data ?? []) as Promise<{ url_slug: string; released_at: string }[]>,
  });

  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <Globe size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Branded URL</CardTitle>
        </div>
        {urlSlug ? <Badge tone="success">Configured</Badge> : <Badge tone="neutral">Not configured</Badge>}
      </CardHeader>
      <CardBody className="space-y-3">
        <div>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Current hostname slug</p>
          <p className="text-sm font-medium mt-0.5 font-mono" style={{ color: 'var(--text-primary)' }}>
            {urlSlug ?? '—'}
          </p>
        </div>

        {preview && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Preview: <span className="font-mono">{preview}</span>
          </p>
        )}
        {!process.env.NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN && (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            URL branding is not yet turned on platform-wide (no branded root domain configured) — public links will
            keep using the default hostname regardless of any slug set here.
          </p>
        )}

        {history && history.length > 0 && (
          <div className="pt-1">
            <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Slug history</p>
            <ul className="space-y-1">
              {history.map((h, i) => (
                <li key={i} className="text-xs flex items-center justify-between gap-2" style={{ color: 'var(--text-muted)' }}>
                  <span className="font-mono">{h.url_slug}</span>
                  <span>released {formatDateTime(h.released_at)}</span>
                </li>
              ))}
            </ul>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Released slugs redirect here while still owned by this organisation, and can never be claimed by another organisation.
            </p>
          </div>
        )}

        {isSuperAdmin && (
          <div className="flex gap-2 pt-1">
            <button
              onClick={() => setDialogOpen('set')}
              className="px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {urlSlug ? 'Change' : 'Set'} Branded URL
            </button>
            {urlSlug && (
              <button
                onClick={() => setDialogOpen('remove')}
                className="px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity hover:opacity-90"
                style={{ backgroundColor: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171' }}
              >
                Remove
              </button>
            )}
          </div>
        )}
      </CardBody>

      {dialogOpen === 'set' && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={closeDialog}>
          <div
            className="w-full max-w-md rounded-2xl p-6 ss-animate-in"
            style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-start justify-between mb-5">
              <div>
                <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Set Branded URL</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{organizationName}</p>
              </div>
              <button onClick={closeDialog}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
            </div>

            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Hostname slug <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <input
              value={slugInput}
              onChange={e => setSlugInput(e.target.value)}
              placeholder="star-affinity"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none mb-1 font-mono"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
              Lowercase letters, numbers, and hyphens only. {previewUrl(slugInput || 'example') && (
                <>Preview: <span className="font-mono">{previewUrl(slugInput || 'example')}</span></>
              )}
            </p>

            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Reason <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={3}
              placeholder="Explain why this hostname is being set…"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none mb-1"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            {!reasonValid && reason.length > 0 && (
              <p className="text-xs mb-2" style={{ color: '#f87171' }}>Reason must be at least {REASON_MIN_LENGTH} characters.</p>
            )}

            {fieldError && <p className="text-xs mb-3" style={{ color: '#f87171' }}>{fieldError}</p>}

            <label className="flex items-start gap-2 text-xs mb-5 mt-2" style={{ color: 'var(--text-secondary)' }}>
              <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
              I confirm changing {organizationName}&rsquo;s public branded hostname.
            </label>

            <div className="flex gap-3">
              <button onClick={closeDialog} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button
                onClick={() => {
                  setFieldError(null);
                  updateMutation.mutate({ url_slug: slugInput.trim() || null, reason: reason.trim(), confirmed: true });
                }}
                disabled={!slugInput.trim() || !reasonValid || !confirmed || updateMutation.isPending}
                className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                {updateMutation.isPending ? 'Saving…' : 'Save'}
              </button>
            </div>
          </div>
        </div>
      )}

      {dialogOpen === 'remove' && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={closeDialog}>
          <div
            className="w-full max-w-md rounded-2xl p-6 ss-animate-in"
            style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-start justify-between mb-5">
              <div>
                <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Remove Branded URL</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{organizationName}</p>
              </div>
              <button onClick={closeDialog}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
            </div>

            <div className="rounded-xl p-3.5 text-xs space-y-1.5 mb-4" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.2)', color: 'var(--text-secondary)' }}>
              <p className="font-medium" style={{ color: '#f87171' }}>Existing bookmarked/shared branded links will stop resolving.</p>
              <p>Public links generated after this change fall back to the default SureSign hostname.</p>
            </div>

            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Reason <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <textarea
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={3}
              placeholder="Explain why this hostname is being removed…"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none mb-1"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            {!reasonValid && reason.length > 0 && (
              <p className="text-xs mb-2" style={{ color: '#f87171' }}>Reason must be at least {REASON_MIN_LENGTH} characters.</p>
            )}
            {fieldError && <p className="text-xs mb-3" style={{ color: '#f87171' }}>{fieldError}</p>}

            <label className="flex items-start gap-2 text-xs mb-5 mt-2" style={{ color: 'var(--text-secondary)' }}>
              <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
              I confirm removing {organizationName}&rsquo;s public branded hostname.
            </label>

            <div className="flex gap-3">
              <button onClick={closeDialog} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button
                onClick={() => {
                  setFieldError(null);
                  removeMutation.mutate({ reason: reason.trim(), confirmed: true });
                }}
                disabled={!reasonValid || !confirmed || removeMutation.isPending}
                className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: '#ef4444', color: '#fff' }}
              >
                {removeMutation.isPending ? 'Removing…' : 'Remove'}
              </button>
            </div>
          </div>
        </div>
      )}
    </Card>
  );
}
