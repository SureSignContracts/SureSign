import { create } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';
import api from '@/lib/api';
import {
  clearStoredAuthBlob,
  clearStoredToken,
  getRememberPreference,
  getStoredToken,
  rememberAwareStorage,
  setStoredToken,
} from '@/lib/authStorage';

interface Organization {
  id: number;
  name: string;
  slug: string;
  is_onboarded: boolean;
  timezone?: string;
  // `currency` is the organisation's own raw override (null = inheriting the
  // platform default). `effective_currency` is what actually applies right
  // now — organisation's own, else platform, else GBP. Prefer this for any
  // display or "default to organisation currency" logic; never infer a
  // currency from `country`/`address` instead.
  currency?: string | null;
  effective_currency?: string;
  branding?: {
    primary_color: string;
    logo_path: string | null;
    company_display_name: string | null;
  } | null;
}

interface User {
  id: number;
  name: string;
  first_name?: string;
  last_name?: string;
  email: string;
  phone?: string;
  job_title?: string;
  avatar?: string;
  address?: string;
  city?: string;
  province?: string;
  postal_code?: string;
  country?: string;
  // `timezone` is the user's own override (null = inheriting the
  // organisation's timezone). `effective_timezone` is what actually
  // applies right now — prefer this for any display logic.
  timezone?: string | null;
  effective_timezone?: string;
  roles: string[];
  permissions: string[];
  organization: Organization | null;
  email_verified_at?: string | null;
  is_active?: boolean;
  banned_at?: string | null;
  must_change_password?: boolean;
  tours_reset_at?: string | null;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isLoading: boolean;
  _hasHydrated: boolean;
  setHasHydrated: (val: boolean) => void;
  setToken: (token: string) => void;
  login: (email: string, password: string, remember?: boolean) => Promise<void>;
  logout: () => Promise<void>;
  fetchUser: () => Promise<void>;
  hasRole: (role: string) => boolean;
  hasPermission: (permission: string) => boolean;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isLoading: false,
      _hasHydrated: false,
      setHasHydrated: (val) => set({ _hasHydrated: val }),
      // Recovers a token found in the raw localStorage key but missing from
      // this store's own persisted state (see onRehydrateStorage below).
      // Also clears `user` — the persisted store caches the FULL user object
      // (including organization.is_onboarded), so if it holds a stale value
      // from whenever the divergence occurred, using it before fetchUser()'s
      // async response lands can trigger a wrong onboarding-guard redirect
      // (AppLayout's `!isOnboarded` check would fire against the stale
      // cached organization instead of the current one). Forcing user to
      // null keeps AppLayout on its loading gate until fresh data arrives,
      // instead of briefly acting on data that shouldn't be trusted.
      setToken: (token) => {
        set({ token, user: null });
        get().fetchUser();
      },

      // `remember` defaults to true — this app's only behavior before
      // "Remember me" existed, so an existing caller/session is unaffected.
      // Written to storage via setStoredToken() BEFORE the `set()` call
      // below, so the persist middleware's own write (triggered by that
      // set(), via rememberAwareStorage) reads the matching, already-updated
      // preference rather than racing it.
      login: async (email, password, remember = true) => {
        set({ isLoading: true });
        try {
          const { data } = await api.post('/auth/login', { email, password });
          // The dev backend (php artisan serve) can return HTTP 200 for a
          // REJECTED login — {"message":"Invalid credentials."} with no
          // token — instead of the 401 the controller actually sets. Axios
          // treats any 2xx as success, so without this check a wrong
          // password would silently "succeed" with token/user set to
          // undefined, which localStorage.setItem coerces to the literal
          // string "undefined" — a truthy value the login page's own
          // already-authenticated check then treats as a valid session,
          // producing an infinite bounce between /login and /app/projects.
          if (!data?.token) {
            set({ isLoading: false });
            throw { response: { data, status: 401 } };
          }
          setStoredToken(data.token, remember);
          set({ token: data.token, user: data.user, isLoading: false });
        } catch (err) {
          set({ isLoading: false });
          throw err;
        }
      },

      logout: async () => {
        try { await api.post('/auth/logout'); } catch {}
        clearStoredToken();
        clearStoredAuthBlob();
        set({ user: null, token: null });
      },

      fetchUser: async () => {
        try {
          const { data } = await api.get('/auth/me');
          set({ user: data });
        } catch {}
      },

      hasRole: (role) => get().user?.roles?.includes(role) ?? false,
      hasPermission: (perm) => get().user?.permissions?.includes(perm) ?? false,
    }),
    {
      name: 'suresign-auth',
      // "Remember me" — writes/reads this blob to/from localStorage or
      // sessionStorage depending on the preference setStoredToken() records
      // at login, so a "don't remember" session is actually forgotten (not
      // resurrected from this blob still sitting in localStorage) — see
      // authStorage.ts's own docblock.
      storage: createJSONStorage(() => rememberAwareStorage),
      partialize: (s) => ({ token: s.token, user: s.user }),
      onRehydrateStorage: () => (state) => {
        // Two independent copies of the token exist — the raw `suresign_token`
        // key (read directly by the axios interceptor and the login page's
        // redirect check) and this store's own persisted `suresign-auth` blob.
        // If they diverge (e.g. a stale tab's logout raced with another tab's
        // fresh login), previously this always overwrote the raw key from the
        // Zustand side — which, if Zustand's copy was the stale/null one,
        // wiped out a perfectly valid session and caused AppLayout's auth
        // guard and the login page's "already authenticated" check to
        // disagree forever (bounce loop between /login and /app/projects).
        // Reconcile by trusting whichever side actually has a token — both
        // reads/writes go through the same remember-aware storage as above.
        const rawToken = getStoredToken();
        if (state?.token) {
          setStoredToken(state.token, getRememberPreference());
        } else if (rawToken) {
          state?.setToken?.(rawToken);
        } else {
          clearStoredToken();
        }
        state?.setHasHydrated(true);
      },
    }
  )
);
