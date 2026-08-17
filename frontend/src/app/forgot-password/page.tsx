'use client';

import { useState } from 'react';
import { ArrowLeft, ArrowRight, Mail } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import RecoveryShell from '@/components/auth/RecoveryShell';

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
    <RecoveryShell eyebrow="Account recovery" title="Forgot your password?" description="Enter the email used for your SureSign account and we’ll send a secure reset link." icon={Mail}>
      <div className="space-y-6">
        {error && (
          <div
            className="border-l-2 border-[#b7554c] bg-[#f9eeec] px-4 py-3 text-sm text-[#96392f]"
          >
            {error}
          </div>
        )}

        {sent ? (
          <div className="rounded-xl bg-[#e9f6ed] p-5 text-sm text-[#286c43]" role="status">
            <p className="font-semibold">Check your inbox</p>
            <p className="mt-1.5 leading-6 text-[#52705e]">If an account exists for <strong>{email}</strong>, a reset link is on its way.</p>
            <button onClick={() => setSent(false)} className="mt-4 text-xs font-semibold underline-offset-4 hover:underline">Use a different email</button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-2">
              <label htmlFor="recovery-email" className="block text-xs font-semibold text-[#3f4944]">
                Email address
              </label>
              <input
                id="recovery-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                placeholder="you@company.com"
                className="h-12 w-full rounded-xl border border-[#e1e5e2] bg-[#f5f6f5] px-4 text-sm placeholder:text-[#a1aaa5] hover:bg-[#f0f2f1] focus:border-[#4d8966] focus:bg-white focus-visible:outline-2 focus-visible:outline-[#4d8966] focus-visible:outline-offset-2"
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
              {isLoading ? 'Sending…' : 'Send reset link'}
              <span
                className="absolute right-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full flex items-center justify-center group-hover:translate-x-0.5"
                style={{ backgroundColor: 'rgba(255,255,255,0.12)', color: '#ffffff', transition: `transform 300ms ${EASE}` }}
              >
                <ArrowRight size={13} strokeWidth={2} />
              </span>
            </button>
          </form>
        )}

        <a href="/login" className="flex items-center gap-1.5 text-xs font-medium text-[#68736d] transition-all hover:-translate-x-0.5 hover:text-[#18211d]">
          <ArrowLeft size={13} strokeWidth={1.75} />
          Back to sign in
        </a>
      </div>
    </RecoveryShell>
  );
}
