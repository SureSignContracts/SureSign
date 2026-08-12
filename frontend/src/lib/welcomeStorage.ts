type UserId = number | string | null | undefined;

const WELCOME_VERSION = 'v1';

function storageKey(userId: UserId): string {
  return `suresign-welcome-${WELCOME_VERSION}-${userId ?? 'guest'}`;
}

function currentMarker(toursResetAt?: string | null): string {
  return toursResetAt || 'seen';
}

/**
 * The welcome is a browser-level product introduction, matching the existing
 * per-user guided-tour storage. A server-side tour reset also makes it
 * available again by changing the marker expected for that user.
 */
export function isWelcomeSeen(userId: UserId, toursResetAt?: string | null): boolean {
  if (typeof window === 'undefined') return true;
  try {
    return localStorage.getItem(storageKey(userId)) === currentMarker(toursResetAt);
  } catch {
    return true;
  }
}

export function markWelcomeSeen(userId: UserId, toursResetAt?: string | null): void {
  if (typeof window === 'undefined') return;
  try {
    localStorage.setItem(storageKey(userId), currentMarker(toursResetAt));
  } catch {}
}
