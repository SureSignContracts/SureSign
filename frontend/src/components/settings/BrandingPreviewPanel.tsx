'use client';

import { useState } from 'react';
import { Monitor, Tablet, Smartphone } from 'lucide-react';

type Viewport = 'desktop' | 'tablet' | 'mobile';
type PreviewTarget = 'workspace' | 'login' | 'public' | 'email';
type PublicPageState = 'normal' | 'loading' | 'error';

const VIEWPORT_WIDTH: Record<Viewport, string> = {
  desktop: '100%',
  tablet: '480px',
  mobile: '320px',
};

/**
 * Organisation URL Branding, Phase 4 — Company Branding Preview. Reacts
 * immediately to unsaved form state (logo/colour/name), matching this
 * page's own existing colour-picker "live preview, not persisted"
 * convention — no network round-trip on edit.
 *
 * Deliberately restrained to exactly 4 targets (Workspace/Login/Public
 * page/Email) with Desktop/Tablet/Mobile as viewport-width toggles over
 * the SAME preview, and Loading/Error as state toggles WITHIN the Public
 * page preview only — no additional preview targets.
 *
 * Every preview here is a clearly-labelled, non-functional VISUAL
 * APPROXIMATION, not a live embed of the real production component —
 * this is deliberate, not a shortcut: `marketing/`'s login gateway and
 * public pages are a separate Next.js application (no shared-package
 * convention exists in this repo to import them directly — see Phase 4's
 * architecture notes), and even the in-app Workspace sidebar is a real,
 * interactive component wired to live auth/navigation state that a
 * preview must never accidentally trigger. Approximating all 4 the same
 * way keeps that boundary consistent rather than mixing "real" and
 * "approximated" previews inconsistently.
 */
export default function BrandingPreviewPanel({
  companyName,
  logoUrl,
  accentColor,
}: {
  companyName: string;
  logoUrl: string | null;
  accentColor: string;
}) {
  const [viewport, setViewport] = useState<Viewport>('desktop');
  const [target, setTarget] = useState<PreviewTarget>('workspace');
  const [publicState, setPublicState] = useState<PublicPageState>('normal');

  const displayName = companyName?.trim() || 'Your Company';
  const isLight = isLightColor(accentColor);
  const accentFg = isLight ? '#0a0a0a' : '#ffffff';

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex flex-wrap gap-1.5">
          {(['workspace', 'login', 'public', 'email'] as PreviewTarget[]).map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setTarget(t)}
              className="rounded-full px-3.5 py-1.5 text-xs font-medium capitalize transition-colors"
              style={{
                backgroundColor: target === t ? 'var(--bg-elevated)' : 'transparent',
                color: target === t ? 'var(--text-primary)' : 'var(--text-secondary)',
                border: '1px solid var(--border)',
              }}
            >
              {t === 'public' ? 'Public page' : t}
            </button>
          ))}
        </div>

        <div className="flex items-center gap-1 rounded-full p-1" style={{ border: '1px solid var(--border)' }}>
          {([
            ['desktop', Monitor],
            ['tablet', Tablet],
            ['mobile', Smartphone],
          ] as [Viewport, typeof Monitor][]).map(([vp, Icon]) => (
            <button
              key={vp}
              type="button"
              aria-label={vp}
              onClick={() => setViewport(vp)}
              className="rounded-full p-1.5 transition-colors"
              style={{ backgroundColor: viewport === vp ? 'var(--bg-elevated)' : 'transparent' }}
            >
              <Icon size={14} style={{ color: viewport === vp ? 'var(--text-primary)' : 'var(--text-muted)' }} />
            </button>
          ))}
        </div>
      </div>

      {target === 'public' && (
        <div className="flex gap-1.5">
          {(['normal', 'loading', 'error'] as PublicPageState[]).map((s) => (
            <button
              key={s}
              type="button"
              onClick={() => setPublicState(s)}
              className="rounded-full px-3 py-1 text-[11px] font-medium capitalize transition-colors"
              style={{
                backgroundColor: publicState === s ? 'var(--bg-elevated)' : 'transparent',
                color: publicState === s ? 'var(--text-primary)' : 'var(--text-muted)',
                border: '1px solid var(--border)',
              }}
            >
              {s}
            </button>
          ))}
        </div>
      )}

      <div className="flex justify-center overflow-x-auto rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        <div
          className="w-full overflow-hidden rounded-xl shadow-lg transition-all"
          style={{ maxWidth: VIEWPORT_WIDTH[viewport], backgroundColor: '#ffffff' }}
        >
          {target === 'workspace' && <WorkspacePreview name={displayName} logoUrl={logoUrl} accent={accentColor} accentFg={accentFg} />}
          {target === 'login' && <LoginPreview name={displayName} logoUrl={logoUrl} accent={accentColor} accentFg={accentFg} />}
          {target === 'public' && <PublicPagePreview name={displayName} logoUrl={logoUrl} accent={accentColor} accentFg={accentFg} state={publicState} />}
          {target === 'email' && <EmailPreview name={displayName} logoUrl={logoUrl} accent={accentColor} accentFg={accentFg} />}
        </div>
      </div>

      <p className="text-center text-[11px]" style={{ color: 'var(--text-muted)' }}>
        Preview only — approximates how {displayName} will appear. Changes here are not saved until you click Save.
      </p>
    </div>
  );
}

function isLightColor(hex: string): boolean {
  const h = (hex || '#000000').replace('#', '');
  if (h.length !== 6) return true;
  const r = parseInt(h.slice(0, 2), 16);
  const g = parseInt(h.slice(2, 4), 16);
  const b = parseInt(h.slice(4, 6), 16);
  return (r * 299 + g * 587 + b * 114) / 1000 > 128;
}

type Swatch = { name: string; logoUrl: string | null; accent: string; accentFg: string };

function Logo({ name, logoUrl, size = 28 }: { name: string; logoUrl: string | null; size?: number }) {
  if (logoUrl) {
    // eslint-disable-next-line @next/next/no-img-element
    return <img src={logoUrl} alt="" style={{ height: size, width: 'auto', objectFit: 'contain' }} />;
  }
  return (
    <div
      className="flex items-center justify-center rounded-md font-semibold"
      style={{ height: size, width: size, backgroundColor: '#0a0a0a', color: '#ffffff', fontSize: size * 0.45 }}
    >
      {name.charAt(0).toUpperCase()}
    </div>
  );
}

function WorkspacePreview({ name, logoUrl, accent, accentFg }: Swatch) {
  return (
    <div className="flex text-left" style={{ fontFamily: 'inherit' }}>
      <div className="w-[38%] shrink-0 space-y-4 p-3" style={{ backgroundColor: '#111111' }}>
        <div className="flex items-center gap-2">
          <Logo name={name} logoUrl={logoUrl} size={22} />
          <span className="truncate text-[11px] font-medium text-white">{name}</span>
        </div>
        <div className="space-y-1.5">
          <div className="rounded-md px-2 py-1.5 text-[10px] font-medium" style={{ backgroundColor: accent, color: accentFg }}>Projects</div>
          {['Contracts', 'Payments', 'Documents'].map((label) => (
            <div key={label} className="rounded-md px-2 py-1.5 text-[10px]" style={{ color: 'rgba(255,255,255,0.55)' }}>{label}</div>
          ))}
        </div>
      </div>
      <div className="flex-1 space-y-2 p-4">
        <div className="h-3 w-24 rounded" style={{ backgroundColor: '#e5e5e5' }} />
        <div className="h-16 w-full rounded-lg" style={{ border: '1px solid #ececec' }} />
        <div className="h-16 w-full rounded-lg" style={{ border: '1px solid #ececec' }} />
      </div>
    </div>
  );
}

function LoginPreview({ name, logoUrl, accent, accentFg }: Swatch) {
  return (
    <div className="flex flex-col items-center gap-3 p-8 text-center">
      <Logo name={name} logoUrl={logoUrl} size={36} />
      <div className="text-sm font-medium" style={{ color: '#0f0f0f' }}>Welcome to {name}</div>
      <div className="text-xs" style={{ color: '#737373' }}>Sign in to your organisation&apos;s SureSign workspace.</div>
      <div className="mt-2 w-full max-w-[220px] rounded-full py-2 text-xs font-medium" style={{ backgroundColor: accent, color: accentFg }}>
        Continue to Sign In
      </div>
      <div className="text-[10px]" style={{ color: '#a3a3a3' }}>Powered by SureSign</div>
    </div>
  );
}

function PublicPagePreview({ name, logoUrl, accent, accentFg, state }: Swatch & { state: PublicPageState }) {
  if (state === 'loading') {
    return (
      <div className="space-y-3 p-6">
        <div className="flex items-center justify-between">
          <Logo name={name} logoUrl={logoUrl} size={24} />
          <div className="h-5 w-16 animate-pulse rounded-full" style={{ backgroundColor: '#ececec' }} />
        </div>
        <div className="h-4 w-3/4 animate-pulse rounded" style={{ backgroundColor: '#ececec' }} />
        <div className="h-4 w-1/2 animate-pulse rounded" style={{ backgroundColor: '#ececec' }} />
      </div>
    );
  }

  if (state === 'error') {
    return (
      <div className="flex flex-col items-center gap-2 p-8 text-center">
        <div className="text-sm font-medium" style={{ color: '#0f0f0f' }}>We couldn&apos;t load this page</div>
        <div className="text-xs" style={{ color: '#737373' }}>Check your connection and try again.</div>
      </div>
    );
  }

  return (
    <div className="space-y-3 p-6">
      <div className="flex items-center gap-2 rounded-lg p-2" style={{ border: '1px solid #ececec' }}>
        <Logo name={name} logoUrl={logoUrl} size={22} />
        <span className="text-[11px]" style={{ color: '#737373' }}>Booked with <b style={{ color: '#0f0f0f' }}>{name}</b></span>
      </div>
      <div className="h-3 w-2/3 rounded" style={{ backgroundColor: '#e5e5e5' }} />
      <div className="h-16 rounded-lg" style={{ border: '1px solid #ececec' }} />
      <div className="w-32 rounded-full py-2 text-center text-[11px] font-medium" style={{ backgroundColor: accent, color: accentFg }}>
        Confirm
      </div>
    </div>
  );
}

function EmailPreview({ name, logoUrl, accent, accentFg }: Swatch) {
  return (
    <div className="p-0">
      <div className="flex items-center gap-2 p-4" style={{ backgroundColor: '#0a0a0a' }}>
        <Logo name={name} logoUrl={logoUrl} size={20} />
        <span className="text-[11px] font-medium text-white">SureSign</span>
      </div>
      <div className="space-y-2 p-4">
        <div className="text-[10px] font-semibold uppercase tracking-wide" style={{ color: '#6b6b6b' }}>Variation</div>
        <div className="h-3 w-3/4 rounded" style={{ backgroundColor: '#e5e5e5' }} />
        <div className="h-2 w-full rounded" style={{ backgroundColor: '#f0f0f0' }} />
        <div className="h-2 w-5/6 rounded" style={{ backgroundColor: '#f0f0f0' }} />
        <div className="mt-2 w-40 rounded-full py-1.5 text-center text-[10px] font-medium" style={{ backgroundColor: accent, color: accentFg }}>
          Open {name} Workspace
        </div>
      </div>
      <div className="p-3 text-center text-[9px]" style={{ backgroundColor: '#1a1a1a', color: '#a3a3a3' }}>
        Sent by SureSign Contracts. Powered by SureSign.
      </div>
    </div>
  );
}
