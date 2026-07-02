'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { Eye, EyeOff, Shield, Check } from 'lucide-react';

const FEATURES = [
  'Document generation',
  'Tender and subcontract package management',
  'RFIs, variations, notices, and meeting minutes',
  'Secure document registers and previews',
];

const MOCK_DOCS = [
  { ref: 'SP-COL-001', title: 'Subcontract Package — Landscaping',  status: 'Issued',   dot: '#4ade80' },
  { ref: 'RFI-042',    title: 'RFI — Structural Steel Detail',      status: 'Pending',  dot: '#facc15' },
  { ref: 'VAR-018',    title: 'Variation — Revised Ground Works',   status: 'Approved', dot: '#4ade80' },
];

export default function LoginPage() {
  const router    = useRouter();
  const login     = useAuthStore((s) => s.login);
  const isLoading = useAuthStore((s) => s.isLoading);
  const [email,    setEmail]    = useState('');
  const [password, setPassword] = useState('');
  const [showPw,   setShowPw]   = useState(false);
  const [error,    setError]    = useState('');
  // Start hidden — we reveal only once we've confirmed the user is NOT authenticated.
  // This avoids SSR/client hydration mismatch (window is undefined on server so we
  // cannot check localStorage until after mount).
  const [ready, setReady] = useState(false);

  useEffect(() => {
    // Check both localStorage keys in case they diverged
    const hasToken = (() => {
      if (localStorage.getItem('suresign_token')) return true;
      try {
        const p = localStorage.getItem('suresign-auth');
        return !!(p && JSON.parse(p)?.state?.token);
      } catch { return false; }
    })();

    if (hasToken) {
      router.replace('/app/projects');
      return; // stay hidden (ready stays false) while redirect happens
    }

    // Not authenticated — safe to show login form
    document.documentElement.setAttribute('data-theme', 'light');
    document.documentElement.style.removeProperty('--gold');
    document.documentElement.style.removeProperty('--accent-fg');
    setReady(true);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  if (!ready) return null;

  async function handleSubmit(e: React.SyntheticEvent<HTMLFormElement>) {
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
    <>
      {/* Keyframe animations */}
      <style>{`
        @keyframes drift1 {
          0%   { transform: translate(0px, 0px) scale(1); }
          33%  { transform: translate(40px, -30px) scale(1.08); }
          66%  { transform: translate(-20px, 20px) scale(0.95); }
          100% { transform: translate(0px, 0px) scale(1); }
        }
        @keyframes drift2 {
          0%   { transform: translate(0px, 0px) scale(1); }
          33%  { transform: translate(-50px, 30px) scale(1.1); }
          66%  { transform: translate(25px, -40px) scale(0.92); }
          100% { transform: translate(0px, 0px) scale(1); }
        }
        @keyframes drift3 {
          0%   { transform: translate(0px, 0px) scale(1); }
          50%  { transform: translate(30px, 50px) scale(1.06); }
          100% { transform: translate(0px, 0px) scale(1); }
        }
        @keyframes gridMove {
          0%   { transform: translate(0, 0); }
          100% { transform: translate(24px, 24px); }
        }
      `}</style>

      <div className="h-screen overflow-hidden flex flex-col lg:flex-row">

        {/* ── Left dark panel ──────────────────────────────────────────────── */}
        <div
          className="hidden lg:flex lg:w-[48%] xl:w-[50%] flex-col justify-between p-8 xl:p-12 relative overflow-hidden flex-shrink-0 h-full"
          style={{ backgroundColor: '#0d0d0d' }}
        >
          {/* Animated dot grid */}
          <div className="absolute inset-0 pointer-events-none" style={{ opacity: 0.06 }}>
            <svg
              className="absolute"
              style={{
                width: 'calc(100% + 24px)',
                height: 'calc(100% + 24px)',
                top: -12, left: -12,
                animation: 'gridMove 8s linear infinite',
              }}
              xmlns="http://www.w3.org/2000/svg"
            >
              <defs>
                <pattern id="dots" x="0" y="0" width="24" height="24" patternUnits="userSpaceOnUse">
                  <circle cx="1.5" cy="1.5" r="1.5" fill="white" />
                </pattern>
              </defs>
              <rect width="100%" height="100%" fill="url(#dots)" />
            </svg>
          </div>

          {/* Floating glow orbs */}
          <div
            className="absolute rounded-full pointer-events-none"
            style={{
              width: 420, height: 420,
              top: '-15%', left: '-10%',
              background: 'radial-gradient(circle, rgba(255,255,255,0.05) 0%, transparent 65%)',
              animation: 'drift1 18s ease-in-out infinite',
            }}
          />
          <div
            className="absolute rounded-full pointer-events-none"
            style={{
              width: 360, height: 360,
              bottom: '0%', right: '-5%',
              background: 'radial-gradient(circle, rgba(255,255,255,0.04) 0%, transparent 65%)',
              animation: 'drift2 22s ease-in-out infinite',
            }}
          />
          <div
            className="absolute rounded-full pointer-events-none"
            style={{
              width: 280, height: 280,
              top: '40%', right: '20%',
              background: 'radial-gradient(circle, rgba(255,255,255,0.03) 0%, transparent 65%)',
              animation: 'drift3 15s ease-in-out infinite',
            }}
          />

          {/* Logo */}
          <div className="relative z-10 flex items-center gap-3">
            <img src="/logo_white/SureSign_WLOGO.png" alt="SureSign" className="w-8 h-8 object-contain flex-shrink-0" />
            <span className="text-base font-semibold tracking-tight" style={{ color: '#f5f5f5' }}>SureSign</span>
          </div>

          {/* Hero content */}
          <div className="relative z-10 space-y-6">
            {/* Pill */}
            <div
              className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-medium border"
              style={{ borderColor: 'rgba(255,255,255,0.15)', color: 'rgba(255,255,255,0.5)' }}
            >
              <span className="w-1.5 h-1.5 rounded-full" style={{ backgroundColor: 'rgba(255,255,255,0.4)' }} />
              Construction Contract Administration
            </div>

            <div className="space-y-3">
              <h1 className="text-[1.75rem] xl:text-[2.1rem] font-bold leading-[1.18] tracking-tight" style={{ color: '#f5f5f5' }}>
                Construction Contract<br />
                Administration,{' '}
                <span style={{ color: 'rgba(255,255,255,0.42)' }}>Simplified.</span>
              </h1>
              <p className="text-sm leading-relaxed max-w-sm" style={{ color: 'rgba(255,255,255,0.42)' }}>
                Manage contract documents, tender packages, RFIs, variations, and project
                workflows from one secure workspace.
              </p>
            </div>

            {/* Feature list */}
            <ul className="space-y-2">
              {FEATURES.map((f) => (
                <li key={f} className="flex items-center gap-2.5">
                  <div
                    className="w-4 h-4 rounded flex items-center justify-center flex-shrink-0"
                    style={{ backgroundColor: 'rgba(255,255,255,0.08)', border: '1px solid rgba(255,255,255,0.14)' }}
                  >
                    <Check size={9} color="rgba(255,255,255,0.65)" strokeWidth={3} />
                  </div>
                  <span className="text-sm" style={{ color: 'rgba(255,255,255,0.5)' }}>{f}</span>
                </li>
              ))}
            </ul>

            {/* Document card */}
            <div
              className="rounded-xl overflow-hidden max-w-sm"
              style={{ backgroundColor: 'rgba(255,255,255,0.04)', border: '1px solid rgba(255,255,255,0.09)' }}
            >
              <div
                className="flex items-center justify-between px-4 py-2.5"
                style={{ borderBottom: '1px solid rgba(255,255,255,0.07)' }}
              >
                <span className="text-xs font-semibold tracking-widest uppercase" style={{ color: 'rgba(255,255,255,0.28)' }}>
                  Recent Documents
                </span>
                <span className="text-xs px-2 py-0.5 rounded" style={{ backgroundColor: 'rgba(255,255,255,0.07)', color: 'rgba(255,255,255,0.38)' }}>
                  Project
                </span>
              </div>
              {MOCK_DOCS.map((doc, i) => (
                <div
                  key={doc.ref}
                  className="flex items-center gap-3 px-4 py-2.5"
                  style={i < MOCK_DOCS.length - 1 ? { borderBottom: '1px solid rgba(255,255,255,0.05)' } : {}}
                >
                  <span className="w-1.5 h-1.5 rounded-full flex-shrink-0" style={{ backgroundColor: doc.dot }} />
                  <div className="flex-1 min-w-0">
                    <div className="text-xs font-medium truncate" style={{ color: 'rgba(255,255,255,0.68)' }}>{doc.title}</div>
                    <div className="text-xs mt-0.5" style={{ color: 'rgba(255,255,255,0.24)' }}>{doc.ref}</div>
                  </div>
                  <span
                    className="text-xs px-2 py-0.5 rounded font-medium flex-shrink-0"
                    style={{
                      backgroundColor: doc.status === 'Pending' ? 'rgba(250,204,21,0.1)' : 'rgba(74,222,128,0.1)',
                      color: doc.status === 'Pending' ? '#facc15' : '#4ade80',
                    }}
                  >
                    {doc.status}
                  </span>
                </div>
              ))}
            </div>
          </div>

          <p className="relative z-10 text-xs" style={{ color: 'rgba(255,255,255,0.18)' }}>
            © 2026 SureSign. All rights reserved.
          </p>
        </div>

        {/* ── Right form panel ─────────────────────────────────────────────── */}
        <div
          className="flex-1 flex items-center justify-center p-6 sm:p-10 h-full overflow-hidden"
          style={{ backgroundColor: '#ffffff' }}
        >
          <div className="w-full max-w-[380px] space-y-6">

            {/* Mobile logo */}
            <div className="lg:hidden flex items-center gap-3">
              <img
                src="/logo_black/SureSign_BLOGO.png"
                alt="SureSign"
                className="w-8 h-8 object-contain"
              />
              <span className="text-lg font-semibold" style={{ color: '#0f0f0f' }}>SureSign</span>
            </div>

            {/* Heading */}
            <div>
              <h2 className="text-2xl font-bold tracking-tight" style={{ color: '#0f0f0f' }}>
                Welcome back
              </h2>
              <p className="mt-1 text-sm" style={{ color: '#9ca3af' }}>
                Sign in to your workspace
              </p>
            </div>

            {/* Error */}
            {error && (
              <div
                className="rounded-xl px-4 py-3 text-sm flex items-start gap-2.5"
                style={{ backgroundColor: 'rgba(220,38,38,0.07)', border: '1px solid rgba(220,38,38,0.2)', color: '#f87171' }}
              >
                <span className="flex-shrink-0 mt-px">⚠</span>
                <span>{error}</span>
              </div>
            )}

            {/* Form */}
            <form onSubmit={handleSubmit} className="space-y-4">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold uppercase tracking-widest" style={{ color: '#9ca3af' }}>
                  Email address
                </label>
                <input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  required
                  autoComplete="email"
                  placeholder="you@company.com"
                  className="w-full px-4 py-3 rounded-xl text-sm outline-none transition-all"
                  style={{
                    backgroundColor: '#f8f8f8',
                    border: '1.5px solid #e5e7eb',
                    color: '#0f0f0f',
                  }}
                  onFocus={(e) => {
                    e.target.style.borderColor = '#0f0f0f';
                    e.target.style.backgroundColor = '#fff';
                  }}
                  onBlur={(e) => {
                    e.target.style.borderColor = '#e5e7eb';
                    e.target.style.backgroundColor = '#f8f8f8';
                  }}
                />
              </div>

              <div className="space-y-1.5">
                <div className="flex items-center justify-between">
                  <label className="text-xs font-semibold uppercase tracking-widest" style={{ color: '#9ca3af' }}>
                    Password
                  </label>
                  <a href="#" className="text-xs transition-opacity hover:opacity-60" style={{ color: '#6b7280' }}>
                    Forgot password?
                  </a>
                </div>
                <div className="relative">
                  <input
                    type={showPw ? 'text' : 'password'}
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    required
                    autoComplete="current-password"
                    placeholder="••••••••"
                    className="w-full px-4 py-3 pr-11 rounded-xl text-sm outline-none transition-all"
                    style={{
                      backgroundColor: '#f8f8f8',
                      border: '1.5px solid #e5e7eb',
                      color: '#0f0f0f',
                    }}
                    onFocus={(e) => {
                      e.target.style.borderColor = '#0f0f0f';
                      e.target.style.backgroundColor = '#fff';
                    }}
                    onBlur={(e) => {
                      e.target.style.borderColor = '#e5e7eb';
                      e.target.style.backgroundColor = '#f8f8f8';
                    }}
                  />
                  <button
                    type="button"
                    tabIndex={-1}
                    onClick={() => setShowPw(p => !p)}
                    className="absolute right-3.5 top-1/2 -translate-y-1/2 rounded transition-opacity hover:opacity-60"
                    style={{ color: '#9ca3af' }}
                  >
                    {showPw ? <EyeOff size={15} /> : <Eye size={15} />}
                  </button>
                </div>
              </div>

              <button
                type="submit"
                disabled={isLoading}
                className="w-full py-3 rounded-xl text-sm font-semibold transition-all disabled:opacity-40 disabled:cursor-not-allowed"
                style={{
                  backgroundColor: '#0f0f0f',
                  color: '#ffffff',
                  letterSpacing: '0.01em',
                }}
                onMouseEnter={(e) => { if (!isLoading) (e.currentTarget as HTMLElement).style.opacity = '0.82'; }}
                onMouseLeave={(e) => { if (!isLoading) (e.currentTarget as HTMLElement).style.opacity = '1'; }}
              >
                {isLoading ? 'Signing in…' : 'Sign in'}
              </button>
            </form>

            {/* Divider */}
            <div className="flex items-center gap-3">
              <div className="flex-1 h-px" style={{ backgroundColor: '#e5e7eb' }} />
              <span className="text-xs flex items-center gap-1" style={{ color: '#9ca3af' }}>
                <Shield size={11} />
                Secure access
              </span>
              <div className="flex-1 h-px" style={{ backgroundColor: '#e5e7eb' }} />
            </div>

            <p className="text-xs text-center" style={{ color: '#9ca3af' }}>
              Need access?{' '}
              <a href="mailto:admin@suresign.app" className="font-medium hover:underline" style={{ color: '#0f0f0f' }}>
                Contact your administrator
              </a>
            </p>
          </div>
        </div>
      </div>
    </>
  );
}
