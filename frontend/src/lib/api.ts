import axios from 'axios';

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api',
  headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
  withCredentials: false,
});

// Attach token from localStorage on every request
api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    const token = localStorage.getItem('suresign_token');
    if (token) config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Endpoints where a 401 means "these credentials were rejected", not
// "your session expired" — a failed login attempt must never trigger the
// global session-expiry redirect below. Previously it did: entering a wrong
// password (including a stale one from browser autofill) returned 401 from
// /auth/login, which this interceptor treated as an expired session and
// force-reloaded straight back to /login — indistinguishable from an
// infinite loop, especially if autofill resubmitted the same bad password.
const AUTH_ENDPOINTS_EXEMPT_FROM_SESSION_REDIRECT = ['/auth/login', '/auth/register'];

// Backend code for "this account is deactivated/banned" (EnsureAccountIsActive
// middleware) — distinct from the generic tenant-isolation 403s the app
// already relies on elsewhere, which must NOT trigger a logout.
const ACCOUNT_UNAVAILABLE_CODE = 'account_unavailable';

// Handle 401 (expired/invalid token) and the account-unavailable 403 globally.
// Deliberately does NOT special-case the password_change_required 403 here:
// the frontend's ForcePasswordChangeGate (driven by user.must_change_password,
// already known from the login/me response) blocks normal navigation before
// any such request would ever fire — treating that 403 as a logout here would
// undo that gate's whole point of keeping the user authenticated while they
// complete the required change.
// Error Messaging & Recovery UX, Batch 1 — a safe, non-sensitive reason code
// carried on the redirect so the login page can explain why the user landed
// there instead of showing a bare form (e.g. "Your session has expired.").
// Never anything more specific than these two fixed enum values — no token,
// no destination path, no account/organisation detail.
type AuthNotice = 'session_expired' | 'account_unavailable';

api.interceptors.response.use(
  (res) => res,
  (err) => {
    const url: string = err.config?.url || '';
    const isExemptAuthEndpoint = AUTH_ENDPOINTS_EXEMPT_FROM_SESSION_REDIRECT.some((p) => url.includes(p));
    const isAccountUnavailable = err.response?.status === 403 && err.response?.data?.code === ACCOUNT_UNAVAILABLE_CODE;
    if ((err.response?.status === 401 || isAccountUnavailable) && !isExemptAuthEndpoint && typeof window !== 'undefined') {
      // Clear both copies of the session — the raw key this interceptor reads,
      // and the Zustand-persisted blob. Leaving the latter behind resurrects
      // the just-invalidated token on the next page load (its onRehydrateStorage
      // reconciliation rewrites `suresign_token` from the stale `suresign-auth`
      // value), which sends the login page straight back into the app, back
      // into another 401, forever — a redirect loop that a server-side unban
      // can't stop, since it's caused entirely by stale client storage.
      localStorage.removeItem('suresign_token');
      localStorage.removeItem('suresign-auth');
      const notice: AuthNotice = isAccountUnavailable ? 'account_unavailable' : 'session_expired';
      window.location.href = `/login?authNotice=${notice}`;
    }
    return Promise.reject(err);
  }
);

export default api;
