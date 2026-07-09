'use client';

import { useState } from 'react';
import { ArrowLeft, ArrowRight, Mail } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';

const EASE = 'cubic-bezier(0.32, 0.72, 0, 1)';

export default function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  const [sent, setSent] = useState(false);

  async function handleSubmit(e: React.SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    setError('');
    setIsLoading(true);
    try {
      await api.post('/auth/forgot-password', { email });
      setSent(true);
    } catch (err) {
      setError(getErrorMessage(err, 'Something went wrong. Please try again.'));
    } finally {
      setIsLoading(false);
    }
  }

  return (
    <div className="min-h-dvh flex items-center justify-center px-6" style={{ backgroundColor: '#ffffff' }}>
      <div className="w-full max-w-[380px] space-y-8">
        <div>
          <div
            className="w-11 h-11 rounded-xl flex items-center justify-center mb-6"
            style={{ backgroundColor: '#0f0f0f' }}
          >
            <Mail size={18} strokeWidth={1.75} color="#ffffff" />
          </div>
          <h2 className="text-[1.7rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
            Forgot password?
          </h2>
          <p className="mt-1.5 text-sm" style={{ color: '#737373' }}>
            Enter your email and we&apos;ll send you a link to reset it.
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

        {sent ? (
          <div
            className="rounded-xl px-4 py-3 text-sm"
            style={{ backgroundColor: 'rgba(22,163,74,0.06)', border: '1px solid rgba(22,163,74,0.18)', color: '#15803d' }}
          >
            If an account exists for <strong>{email}</strong>, a password reset link has been sent. Check your inbox.
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-1.5">
              <label className="block text-xs font-medium" style={{ color: '#525252' }}>
                Email address
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                placeholder="you@company.com"
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
              {isLoading ? 'Sending…' : 'Send reset link'}
              <span
                className="absolute right-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full flex items-center justify-center group-hover:translate-x-0.5"
                style={{ backgroundColor: 'rgba(255,255,255,0.12)', transition: `transform 300ms ${EASE}` }}
              >
                <ArrowRight size={13} strokeWidth={2} />
              </span>
            </button>
          </form>
        )}

        <a href="/login" className="flex items-center gap-1.5 text-xs transition-opacity hover:opacity-60" style={{ color: '#737373' }}>
          <ArrowLeft size={13} strokeWidth={1.75} />
          Back to sign in
        </a>
      </div>
    </div>
  );
}
