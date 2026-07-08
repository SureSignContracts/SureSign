// Per-user tour completion state. Mirrors the pattern used by useTheme.ts
// (localStorage keyed by user id) — no backend preferences system exists
// yet, so this is the v1 storage layer. Swap the implementation here for a
// backend-persisted one later without touching call sites.

type UserId = number | string | null | undefined;

function storageKey(userId: UserId): string {
  return `suresign-tours-${userId ?? 'guest'}`;
}

function readAll(userId: UserId): Record<string, boolean> {
  try {
    const raw = localStorage.getItem(storageKey(userId));
    return raw ? JSON.parse(raw) : {};
  } catch {
    return {};
  }
}

function writeAll(userId: UserId, data: Record<string, boolean>) {
  try {
    localStorage.setItem(storageKey(userId), JSON.stringify(data));
  } catch {}
}

export function isTourCompleted(userId: UserId, tourKey: string): boolean {
  return !!readAll(userId)[tourKey];
}

export function markTourCompleted(userId: UserId, tourKey: string) {
  const all = readAll(userId);
  all[tourKey] = true;
  writeAll(userId, all);
}

export function resetTourCompletion(userId: UserId, tourKey: string) {
  const all = readAll(userId);
  delete all[tourKey];
  writeAll(userId, all);
}

export function getCompletedTourKeys(userId: UserId): string[] {
  const all = readAll(userId);
  return Object.keys(all).filter(k => all[k]);
}

function resetMarkerKey(userId: UserId): string {
  return `suresign-tours-reset-marker-${userId ?? 'guest'}`;
}

// A Super Admin can reset a user's tours from the admin Users page, but that
// only sets a timestamp server-side (`users.tours_reset_at`) — it can't
// reach into another browser's localStorage directly. Call this once per
// session (with the timestamp from the freshly-fetched user object) to apply
// that reset locally the next time this browser sees a newer timestamp than
// the one it last applied.
export function applyServerTourReset(userId: UserId, toursResetAt: string | null | undefined) {
  if (!toursResetAt) return;
  try {
    const lastApplied = localStorage.getItem(resetMarkerKey(userId));
    if (lastApplied === toursResetAt) return;
    localStorage.removeItem(storageKey(userId));
    localStorage.setItem(resetMarkerKey(userId), toursResetAt);
  } catch {}
}
