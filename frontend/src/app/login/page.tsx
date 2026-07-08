'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { Eye, EyeOff, Shield, ArrowRight } from 'lucide-react';

const EASE = 'cubic-bezier(0.32, 0.72, 0, 1)';

const FEATURES = [
  'Document generation',
  'Tender and subcontract package management',
  'RFIs, variations, notices, and meeting minutes',
  'Secure document registers and previews',
];

const MOCK_DOCS = [
  { ref: 'SP-COL-001', title: 'Subcontract Package: Landscaping',  status: 'Issued',   color: '#4ade80' },
  { ref: 'RFI-042',    title: 'RFI: Structural Steel Detail',      status: 'Pending',  color: '#facc15' },
  { ref: 'VAR-018',    title: 'Variation: Revised Ground Works',   status: 'Approved', color: '#4ade80' },
];

// Film grain — static SVG noise, fixed opacity, breaks digital flatness
const NOISE_URI = `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='160' height='160' filter='url(%23n)' opacity='0.4'/%3E%3C/svg%3E")`;

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
    <div className="min-h-dvh lg:h-dvh lg:overflow-hidden flex flex-col lg:flex-row">

      {/* ── Left panel — the contract record, set like a drawing sheet ─────── */}
      <div
        className="hidden lg:flex lg:w-[48%] xl:w-[50%] flex-col justify-between p-10 xl:p-14 relative overflow-hidden flex-shrink-0 h-full"
        style={{ backgroundColor: '#0a0a0a' }}
      >
        {/* Blueprint grid — static hairlines, faded toward the edges */}
        <div
          className="absolute inset-0 pointer-events-none"
          style={{
            backgroundImage:
              'linear-gradient(rgba(255,255,255,0.045) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.045) 1px, transparent 1px)',
            backgroundSize: '56px 56px',
            maskImage: 'radial-gradient(ellipse at 30% 35%, black 0%, transparent 78%)',
            WebkitMaskImage: 'radial-gradient(ellipse at 30% 35%, black 0%, transparent 78%)',
          }}
        />
        {/* Film grain */}
        <div
          className="absolute inset-0 pointer-events-none"
          style={{ backgroundImage: NOISE_URI, opacity: 0.05, mixBlendMode: 'overlay' }}
        />

        {/* Logo */}
        <div className="relative z-10 flex items-center gap-3 ss-animate-in">
          <img src="/logo_white/SureSign_WLOGO.png" alt="SureSign" className="w-8 h-8 object-contain flex-shrink-0" />
          <span className="text-base font-semibold tracking-tight" style={{ color: '#f5f5f5' }}>SureSign</span>
        </div>

        {/* Hero content */}
        <div className="relative z-10 space-y-8 max-w-md">
          <div className="ss-animate-in" style={{ animationDelay: '60ms' }}>
            <span
              className="inline-flex items-center gap-2 rounded-full px-3 py-1 text-[10px] uppercase tracking-[0.2em] font-medium border"
              style={{ borderColor: 'rgba(255,255,255,0.14)', color: 'rgba(255,255,255,0.48)' }}
            >
              Construction contract administration
            </span>
          </div>

          <div className="space-y-4 ss-animate-in" style={{ animationDelay: '120ms' }}>
            <h1
              className="text-[2.4rem] xl:text-[2.8rem] font-semibold leading-[1.08]"
              style={{ color: '#f5f5f5', letterSpacing: '-0.03em' }}
            >
              Run the contract,
              <br />
              <span style={{ color: 'rgba(255,255,255,0.38)' }}>not the paperwork.</span>
            </h1>
            <p className="text-sm leading-relaxed" style={{ color: 'rgba(255,255,255,0.45)', maxWidth: '24rem' }}>
              Payment applications, notices, variations and programme records,
              administered from one secure workspace.
            </p>
          </div>

          {/* Numbered editorial list */}
          <div className="ss-animate-in" style={{ animationDelay: '180ms' }}>
            {FEATURES.map((f, i) => (
              <div
                key={f}
                className="flex items-baseline gap-4 py-2.5"
                style={{ borderTop: '1px solid rgba(255,255,255,0.08)' }}
              >
                <span className="tabular-nums text-[11px] flex-shrink-0" style={{ color: 'rgba(255,255,255,0.28)' }}>
                  {String(i + 1).padStart(2, '0')}
                </span>
                <span className="text-sm" style={{ color: 'rgba(255,255,255,0.55)' }}>{f}</span>
              </div>
            ))}
          </div>

          {/* Document card — double bezel, machined */}
          <div
            className="ss-animate-in rounded-[1.4rem] p-1.5"
            style={{
              animationDelay: '260ms',
              backgroundColor: 'rgba(255,255,255,0.04)',
              border: '1px solid rgba(255,255,255,0.09)',
            }}
          >
            <div
              className="rounded-[calc(1.4rem-7px)] overflow-hidden"
              style={{
                backgroundColor: '#101010',
                border: '1px solid rgba(255,255,255,0.07)',
                boxShadow: 'inset 0 1px 0 rgba(255,255,255,0.06)',
              }}
            >
              <div
                className="flex items-center justify-between px-4 py-2.5"
                style={{ borderBottom: '1px solid rgba(255,255,255,0.07)' }}
              >
                <span className="text-[10px] font-medium tracking-[0.18em] uppercase" style={{ color: 'rgba(255,255,255,0.3)' }}>
                  Recent documents
                </span>
                <span className="flex items-center gap-1.5 text-[10px]" style={{ color: 'rgba(255,255,255,0.35)' }}>
                  <span className="w-1 h-1 rounded-full" style={{ backgroundColor: '#4ade80' }} />
                  Live register
                </span>
              </div>
              {MOCK_DOCS.map((doc, i) => (
                <div
                  key={doc.ref}
                  className="flex items-center gap-3 px-4 py-2.5"
                  style={i < MOCK_DOCS.length - 1 ? { borderBottom: '1px solid rgba(255,255,255,0.05)' } : {}}
                >
                  <span className="font-mono text-[10px] flex-shrink-0 w-[74px]" style={{ color: 'rgba(255,255,255,0.3)' }}>
                    {doc.ref}
                  </span>
                  <span className="flex-1 min-w-0 text-xs font-medium truncate" style={{ color: 'rgba(255,255,255,0.66)' }}>
                    {doc.title}
                  </span>
                  <span
                    className="text-[10px] px-2 py-0.5 rounded-md font-medium flex-shrink-0"
                    style={{ backgroundColor: `${doc.color}1a`, color: doc.color }}
                  >
                    {doc.status}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>

        <p className="relative z-10 text-xs ss-animate-in" style={{ animationDelay: '320ms', color: 'rgba(255,255,255,0.2)' }}>
          © 2026 SureSign Contracts. All rights reserved.
        </p>
      </div>

      {/* ── Right panel — the form ──────────────────────────────────────────── */}
      <div
        className="flex-1 flex items-center justify-center px-4 py-10 sm:p-10 lg:h-full overflow-hidden"
        style={{ backgroundColor: '#ffffff' }}
      >
        <div className="w-full max-w-[400px] space-y-7">

          {/* Mobile logo */}
          <div className="lg:hidden flex items-center gap-3 ss-animate-in">
            <img src="/logo_black/SureSign_BLOGO.png" alt="SureSign" className="w-8 h-8 object-contain" />
            <span className="text-lg font-semibold tracking-tight" style={{ color: '#0f0f0f' }}>SureSign</span>
          </div>

          {/* Heading */}
          <div className="ss-animate-in">
            <h2 className="text-[1.7rem] font-semibold" style={{ color: '#0f0f0f', letterSpacing: '-0.025em' }}>
              Welcome back
            </h2>
            <p className="mt-1.5 text-sm" style={{ color: '#737373' }}>
              Sign in to your workspace
            </p>
          </div>

          {/* Error */}
          {error && (
            <div
              className="rounded-xl px-4 py-3 text-sm"
              style={{ backgroundColor: 'rgba(220,38,38,0.06)', border: '1px solid rgba(220,38,38,0.18)', color: '#b91c1c' }}
            >
              {error}
            </div>
          )}

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-4 ss-animate-in" style={{ animationDelay: '80ms' }}>
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

            <div className="space-y-1.5">
              <div className="flex items-center justify-between">
                <label className="block text-xs font-medium" style={{ color: '#525252' }}>
                  Password
                </label>
                <a href="mailto:admin@suresign.app" className="text-xs transition-opacity hover:opacity-60" style={{ color: '#737373' }}>
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
            </div>

            {/* Pill CTA with nested arrow island */}
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
              {isLoading ? 'Signing in…' : 'Sign in'}
              <span
                className="absolute right-1.5 top-1/2 -translate-y-1/2 w-8 h-8 rounded-full flex items-center justify-center group-hover:translate-x-0.5"
                style={{ backgroundColor: 'rgba(255,255,255,0.12)', transition: `transform 300ms ${EASE}` }}
              >
                <ArrowRight size={13} strokeWidth={2} />
              </span>
            </button>
          </form>

          {/* Divider */}
          <div className="flex items-center gap-3 ss-animate-in" style={{ animationDelay: '160ms' }}>
            <div className="flex-1 h-px" style={{ backgroundColor: '#ececec' }} />
            <span className="text-xs flex items-center gap-1.5" style={{ color: '#a3a3a3' }}>
              <Shield size={11} strokeWidth={1.75} />
              Secure access
            </span>
            <div className="flex-1 h-px" style={{ backgroundColor: '#ececec' }} />
          </div>

          <p className="text-xs text-center ss-animate-in" style={{ animationDelay: '220ms', color: '#a3a3a3' }}>
            Need access?{' '}
            <a href="mailto:admin@suresign.app" className="font-medium hover:underline" style={{ color: '#0f0f0f' }}>
              Contact your administrator
            </a>
          </p>
        </div>
      </div>
    </div>
  );
}
