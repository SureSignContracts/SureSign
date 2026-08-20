'use client';

import { useEffect, useRef } from 'react';
import { useNotifications } from '@/hooks/useNotifications';
import { useAuthStore } from '@/store/authStore';

// Bounded ID history — see the docblock on useNewNotificationWatcher below
// for why this exists and why this specific number is safe.
const MAX_TRACKED_IDS = 200;

/**
 * The shell-level owner of "has a genuinely NEW SureSign notification
 * arrived" — the foundation the future notification-sound feature plugs
 * into via `onNew`, but useful/testable on its own regardless of sound
 * (it's what keeps `useNotifications('active')` polling alive on pages
 * that don't render `NotificationBell`, e.g. the Project Workspace).
 *
 * Mount this ONCE per authenticated shell (AppLayout, AdminLayout) — not
 * inside NotificationBell itself, and not gated on the notification
 * dropdown being open. It shares the exact same `['notifications','active']`
 * React Query cache entry NotificationBell's own `useNotifications('active')`
 * call uses, so mounting this alongside the bell never causes a second
 * network request — only one query is ever in flight per queryKey.
 *
 * New-notification semantics (see CLAUDE.md's "Notification Sound System"
 * section):
 * - The FIRST successful fetch for a given authenticated user establishes
 *   the baseline only — every ID present is recorded as "already seen",
 *   `onNew` is never called for it.
 * - A later fetch is compared against that baseline by `SuresignNotification.id`
 *   membership only — never unread_count, never array position/order
 *   (the backend sorts by priority then created_at, so an unchanged ID set
 *   can legitimately reorder between polls; that must never look "new").
 * - Every ID not previously seen in one fetch is reported via a single
 *   `onNew(newIds)` call — one event per batch, never one call per ID.
 * - The seen-ID set is bounded to the `MAX_TRACKED_IDS` most recently
 *   observed IDs (oldest evicted first) so a long-running browser session
 *   can't grow this unbounded in memory — 200 is comfortably above the
 *   default `per_page=25` notification page size and any realistic
 *   multi-poll accumulation, without holding a full session's history.
 * - The baseline resets whenever the authenticated user id changes
 *   (including to `undefined` on logout), so a later login as a different
 *   user is never compared against the previous user's notification IDs.
 */
export function useNewNotificationWatcher(opts: { enabled: boolean; onNew?: (newIds: number[]) => void }) {
  const userId = useAuthStore(s => s.user?.id);
  const { notifications } = useNotifications('active', undefined, { enabled: opts.enabled });

  const seenRef = useRef<Set<number>>(new Set());
  const orderRef = useRef<number[]>([]);
  const baselineUserRef = useRef<number | undefined>(undefined);
  const baselineEstablishedRef = useRef(false);

  // Latest-callback ref — avoids re-running the diff effect just because
  // the caller passed a fresh inline `onNew` function identity this render.
  // Updated in its own effect (not during render) — refs must only be
  // written outside of render.
  const onNewRef = useRef(opts.onNew);
  useEffect(() => {
    onNewRef.current = opts.onNew;
  });

  // Reset the baseline whenever the authenticated user changes.
  useEffect(() => {
    if (baselineUserRef.current !== userId) {
      seenRef.current = new Set();
      orderRef.current = [];
      baselineEstablishedRef.current = false;
      baselineUserRef.current = userId;
    }
  }, [userId]);

  useEffect(() => {
    if (!opts.enabled || !notifications || userId === undefined) return;

    const seen = seenRef.current;
    const order = orderRef.current;

    function remember(id: number) {
      if (seen.has(id)) return;
      seen.add(id);
      order.push(id);
      while (order.length > MAX_TRACKED_IDS) {
        const oldest = order.shift();
        if (oldest !== undefined) seen.delete(oldest);
      }
    }

    const ids = notifications.map(n => n.id);

    if (!baselineEstablishedRef.current) {
      // First observation for this user — establish baseline only, no
      // sound/callback for notifications that already existed.
      ids.forEach(remember);
      baselineEstablishedRef.current = true;
      return;
    }

    const newIds = ids.filter(id => !seen.has(id));
    ids.forEach(remember);

    if (newIds.length > 0) {
      onNewRef.current?.(newIds);
    }
    // `opts` itself is intentionally not a dependency — only its `enabled`
    // flag matters here, and `onNew` is read via the ref above so a new
    // inline function identity each render doesn't retrigger this effect.
  }, [notifications, userId, opts.enabled]);
}
