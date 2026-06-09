'use client';

import { useState, useRef, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Users, UserPlus, Shield, Mail, Search, Copy, Check,
  MoreVertical, UserX, UserCheck, Trash2, Pencil, X,
} from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import toast from 'react-hot-toast';
import PaginationBar from '@/components/ui/PaginationBar';

const ALL_ROLES = ['Super Admin', 'Admin', 'Manager', 'Client', 'Viewer'] as const;

const INVITE_ROLES = ['Admin', 'Manager', 'Client', 'Viewer'] as const;
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
  'Manager':     { bg: 'rgba(234,179,8,0.12)',   text: '#facc15' },
  'Client':      { bg: 'rgba(59,130,246,0.12)',  text: '#60a5fa' },
  'Viewer':      { bg: 'rgba(90,86,82,0.15)',    text: '#9a9490' },
};

interface AdminUser {
  id: number;
  name: string;
  email: string;
  roles: string[];
  is_active: boolean;
  last_login_at: string | null;
  created_at: string;
}

// ── Row action dropdown ───────────────────────────────────────────────────────
function ActionMenu({
  user,
  onEditRole,
  onToggleActive,
  onRemove,
}: {
  user: AdminUser;
  onEditRole: (u: AdminUser) => void;
  onToggleActive: (u: AdminUser) => void;
  onRemove: (u: AdminUser) => void;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handle(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    if (open) document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(o => !o)}
        className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-elevated)]"
      >
        <MoreVertical size={14} style={{ color: 'var(--text-muted)' }} />
      </button>

      {open && (
        <div
          className="absolute right-0 mt-1 w-44 rounded-xl overflow-hidden z-20"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 8px 24px rgba(0,0,0,0.12)' }}
        >
          <button
            onClick={() => { setOpen(false); onEditRole(user); }}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm text-left transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-secondary)' }}
          >
            <Pencil size={13} />
            Edit Role
          </button>
          <button
            onClick={() => { setOpen(false); onToggleActive(user); }}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm text-left transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: user.is_active ? '#f97316' : '#22c55e' }}
          >
            {user.is_active ? <UserX size={13} /> : <UserCheck size={13} />}
            {user.is_active ? 'Disable User' : 'Activate User'}
          </button>
          <div style={{ borderTop: '1px solid var(--border)' }} />
          <button
            onClick={() => { setOpen(false); onRemove(user); }}
            className="w-full flex items-center gap-2.5 px-3 py-2.5 text-sm text-left transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: '#ef4444' }}
          >
            <Trash2 size={13} />
            Remove User
          </button>
        </div>
      )}
    </div>
  );
}

// ── Edit role modal ───────────────────────────────────────────────────────────
function EditRoleModal({
  user,
  onClose,
  onSave,
  saving,
}: {
  user: AdminUser;
  onClose: () => void;
  onSave: (role: string) => void;
  saving: boolean;
}) {
  const current = user.roles[0] ?? 'Viewer';
  const [role, setRole] = useState(current);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-sm rounded-2xl p-6"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Edit Role</h2>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
          {user.name} · {user.email}
        </p>
        <div className="space-y-2 mb-5">
          {ALL_ROLES.map(r => (
            <button
              key={r}
              onClick={() => setRole(r)}
              className="w-full flex items-center justify-between px-3 py-2.5 rounded-xl text-sm transition-all"
              style={{
                backgroundColor: role === r ? 'rgba(185,149,102,0.1)' : 'var(--bg-elevated)',
                border: `1px solid ${role === r ? 'rgba(185,149,102,0.4)' : 'var(--border)'}`,
                color: role === r ? 'var(--text-primary)' : 'var(--text-secondary)',
              }}
            >
              <span className="flex items-center gap-2">
                <Shield size={12} style={{ color: role === r ? 'var(--gold)' : 'var(--text-muted)' }} />
                {r}
              </span>
              {role === r && <Check size={13} style={{ color: 'var(--gold)' }} />}
            </button>
          ))}
        </div>
        <div className="flex gap-3">
          <button
            onClick={onClose}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
          >
            Cancel
          </button>
          <button
            onClick={() => onSave(role)}
            disabled={saving || role === current}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-60 transition-opacity hover:opacity-90"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {saving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Page ──────────────────────────────────────────────────────────────────────
export default function AdminUsersPage() {
  const [search, setSearch]             = useState('');
  const [debouncedSearch, setDebounced] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [page, setPage]                 = useState(1);
  const [perPage, setPerPage]           = useState<number>(25);
  const [inviteOpen, setInviteOpen]     = useState(false);
  const [inviteEmail, setInviteEmail]   = useState('');
  const [inviteRole, setInviteRole]     = useState<InviteRole>('Client');
  const [credentials, setCredentials]   = useState<{ email: string; password: string } | null>(null);
  const [copied, setCopied]             = useState(false);
  const [editUser, setEditUser]         = useState<AdminUser | null>(null);
  const [confirmRemove, setConfirmRemove] = useState<AdminUser | null>(null);
  const qc = useQueryClient();

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
  });

  const inviteMutation = useMutation({
    mutationFn: (payload: { email: string; role: InviteRole }) => api.post('/users/invite', payload).then(r => r.data),
    onSuccess: (res: any) => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      setInviteOpen(false);
      setInviteEmail('');
      setInviteRole('Client');
      setCredentials({ email: res.data.email, password: res.data.temp_password });
    },
    onError: (e: any) => {
      toast.error(e?.response?.data?.message ?? e?.response?.data?.errors?.email?.[0] ?? 'Failed to create user.');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      api.put(`/users/${id}`, payload).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      setEditUser(null);
      toast.success('User updated.');
    },
    onError: (e: any) => {
      toast.error(e?.response?.data?.message ?? 'Failed to update user.');
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/users/${id}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-users'] });
      setConfirmRemove(null);
      toast.success('User removed.');
    },
    onError: (e: any) => {
      toast.error(e?.response?.data?.message ?? 'Failed to remove user.');
    },
  });

  const users: AdminUser[]   = data?.data          ?? [];
  const totalUsers: number   = data?.total          ?? 0;
  const lastPage: number     = data?.last_page       ?? 1;
  const currentPage: number  = data?.current_page    ?? 1;

  return (
    <div className="p-6 max-w-5xl mx-auto">
      {/* Header */}
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Users</h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Manage team members and access
            {!isLoading && totalUsers > 0 && <span className="ml-1">· {totalUsers} total</span>}
          </p>
        </div>
        <button
          onClick={() => setInviteOpen(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <UserPlus size={15} />
          Invite User
        </button>
      </div>

      {/* Filters row */}
      <div className="flex gap-3 mb-5">
        <div className="flex gap-1 p-1 rounded-lg flex-shrink-0" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          {STATUS_FILTERS.map(f => (
            <button
              key={f.key}
              onClick={() => { setStatusFilter(f.key); setPage(1); }}
              className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
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
      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['User', 'Role', 'Status', 'Joined', 'Last Active', ''].map((h, i) => (
                <th key={i} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                  {[...Array(6)].map((_, j) => (
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
                  style={{
                    borderBottom: idx < users.length - 1 ? '1px solid var(--border)' : undefined,
                    backgroundColor: 'var(--bg-surface)',
                    opacity: u.is_active ? 1 : 0.6,
                  }}
                >
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0"
                           style={{ backgroundColor: 'rgba(185,149,102,0.15)', color: 'var(--gold)' }}>
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
                  <td className="px-4 py-3">
                    <span className="text-xs px-2 py-0.5 rounded-full font-medium"
                          style={{
                            backgroundColor: u.is_active ? 'rgba(34,197,94,0.12)' : 'rgba(90,86,82,0.15)',
                            color: u.is_active ? '#4ade80' : 'var(--text-muted)',
                          }}>
                      {u.is_active ? 'Active' : 'Disabled'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      {u.created_at ? formatDate(u.created_at) : '—'}
                    </span>
                  </td>
                  <td className="px-4 py-3">
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      {u.last_login_at ? formatDate(u.last_login_at) : 'Never'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <ActionMenu
                      user={u}
                      onEditRole={setEditUser}
                      onToggleActive={u => updateMutation.mutate({ id: u.id, payload: { is_active: !u.is_active } })}
                      onRemove={setConfirmRemove}
                    />
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

      {/* Edit role modal */}
      {editUser && (
        <EditRoleModal
          user={editUser}
          onClose={() => setEditUser(null)}
          saving={updateMutation.isPending}
          onSave={role => updateMutation.mutate({ id: editUser.id, payload: { role } })}
        />
      )}

      {/* Confirm remove modal */}
      {confirmRemove && (
        <div className="fixed inset-0 z-50 flex items-center justify-center" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setConfirmRemove(null)}>
          <div className="w-full max-w-sm rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }} onClick={e => e.stopPropagation()}>
            <h2 className="text-base font-semibold mb-2" style={{ color: 'var(--text-primary)' }}>Remove User</h2>
            <p className="text-sm mb-5" style={{ color: 'var(--text-muted)' }}>
              Remove <strong style={{ color: 'var(--text-primary)' }}>{confirmRemove.name}</strong> ({confirmRemove.email})?
              This cannot be undone.
            </p>
            <div className="flex gap-3">
              <button onClick={() => setConfirmRemove(null)} className="flex-1 py-2.5 rounded-xl text-sm font-medium"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button onClick={() => removeMutation.mutate(confirmRemove.id)} disabled={removeMutation.isPending}
                      className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-60"
                      style={{ backgroundColor: '#ef4444', color: '#fff' }}>
                {removeMutation.isPending ? 'Removing…' : 'Remove'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Credentials modal */}
      {credentials && (
        <div className="fixed inset-0 z-50 flex items-center justify-center" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}>
          <div className="w-full max-w-md rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }}>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-full flex items-center justify-center" style={{ backgroundColor: 'rgba(34,197,94,0.15)' }}>
                <Check size={20} style={{ color: '#4ade80' }} />
              </div>
              <div>
                <h2 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>User Created</h2>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Share these credentials with the new user</p>
              </div>
            </div>
            <div className="space-y-3 mb-5">
              <div className="rounded-lg p-3" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                <p className="text-xs font-medium mb-1" style={{ color: 'var(--text-muted)' }}>Email</p>
                <p className="text-sm font-mono" style={{ color: 'var(--text-primary)' }}>{credentials.email}</p>
              </div>
              <div className="rounded-lg p-3" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                <div className="flex items-center justify-between mb-1">
                  <p className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Temporary Password</p>
                  <button
                    onClick={() => { navigator.clipboard.writeText(credentials.password); setCopied(true); setTimeout(() => setCopied(false), 2000); }}
                    className="flex items-center gap-1 text-xs px-2 py-0.5 rounded"
                    style={{ color: copied ? '#4ade80' : 'var(--gold)', backgroundColor: copied ? 'rgba(34,197,94,0.1)' : 'rgba(185,149,102,0.1)' }}
                  >
                    {copied ? <Check size={11} /> : <Copy size={11} />}
                    {copied ? 'Copied!' : 'Copy'}
                  </button>
                </div>
                <p className="text-base font-mono font-semibold tracking-widest" style={{ color: 'var(--text-primary)' }}>{credentials.password}</p>
              </div>
            </div>
            <p className="text-xs mb-5 p-3 rounded-lg" style={{ color: 'var(--text-muted)', backgroundColor: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.2)' }}>
              The user should log in with these credentials and change their password immediately.
            </p>
            <button onClick={() => { setCredentials(null); setCopied(false); }}
                    className="w-full py-2.5 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
                    style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              Done
            </button>
          </div>
        </div>
      )}

      {/* Invite modal */}
      {inviteOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={() => setInviteOpen(false)}>
          <div className="w-full max-w-md rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }} onClick={e => e.stopPropagation()}>
            <h2 className="text-lg font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>Invite User</h2>
            <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Create a new user account and share their credentials</p>
            <div className="space-y-4">
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
              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Role</label>
                <select value={inviteRole} onChange={e => setInviteRole(e.target.value as typeof INVITE_ROLES[number])}
                        className="w-full px-3 py-2.5 rounded-lg text-sm outline-none appearance-none"
                        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
                  {INVITE_ROLES.map((r: string) => <option key={r} value={r}>{r}</option>)}
                </select>
              </div>
            </div>
            <div className="flex gap-3 mt-6">
              <button onClick={() => setInviteOpen(false)} className="flex-1 py-2.5 rounded-lg text-sm font-medium"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button onClick={() => inviteMutation.mutate({ email: inviteEmail, role: inviteRole })}
                      disabled={!inviteEmail || inviteMutation.isPending}
                      className="flex-1 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-60"
                      style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                {inviteMutation.isPending ? 'Creating…' : 'Create User'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
