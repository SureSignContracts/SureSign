'use client';

import { Menu } from 'lucide-react';

/**
 * Mobile-only top bar (hidden at `lg` and up) that exposes a hamburger to open
 * the navigation drawer. Keeps the same workspace identity visible on small
 * screens where the persistent sidebar is hidden.
 */
export default function MobileTopBar({
  onMenu,
  title,
  subtitle,
  logoUrl,
  fallbackInitial,
  right,
}: {
  onMenu: () => void;
  title: string;
  subtitle?: string;
  logoUrl?: string | null;
  fallbackInitial?: string;
  right?: React.ReactNode;
}) {
  return (
    <header
      className="lg:hidden sticky top-0 z-30 flex items-center gap-3 h-14 px-3 flex-shrink-0"
      style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}
    >
      <button
        onClick={onMenu}
        aria-label="Open navigation menu"
        className="flex-shrink-0 w-9 h-9 flex items-center justify-center rounded-lg transition-colors hover:bg-[var(--bg-hover)] active:scale-95"
        style={{ color: 'var(--text-secondary)' }}
      >
        <Menu size={20} />
      </button>

      <div className="flex items-center gap-2 flex-1 min-w-0">
        {logoUrl ? (
          <img
            src={logoUrl}
            alt={title}
            style={{ width: 22, height: 22, objectFit: 'contain', borderRadius: 4, flexShrink: 0 }}
          />
        ) : fallbackInitial ? (
          <div
            className="w-6 h-6 rounded-md flex items-center justify-center text-[11px] font-bold flex-shrink-0"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {fallbackInitial}
          </div>
        ) : null}
        <div className="min-w-0">
          <p className="text-sm font-bold tracking-tight truncate" style={{ color: 'var(--text-primary)' }}>
            {title}
          </p>
          {subtitle && (
            <p className="text-[10px] truncate leading-none" style={{ color: 'var(--text-muted)' }}>
              {subtitle}
            </p>
          )}
        </div>
      </div>

      {right && <div className="flex-shrink-0">{right}</div>}
    </header>
  );
}
