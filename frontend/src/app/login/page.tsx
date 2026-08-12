'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useAuthStore } from '@/store/authStore';
import { Eye, EyeOff, Shield, ArrowRight } from 'lucide-react';
import api from '@/lib/api';
import { isHostnameSyntacticallyValid } from '@/lib/hostnameValidation';
import { resolveHostContext } from '@/lib/hostContext';
import { isSafeAppDeepLink } from '@/lib/safeRedirect';
import { normalizeApiError } from '@/lib/normalizeApiError';
import {
  getStoredAuthBlob,
  getStoredToken,
  markPostLoginEntrance,
} from '@/lib/authStorage';
import LoginProductShowcase from '@/components/login/LoginProductShowcase';

interface BrandGateway {
  organisation_name: string;
  logo_url: string | null;
  accent_color: string;
}

const EASE = 'cubic-bezier(0.32, 0.72, 0, 1)';

// Film grain — static SVG noise, fixed opacity, breaks digital flatness
const NOISE_URI = `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='160' height='160' filter='url(%23n)' opacity='0.4'/%3E%3C/svg%3E")`;

export default function LoginPage() {
  const router    = useRouter();
  const login     = useAuthStore((s) => s.login);
  const isLoading = useAuthStore((s) => s.isLoading);
  const [email,    setEmail]    = useState('');
  const [password, setPassword] = useState('');
  const [showPw,   setShowPw]   = useState(false);
  // Defaults to checked — this app's only behavior before "Remember me"
  // existed (the session always survived closing the browser), so leaving
  // it unchanged is the least surprising default. Unchecking it makes
  // login() store the session in sessionStorage instead of localStorage
  // (see lib/authStorage.ts) — cleared when the browser/tab closes.
  const [remember, setRemember] = useState(true);
  const [error,    setError]    = useState('');
  // Set only from a normalized 422 field-error response — native `required`
  // already blocks an empty submit client-side, so this is mainly a safety
  // net (e.g. a malformed-but-non-empty value the browser doesn't catch).
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});
  // Set from the Axios interceptor's own redirect (lib/api.ts) — a
  // non-sensitive reason code only, never a token or destination.
  const [authNotice, setAuthNotice] = useState<'session_expired' | 'account_unavailable' | null>(null);
  // Invitation & First-Time Account Setup follow-up — set only from the
  // `invited`/`email` query params accept-invitation/page.tsx's success
  // screen links to. Both are non-secret (a boolean marker and the address
  // itself, never the signed invitation URL's user id/expires/signature,
  // and never the chosen password) and are stripped from the URL/history
  // immediately, same discipline as authNotice/brandHost below. Component
  // state only — never persisted — so it naturally reverts to the normal
  // "Welcome back" experience on any later, ordinary visit to /login.
  // Deliberately NOT cleared on a failed submit (see handleSubmit) — the
  // recipient is still completing the same invitation-login session.
  const [invitationSuccess, setInvitationSuccess] = useState(false);
  // Start hidden — we reveal only once we've confirmed the user is NOT authenticated.
  // This avoids SSR/client hydration mismatch (window is undefined on server so we
  // cannot check localStorage until after mount).
  const [ready, setReady] = useState(false);
  // Organisation URL Branding, Phase 4 — set only when a valid `brandHost`
  // query param resolves to a real organisation via the same public
  // branding-lookup endpoint marketing/'s login gateway uses. Purely
  // decorative (heading/logo above the unmodified form below) — never
  // changes login()/token storage/the post-login redirect logic, and
  // never overrides the authenticated user's own organisation once
  // logged in (this state only exists pre-auth, on this one page).
  const [brand, setBrand] = useState<BrandGateway | null>(null);
  // Organisation URL Branding, Phase 5 (Stage 2C) — 'checking' until the
  // direct-hostname resolution (see below) settles. 'not_found' renders a
  // neutral workspace-not-found message INSTEAD of the login form — never
  // the plain unbranded form (that would silently let a customer log in
  // on what looks like their own dead/removed workspace URL without
  // telling them). 'unavailable' (resolver outage) intentionally falls
  // back to 'ok' — see the effect below — matching the outage-safety
  // contract in lib/hostContext.ts: never treat an outage as proof of
  // anything.
  const [workspaceStatus, setWorkspaceStatus] = useState<'checking' | 'ok' | 'not_found'>('checking');

  useEffect(() => {
    // Same "strip immediately, regardless of validity" discipline as
    // brandHost below — never lingers in the address bar/history. Both
    // authNotice and the invitation-success params are read and stripped
    // together, from the same params object, in one replaceState call —
    // deliberately consolidated rather than a second competing effect, so
    // neither clears a param the other just set.
    const params = new URLSearchParams(window.location.search);
    let changed = false;

    const notice = params.get('authNotice');
    if (notice === 'session_expired' || notice === 'account_unavailable') {
      // Reading a one-time query param on mount — same
      // react-hooks/set-state-in-effect pattern already present and
      // un-suppressed for workspaceStatus/ready further down this file.
      setAuthNotice(notice);
      params.delete('authNotice');
      changed = true;
    }

    if (params.get('invited') === '1') {
      setInvitationSuccess(true);
      const invitedEmail = params.get('email');
      if (invitedEmail) setEmail(invitedEmail);
      params.delete('invited');
      params.delete('email');
      changed = true;
    }

    if (changed) {
      const rest = params.toString();
      window.history.replaceState(null, '', window.location.pathname + (rest ? `?${rest}` : ''));
    }
  }, []);

  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const brandHost = params.get('brandHost');

    // Strip it from the visible URL immediately, regardless of whether it
    // turns out to be valid/resolvable — it must never linger in the
    // address bar or browser history, and is never logged/sent to
    // analytics from this page.
    if (brandHost !== null) {
      window.history.replaceState(null, '', window.location.pathname);
    }

    if (brandHost && isHostnameSyntacticallyValid(brandHost)) {
      // Explicit handoff from marketing/'s branded login gateway — decorate
      // the form, same as before. This path never runs the direct-hostname
      // resolution below (this page IS being served on the fixed app host
      // in this case, not the organisation's own hostname).
      api.get(`/public/organisation-branding/${encodeURIComponent(brandHost)}`)
        .then(r => {
          const data = r.data?.data;
          if (data?.organisation_name) {
            setBrand({
              organisation_name: data.organisation_name,
              logo_url: data.logo_url ?? null,
              accent_color: data.accent_color,
            });
          }
        })
        .catch(() => {
          // Resolver unavailable/host unknown — silent fallback, same as absent.
        });
      setWorkspaceStatus('ok');
      return;
    }

    // No brandHost handoff — Stage 2C: resolve directly from the ACTUAL
    // hostname this page is being served on. Today (before Stage 5's
    // Traefik cutover) this always resolves 'platform', since organisation
    // hostnames still route to marketing — this code is deployed now,
    // inert until that cutover, exactly as Stage 2's own compatibility
    // requirement specifies.
    resolveHostContext().then((ctx) => {
      if (ctx.type === 'organisation') {
        setBrand(ctx.branding ?? null);
        setWorkspaceStatus('ok');
      } else if (ctx.type === 'not_found') {
        setWorkspaceStatus('not_found');
      } else if (ctx.type === 'historical_redirect' && ctx.redirect_base_url) {
        // Preserve path/query — mirrors OrganisationUrlGenerator's own
        // contract; never construct this destination from user input.
        window.location.replace(ctx.redirect_base_url + window.location.pathname + window.location.search);
      } else {
        // 'platform' or 'unavailable' — plain form, no neutral-not-found
        // page. An outage must never be misreported as "this workspace
        // doesn't exist."
        setWorkspaceStatus('ok');
      }
    });
  }, []);

  useEffect(() => {
    // Checks both storage backends (localStorage AND sessionStorage — see
    // lib/authStorage.ts) since a "Remember me" session lives in whichever
    // one the user chose at login.
    const hasToken = (() => {
      if (getStoredToken()) return true;
      try {
        const p = getStoredAuthBlob();
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

  if (!ready || workspaceStatus === 'checking') return null;

  if (workspaceStatus === 'not_found') {
    return (
      <div className="min-h-dvh flex items-center justify-center px-4" style={{ backgroundColor: '#ffffff' }}>
        <div className="w-full max-w-sm text-center space-y-3">
          <h1 className="text-xl font-semibold" style={{ color: '#0f0f0f' }}>Workspace not found</h1>
          <p className="text-sm" style={{ color: '#737373' }}>
            This SureSign workspace address doesn&apos;t exist or is no longer available.
          </p>
          <a
            href={process.env.NEXT_PUBLIC_APP_HOST || '/'}
            className="inline-block text-sm font-medium hover:underline"
            style={{ color: '#0f0f0f' }}
          >
            Go to SureSign
          </a>
        </div>
      </div>
    );
  }

  async function handleSubmit(e: React.SyntheticEvent<HTMLFormElement>) {
    e.preventDefault();
    setError('');
    setFieldErrors({});
    setAuthNotice(null);
    try {
      await login(email, password, remember);
      markPostLoginEntrance();
      const user = useAuthStore.getState().user;
      if (user?.roles?.includes('Super Admin') || user?.roles?.includes('Admin')) {
        router.push('/admin');
      } else if (!user?.organization || !user.organization.is_onboarded) {
        // Widened to also cover organization === null (e.g. every invited
        // user today, who has no organisation at all until they onboard)
        // — previously only "has an organisation but it's not onboarded"
        // was caught here, so a null-organisation user fell through to the
        // else branch and reached /app/onboarding only via app/layout.tsx's
        // own guard correcting it a beat later. That guard remains the
        // authoritative source of truth regardless — this is purely a
        // convenience match to avoid the redundant /app -> /app/onboarding
        // hop, not a new onboarding decision. Never based on "did this user
        // arrive via an invitation" — only on their actual organisation
        // state, so it correctly helps any non-system user with no org.
        router.push('/app/onboarding');
      } else {
        // Stage 3, Part F — a deep-link destination on THIS SAME
        // hostname, e.g. test-company.suresigncontracts.app/login?next=/app/projects
        // continuing to that same host's /app/projects. Only ever a
        // same-app relative path is accepted — see isSafeAppDeepLink's
        // own docblock for exactly what's rejected.
        const next = new URLSearchParams(window.location.search).get('next');
        router.push(next && isSafeAppDeepLink(next) ? next : '/app');
      }
    } catch (err) {
      const normalized = normalizeApiError(err);
      setFieldErrors(normalized.fieldErrors ?? {});
      // Every other case's backend message is already written to be
      // customer-safe and specific (invalid credentials, account
      // unavailable, rate limit, validation, network) — only a genuine
      // server failure gets login-specific wording instead of the
      // normalizer's generic one, since "sign in" is more precise here than
      // "Something went wrong on our side."
      setError(
        normalized.type === 'server'
          ? "We couldn't sign you in right now. Please try again."
          : normalized.message
      );
    }
  }

  return (
    <div className="min-h-dvh lg:h-dvh lg:overflow-hidden flex flex-col lg:flex-row">

      {/* ── Left panel — the contract record, set like a drawing sheet ─────── */}
      <div
        className="ss-login-panel-left hidden lg:flex lg:w-[48%] xl:w-[50%] flex-col justify-between p-10 xl:p-14 relative overflow-hidden flex-shrink-0 h-full"
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
        <div className="ss-login-reveal relative z-10 flex items-center gap-3" style={{ animationDelay: '340ms' }}>
          <img src="/logo_white/SureSign_WLOGO.webp" alt="SureSign" className="w-8 h-8 object-contain flex-shrink-0" />
          <span className="text-base font-semibold tracking-tight" style={{ color: '#f5f5f5' }}>SureSign</span>
        </div>

        {/* Product story */}
        <div className="relative z-10 w-full max-w-[31rem] space-y-5">
          <div className="ss-login-reveal" style={{ animationDelay: '420ms' }}>
            <span
              className="inline-flex items-center gap-2 rounded-full px-3 py-1 text-[10px] uppercase tracking-[0.2em] font-medium border"
              style={{ borderColor: 'rgba(255,255,255,0.14)', color: 'rgba(255,255,255,0.48)' }}
            >
              Construction contract administration
            </span>
          </div>

          <div className="ss-login-reveal space-y-3" style={{ animationDelay: '520ms' }}>
            <h1
              className="text-[2.15rem] font-semibold leading-[1.05] xl:text-[2.5rem]"
              style={{ color: '#f5f5f5', letterSpacing: '-0.03em' }}
            >
              Run the contract,
              <br />
              <span style={{ color: 'rgba(255,255,255,0.38)' }}>not the paperwork.</span>
            </h1>
            <p className="max-w-[27rem] text-xs leading-relaxed" style={{ color: 'rgba(255,255,255,0.45)' }}>
              Payment applications, notices, variations and programme records,
              administered from one secure workspace.
            </p>
          </div>

          <div className="ss-login-reveal" style={{ animationDelay: '670ms' }}>
            <LoginProductShowcase />
          </div>
        </div>

        <p className="ss-login-reveal relative z-10 text-xs" style={{ animationDelay: '920ms', color: 'rgba(255,255,255,0.2)' }}>
          © 2026 SureSign Contracts. All rights reserved.
        </p>
      </div>

      {/* ── Right panel — the form ──────────────────────────────────────────── */}
      <div
        className="ss-login-panel-right relative flex flex-1 items-center justify-center overflow-hidden border-[#e5e2dc] px-5 py-8 sm:px-10 lg:h-full lg:border-l lg:px-12"
        style={{ backgroundColor: '#ffffff' }}
      >
        <main className="relative w-full max-w-[420px]">
          {/* Mobile logo */}
          <div className="ss-login-reveal mb-10 flex items-center gap-2.5 lg:hidden" style={{ animationDelay: '340ms' }}>
            <img src="/logo_black/SureSign_BLOGO.webp" alt="SureSign" className="h-7 w-7 object-contain" />
            <span className="text-base font-semibold tracking-tight" style={{ color: '#0f0f0f' }}>SureSign</span>
          </div>

          {/* Heading — Organisation URL Branding, Phase 4: decorated with
              the organisation's own logo/name when arriving via a valid
              brandHost handoff from marketing/'s branded login gateway. */}
          <header className="ss-login-reveal" style={{ animationDelay: '420ms' }}>
            {brand?.logo_url && (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={brand.logo_url} alt={`${brand.organisation_name} logo`} className="mb-7 h-8 w-auto" />
            )}
            <div
              className="mb-5 flex items-center gap-3 text-[10px] font-semibold uppercase tracking-[0.2em]"
              style={{ color: invitationSuccess ? '#397154' : '#686762' }}
            >
              <span
                className="h-px w-7"
                style={{
                  backgroundColor: invitationSuccess ? '#4d8966' : brand?.accent_color || '#0f0f0f',
                }}
              />
              {invitationSuccess ? 'Account ready' : 'Secure workspace access'}
            </div>
            <h2 className="text-[2rem] font-semibold leading-[1.05] sm:text-[2.2rem]" style={{ color: '#11110f', letterSpacing: '-0.045em' }}>
              {invitationSuccess ? 'Your SureSign account is ready' : brand ? `Welcome to ${brand.organisation_name}` : 'Welcome back'}
            </h2>
            <p className="mt-3 max-w-[38ch] text-sm leading-relaxed" style={{ color: '#6d6b65' }}>
              {invitationSuccess
                ? 'Sign in with the password you just created to continue setting up SureSign.'
                : brand ? "Sign in to your organisation's SureSign workspace." : 'Sign in to your workspace'}
            </p>
            {brand && (
              <p className="mt-2 text-[11px]" style={{ color: '#9a9994' }}>Powered by SureSign</p>
            )}
          </header>

          <div className="ss-login-rule my-8 h-px" style={{ backgroundColor: '#dfdcd5' }} />

          {/* Session-expiry / account-unavailable notice — set only from the
              Axios interceptor's own redirect (lib/api.ts), never shown
              alongside a submit error (a fresh submit always clears it). */}
          {!error && authNotice && (
            <div
              role="status"
              className="ss-login-status mb-5 border-l-2 px-3 py-2 text-xs leading-relaxed"
              style={{ backgroundColor: '#f0efeb', borderColor: '#99968e', color: '#52514d' }}
            >
              {authNotice === 'session_expired'
                ? 'Your session has expired. Sign in again to continue.'
                : 'This account is currently unavailable. Contact your administrator or SureSign support.'}
            </div>
          )}

          {/* Error */}
          {error && (
            <div
              role="alert"
              className="ss-login-status mb-5 border-l-2 px-3 py-2 text-xs leading-relaxed"
              style={{ backgroundColor: '#f9eeec', borderColor: '#bb5b50', color: '#96392f' }}
            >
              {error}
            </div>
          )}

          {/* Form */}
          <form onSubmit={handleSubmit} className="space-y-5">
            <div className="ss-login-reveal space-y-2.5" style={{ animationDelay: '650ms' }}>
              <label htmlFor="login-email" className="block text-xs font-semibold" style={{ color: '#3f3e39' }}>
                Email address
              </label>
              <input
                id="login-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                autoComplete="email"
                placeholder="you@company.com"
                aria-invalid={fieldErrors.email ? true : undefined}
                aria-describedby={fieldErrors.email ? 'login-email-error' : undefined}
                className={`ss-auth-input h-12 w-full rounded-xl border bg-[#f5f5f3] px-4 text-sm placeholder:text-[#aaa8a1] hover:bg-[#f1f1ef] focus:bg-white focus-visible:outline-none ${fieldErrors.email ? 'border-[#cf8178]' : 'border-[#e3e2de] focus:border-[#77746d]'}`}
                style={{ color: '#0f0f0f', transition: `border-color 180ms ${EASE}, background-color 180ms ${EASE}, box-shadow 180ms ${EASE}` }}
              />
              {fieldErrors.email && (
                <p id="login-email-error" className="text-xs" style={{ color: '#b91c1c' }}>{fieldErrors.email[0]}</p>
              )}
            </div>

            <div className="ss-login-reveal space-y-2.5" style={{ animationDelay: '750ms' }}>
              <div className="flex items-center justify-between">
                <label htmlFor="login-password" className="block text-xs font-semibold" style={{ color: '#3f3e39' }}>
                  Password
                </label>
                <a href="/forgot-password" className="rounded text-xs font-medium underline-offset-4 hover:underline" style={{ color: '#65635d' }}>
                  Forgot password?
                </a>
              </div>
              <div className="relative">
                <input
                  id="login-password"
                  type={showPw ? 'text' : 'password'}
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  required
                  autoComplete="current-password"
                  placeholder="••••••••"
                  aria-invalid={fieldErrors.password ? true : undefined}
                  aria-describedby={fieldErrors.password ? 'login-password-error' : undefined}
                  className={`ss-auth-input h-12 w-full rounded-xl border bg-[#f5f5f3] px-4 pr-12 text-sm placeholder:text-[#aaa8a1] hover:bg-[#f1f1ef] focus:bg-white focus-visible:outline-none ${fieldErrors.password ? 'border-[#cf8178]' : 'border-[#e3e2de] focus:border-[#77746d]'}`}
                  style={{ color: '#0f0f0f', transition: `border-color 180ms ${EASE}, background-color 180ms ${EASE}, box-shadow 180ms ${EASE}` }}
                />
                <button
                  type="button"
                  onClick={() => setShowPw(p => !p)}
                  aria-label={showPw ? 'Hide password' : 'Show password'}
                  aria-pressed={showPw}
                  className="absolute right-1.5 top-1/2 flex h-9 w-9 -translate-y-1/2 items-center justify-center rounded-lg hover:bg-[#e8e8e5]"
                  style={{ color: '#777671' }}
                >
                  {showPw ? <EyeOff size={15} strokeWidth={1.75} /> : <Eye size={15} strokeWidth={1.75} />}
                </button>
              </div>
              {fieldErrors.password && (
                <p id="login-password-error" className="text-xs" style={{ color: '#b91c1c' }}>{fieldErrors.password[0]}</p>
              )}
            </div>

            <label className="ss-login-reveal flex items-center gap-2 text-xs font-medium select-none" style={{ animationDelay: '840ms', color: '#444440' }}>
              <input
                type="checkbox"
                checked={remember}
                onChange={(e) => setRemember(e.target.checked)}
                className="h-4 w-4 rounded border focus-visible:outline-2 focus-visible:outline-[#0f0f0f] focus-visible:outline-offset-2"
                style={{ accentColor: '#0f0f0f', borderColor: '#deddd9' }}
              />
              Remember me
            </label>

            {/* Primary CTA with a restrained directional affordance */}
            <div className="ss-login-reveal" style={{ animationDelay: '920ms' }}>
              <button
                type="submit"
                disabled={isLoading}
                aria-live="polite"
                className="group relative mt-1 flex h-12 w-full items-center justify-center rounded-xl px-5 text-xs font-semibold hover:-translate-y-0.5 hover:bg-[#292926] hover:shadow-[0_8px_18px_rgba(15,15,15,0.16)] active:translate-y-px disabled:cursor-not-allowed disabled:opacity-45 disabled:shadow-none"
                style={{
                  backgroundColor: '#0f0f0f',
                  color: '#ffffff',
                  transition: `background-color 300ms ${EASE}, transform 200ms ${EASE}, opacity 200ms ${EASE}`,
                }}
              >
                {isLoading ? 'Signing in…' : 'Sign in'}
                <span className="absolute right-5 flex items-center justify-center group-hover:translate-x-0.5" style={{ transition: `transform 180ms ${EASE}` }}>
                  <ArrowRight size={13} strokeWidth={2} />
                </span>
              </button>
            </div>
          </form>

          <footer
            className="ss-login-reveal mt-8 flex flex-col items-start justify-between gap-3 border-t border-[#dfdcd5] pt-5 text-xs sm:flex-row sm:items-center"
            style={{ animationDelay: '1040ms', color: '#85827a' }}
          >
            <span className="flex items-center gap-1.5">
              <Shield size={12} strokeWidth={1.75} />
              Protected workspace access
            </span>
            <span>
              Need access?{' '}
              <a href="/contact-administrator" className="rounded font-semibold underline-offset-4 hover:underline" style={{ color: '#353430' }}>
                Contact administrator
              </a>
            </span>
          </footer>
        </main>
      </div>
    </div>
  );
}
