'use client';

import { useEffect, useRef, useState } from 'react';
import { useSearchParams, useRouter } from 'next/navigation';
import { Loader2, CheckCircle2, XCircle } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';

/**
 * Google Integration Foundation, Stage 4A — the frontend landing page
 * config('google.redirect_uri') points to. Google redirects the bare
 * browser here with ?code=&state=; this page (already authenticated,
 * since it's under /admin) then makes the actual authenticated API call
 * to complete the exchange, rather than Google ever hitting an
 * unauthenticated backend route directly.
 */
export default function GoogleOAuthCallbackPage() {
  const searchParams = useSearchParams();
  const router = useRouter();

  const code = searchParams.get('code');
  const state = searchParams.get('state');
  const oauthError = searchParams.get('error');

  // The validation outcome is derivable synchronously from the URL at
  // render time — computed as the lazy initial state (not a same-tick
  // setState inside the effect below, which react-hooks/set-state-in-effect
  // rightly flags). The effect below only performs the actual async side
  // effect (the exchange API call) when the params were valid.
  const [status, setStatus] = useState<'processing' | 'success' | 'error'>(
    () => (oauthError || !code || !state ? 'error' : 'processing'),
  );
  const [message, setMessage] = useState<string>(() => {
    if (oauthError) return `Google declined the connection request: ${oauthError}`;
    if (!code || !state) return 'This link is missing required parameters. Please try connecting again.';
    return 'Completing Google connection…';
  });
  const ranOnce = useRef(false);

  useEffect(() => {
    if (ranOnce.current) return;
    if (!code || !state || oauthError) return;
    ranOnce.current = true;

    api.post('/admin/google/oauth/callback', { code, state })
      .then(() => {
        setStatus('success');
        setMessage('Google account connected successfully.');
        setTimeout(() => router.replace('/admin/google-integration'), 1500);
      })
      .catch((err) => {
        setStatus('error');
        setMessage(getErrorMessage(err, 'Failed to complete the Google connection.'));
      });
  }, [code, state, oauthError, router]);

  return (
    <div className="flex items-center justify-center min-h-[60vh] p-6">
      <div className="max-w-md w-full text-center rounded-2xl p-8 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {status === 'processing' && <Loader2 size={32} className="animate-spin mx-auto" style={{ color: 'var(--gold)' }} />}
        {status === 'success' && <CheckCircle2 size={32} className="mx-auto" style={{ color: '#4ade80' }} />}
        {status === 'error' && <XCircle size={32} className="mx-auto" style={{ color: '#f87171' }} />}
        <p className="text-sm" style={{ color: 'var(--text-primary)' }}>{message}</p>
        {status === 'error' && (
          <a href="/admin/google-integration" className="inline-block text-xs font-medium" style={{ color: 'var(--gold)' }}>
            ← Back to Google Integration
          </a>
        )}
      </div>
    </div>
  );
}
