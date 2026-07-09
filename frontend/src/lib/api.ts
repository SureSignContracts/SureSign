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

// Handle 401 globally
api.interceptors.response.use(
  (res) => res,
  (err) => {
    const url: string = err.config?.url || '';
    const isExemptAuthEndpoint = AUTH_ENDPOINTS_EXEMPT_FROM_SESSION_REDIRECT.some((p) => url.includes(p));
    if (err.response?.status === 401 && !isExemptAuthEndpoint && typeof window !== 'undefined') {
      // Clear both copies of the session — the raw key this interceptor reads,
      // and the Zustand-persisted blob. Leaving the latter behind resurrects
      // the just-invalidated token on the next page load (its onRehydrateStorage
      // reconciliation rewrites `suresign_token` from the stale `suresign-auth`
      // value), which sends the login page straight back into the app, back
      // into another 401, forever — a redirect loop that a server-side unban
      // can't stop, since it's caused entirely by stale client storage.
      localStorage.removeItem('suresign_token');
      localStorage.removeItem('suresign-auth');
      window.location.href = '/login';
    }
    return Promise.reject(err);
  }
);

export default api;
