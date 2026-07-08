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
      localStorage.removeItem('suresign_token');
      window.location.href = '/login';
    }
    return Promise.reject(err);
  }
);

export default api;
