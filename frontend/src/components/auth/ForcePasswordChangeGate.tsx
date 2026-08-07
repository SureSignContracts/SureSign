'use client';

import { useState } from 'react';
import { Lock, Eye, EyeOff } from 'lucide-react';
import { useAuthStore } from '@/store/authStore';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import { getErrorMessage } from '@/lib/getErrorMessage';

// Rendered instead of the normal app shell whenever the logged-in user's
// `must_change_password` flag is set (a Super Admin forced a reset, or set
// a temporary password requiring one). Blocks all other UI until a new
// password is submitted — there is no dismiss/skip path by design.
export default function ForcePasswordChangeGate() {
  const fetchUser = useAuthStore(s => s.fetchUser);
  const logout = useAuthStore(s => s.logout);
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [showPw, setShowPw] = useState(false);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');

    if (password !== confirm) {
      setError('Passwords do not match.');
      return;
    }

    setSaving(true);
    try {
      await api.put('/auth/force-password-change', { password, password_confirmation: confirm });
      toast.success('Password updated.');
      await fetchUser();
    } catch (err) {
      setError(getErrorMessage(err, 'Failed to update password.'));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="min-h-dvh flex items-center justify-center px-4" style={{ backgroundColor: 'var(--bg-base)' }}>
      <div
        className="w-full max-w-sm rounded-2xl p-7 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        <div className="w-11 h-11 rounded-xl flex items-center justify-center mb-4" style={{ backgroundColor: 'var(--gold-15)' }}>
          <Lock size={19} style={{ color: 'var(--gold)' }} />
        </div>
        <h1 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>Set a new password</h1>
        <p className="mt-1.5 text-sm" style={{ color: 'var(--text-muted)' }}>
          An administrator requires you to set a new password before continuing.
        </p>

        {error && (
          <div className="mt-4 rounded-xl px-3.5 py-2.5 text-sm" style={{ backgroundColor: 'rgba(220,38,38,0.08)', border: '1px solid rgba(220,38,38,0.2)', color: '#f87171' }}>
            {error}
          </div>
        )}

        <form onSubmit={handleSubmit} className="mt-5 space-y-3.5">
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>New password</label>
            <div className="relative">
              <input
                type={showPw ? 'text' : 'password'}
                value={password}
                onChange={e => setPassword(e.target.value)}
                required
                autoComplete="new-password"
                className="w-full px-3.5 py-2.5 pr-10 rounded-xl text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
              <button
                type="button"
                tabIndex={-1}
                onClick={() => setShowPw(p => !p)}
                className="absolute right-3 top-1/2 -translate-y-1/2"
                style={{ color: 'var(--text-muted)' }}
              >
                {showPw ? <EyeOff size={14} /> : <Eye size={14} />}
              </button>
            </div>
          </div>
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Confirm new password</label>
            <input
              type={showPw ? 'text' : 'password'}
              value={confirm}
              onChange={e => setConfirm(e.target.value)}
              required
              autoComplete="new-password"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            At least 8 characters, with upper &amp; lower case, a number and a symbol.
          </p>
          <button
            type="submit"
            disabled={saving}
            className="w-full py-2.5 rounded-xl text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98] disabled:opacity-60"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {saving ? 'Updating…' : 'Update password'}
          </button>
          <button
            type="button"
            onClick={() => logout().then(() => (window.location.href = '/login'))}
            className="w-full py-2 text-xs font-medium transition-opacity hover:opacity-70"
            style={{ color: 'var(--text-muted)' }}
          >
            Sign out instead
          </button>
        </form>
      </div>
    </div>
  );
}
