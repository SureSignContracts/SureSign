'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Users, UserPlus, Shield, Mail, MoreVertical, Search, Copy, Check } from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import toast from 'react-hot-toast';

const INVITE_ROLES = ['Admin', 'Client'] as const;
type InviteRole = typeof INVITE_ROLES[number];

const roleBadge: Record<string, { bg: string; text: string }> = {
  'Super Admin': { bg: 'rgba(239,68,68,0.15)',  text: '#f87171' },
  'Admin':       { bg: 'rgba(249,115,22,0.15)', text: '#fb923c' },
  'Client':      { bg: 'rgba(59,130,246,0.15)',  text: '#60a5fa' },
};

export default function AdminUsersPage() {
  const [search, setSearch] = useState('');
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState<InviteRole>('Client');
  const [credentials, setCredentials] = useState<{ email: string; password: string } | null>(null);
  const [copied, setCopied] = useState(false);
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['admin-users'],
    queryFn: () => api.get('/users').then(r => r.data),
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
      const msg = e?.response?.data?.message ?? e?.response?.data?.errors?.email?.[0] ?? 'Failed to send invite.';
      toast.error(msg);
    },
  });

  const users = (data?.data ?? []).filter((u: any) =>
    u.name?.toLowerCase().includes(search.toLowerCase()) ||
    u.email?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-5xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Users</h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>Manage team members and roles</p>
        </div>
        <button
          onClick={() => setInviteOpen(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <UserPlus size={16} />
          Invite User
        </button>
      </div>

      {/* Search */}
      <div className="relative mb-5">
        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search users..."
          className="w-full pl-9 pr-4 py-2.5 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['User', 'Role', 'Joined', ''].map((h, i) => (
                <th key={i} className="text-left px-4 py-3 text-xs font-medium"
                    style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(4)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                  {[...Array(4)].map((_, j) => (
                    <td key={j} className="px-4 py-3">
                      <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: j === 0 ? '60%' : '40%' }} />
                    </td>
                  ))}
                </tr>
              ))
            ) : users.length === 0 ? (
              <tr style={{ backgroundColor: 'var(--bg-surface)' }}>
                <td colSpan={4} className="text-center py-16">
                  <Users size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No users found</p>
                </td>
              </tr>
            ) : users.map((u: any, idx: number) => {
              const role = u.roles?.[0]?.name || u.role || 'Viewer';
              const badge = roleBadge[role] ?? { bg: 'rgba(90,86,82,0.2)', text: '#9a9490' };
              const initials = u.name?.split(' ').map((p: string) => p[0]).slice(0, 2).join('').toUpperCase() || '?';
              return (
                <tr key={u.id} style={{
                  borderBottom: idx < users.length - 1 ? '1px solid var(--border)' : undefined,
                  backgroundColor: 'var(--bg-surface)',
                }}>
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
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                      {u.created_at ? formatDate(u.created_at) : '—'}
                    </span>
                  </td>
                  <td className="px-4 py-3 text-right">
                    <button className="p-1 rounded-lg hover:bg-[var(--bg-elevated)] transition-colors">
                      <MoreVertical size={14} style={{ color: 'var(--text-muted)' }} />
                    </button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* Credentials Modal — shown after user is created */}
      {credentials && (
        <div className="fixed inset-0 z-50 flex items-center justify-center"
             style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}>
          <div className="w-full max-w-md rounded-2xl p-6"
               style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }}>
            <div className="flex items-center gap-3 mb-4">
              <div className="w-10 h-10 rounded-full flex items-center justify-center"
                   style={{ backgroundColor: 'rgba(34,197,94,0.15)' }}>
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
                    onClick={() => {
                      navigator.clipboard.writeText(credentials.password);
                      setCopied(true);
                      setTimeout(() => setCopied(false), 2000);
                    }}
                    className="flex items-center gap-1 text-xs px-2 py-0.5 rounded transition-colors"
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
              The user should log in with the above credentials and change their password immediately.
            </p>

            <button
              onClick={() => { setCredentials(null); setCopied(false); }}
              className="w-full py-2.5 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              Done
            </button>
          </div>
        </div>
      )}

      {/* Invite Modal */}
      {inviteOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center"
             style={{ backgroundColor: 'rgba(0,0,0,0.7)' }}
             onClick={() => setInviteOpen(false)}>
          <div className="w-full max-w-md rounded-2xl p-6"
               style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }}
               onClick={e => e.stopPropagation()}>
            <h2 className="text-lg font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>Invite User</h2>
            <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>Send an invite email to add a new team member</p>

            <div className="space-y-4">
              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Email</label>
                <div className="relative">
                  <Mail size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
                  <input
                    type="email"
                    value={inviteEmail}
                    onChange={e => setInviteEmail(e.target.value)}
                    placeholder="colleague@company.com"
                    className="w-full pl-9 pr-4 py-2.5 rounded-lg text-sm outline-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  />
                </div>
              </div>
              <div>
                <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Role</label>
                <select
                  value={inviteRole}
                  onChange={e => setInviteRole(e.target.value)}
                  className="w-full px-3 py-2.5 rounded-lg text-sm outline-none appearance-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                >
                  {INVITE_ROLES.map(r => <option key={r} value={r}>{r}</option>)}
                </select>
              </div>
            </div>

            <div className="flex gap-3 mt-6">
              <button onClick={() => setInviteOpen(false)}
                className="flex-1 py-2.5 rounded-lg text-sm font-medium transition-colors"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button
                onClick={() => inviteMutation.mutate({ email: inviteEmail, role: inviteRole })}
                disabled={!inviteEmail || inviteMutation.isPending}
                className="flex-1 py-2.5 rounded-lg text-sm font-medium transition-opacity disabled:opacity-60"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                {inviteMutation.isPending ? 'Sending...' : 'Send Invite'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
