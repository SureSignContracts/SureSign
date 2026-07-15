'use client';

import { useEffect, useState } from 'react';

const STORAGE_KEY = 'suresign-theme';

function SunIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
    </svg>
  );
}

function MoonIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
      <path d="M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z" />
    </svg>
  );
}

export function ThemeToggle() {
  // Unknown until mounted — the real value lives on the DOM attribute the
  // blocking inline script in layout.tsx already set before paint. Reading
  // it in an effect (not during render) avoids a server/client mismatch.
  const [theme, setTheme] = useState<'light' | 'dark' | null>(null);

  useEffect(() => {
    const current = document.documentElement.getAttribute('data-theme');
    setTheme(current === 'dark' ? 'dark' : 'light');
  }, []);

  function toggle() {
    const next = theme === 'dark' ? 'light' : 'dark';
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!reduceMotion) {
      document.documentElement.classList.add('theme-transitioning');
      window.setTimeout(() => document.documentElement.classList.remove('theme-transitioning'), 350);
    }

    document.documentElement.setAttribute('data-theme', next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Storage unavailable (private browsing, etc.) — theme still applies for this page view.
    }
    setTheme(next);
  }

  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
      className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-border text-text-secondary transition-colors hover:border-border-light hover:text-text-primary"
    >
      {/* Render nothing theme-specific until mounted, to keep SSR/CSR markup identical. */}
      {theme === null ? <span className="h-4 w-4" aria-hidden /> : theme === 'dark' ? <SunIcon /> : <MoonIcon />}
    </button>
  );
}
