'use client';

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { useTheme } from '@/hooks/useTheme';

export default function LoginPage() {
  const router = useRouter();
  const login = useAuthStore((s) => s.login);
  const isLoading = useAuthStore((s) => s.isLoading);
  const { theme } = useTheme();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    try {
      await login(email, password);
      const user = useAuthStore.getState().user;
      if (user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin')) {
        router.push('/admin');
      } else {
        router.push('/app');
      }
    } catch (err: any) {
      setError(err.response?.data?.message || 'Login failed. Please check your credentials.');
    }
  }

  return (
    <div className="min-h-screen flex" style={{ backgroundColor: 'var(--bg-base)' }}>
      {/* Left branding panel */}
      <div className="hidden lg:flex lg:w-[45%] flex-col justify-between p-12 relative overflow-hidden"
           style={{ backgroundColor: 'var(--bg-surface)', borderRight: '1px solid var(--border)' }}>
        <div className="absolute inset-0 opacity-5"
             style={{ backgroundImage: 'radial-gradient(circle at 30% 50%, #B99566 0%, transparent 60%)' }} />

        {/* Logo */}
        <div className="relative z-10 flex items-center gap-3">
          <img src={theme === 'dark' ? '/logo_white/SureSign_WLOGO.png' : '/logo_black/SureSign_BLOGO.png'} alt="SureSign" className="w-10 h-10 object-contain flex-shrink-0" />
          <span className="text-xl font-semibold tracking-tight" style={{ color: 'var(--text-primary)' }}>SureSign</span>
        </div>

        {/* Hero text */}
        <div className="relative z-10 space-y-6">
          <div>
            <h1 className="text-4xl font-bold leading-tight" style={{ color: 'var(--text-primary)' }}>
              Construction<br />
              Administration<br />
              <span style={{ color: 'var(--gold)' }}>Automated.</span>
            </h1>
            <p className="mt-4 text-lg leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
              AI-powered contract administration, commercial workflows, and document management for construction professionals.
            </p>
          </div>

          {/* Feature list */}
          <ul className="space-y-3">
            {['AI-assisted document generation', 'Commercial administration & tracking', 'Contract & variation management', 'RFIs, meeting minutes, site diaries'].map((f) => (
              <li key={f} className="flex items-center gap-3" style={{ color: 'var(--text-secondary)' }}>
                <div className="w-1.5 h-1.5 rounded-full flex-shrink-0" style={{ backgroundColor: 'var(--gold)' }} />
                <span className="text-sm">{f}</span>
              </li>
            ))}
          </ul>
        </div>

        <p className="relative z-10 text-xs" style={{ color: 'var(--text-muted)' }}>
          © 2026 SureSign. All rights reserved.
        </p>
      </div>

      {/* Right login form */}
      <div className="flex-1 flex items-center justify-center p-8">
        <div className="w-full max-w-[400px] space-y-8">

          {/* Mobile logo */}
          <div className="lg:hidden flex items-center gap-3 mb-8">
            <div className="w-9 h-9 rounded-lg flex items-center justify-center"
                 style={{ backgroundColor: 'var(--gold)' }}>
              <span className="font-bold" style={{ color: 'var(--accent-fg)' }}>S</span>
            </div>
            <span className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>SureSign</span>
          </div>

          <div>
            <h2 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>
              Welcome back
            </h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--text-secondary)' }}>
              Sign in to your workspace
            </p>
          </div>

          {error && (
            <div className="rounded-lg p-3 text-sm"
                 style={{ backgroundColor: 'rgba(220,38,38,0.1)', border: '1px solid rgba(220,38,38,0.3)', color: '#fca5a5' }}>
              {error}
            </div>
          )}

          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="space-y-1.5">
              <label className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>
                Email address
              </label>
              <input
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                placeholder="you@company.com"
                className="w-full px-4 py-2.5 rounded-lg text-sm outline-none transition-all"
                style={{
                  backgroundColor: 'var(--bg-elevated)',
                  border: '1px solid var(--border)',
                  color: 'var(--text-primary)',
                }}
                onFocus={(e) => e.target.style.borderColor = 'var(--gold)'}
                onBlur={(e) => e.target.style.borderColor = 'var(--border)'}
              />
            </div>

            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <label className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>
                  Password
                </label>
                <a href="#" className="text-xs hover:underline" style={{ color: 'var(--gold)' }}>
                  Forgot password?
                </a>
              </div>
              <input
                type="password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                placeholder="••••••••"
                className="w-full px-4 py-2.5 rounded-lg text-sm outline-none transition-all"
                style={{
                  backgroundColor: 'var(--bg-elevated)',
                  border: '1px solid var(--border)',
                  color: 'var(--text-primary)',
                }}
                onFocus={(e) => e.target.style.borderColor = 'var(--gold)'}
                onBlur={(e) => e.target.style.borderColor = 'var(--border)'}
              />
            </div>

            <button
              type="submit"
              disabled={isLoading}
              className="w-full py-2.5 rounded-lg text-sm font-semibold transition-all disabled:opacity-50"
              style={{
                backgroundColor: 'var(--gold)',
                color: 'var(--accent-fg)',
                boxShadow: isLoading ? 'none' : '0 0 20px rgba(185,149,102,0.25)',
              }}
            >
              {isLoading ? 'Logging in…' : 'Login'}
            </button>
          </form>

          <p className="text-xs text-center" style={{ color: 'var(--text-muted)' }}>
            Need access?{' '}
            <a href="mailto:admin@suresign.app" className="hover:underline" style={{ color: 'var(--gold)' }}>
              Contact your administrator
            </a>
          </p>
        </div>
      </div>
    </div>
  );
}
