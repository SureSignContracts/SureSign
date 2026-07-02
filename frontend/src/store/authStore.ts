import { create } from 'zustand';
import { persist } from 'zustand/middleware';
import api from '@/lib/api';

interface Organization {
  id: number;
  name: string;
  slug: string;
  is_onboarded: boolean;
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
  roles: string[];
  permissions: string[];
  organization: Organization | null;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isLoading: boolean;
  _hasHydrated: boolean;
  setHasHydrated: (val: boolean) => void;
  login: (email: string, password: string) => Promise<void>;
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

      login: async (email, password) => {
        set({ isLoading: true });
        try {
          const { data } = await api.post('/auth/login', { email, password });
          localStorage.setItem('suresign_token', data.token);
          set({ token: data.token, user: data.user, isLoading: false });
        } catch (err) {
          set({ isLoading: false });
          throw err;
        }
      },

      logout: async () => {
        try { await api.post('/auth/logout'); } catch {}
        localStorage.removeItem('suresign_token');
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
      partialize: (s) => ({ token: s.token, user: s.user }),
      onRehydrateStorage: () => (state) => {
        // Keep suresign_token in sync with the persisted Zustand token so that
        // components checking localStorage directly (login redirect, axios) always
        // see the correct value even in new tabs or after a page refresh.
        if (state?.token) {
          localStorage.setItem('suresign_token', state.token);
        } else {
          localStorage.removeItem('suresign_token');
        }
        state?.setHasHydrated(true);
      },
    }
  )
);
