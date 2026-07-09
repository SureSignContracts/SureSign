'use client';

import { useEffect, useState, Suspense } from 'react';
import { useSearchParams } from 'next/navigation';
import { CheckCircle2, XCircle, MailCheck } from 'lucide-react';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import { getErrorMessage } from '@/lib/getErrorMessage';

const MISSING_INFO_MESSAGE = 'This verification link is missing required information.';

function VerifyEmailContent() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token') || '';
  const email = searchParams.get('email') || '';
  const hasParams = !!token && !!email;
  const [status, setStatus] = useState<'verifying' | 'success' | 'error'>(hasParams ? 'verifying' : 'error');
  const [message, setMessage] = useState(hasParams ? '' : MISSING_INFO_MESSAGE);

  useEffect(() => {
    if (!hasParams) return;

    api.post('/auth/email/verify', { token, email })
      .then(() => {
        setStatus('success');
        // Refresh the cached user so is_onboarded/email_verified_at reflect reality.
        if (useAuthStore.getState().token) {
          useAuthStore.getState().fetchUser();
        }
      })
      .catch((err) => {
        setStatus('error');
        setMessage(getErrorMessage(err, 'This verification link is invalid or has expired.'));
      });
  }, [hasParams, token, email]);

  const dest = useAuthStore.getState().token ? '/app' : '/login';

  return (
    <div className="min-h-dvh flex items-center justify-center px-6" style={{ backgroundColor: '#ffffff' }}>
      <div className="w-full max-w-[380px] space-y-6 text-center">
        <div
          className="w-11 h-11 mx-auto rounded-xl flex items-center justify-center"
          style={{ backgroundColor: status === 'error' ? '#fef2f2' : '#0f0f0f' }}
        >
          {status === 'verifying' && <MailCheck size={18} strokeWidth={1.75} color="#ffffff" />}
          {status === 'success' && <CheckCircle2 size={18} strokeWidth={1.75} color="#ffffff" />}
          {status === 'error' && <XCircle size={18} strokeWidth={1.75} color="#dc2626" />}
        </div>

        {status === 'verifying' && (
          <p className="text-sm" style={{ color: '#737373' }}>Verifying your email…</p>
        )}

        {status === 'success' && (
          <>
            <h2 className="text-[1.4rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
              Email verified
            </h2>
            <p className="text-sm" style={{ color: '#737373' }}>
              Your email address has been confirmed.
            </p>
            <a
              href={dest}
              className="inline-flex items-center justify-center rounded-full px-6 py-3 text-sm font-medium hover:bg-[#262626]"
              style={{ backgroundColor: '#0f0f0f', color: '#ffffff' }}
            >
              Continue
            </a>
          </>
        )}

        {status === 'error' && (
          <>
            <h2 className="text-[1.4rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
              Verification failed
            </h2>
            <p className="text-sm" style={{ color: '#737373' }}>{message}</p>
            <a href="/login" className="text-sm font-medium hover:underline" style={{ color: '#0f0f0f' }}>
              Back to sign in
            </a>
          </>
        )}
      </div>
    </div>
  );
}

export default function VerifyEmailPage() {
  return (
    <Suspense fallback={null}>
      <VerifyEmailContent />
    </Suspense>
  );
}
