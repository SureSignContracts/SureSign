'use client';

import { useState, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ArrowRight, Eye, EyeOff, KeyRound } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';

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
      <div className="min-h-dvh flex items-center justify-center px-6" style={{ backgroundColor: '#ffffff' }}>
        <div className="w-full max-w-[380px] space-y-4 text-center">
          <p className="text-sm" style={{ color: '#737373' }}>
            This password reset link is missing required information.
          </p>
          <a href="/forgot-password" className="text-sm font-medium hover:underline" style={{ color: '#0f0f0f' }}>
            Request a new link
          </a>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-dvh flex items-center justify-center px-6" style={{ backgroundColor: '#ffffff' }}>
      <div className="w-full max-w-[380px] space-y-8">
        <div>
          <div
            className="w-11 h-11 rounded-xl flex items-center justify-center mb-6"
            style={{ backgroundColor: '#0f0f0f' }}
          >
            <KeyRound size={18} strokeWidth={1.75} color="#ffffff" />
          </div>
          <h2 className="text-[1.7rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
            Reset your password
          </h2>
          <p className="mt-1.5 text-sm" style={{ color: '#737373' }}>
            Choose a new password for <strong>{email}</strong>.
          </p>
        </div>

        {error && (
          <div
            className="rounded-xl px-4 py-3 text-sm"
            style={{ backgroundColor: 'rgba(220,38,38,0.06)', border: '1px solid rgba(220,38,38,0.18)', color: '#b91c1c' }}
          >
            {error}
          </div>
        )}

        {done ? (
          <div
            className="rounded-xl px-4 py-3 text-sm"
            style={{ backgroundColor: 'rgba(22,163,74,0.06)', border: '1px solid rgba(22,163,74,0.18)', color: '#15803d' }}
          >
            Password reset successfully. Redirecting to sign in…
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
                  className="w-full px-4 py-3 pr-11 rounded-xl text-sm bg-[#f7f7f7] border border-[#e5e5e5] focus:border-[#0f0f0f] focus:bg-white focus-visible:outline-2 focus-visible:outline-[#0f0f0f] focus-visible:outline-offset-2"
                  style={{ color: '#0f0f0f', transition: `border-color 300ms ${EASE}, background-color 300ms ${EASE}` }}
                />
                <button
                  type="button"
                  tabIndex={-1}
                  onClick={() => setShowPw(p => !p)}
                  className="absolute right-3.5 top-1/2 -translate-y-1/2 rounded transition-opacity hover:opacity-60"
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
                className="w-full px-4 py-3 rounded-xl text-sm bg-[#f7f7f7] border border-[#e5e5e5] focus:border-[#0f0f0f] focus:bg-white focus-visible:outline-2 focus-visible:outline-[#0f0f0f] focus-visible:outline-offset-2"
                style={{ color: '#0f0f0f', transition: `border-color 300ms ${EASE}, background-color 300ms ${EASE}` }}
              />
            </div>

            <button
              type="submit"
              disabled={isLoading}
              className="group relative w-full flex items-center justify-center rounded-full py-3 pl-6 pr-12 text-sm font-medium hover:bg-[#262626] active:scale-[0.98] disabled:opacity-40 disabled:cursor-not-allowed"
              style={{
                backgroundColor: '#0f0f0f',
                color: '#ffffff',
                transition: `background-color 300ms ${EASE}, transform 200ms ${EASE}, opacity 200ms ${EASE}`,
              }}
            >
              {isLoading ? 'Resetting…' : 'Reset password'}
              <span
                className="absolute right-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full flex items-center justify-center group-hover:translate-x-0.5"
                style={{ backgroundColor: 'rgba(255,255,255,0.12)', transition: `transform 300ms ${EASE}` }}
              >
                <ArrowRight size={13} strokeWidth={2} />
              </span>
            </button>
          </form>
        )}
      </div>
    </div>
  );
}

export default function ResetPasswordPage() {
  return (
    <Suspense fallback={null}>
      <ResetPasswordForm />
    </Suspense>
  );
}
