'use client';
import { useState, useEffect } from 'react';
import { useAuthStore } from '@/store/authStore';

export function useTheme() {
  const userId = useAuthStore(s => s.user?.id);
  const storageKey = `suresign-theme-${userId ?? 'guest'}`;

  const [theme, setTheme] = useState<'light' | 'dark'>('light');

  // Re-run whenever the logged-in user changes so each account gets its own preference
  useEffect(() => {
    const stored = localStorage.getItem(storageKey) as 'light' | 'dark' | null;
    const initial = stored || 'light';
    setTheme(initial);
    document.documentElement.setAttribute('data-theme', initial);
  }, [storageKey]);

  const toggle = () => {
    const next = theme === 'light' ? 'dark' : 'light';
    setTheme(next);
    localStorage.setItem(storageKey, next);
    document.documentElement.setAttribute('data-theme', next);
  };

  return { theme, toggle };
}
