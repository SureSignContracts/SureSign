'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import type { LucideIcon } from 'lucide-react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

export type ModalTone = 'neutral' | 'warning' | 'danger' | 'info';

/** 'md' (default) is every existing confirm-dialog's exact prior width — unchanged. 'xl' is for
 * content-heavy admin panels (e.g. the Entitlements editor) that need real width to lay out a table. */
export type ModalSize = 'md' | 'xl';

const SIZE_CLASS: Record<ModalSize, string> = {
  md: 'max-w-md',
  xl: 'max-w-[960px]',
};

const TONE_ICON_STYLE: Record<ModalTone, React.CSSProperties> = {
  neutral: { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' },
  warning: { backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15' },
  danger: { backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' },
  info: { backgroundColor: 'rgba(59,130,246,0.12)', color: '#60a5fa' },
};

/**
 * Shared confirm-dialog shell for Billing (and reusable anywhere else a
 * centered modal is needed). Every prior dialog reimplemented its own
 * overlay div with no exit animation — closing was an instant unmount.
 * This component plays a genuine exit animation before calling the
 * caller's `onClose`, so callers keep their existing
 * `{open && <Dialog onClose={() => setOpen(false)} />}` pattern unchanged;
 * only the timing of that final `onClose` call is delayed by the
 * animation duration.
 *
 * `children` is a render function receiving `close` — the ANIMATED close
 * handler (backdrop click / Escape / a "Cancel" button should all use
 * this, not the raw `onClose` prop, so every dismissal path animates
 * consistently). The primary/destructive action typically stays wired
 * directly to its own mutation handler, which usually flips the parent's
 * `open` state to false on success — that path closes instantly, which is
 * an acceptable, common trade-off (a toast usually accompanies it).
 *
 * Rendered via a portal to `document.body`, not inline where it's called
 * from. Two independent reasons: (1) correctness — a `position: fixed`
 * element nested inside a card that has ANY non-`none` transform applied
 * (including a lingering one left by an `animation-fill-mode: forwards`
 * entrance animation elsewhere in this app — see the `ss-fade-in-up`
 * keyframe's own comment in globals.css) gets confined to that ancestor's
 * box instead of the viewport; a portal sidesteps the whole containing-
 * block question regardless of what any particular ancestor does. (2) a
 * modal should always sit directly under `<body>` for stacking/z-index
 * predictability, independent of whatever z-index context its trigger
 * button happens to render inside.
 */
export default function Modal({
  title,
  icon: Icon,
  tone = 'neutral',
  size = 'md',
  showCloseButton = false,
  onClose,
  busy = false,
  children,
}: {
  title: string;
  icon?: LucideIcon;
  tone?: ModalTone;
  size?: ModalSize;
  showCloseButton?: boolean;
  onClose: () => void;
  busy?: boolean;
  children: (close: () => void) => React.ReactNode;
}) {
  const [closing, setClosing] = useState(false);
  const panelRef = useRef<HTMLDivElement>(null);

  const close = useCallback(() => {
    if (busy || closing) return;
    setClosing(true);
    window.setTimeout(onClose, 150);
  }, [busy, closing, onClose]);

  // Focus management (Stage 8): move focus into the dialog on open, and
  // hand it back to whatever triggered the dialog on close/unmount — a
  // plain imperative DOM action, not a setState call, so this is exactly
  // the kind of thing an effect is for (not flagged by
  // react-hooks/set-state-in-effect).
  useEffect(() => {
    const previouslyFocused = document.activeElement as HTMLElement | null;
    panelRef.current?.focus();
    return () => previouslyFocused?.focus?.();
  }, []);

  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        // A Radix-based overlay nested inside this modal (Select,
        // Combobox's Popover, ...) already dismissed itself and called
        // `event.preventDefault()` on this exact Escape keydown before it
        // bubbled from `document` up to this `window` listener — found
        // during the dropdown-hardening pass, where a single Escape was
        // closing the dropdown AND the modal underneath it in one press.
        // Respect that: only this modal's own Escape means "close me".
        if (e.defaultPrevented) return;
        close();
        return;
      }
      // Minimal focus trap — cycles Tab/Shift+Tab within the dialog so
      // keyboard focus can never silently escape to the dimmed page
      // behind it.
      if (e.key === 'Tab' && panelRef.current) {
        const focusable = panelRef.current.querySelectorAll<HTMLElement>(
          'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
        );
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [close]);

  // Every call site renders this conditionally from client-side state that
  // starts `false` (`{open && <Modal .../>}`), so this never executes
  // during the initial SSR pass — no hydration-mismatch guard/effect
  // needed, just a plain synchronous check.
  if (typeof document === 'undefined') return null;

  return createPortal(
    <div
      className={cn('fixed inset-0 z-50 flex items-center justify-center p-4', closing ? 'ss-modal-overlay-out' : 'ss-modal-overlay-in')}
      style={{ backgroundColor: 'rgba(10,10,10,0.55)', backdropFilter: 'blur(3px)', WebkitBackdropFilter: 'blur(3px)' }}
      onClick={(e) => { if (e.target === e.currentTarget) close(); }}
    >
      <div
        ref={panelRef}
        tabIndex={-1}
        className={cn(
          'w-full rounded-2xl outline-none max-h-[85vh] flex flex-col overflow-hidden',
          SIZE_CLASS[size],
          closing ? 'ss-modal-panel-out' : 'ss-modal-panel-in',
        )}
        style={{
          backgroundColor: 'var(--bg-surface)',
          border: '1px solid var(--border)',
          // The global [tabindex]:focus-visible rule uses a tighter radius.
          // This dialog is focused on mount for keyboard trapping, so lock
          // its intended shell radius before and after focus moves inside.
          borderRadius: '1rem',
          boxShadow: 'var(--shadow-pop)',
          // The panel receives programmatic focus for keyboard trapping, but
          // it is not an interactive control. Keep the visible focus ring on
          // the buttons and fields inside instead of tinting the modal edge.
          outline: 'none',
        }}
        role="dialog"
        aria-modal="true"
        aria-labelledby="ss-modal-title"
      >
        {/* Fixed header — never scrolls. `flex-shrink-0` keeps it pinned regardless of body height. */}
        <div className="flex items-center gap-3 p-6 pb-4 flex-shrink-0">
          {Icon && (
            <div
              className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
              style={TONE_ICON_STYLE[tone]}
            >
              <Icon size={18} />
            </div>
          )}
          <h2 id="ss-modal-title" className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>
            {title}
          </h2>
          {showCloseButton && (
            <button
              type="button"
              onClick={close}
              aria-label="Close dialog"
              className="ml-auto flex h-9 w-9 items-center justify-center rounded-xl transition-colors hover:bg-[var(--bg-hover)] active:translate-y-px"
              style={{ color: 'var(--text-muted)' }}
            >
              <X size={16} strokeWidth={1.75} />
            </button>
          )}
        </div>

        {/* Body region — `min-h-0` lets this shrink below its content's natural size so an inner
           `flex-1 min-h-0 overflow-y-auto` region (if the caller uses one, e.g. EntitlementsEditor)
           gets a real, computed height to scroll within, instead of an independently-guessed vh value.
           No overflow set here: a short confirm dialog's content just renders at its natural size —
           byte-for-byte the same visual result every existing caller had before this change. */}
        <div className="flex-1 min-h-0 flex flex-col px-6 pb-6">
          {children(close)}
        </div>
      </div>
    </div>,
    document.body,
  );
}
