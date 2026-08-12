const KEY = 'ss-whats-new-closed';

/**
 * Session-local (NOT persisted server-side) record of which Product Update
 * ids the user has clicked plain "Close" on in this browser tab. This is
 * deliberately separate from the server-side dismissal table
 * (product_update_dismissals) — "Close" only suppresses the modal for the
 * rest of this tab session, so the update may still appear again on a
 * future session; "Don't show this update again" is what persists.
 */
export function getSessionClosedIds(): number[] {
  if (typeof window === 'undefined') return [];
  try {
    const raw = window.sessionStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as number[]) : [];
  } catch {
    return [];
  }
}

export function markSessionClosed(ids: number[]): void {
  if (typeof window === 'undefined') return;
  try {
    const existing = getSessionClosedIds();
    const merged = Array.from(new Set([...existing, ...ids]));
    window.sessionStorage.setItem(KEY, JSON.stringify(merged));
  } catch {
    // sessionStorage unavailable (e.g. private browsing edge cases) —
    // the modal simply may reappear on next route change, not a failure.
  }
}
