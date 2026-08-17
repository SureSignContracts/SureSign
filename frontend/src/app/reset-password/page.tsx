'use client';

import { useState, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ArrowRight, Eye, EyeOff, KeyRound } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import RecoveryShell from '@/components/auth/RecoveryShell';

const EASE = 'cubic-bezier(0.32, 0.72, 0, 1)';

function ResetPasswordForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const token = searchParams.get('token') || '';
  const email = searchParams.get('email') || '';

  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [showPw, setShowPw] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);

  async function handleSubmit(e: React.SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    setError('');

    if (password !== passwordConfirmation) {
      setError('Passwords do not match.');
      return;
    }

    setIsLoading(true);
    try {
      await api.post('/auth/reset-password', {
        token,
        email,
        password,
        password_confirmation: passwordConfirmation,
      });
      setDone(true);
      setTimeout(() => router.push('/login'), 2000);
    } catch (err) {
      setError(getErrorMessage(err, 'This password reset link is invalid or has expired.'));
    } finally {
      setIsLoading(false);
    }
  }

  if (!token || !email) {
    return (
      <RecoveryShell eyebrow="Link unavailable" title="Request a fresh reset link." description="This recovery link is missing information or is no longer complete." icon={KeyRound}>
        <div className="space-y-5">
          <p className="text-sm leading-6 text-[#68736d]">
            This password reset link is missing required information.
          </p>
          <a href="/forgot-password" className="flex h-12 items-center justify-center rounded-xl bg-[#18211d] text-sm font-semibold text-white transition-all hover:-translate-y-0.5">
            Request a new link
          </a>
        </div>
      </RecoveryShell>
    );
  }

  return (
    <RecoveryShell eyebrow="Secure credentials" title="Set a new password." description={`Choose a strong replacement password for ${email}.`} icon={KeyRound}>
      <div className="space-y-6">
        {error && (
          <div
            className="border-l-2 border-[#b7554c] bg-[#f9eeec] px-4 py-3 text-sm text-[#96392f]"
          >
            {error}
          </div>
        )}

        {done ? (
          <div className="rounded-xl bg-[#e9f6ed] p-5 text-sm text-[#286c43]" role="status">
            <p className="font-semibold">Password updated</p>
            <p className="mt-1.5 text-[#52705e]">Your new credentials are ready. Redirecting you to sign in…</p>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <label className="block text-xs font-medium" style={{ color: '#525252' }}>
                New password
              </label>
              <div className="relative">
                <input
                  type={showPw ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  autoComplete="new-password"
                  placeholder="••••••••"
                  className="h-12 w-full rounded-xl border border-[#e1e5e2] bg-[#f5f6f5] px-4 pr-12 text-sm hover:bg-[#f0f2f1] focus:border-[#4d8966] focus:bg-white focus-visible:outline-2 focus-visible:outline-[#4d8966] focus-visible:outline-offset-2"
                  style={{ color: '#0f0f0f', transition: `border-color 300ms ${EASE}, background-color 300ms ${EASE}` }}
                />
                <button
                  type="button"
                  onClick={() => setShowPw(p => !p)}
                  aria-label={showPw ? 'Hide passwords' : 'Show passwords'}
                  className="absolute right-1.5 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg transition-colors hover:bg-[#e8ebe9]"
                  style={{ color: '#a3a3a3' }}
                >
                  {showPw ? <EyeOff size={15} strokeWidth={1.75} /> : <Eye size={15} strokeWidth={1.75} />}
                </button>
              </div>
              <p className="text-xs" style={{ color: '#a3a3a3' }}>
                At least 8 characters, with upper and lowercase letters, a number, and a symbol.
              </p>
            </div>

            <div className="space-y-1.5">
              <label className="block text-xs font-medium" style={{ color: '#525252' }}>
                Confirm new password
              </label>
              <input
                type={showPw ? 'text' : 'password'}
                value={passwordConfirmation}
                onChange={(e) => setPasswordConfirmation(e.target.value)}
                required
                autoComplete="new-password"
                placeholder="••••••••"
                className="h-12 w-full rounded-xl border border-[#e1e5e2] bg-[#f5f6f5] px-4 text-sm hover:bg-[#f0f2f1] focus:border-[#4d8966] focus:bg-white focus-visible:outline-2 focus-visible:outline-[#4d8966] focus-visible:outline-offset-2"
                style={{ color: '#0f0f0f', transition: `border-color 300ms ${EASE}, background-color 300ms ${EASE}` }}
              />
            </div>

            <button
              type="submit"
              disabled={isLoading}
              className="group relative flex h-12 w-full items-center justify-center rounded-xl bg-[#18211d] pl-6 pr-12 text-sm font-semibold text-white transition-all hover:-translate-y-0.5 hover:shadow-[0_10px_24px_rgba(24,33,29,0.18)] active:translate-y-px disabled:cursor-not-allowed disabled:opacity-40"
              style={{
                transition: `background-color 300ms ${EASE}, transform 200ms ${EASE}, opacity 200ms ${EASE}`,
              }}
            >
              {isLoading ? 'Resetting…' : 'Reset password'}
              <span
                className="absolute right-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full flex items-center justify-center group-hover:translate-x-0.5"
                style={{ backgroundColor: 'rgba(255,255,255,0.12)', color: '#ffffff', transition: `transform 300ms ${EASE}` }}
              >
                <ArrowRight size={13} strokeWidth={2} />
              </span>
            </button>
          </form>
        )}
      </div>
    </RecoveryShell>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}
