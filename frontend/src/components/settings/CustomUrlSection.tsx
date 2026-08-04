'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Globe, ExternalLink, X, Check } from 'lucide-react';
import api from '@/lib/api';
import toast from 'react-hot-toast';

interface UrlSlugData {
  url_slug: string | null;
  entitled: boolean;
  preview_url: string | null;
}

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
    const fieldError = response?.data?.errors?.url_slug?.[0];
    if (fieldError) return fieldError;
    if (response?.data?.message) return response.data.message;
  }
  return fallback;
}

function rootDomain(): string | null {
  return process.env.NEXT_PUBLIC_ORGANISATION_BRANDED_ROOT_DOMAIN || null;
}

function Skeleton() {
  return (
    <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
      <div className="h-24 rounded-xl animate-pulse motion-reduce:animate-none" style={{ backgroundColor: 'var(--bg-elevated)' }} />
    </div>
  );
}

/**
 * Organisation URL Branding — customer self-service Custom URL section on
 * Company Branding. Backend is always the authority on entitlement and
 * validation (`GET`/`PUT`/`DELETE /organization/url-slug`) — this
 * component only ever renders what the backend already decided; it never
 * hides a control to enforce something the backend wouldn't also enforce
 * itself (see App\Http\Controllers\Api\OrganizationBrandingUrlController).
 */
export default function CustomUrlSection() {
  const qc = useQueryClient();
  const { data, isLoading } = useQuery<UrlSlugData>({
    queryKey: ['organization-url-slug'],
    queryFn: () => api.get('/organization/url-slug').then(r => r.data?.data),
  });

  const [editing, setEditing] = useState(false);
  const [slugInput, setSlugInput] = useState('');
  const [confirmOpen, setConfirmOpen] = useState<'save' | 'remove' | null>(null);
  const [fieldError, setFieldError] = useState<string | null>(null);

  const invalidate = () => qc.invalidateQueries({ queryKey: ['organization-url-slug'] });

  const saveMutation = useMutation({
    mutationFn: (slug: string) => api.put('/organization/url-slug', { url_slug: slug }),
    onSuccess: () => {
      toast.success('Your custom URL has been saved.');
      invalidate();
      setEditing(false);
      setConfirmOpen(null);
      setFieldError(null);
    },
    onError: (e: unknown) => {
      setFieldError(extractErrorMessage(e, 'Could not save that URL.'));
      setConfirmOpen(null);
    },
  });

  const removeMutation = useMutation({
    mutationFn: () => api.delete('/organization/url-slug'),
    onSuccess: () => {
      toast.success('Your custom URL has been removed.');
      invalidate();
      setConfirmOpen(null);
    },
    onError: (e: unknown) => {
      toast.error(extractErrorMessage(e, 'Could not remove that URL.'));
      setConfirmOpen(null);
    },
  });

  if (isLoading) return <Skeleton />;
  if (!data) return null;

  const domain = rootDomain();
  const previewFor = (slug: string) => (domain ? `${slug}.${domain}` : null);

  // No entitlement and nothing currently configured — render a calm
  // upgrade prompt rather than hiding the section entirely (a customer
  // who already had this and lost it via a plan change should still see
  // it, handled by the "entitled === false but url_slug set" case below).
  if (!data.entitled && !data.url_slug) {
    return (
      <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
        <div className="flex items-center gap-2 mb-1">
          <Globe size={14} style={{ color: 'var(--text-muted)' }} />
          <p className="text-xs font-semibold" style={{ color: 'var(--text-secondary)' }}>Custom URL</p>
        </div>
        <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>
            Choose a branded SureSign address for your customer-facing appointment and consultation links.
          </p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
            Available on your current plan&rsquo;s upgrade — contact us to enable this for your organisation.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1.5rem' }}>
      <div className="flex items-center gap-2 mb-1">
        <Globe size={14} style={{ color: 'var(--text-muted)' }} />
        <p className="text-xs font-semibold" style={{ color: 'var(--text-secondary)' }}>Custom URL</p>
      </div>
      <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
        Choose a branded SureSign address for customer-facing appointment and consultation links.
      </p>

      {!data.entitled && data.url_slug && (
        <p className="text-xs mb-3 px-3 py-2 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
          Your current custom URL keeps working, but changing it isn&rsquo;t available on your current plan. Contact us to restore full access.
        </p>
      )}

      {!editing && data.url_slug && (
        <div className="rounded-xl p-4 space-y-3" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <Check size={14} style={{ color: '#4ade80' }} />
            <span className="text-sm font-mono" style={{ color: 'var(--text-primary)' }}>{data.preview_url?.replace(/^https?:\/\//, '').replace(/\/$/, '') ?? previewFor(data.url_slug)}</span>
          </div>
          <div className="flex gap-2 flex-wrap">
            {data.preview_url && (
              <a
                href={data.preview_url}
                target="_blank"
                rel="noreferrer"
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              >
                <ExternalLink size={12} /> Open URL
              </a>
            )}
            {data.entitled && (
              <button
                onClick={() => { setSlugInput(data.url_slug ?? ''); setFieldError(null); setEditing(true); }}
                className="px-3 py-1.5 rounded-lg text-xs font-medium"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              >
                Change
              </button>
            )}
            <button
              onClick={() => setConfirmOpen('remove')}
              className="px-3 py-1.5 rounded-lg text-xs font-medium"
              style={{ backgroundColor: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', color: '#f87171' }}
            >
              Remove
            </button>
          </div>
        </div>
      )}

      {(editing || (!data.url_slug && data.entitled)) && (
        <div className="rounded-xl p-4 space-y-3" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <input
              value={slugInput}
              onChange={e => { setSlugInput(e.target.value); setFieldError(null); }}
              placeholder="your-company"
              className="flex-1 px-3 py-2.5 rounded-lg text-sm outline-none font-mono min-w-0"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            <span className="text-sm font-mono flex-shrink-0" style={{ color: 'var(--text-muted)' }}>
              .{domain ?? 'suresigncontracts.app'}
            </span>
          </div>

          {slugInput && (
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Your address will be: <span className="font-mono">{previewFor(slugInput) ?? `${slugInput}.suresigncontracts.app`}</span>
            </p>
          )}
          {fieldError && <p className="text-xs" style={{ color: '#f87171' }}>{fieldError}</p>}

          <div className="flex gap-2">
            {editing && (
              <button
                onClick={() => { setEditing(false); setFieldError(null); }}
                className="px-3 py-1.5 rounded-lg text-xs font-medium"
                style={{ backgroundColor: 'var(--bg-surface)', color: 'var(--text-secondary)' }}
              >
                Cancel
              </button>
            )}
            <button
              onClick={() => setConfirmOpen('save')}
              disabled={!slugInput.trim() || saveMutation.isPending}
              className="px-3 py-1.5 rounded-lg text-xs font-medium disabled:opacity-50"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {saveMutation.isPending ? 'Saving…' : 'Save'}
            </button>
          </div>
        </div>
      )}

      {confirmOpen === 'save' && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setConfirmOpen(null)}>
          <div
            className="w-full max-w-sm rounded-2xl p-6 ss-animate-in"
            style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-start justify-between mb-3">
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
                {data.url_slug ? 'Change your custom URL?' : 'Set your custom URL?'}
              </h2>
              <button onClick={() => setConfirmOpen(null)}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
            </div>
            <p className="text-sm mb-5" style={{ color: 'var(--text-secondary)' }}>
              Your customer-facing links will use <span className="font-mono">{previewFor(slugInput)}</span> going forward.
              {data.url_slug && ' Links already sent to customers using your previous address will keep working.'}
            </p>
            <div className="flex gap-3">
              <button onClick={() => setConfirmOpen(null)} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button
                onClick={() => saveMutation.mutate(slugInput.trim())}
                disabled={saveMutation.isPending}
                className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                {saveMutation.isPending ? 'Saving…' : 'Confirm'}
              </button>
            </div>
          </div>
        </div>
      )}

      {confirmOpen === 'remove' && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setConfirmOpen(null)}>
          <div
            className="w-full max-w-sm rounded-2xl p-6 ss-animate-in"
            style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
            onClick={e => e.stopPropagation()}
          >
            <div className="flex items-start justify-between mb-3">
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Remove your custom URL?</h2>
              <button onClick={() => setConfirmOpen(null)}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
            </div>
            <p className="text-sm mb-5" style={{ color: 'var(--text-secondary)' }}>
              Your customer-facing links will go back to the default SureSign address. Links already sent to customers will keep working.
            </p>
            <div className="flex gap-3">
              <button onClick={() => setConfirmOpen(null)} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button
                onClick={() => removeMutation.mutate()}
                disabled={removeMutation.isPending}
                className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
                style={{ backgroundColor: '#ef4444', color: '#fff' }}
              >
                {removeMutation.isPending ? 'Removing…' : 'Remove'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
