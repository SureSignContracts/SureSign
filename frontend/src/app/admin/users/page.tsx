'use client';

import { useState, useEffect } from 'react';
import { useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import {
  Users, UserPlus, Shield, Mail, Search, Copy, Check,
  Settings2, Trash2, X,
  KeyRound, RotateCcw, LogOut, Compass, ShieldCheck, ExternalLink,
} from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import toast from '@/lib/toast';
import PaginationBar from '@/components/ui/PaginationBar';
import Toggle from '@/components/ui/Toggle';
import Select from '@/components/ui/Select';
import { Badge, Tone } from '@/components/ui/Badge';
import { useUserInheritedSubscription } from '@/hooks/useBilling';
import { SubscriptionSummaryView } from '@/types/subscriptionIntelligence';
import UsageMeter from '@/components/billing/intelligence/UsageMeter';
import { getErrorMessage } from '@/lib/getErrorMessage';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

const ACCESS_MODE_TONE: Record<string, Tone> = {
  none: 'neutral', trial: 'accent', full: 'success', grace: 'warning', restricted: 'danger',
};
const ACCESS_MODE_LABEL: Record<string, string> = {
  none: 'No access', trial: 'Trial', full: 'Full access', grace: 'Grace period', restricted: 'Restricted',
};

// Matches the roles actually seeded in DatabaseSeeder — 'Manager'/'Viewer'
// were previously listed here but no such role exists in the backend.
const ALL_ROLES = ['Super Admin', 'Admin', 'Client'] as const;

const INVITE_ROLES = ['Admin', 'Client'] as const;
type InviteRole = typeof INVITE_ROLES[number];

const STATUS_FILTERS = [
  { key: 'all',      label: 'All' },
  { key: 'active',   label: 'Active' },
  { key: 'disabled', label: 'Disabled' },
] as const;
type StatusFilter = typeof STATUS_FILTERS[number]['key'];

const roleBadge: Record<string, { bg: string; text: string }> = {
  'Super Admin': { bg: 'rgba(239,68,68,0.12)',   text: '#f87171' },
  'Admin':       { bg: 'rgba(249,115,22,0.12)',  text: '#fb923c' },
  'Client':      { bg: 'rgba(59,130,246,0.12)',  text: '#60a5fa' },
};

// Guarantees at least one of each character class the backend's
// Password::mixedCase()->numbers()->symbols() rule requires, rather than
// leaving it to chance (a purely random base-36 string could land on an
// all-digit or all-letter run and fail server-side validation).
function genPassword(): string {
  const upper  = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
  const lower  = 'abcdefghijkmnopqrstuvwxyz';
  const digits = '23456789';
  const symbols = '!@#$%';
  const pick = (chars: string) => chars[Math.floor(Math.random() * chars.length)];
  const required = [pick(upper), pick(upper), pick(lower), pick(lower), pick(digits), pick(digits), pick(symbols)];
  const filler = Array.from({ length: 3 }, () => pick(upper + lower + digits));
  return [...required, ...filler].sort(() => Math.random() - 0.5).join('');
}

interface OrganizationSubscriptionSummary {
  plan_name: string | null;
  status: string | null;
  access_mode: string;
  trial_ends_at: string | null;
}

interface AdminUser {
  id: number;
  name: string;
  email: string;
  roles: string[];
  is_active: boolean;
  email_verified_at: string | null;
  banned_at: string | null;
  banned_reason: string | null;
  must_change_password: boolean;
  tours_reset_at: string | null;
  last_login_at: string | null;
  created_at: string;
  organization_id: number | null;
  organization_name: string | null;
  is_platform_operator: boolean;
  organization_subscription: OrganizationSubscriptionSummary | null;
}

// ── Organisation / inherited subscription pill (Users list — lightweight, no per-row fetch) ──
function OrganizationCell({ u }: { u: AdminUser }) {
  if (u.is_platform_operator) {
    return (
      <div className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
        <ShieldCheck size={12} />
        Platform Operator
      </div>
    );
  }

  if (!u.organization_name) {
    return <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>;
  }

  const sub = u.organization_subscription;

  return (
    <div>
      <p className="text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{u.organization_name}</p>
      {sub && (
        <div className="flex items-center gap-1.5 mt-0.5">
          <Badge tone={ACCESS_MODE_TONE[sub.access_mode] ?? 'neutral'} className="!px-1.5 !py-0 !text-[10px]">
            {ACCESS_MODE_LABEL[sub.access_mode] ?? sub.access_mode}
          </Badge>
          {sub.plan_name && (
            <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{sub.plan_name}</span>
          )}
        </div>
      )}
    </div>
  );
}

// ── Inherited subscription section inside Manage User modal — fetched lazily, only while the modal is open ──
function InheritedSubscriptionSection({ user }: { user: AdminUser }) {
  const { data, isLoading } = useUserInheritedSubscription(user.id);

  if (user.is_platform_operator) {
    return (
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
        Platform operators have no organisation subscription of their own.
      </p>
    );
  }

  if (isLoading) {
    return <div className="h-16 rounded-xl animate-pulse motion-reduce:animate-none" style={{ backgroundColor: 'var(--bg-elevated)' }} />;
  }

  const info = data?.data;
  if (!info || info.is_platform_operator) {
    return <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Could not load subscription information.</p>;
  }

  const subscription = info.subscription as unknown as SubscriptionSummaryView | null;
  const access = subscription?.access;

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <div>
          <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
            {subscription?.plan_name ?? subscription?.plan_name_snapshot ?? 'No plan'}
          </p>
          <p className="text-xs mt-0.5 capitalize" style={{ color: 'var(--text-muted)' }}>
            {subscription ? String(subscription.status ?? '').replace(/_/g, ' ') : 'No subscription'}
          </p>
        </div>
        {access && (
          <Badge tone={ACCESS_MODE_TONE[access.mode] ?? 'neutral'}>{ACCESS_MODE_LABEL[access.mode] ?? access.mode}</Badge>
        )}
      </div>

      {info.trial && (
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          Trial ends {formatDateTime(info.trial.ends_at)} ({info.trial.days_remaining} day{info.trial.days_remaining === 1 ? '' : 's'} left).
        </p>
      )}

      {(info.ai || info.storage) && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
          {info.ai && <UsageMeter metric={info.ai} />}
          {info.storage && <UsageMeter metric={info.storage} />}
        </div>
      )}

      {user.organization_id && (
        <Link
          href={`/admin/companies/${user.organization_id}`}
          className="inline-flex items-center gap-1.5 text-xs font-medium hover:opacity-80"
          style={{ color: 'var(--gold)' }}
        >
          <ExternalLink size={12} />
          Manage Organization Subscription
        </Link>
      )}
    </div>
  );
}

// ── Status badges ───────────────────────────────────────────────────────────
function StatusBadges({ u }: { u: AdminUser }) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {u.banned_at ? (
        <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' }}>
          Banned
        </span>
      ) : (
        <span
          className="text-xs px-2 py-0.5 rounded-full font-medium"
          style={{
            backgroundColor: u.is_active ? 'rgba(34,197,94,0.12)' : 'rgba(90,86,82,0.15)',
            color: u.is_active ? '#4ade80' : 'var(--text-muted)',
          }}
        >
          {u.is_active ? 'Active' : 'Disabled'}
        </span>
      )}
      <span
        className="text-xs px-2 py-0.5 rounded-full font-medium"
        style={{
          backgroundColor: u.email_verified_at ? 'rgba(59,130,246,0.12)' : 'rgba(90,86,82,0.15)',
          color: u.email_verified_at ? '#60a5fa' : 'var(--text-muted)',
        }}
      >
        {u.email_verified_at ? 'Verified' : 'Unverified'}
      </span>
    </div>
  );
}

// ── Section header — small uppercase label used to divide the modal ───────────
function SectionHeader({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-[10px] uppercase tracking-widest font-semibold mb-2.5" style={{ color: 'var(--text-muted)' }}>
      {children}
    </p>
  );
}

// ── Status row: label + description + toggle pill ─────────────────────────────
function StatusRow({
  label,
  description,
  checked,
  onChange,
  disabled,
}: {
  label: string;
  description: string;
  checked: boolean;
  onChange: (value: boolean) => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex items-center justify-between gap-4 py-2.5">
      <div className="min-w-0">
        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{label}</p>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{description}</p>
      </div>
      <Toggle checked={checked} onChange={onChange} disabled={disabled} />
    </div>
  );
}

// ── One-shot action with an inline "are you sure?" instead of a nested modal ──
function ConfirmButton({
  label,
  icon,
  onConfirm,
  loading,
  danger,
}: {
  label: string;
  icon: React.ReactNode;
  onConfirm: () => void;
  loading?: boolean;
  danger?: boolean;
}) {
  const [confirming, setConfirming] = useState(false);

  if (confirming) {
    return (
      <div className="flex items-center justify-between gap-2 px-3 py-2.5 rounded-xl" style={{ border: '1px solid var(--border)' }}>
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Are you sure?</span>
        <div className="flex items-center gap-2">
          <button
            onClick={() => { onConfirm(); setConfirming(false); }}
            disabled={loading}
            className="text-xs px-2.5 py-1 rounded-lg font-medium disabled:opacity-60"
            style={{ backgroundColor: danger ? '#ef4444' : 'var(--gold)', color: danger ? '#fff' : 'var(--accent-fg)' }}
          >
            Yes
          </button>
          <button
            onClick={() => setConfirming(false)}
            className="text-xs px-2.5 py-1 rounded-lg font-medium"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
          >
            No
          </button>
        </div>
      </div>
    );
  }

  return (
    <button
      onClick={() => setConfirming(true)}
      className="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm text-left transition-colors hover:bg-[var(--bg-hover)]"
      style={{ color: danger ? '#ef4444' : 'var(--text-secondary)', border: '1px solid var(--border)' }}
    >
      {icon}
      {label}
    </button>
  );
}

// ── Manage user modal — consolidates rename, role, status pills and actions ───
function ManageUserModal({
  user,
  onClose,
  onSave,
  saving,
  onToggleActive,
  onToggleVerify,
  onBan,
  onUnban,
  actionLoading,
  onForcePasswordReset,
  onSetPassword,
  onRevokeTokens,
  onResetTours,
  onRemove,
}: {
  user: AdminUser;
  onClose: () => void;
  onSave: (payload: { name?: string; role?: string }) => void;
  saving: boolean;
  onToggleActive: (active: boolean) => void;
  onToggleVerify: (verified: boolean) => void;
  onBan: (reason: string) => void;
  onUnban: () => void;
  actionLoading: boolean;
  onForcePasswordReset: () => void;
  onSetPassword: () => void;
  onRevokeTokens: () => void;
  onResetTours: () => void;
  onRemove: () => void;
}) {
  const [name, setName] = useState(user.name);
  const [role, setRole] = useState(user.roles[0] ?? 'Client');
  const [banReasonOpen, setBanReasonOpen] = useState(false);
  const [banReason, setBanReason] = useState('');

  const nameDirty = name.trim() !== user.name && name.trim().length > 0;
  const roleDirty = role !== (user.roles[0] ?? 'Client');
  const initials = user.name?.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase() || '?';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-md" style={{ backgroundColor: 'rgba(9,14,12,0.76)' }} onClick={onClose}>
      <div
        className="grid max-h-[90vh] w-full max-w-4xl overflow-hidden rounded-2xl ss-animate-in md:grid-cols-[280px_minmax(0,1fr)]"
        style={{ backgroundColor: 'var(--bg-panel)', boxShadow: '0 28px 80px rgba(8, 14, 11, 0.38)' }}
        onClick={e => e.stopPropagation()}
      >
        <aside className="relative hidden overflow-hidden bg-[#18211d] p-7 text-white md:flex md:flex-col">
          <div className="pointer-events-none absolute -right-20 -top-20 h-56 w-56 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
          <div className="relative">
            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-[#9ee5b5]">Identity control</p>
            <div className="mt-8 flex h-14 w-14 items-center justify-center rounded-2xl bg-[#9ee5b5] text-base font-bold text-[#18211d] shadow-[0_12px_30px_rgba(158,229,181,0.18)]">
              {initials}
            </div>
            <h2 className="mt-5 text-xl font-semibold tracking-[-0.03em]">{user.name}</h2>
            <p className="mt-1 break-all text-xs text-white/45">{user.email}</p>

            <div className="mt-8 space-y-4 border-t border-white/10 pt-6">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-medium">Account access</p>
                  <p className="mt-0.5 text-[11px] text-white/40">Allow this user to sign in</p>
                </div>
                <Toggle checked={user.is_active} onChange={onToggleActive} disabled={actionLoading} />
              </div>
              <div className="grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-white/10">
                <div className="bg-[#18211d] p-3">
                  <p className="text-[10px] text-white/35">Joined</p>
                  <p className="mt-1 text-xs font-medium">{user.created_at ? formatDate(user.created_at) : '—'}</p>
                </div>
                <div className="bg-[#18211d] p-3">
                  <p className="text-[10px] text-white/35">Last active</p>
                  <p className="mt-1 text-xs font-medium">{user.last_login_at ? formatDate(user.last_login_at) : 'Never'}</p>
                </div>
              </div>
            </div>
          </div>

          <div className="relative mt-auto border-t border-white/10 pt-5">
            <p className="text-[10px] uppercase tracking-[0.14em] text-white/30">Current role</p>
            <p className="mt-2 flex items-center gap-2 text-sm font-medium text-[#9ee5b5]"><Shield size={14} />{user.roles[0] ?? 'Client'}</p>
          </div>
        </aside>

        <main className="relative max-h-[90vh] overflow-y-auto p-6 sm:p-8">
          <div className="mb-7 pr-10">
            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] md:hidden" style={{ color: 'var(--gold)' }}>Identity control</p>
            <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Manage user</h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Profile, permissions and account security</p>
          </div>
          <button onClick={onClose} aria-label="Close user manager" className="absolute right-5 top-5 rounded-xl p-2 transition-colors hover:bg-[var(--bg-hover)]">
            <X size={17} style={{ color: 'var(--text-muted)' }} />
          </button>

        {/* ── Account Information ── */}
        <section className="mb-6">
          <SectionHeader>Account Information</SectionHeader>

          <div className="mb-3">
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Name</label>
            <input
              value={name}
              onChange={e => setName(e.target.value)}
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          <div className="grid grid-cols-2 gap-3 mb-1 md:hidden">
            <div className="rounded-xl px-3.5 py-2.5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              <p className="text-[10px] font-medium" style={{ color: 'var(--text-muted)' }}>Joined</p>
              <p className="text-sm mt-0.5 tabular-nums" style={{ color: 'var(--text-primary)' }}>
                {user.created_at ? formatDate(user.created_at) : '—'}
              </p>
            </div>
            <div className="rounded-xl px-3.5 py-2.5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              <p className="text-[10px] font-medium" style={{ color: 'var(--text-muted)' }}>Last Active</p>
              <p className="text-sm mt-0.5 tabular-nums" style={{ color: 'var(--text-primary)' }}>
                {user.last_login_at ? formatDate(user.last_login_at) : 'Never'}
              </p>
            </div>
          </div>

          <div className="md:hidden"><StatusRow
            label="Active"
            description="Deactivated users cannot log in."
            checked={user.is_active}
            onChange={onToggleActive}
            disabled={actionLoading}
          /></div>
        </section>

        <div style={{ borderTop: '1px solid var(--border)', margin: '0 0 20px' }} />

        {/* ── Subscription (inherited, read-only — G4A) ── */}
        <section className="mb-6">
          <SectionHeader>Subscription</SectionHeader>
          <InheritedSubscriptionSection user={user} />
        </section>

        <div style={{ borderTop: '1px solid var(--border)', margin: '0 0 20px' }} />

        {/* ── Permissions ── */}
        <section className="mb-6">
          <SectionHeader>Permissions</SectionHeader>
          <div className="flex gap-2 flex-wrap">
            {ALL_ROLES.map(r => (
              <button
                key={r}
                onClick={() => setRole(r)}
                className="flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium transition-all"
                style={{
                  backgroundColor: role === r ? 'var(--gold-15)' : 'var(--bg-elevated)',
                  border: `1px solid ${role === r ? 'var(--gold-50)' : 'var(--border)'}`,
                  color: role === r ? 'var(--text-primary)' : 'var(--text-secondary)',
                }}
              >
                <Shield size={11} style={{ color: role === r ? 'var(--gold)' : 'var(--text-muted)' }} />
                {r}
                {role === r && <Check size={12} style={{ color: 'var(--gold)' }} />}
              </button>
            ))}
          </div>

          {(nameDirty || roleDirty) && (
            <button
              onClick={() => onSave({ ...(nameDirty ? { name: name.trim() } : {}), ...(roleDirty ? { role } : {}) })}
              disabled={saving}
              className="w-full mt-3 py-2.5 rounded-xl text-sm font-medium disabled:opacity-60 transition-opacity hover:opacity-90 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {saving ? 'Saving…' : 'Save Changes'}
            </button>
          )}
        </section>

        <div style={{ borderTop: '1px solid var(--border)', margin: '0 0 20px' }} />

        {/* ── Security ── */}
        <section className="mb-6">
          <SectionHeader>Security</SectionHeader>

          <div className="divide-y mb-3" style={{ borderColor: 'var(--border)' }}>
            <StatusRow
              label="Email Verified"
              description="Marks this user's email address as confirmed."
              checked={!!user.email_verified_at}
              onChange={onToggleVerify}
              disabled={actionLoading}
            />
            <div className="py-2.5">
              <div className="flex items-center justify-between gap-4">
                <div className="min-w-0">
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Banned</p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    {user.banned_at ? `Reason: ${user.banned_reason}` : 'Banned users are signed out everywhere and cannot log in.'}
                  </p>
                </div>
                <Toggle
                  checked={!!user.banned_at}
                  disabled={actionLoading}
                  onChange={(checked) => {
                    if (checked) {
                      setBanReasonOpen(true);
                    } else {
                      onUnban();
                    }
                  }}
                />
              </div>
              {banReasonOpen && (
                <div className="mt-2.5 space-y-2">
                  <textarea
                    value={banReason}
                    onChange={e => setBanReason(e.target.value)}
                    placeholder="Reason for ban…"
                    rows={2}
                    className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  />
                  <div className="flex gap-2">
                    <button
                      onClick={() => { setBanReasonOpen(false); setBanReason(''); }}
                      className="flex-1 py-2 rounded-lg text-xs font-medium"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                    >
                      Cancel
                    </button>
                    <button
                      onClick={() => { onBan(banReason.trim()); setBanReasonOpen(false); setBanReason(''); }}
                      disabled={!banReason.trim() || actionLoading}
                      className="flex-1 py-2 rounded-lg text-xs font-medium disabled:opacity-60"
                      style={{ backgroundColor: '#ef4444', color: '#fff' }}
                    >
                      Confirm Ban
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>

          <div className="space-y-2">
            <ConfirmButton label="Force Password Reset" icon={<RotateCcw size={13} />} onConfirm={onForcePasswordReset} loading={actionLoading} />
            <button
              onClick={onSetPassword}
              className="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm text-left transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
            >
              <KeyRound size={13} />
              Set Temporary Password
            </button>
          </div>
        </section>

        <div style={{ borderTop: '1px solid var(--border)', margin: '0 0 20px' }} />

        {/* ── Sessions ── */}
        <section className="mb-6">
          <SectionHeader>Sessions</SectionHeader>
          <ConfirmButton label="Revoke Active Sessions" icon={<LogOut size={13} />} onConfirm={onRevokeTokens} loading={actionLoading} />
        </section>

        <div style={{ borderTop: '1px solid var(--border)', margin: '0 0 20px' }} />

        {/* ── Onboarding ── */}
        <section className="mb-6">
          <SectionHeader>Onboarding</SectionHeader>
          <button
            onClick={onResetTours}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 rounded-xl text-sm text-left transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
          >
            <Compass size={13} />
            Reset Onboarding Tours
          </button>
          {user.tours_reset_at && (
            <p className="text-xs mt-2" style={{ color: 'var(--text-muted)' }}>
              Last reset {formatDate(user.tours_reset_at)}
            </p>
          )}
        </section>

        <div style={{ borderTop: '1px solid var(--border)', margin: '0 0 20px' }} />

        {/* ── Danger Zone ── */}
        <section>
          <SectionHeader>Danger Zone</SectionHeader>
          <ConfirmButton label="Remove User" icon={<Trash2 size={13} />} onConfirm={onRemove} loading={actionLoading} danger />
        </section>
        </main>
      </div>
    </div>
  );
}

// ── Set temporary password modal ─────────────────────────────────────────────
function SetPasswordModal({
  user,
  onClose,
  onSave,
  saving,
  result,
}: {
  user: AdminUser;
  onClose: () => void;
  onSave: (password: string, requireChange: boolean) => void;
  saving: boolean;
  result: string | null;
}) {
  const [password, setPassword] = useState(() => genPassword());
  const [requireChange, setRequireChange] = useState(true);
  const [copied, setCopied] = useState(false);

  if (result) {
    return (
      <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}>
        <div className="w-full max-w-sm rounded-2xl p-6 ss-animate-in" style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
          <h2 className="text-base font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>Password Set</h2>
          <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>Share this with {user.email} — it will not be shown again.</p>
          <div className="rounded-lg p-3 mb-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <div className="flex items-center justify-between mb-1">
              <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Temporary Password</p>
              <button
                onClick={() => { navigator.clipboard.writeText(result); setCopied(true); setTimeout(() => setCopied(false), 2000); }}
                className="flex items-center gap-1 text-xs px-2 py-0.5 rounded"
                style={{ color: copied ? '#4ade80' : 'var(--gold)', backgroundColor: copied ? 'rgba(34,197,94,0.1)' : 'var(--gold-15)' }}
              >
                {copied ? <Check size={11} /> : <Copy size={11} />}
                {copied ? 'Copied!' : 'Copy'}
              </button>
            </div>
            <p className="text-base font-mono font-semibold tracking-widest" style={{ color: 'var(--text-primary)' }}>{result}</p>
          </div>
          <button onClick={onClose} className="w-full py-2.5 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            Done
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div className="w-full max-w-sm rounded-2xl p-6 ss-animate-in" style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }} onClick={e => e.stopPropagation()}>
        <h2 className="text-base font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>Set Temporary Password</h2>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>{user.name} · {user.email}</p>
        <div className="relative mb-3">
          <input
            value={password}
            onChange={e => setPassword(e.target.value)}
            className="w-full px-3.5 py-2.5 pr-20 rounded-xl text-sm font-mono outline-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
          <button
            onClick={() => setPassword(genPassword())}
            className="absolute right-2 top-1/2 -translate-y-1/2 text-xs px-2 py-1 rounded-lg"
            style={{ color: 'var(--gold)', backgroundColor: 'var(--gold-15)' }}
          >
            Regenerate
          </button>
        </div>
        <label className="flex items-center gap-2 mb-5 text-xs" style={{ color: 'var(--text-secondary)' }}>
          <input type="checkbox" checked={requireChange} onChange={e => setRequireChange(e.target.checked)} />
          Require password change on next login
        </label>
        <div className="flex gap-3">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => onSave(password, requireChange)}
            disabled={saving || password.length < 8}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-60"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {saving ? 'Saving…' : 'Set Password'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function AdminUsersPage() {
  const router = useRouter();
  const currentUser = useAuthStore(s => s.user);
  const isSuperAdmin = currentUser?.roles?.includes('Super Admin') ?? false;

  const [search, setSearch]             = useState('');
  const [debouncedSearch, setDebounced] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [page, setPage]                 = useState(1);
  const [perPage, setPerPage]           = useState<number>(25);
  const [inviteOpen, setInviteOpen]     = useState(false);
  const [inviteEmail, setInviteEmail]   = useState('');
  const [inviteRole, setInviteRole]     = useState<InviteRole>('Client');
  const [inviteBetaNotice, setInviteBetaNotice] = useState(false);
  const [inviteMode, setInviteMode]     = useState<'single' | 'bulk'>('single');
  const [bulkEmailsText, setBulkEmailsText] = useState('');
  const [bulkResult, setBulkResult] = useState<{ invited: { email: string }[]; failed: { email: string; reason: string }[] } | null>(null);
  const [manageUser, setManageUser]     = useState<AdminUser | null>(null);
  const [passwordUser, setPasswordUser]   = useState<AdminUser | null>(null);
  const [passwordResult, setPasswordResult] = useState<string | null>(null);
  const qc = useQueryClient();

  // Defense-in-depth: nav hiding already keeps non-Super-Admins from seeing
  // the link, but a direct URL visit should not render this page either.
  // The API itself is the real boundary (role:Super Admin middleware).
  useEffect(() => {
    if (currentUser && !isSuperAdmin) {
      router.replace('/admin');
    }
  }, [currentUser, isSuperAdmin, router]);

  // Debounce search to avoid request-per-keystroke
  useEffect(() => {
    const t = setTimeout(() => { setDebounced(search); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-users', debouncedSearch, statusFilter, page, perPage],
    queryFn: () => {
      const params: Record<string, string | number> = { page, per_page: perPage };
      if (debouncedSearch) params.search = debouncedSearch;
      if (statusFilter !== 'all') params.status = statusFilter;
      return api.get('/users', { params }).then(r => r.data);
    },
    placeholderData: (prev: any) => prev,
    enabled: isSuperAdmin,
  });

  const inviteMutation = useMutation({
    mutationFn: (payload: { email: string; role: InviteRole; include_beta_notice: boolean }) => api.post('/users/invite', payload).then(r => r.data),
    onSuccess: (res: any) => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      setInviteOpen(false);
      setInviteEmail('');
      setInviteRole('Client');
      setInviteBetaNotice(false);
      // The recipient sets their own password via the invitation email —
      // no credential is ever generated for display here (see
      // UserController::invite()).
      toast.success(res?.message ?? `Invitation sent to ${res?.data?.email ?? inviteEmail}.`);
    },
    onError: (e: any) => {
      toast.error(e?.response?.data?.message ?? e?.response?.data?.errors?.email?.[0] ?? 'Failed to send invitation.');
    },
  });

  // Splits on newlines and/or commas (so a column pasted from a
  // spreadsheet or a comma-separated list both work), trims whitespace,
  // and drops blank lines — deduping and per-email validation both happen
  // server-side (UserController::bulkInvite()) so failures are reported
  // per email rather than guessed at here.
  const parseBulkEmails = (text: string): string[] =>
    text.split(/[\n,]/).map(e => e.trim()).filter(Boolean);

  const bulkInviteMutation = useMutation({
    mutationFn: (payload: { emails: string[]; role: InviteRole; include_beta_notice: boolean }) =>
      api.post('/users/bulk-invite', payload).then(r => r.data),
    onSuccess: (res: any) => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      const invited: { email: string }[] = res?.data?.invited ?? [];
      const failed: { email: string; reason: string }[] = res?.data?.failed ?? [];
      setBulkResult({ invited, failed });
      if (failed.length === 0) {
        // Every email succeeded — close and reset exactly like the single
        // invite flow. A partial result stays open (see JSX below) so the
        // admin can see and act on which emails failed and why, per the
        // Error Handling Standard's partial-success honesty rule.
        setInviteOpen(false);
        setBulkEmailsText('');
        setInviteRole('Client');
        setInviteBetaNotice(false);
        setBulkResult(null);
      }
      toast.success(res?.message ?? `${invited.length} invitation(s) sent.`);
    },
    onError: (e: any) => {
      toast.error(e?.response?.data?.message ?? 'Failed to send invitations.');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      api.put(`/users/${id}`, payload).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      toast.success('User updated.');
    },
    onError: (e: any) => {
      toast.error(getErrorMessage(e, 'Failed to update user.'));
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/users/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      setManageUser(null);
      toast.success('User removed.');
    },
    onError: (e: any) => {
      toast.error(getErrorMessage(e, 'Failed to remove user.'));
    },
  });

  // Generic action mutation for the simple POST /users/{id}/{action} endpoints.
  const actionMutation = useMutation({
    mutationFn: ({ id, action, payload }: { id: number; action: string; payload?: Record<string, unknown> }) =>
      api.post(`/users/${id}/${action}`, payload).then(r => r.data),
    onSuccess: (_res, vars) => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      toast.success(actionSuccessMessage(vars.action));
    },
    onError: (e: any) => {
      toast.error(getErrorMessage(e, 'Action failed.'));
    },
  });

  const setPasswordMutation = useMutation({
    mutationFn: ({ id, password, requireChange }: { id: number; password: string; requireChange: boolean }) =>
      api.post(`/users/${id}/set-password`, { password, require_change: requireChange }).then(() => password),
    onSuccess: (password) => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      setPasswordResult(password);
    },
    onError: (e: any) => {
      toast.error(e?.response?.data?.message ?? e?.response?.data?.errors?.password?.[0] ?? 'Failed to set password.');
    },
  });

  function actionSuccessMessage(action: string): string {
    switch (action) {
      case 'verify-email':          return 'Email marked as verified.';
      case 'unverify-email':        return 'Email marked as unverified.';
      case 'unban':                 return 'User unbanned.';
      case 'ban':                   return 'User banned.';
      case 'force-password-reset':  return 'User must change their password on next login.';
      case 'revoke-tokens':         return 'Active sessions revoked.';
      case 'reset-tours':           return 'Onboarding tours reset for this user.';
      default:                      return 'Done.';
    }
  }

  const users: AdminUser[]   = data?.data          ?? [];
  const totalUsers: number   = data?.total          ?? 0;
  const lastPage: number     = data?.last_page       ?? 1;
  const currentPage: number  = data?.current_page    ?? 1;

  if (currentUser && !isSuperAdmin) return null;

  return (
    <div className="mx-auto max-w-7xl space-y-6 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero
        eyebrow="Identity & access"
        title="Users"
        description="Manage platform operators, client accounts and the access attached to every identity."
        metrics={[
          { label: 'Registered users', value: totalUsers, detail: 'across the platform', icon: Users },
          { label: 'Active in view', value: users.filter(user => user.is_active && !user.banned_at).length, detail: 'current result set', icon: ShieldCheck },
          { label: 'Verified in view', value: users.filter(user => user.email_verified_at).length, detail: 'confirmed email addresses', icon: Mail },
          { label: 'Operators in view', value: users.filter(user => user.is_platform_operator).length, detail: 'platform-level access', icon: KeyRound },
        ]}
        loading={isLoading}
        action={(
          <button
            onClick={() => { setInviteMode('single'); setBulkResult(null); setInviteOpen(true); }}
            className="inline-flex items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-4 py-3 text-sm font-semibold text-[#18211d] transition duration-200 hover:-translate-y-0.5 hover:bg-[#b5edc7] active:translate-y-0"
          >
            <UserPlus size={16} />
            Invite user
          </button>
        )}
      />

      {/* Filters row */}
      <div className="flex flex-col gap-3 sm:flex-row">
        <div className="flex gap-1 p-1 rounded-full flex-shrink-0" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          {STATUS_FILTERS.map(f => (
            <button
              key={f.key}
              onClick={() => { setStatusFilter(f.key); setPage(1); }}
              className="px-3 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
              style={{
                backgroundColor: statusFilter === f.key ? 'var(--bg-elevated)' : 'transparent',
                color: statusFilter === f.key ? 'var(--text-primary)' : 'var(--text-muted)',
                border: statusFilter === f.key ? '1px solid var(--border)' : '1px solid transparent',
              }}
            >
              {f.label}
            </button>
          ))}
        </div>
        <div className="relative flex-1">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search by name or email…"
            className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
        </div>
      </div>

      {/* Table */}
      <div className="overflow-x-auto rounded-2xl" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full min-w-[820px]">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['User', 'Role', 'Status', 'Organisation', 'Joined', 'Last Active', ''].map((h, i) => (
                <th key={i} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                  {[...Array(7)].map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: j === 0 ? '60%' : '40%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : users.length === 0 ? (
              <tr style={{ backgroundColor: 'var(--bg-surface)' }}>
                <td colSpan={6} className="text-center py-16">
                  <Users size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                    {search || statusFilter !== 'all' ? 'No users match your filter.' : 'No users yet.'}
                  </p>
                </td>
              </tr>
            ) : users.map((u: AdminUser, idx: number) => {
              const role = u.roles?.[0] ?? 'Viewer';
              const badge = roleBadge[role] ?? { bg: 'rgba(90,86,82,0.15)', text: '#9a9490' };
              const initials = u.name?.split(' ').map((p: string) => p[0]).slice(0, 2).join('').toUpperCase() || '?';
              return (
                <tr
                  key={u.id}
                  className="group transition-colors hover:bg-[var(--bg-hover)]"
                  style={{
                    borderBottom: idx < users.length - 1 ? '1px solid var(--border)' : undefined,
                    opacity: u.is_active && !u.banned_at ? 1 : 0.6,
                  }}
                >
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-xl text-xs font-bold transition-transform duration-200 group-hover:-translate-y-0.5"
                           style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                        {initials}
                      </div>
                      <div>
                        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{u.name}</p>
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{u.email}</p>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-xs px-2 py-0.5 rounded-full flex items-center gap-1 w-fit"
                          style={{ backgroundColor: badge.bg, color: badge.text }}>
                      <Shield size={10} />
                      {role}
                    </span>
                  </td>
                  <td className="px-4 py-3"><StatusBadges u={u} /></td>
                  <td className="px-4 py-3"><OrganizationCell u={u} /></td>
                  <td className="px-4 py-3">
                    <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                      {u.created_at ? formatDate(u.created_at) : '—'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                      {u.last_login_at ? formatDate(u.last_login_at) : 'Never'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button
                      onClick={() => setManageUser(u)}
                      title="Manage user"
                      className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                    >
                      <Settings2 size={14} style={{ color: 'var(--text-muted)' }} />
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <PaginationBar
        page={currentPage}
        lastPage={lastPage}
        total={totalUsers}
        perPage={perPage}
        onPage={setPage}
        onPerPage={n => { setPerPage(n); setPage(1); }}
      />

      {/* Manage user modal — consolidates rename, role, status pills and actions */}
      {manageUser && (
        <ManageUserModal
          user={users.find(x => x.id === manageUser.id) ?? manageUser}
          onClose={() => setManageUser(null)}
          saving={updateMutation.isPending}
          actionLoading={actionMutation.isPending || removeMutation.isPending}
          onSave={payload => updateMutation.mutate({ id: manageUser.id, payload })}
          onToggleActive={active => updateMutation.mutate({ id: manageUser.id, payload: { is_active: active } })}
          onToggleVerify={verified => actionMutation.mutate({ id: manageUser.id, action: verified ? 'verify-email' : 'unverify-email' })}
          onBan={reason => actionMutation.mutate({ id: manageUser.id, action: 'ban', payload: { reason } })}
          onUnban={() => actionMutation.mutate({ id: manageUser.id, action: 'unban' })}
          onForcePasswordReset={() => actionMutation.mutate({ id: manageUser.id, action: 'force-password-reset' })}
          onSetPassword={() => setPasswordUser(manageUser)}
          onRevokeTokens={() => actionMutation.mutate({ id: manageUser.id, action: 'revoke-tokens' })}
          onResetTours={() => actionMutation.mutate({ id: manageUser.id, action: 'reset-tours' })}
          onRemove={() => removeMutation.mutate(manageUser.id)}
        />
      )}

      {/* Set temporary password modal — nested above ManageUserModal */}
      {passwordUser && (
        <SetPasswordModal
          user={passwordUser}
          saving={setPasswordMutation.isPending}
          result={passwordResult}
          onClose={() => { setPasswordUser(null); setPasswordResult(null); }}
          onSave={(password, requireChange) => setPasswordMutation.mutate({ id: passwordUser.id, password, requireChange })}
        />
      )}

      {/* Invite modal */}
      {inviteOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setInviteOpen(false)}>
          <div className="w-full max-w-md rounded-2xl p-6 ss-animate-in" style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }} onClick={e => e.stopPropagation()}>
            <h2 className="text-lg font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>Invite User</h2>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
              {inviteMode === 'single' ? 'Create a new user account and share their credentials' : 'Invite a list of users at once, all with the same role'}
            </p>

            {/* Single / Bulk mode toggle */}
            <div className="flex gap-1 p-1 rounded-lg mb-4" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              {(['single', 'bulk'] as const).map(mode => (
                <button
                  key={mode}
                  onClick={() => { setInviteMode(mode); setBulkResult(null); }}
                  className="flex-1 py-1.5 rounded-md text-xs font-medium transition-colors"
                  style={inviteMode === mode
                    ? { backgroundColor: 'var(--bg-panel)', color: 'var(--text-primary)', boxShadow: 'var(--shadow-card)' }
                    : { color: 'var(--text-muted)' }}
                >
                  {mode === 'single' ? 'Single' : 'Bulk'}
                </button>
              ))}
            </div>

            <div className="space-y-4">
              {inviteMode === 'single' ? (
                <div>
                  <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Email</label>
                  <div className="relative">
                    <Mail size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
                    <input
                      type="email" value={inviteEmail} onChange={e => setInviteEmail(e.target.value)}
                      placeholder="colleague@company.com"
                      className="w-full pl-9 pr-4 py-2.5 rounded-lg text-sm outline-none"
                      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                    />
                  </div>
                </div>
              ) : (
                <div>
                  <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                    Emails <span style={{ color: 'var(--text-muted)' }}>(one per line, or comma-separated — up to 100)</span>
                  </label>
                  <textarea
                    value={bulkEmailsText}
                    onChange={e => setBulkEmailsText(e.target.value)}
                    placeholder={'jane@company.com\njohn@company.com'}
                    rows={5}
                    className="w-full px-3 py-2.5 rounded-lg text-sm outline-none resize-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  />
                  {bulkEmailsText.trim() && (
                    <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                      {parseBulkEmails(bulkEmailsText).length} email(s) detected
                    </p>
                  )}
                </div>
              )}
              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Role</label>
                <Select value={inviteRole} onChange={e => setInviteRole(e.target.value as typeof INVITE_ROLES[number])} className="w-full">
                  {INVITE_ROLES.map((r: string) => <option key={r} value={r}>{r}</option>)}
                </Select>
                {inviteMode === 'bulk' && (
                  <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Applies to every email in this batch.</p>
                )}
              </div>
              <label className="flex items-start gap-2 cursor-pointer">
                <input
                  type="checkbox" checked={inviteBetaNotice} onChange={e => setInviteBetaNotice(e.target.checked)}
                  className="mt-0.5"
                />
                <span className="text-xs" style={{ color: 'var(--text-secondary)' }}>
                  Include beta notice in invitation email
                </span>
              </label>

              {/* Partial-success results — a bad email in the batch never
                  blocks the rest (see UserController::bulkInvite()); shown
                  here so the admin can see and fix exactly what failed,
                  rather than only a generic toast. */}
              {bulkResult && bulkResult.failed.length > 0 && (
                <div className="rounded-lg p-3 text-xs" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                  <p className="font-medium mb-1.5" style={{ color: 'var(--text-primary)' }}>
                    {bulkResult.invited.length} sent, {bulkResult.failed.length} failed:
                  </p>
                  <ul className="space-y-0.5">
                    {bulkResult.failed.map(f => (
                      <li key={f.email} style={{ color: 'var(--text-muted)' }}>
                        <span style={{ color: 'var(--text-secondary)' }}>{f.email}</span> — {f.reason}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
            <div className="flex gap-3 mt-6">
              <button onClick={() => { setInviteOpen(false); setBulkResult(null); }} className="flex-1 py-2.5 rounded-lg text-sm font-medium"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                {bulkResult ? 'Close' : 'Cancel'}
              </button>
              {inviteMode === 'single' ? (
                <button onClick={() => inviteMutation.mutate({ email: inviteEmail, role: inviteRole, include_beta_notice: inviteBetaNotice })}
                        disabled={!inviteEmail || inviteMutation.isPending}
                        className="flex-1 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-60 active:scale-[0.98]"
                        style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                  {inviteMutation.isPending ? 'Creating…' : 'Create User'}
                </button>
              ) : (
                <button onClick={() => bulkInviteMutation.mutate({ emails: parseBulkEmails(bulkEmailsText), role: inviteRole, include_beta_notice: inviteBetaNotice })}
                        disabled={parseBulkEmails(bulkEmailsText).length === 0 || bulkInviteMutation.isPending}
                        className="flex-1 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-60 active:scale-[0.98]"
                        style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                  {bulkInviteMutation.isPending ? 'Sending…' : `Invite ${parseBulkEmails(bulkEmailsText).length || ''}`.trim()}
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
