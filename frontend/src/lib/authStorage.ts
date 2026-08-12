'use client';

/**
 * The single shared source of truth for where the SureSign session
 * actually lives — localStorage (survives closing the browser, "Remember
 * me" checked) or sessionStorage (cleared when the tab/browser closes,
 * "Remember me" unchecked). Every place in the app that reads or writes
 * the raw token or the Zustand-persisted auth blob goes through this
 * module, so a session created either way is found consistently by every
 * call site (the axios interceptor, the login page's "already
 * authenticated" check, useAuthSplash, and authStore's own persist
 * middleware) — a previous, real bug in this exact area (see authStore.ts's
 * onRehydrateStorage comments) came from two independent copies of the
 * token disagreeing; introducing a second storage backend without a
 * single shared lookup would reintroduce that class of bug.
 */

const TOKEN_KEY = 'suresign_token';
const AUTH_BLOB_KEY = 'suresign-auth';
const REMEMBER_KEY = 'suresign_remember_me';
const POST_LOGIN_ENTRANCE_KEY = 'suresign_post_login_entrance';
const postLoginEntranceListeners = new Set<() => void>();

function notifyPostLoginEntranceListeners(): void {
  postLoginEntranceListeners.forEach((listener) => listener());
}

/**
 * A short-lived, tab-scoped handoff between the login page and whichever
 * authenticated route the user lands on. It is deliberately separate from
 * the persisted auth state: this is presentation state, not a credential,
 * and must not replay on later reloads or ordinary in-app navigation.
 */
export function markPostLoginEntrance(): void {
  if (typeof window === 'undefined') return;
  sessionStorage.setItem(POST_LOGIN_ENTRANCE_KEY, 'true');
  notifyPostLoginEntranceListeners();
}

export function hasPostLoginEntrance(): boolean {
  if (typeof window === 'undefined') return false;
  return sessionStorage.getItem(POST_LOGIN_ENTRANCE_KEY) === 'true';
}

export function clearPostLoginEntrance(): void {
  if (typeof window === 'undefined') return;
  sessionStorage.removeItem(POST_LOGIN_ENTRANCE_KEY);
  notifyPostLoginEntranceListeners();
}

/** Allows React to read the browser-only handoff without freezing the
 * server-rendered `false` value into component state during hydration. */
export function subscribePostLoginEntrance(listener: () => void): () => void {
  postLoginEntranceListeners.add(listener);
  return () => postLoginEntranceListeners.delete(listener);
}

/**
 * Whether the most recent login opted in to a persistent session. Always
 * stored in localStorage regardless of the choice it records — this is a
 * tiny, non-sensitive UI preference, not a credential, so there's no
 * reason for it to disappear when the session itself does. Defaults to
 * true (this app's only behavior before "Remember me" existed), so an
 * existing session created before this feature shipped is unaffected.
 */
export function getRememberPreference(): boolean {
  if (typeof window === 'undefined') return true;
  const raw = localStorage.getItem(REMEMBER_KEY);
  return raw === null ? true : raw === 'true';
}

function storageFor(remember: boolean): Storage {
  return remember ? localStorage : sessionStorage;
}

/** Reads the raw token from wherever it actually lives. */
export function getStoredToken(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(TOKEN_KEY) ?? sessionStorage.getItem(TOKEN_KEY);
}

/** Reads the Zustand-persisted auth blob from wherever it actually lives. */
export function getStoredAuthBlob(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(AUTH_BLOB_KEY) ?? sessionStorage.getItem(AUTH_BLOB_KEY);
}

/**
 * Records the remember choice and writes the token to the matching
 * storage, clearing any stale copy from the other one first — a session
 * must live in exactly one place, never both, or a later logout could
 * leave one copy behind (exactly the divergence this module exists to
 * prevent).
 */
export function setStoredToken(token: string, remember: boolean): void {
  if (typeof window === 'undefined') return;
  localStorage.setItem(REMEMBER_KEY, remember ? 'true' : 'false');
  storageFor(remember).setItem(TOKEN_KEY, token);
  storageFor(!remember).removeItem(TOKEN_KEY);
}

export function clearStoredToken(): void {
  if (typeof window === 'undefined') return;
  localStorage.removeItem(TOKEN_KEY);
  sessionStorage.removeItem(TOKEN_KEY);
}

export function clearStoredAuthBlob(): void {
  if (typeof window === 'undefined') return;
  localStorage.removeItem(AUTH_BLOB_KEY);
  sessionStorage.removeItem(AUTH_BLOB_KEY);
}

/**
 * A Zustand `persist` `StateStorage` implementation that writes the auth
 * blob to whichever backend `getRememberPreference()` currently says,
 * always clearing the other one — so authStore's own persisted
 * `{token, user}` blob follows the exact same remember/don't-remember rule
 * as the raw token above, instead of unconditionally resurrecting a
 * "forgotten" session on the next page load.
 */
export const rememberAwareStorage = {
  getItem: (name: string): string | null => {
    if (typeof window === 'undefined') return null;
    return localStorage.getItem(name) ?? sessionStorage.getItem(name);
  },
  setItem: (name: string, value: string): void => {
    if (typeof window === 'undefined') return;
    const remember = getRememberPreference();
    storageFor(remember).setItem(name, value);
    storageFor(!remember).removeItem(name);
  },
  removeItem: (name: string): void => {
    if (typeof window === 'undefined') return;
    localStorage.removeItem(name);
    sessionStorage.removeItem(name);
  },
};
