'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { Users, UserPlus, Shield, Mail, MoreVertical, Search } from 'lucide-react';
import { formatDate } from '@/lib/utils';

const ROLES = ['Company Admin', 'Project Manager', 'Quantity Surveyor', 'Site Manager', 'Commercial Manager', 'Read-only User'];

const roleBadge: Record<string, { bg: string; text: string }> = {
  'Company Admin':       { bg: 'rgba(249,115,22,0.15)', text: '#fb923c' },
  'Project Manager':     { bg: 'rgba(185,149,102,0.15)', text: '#B99566' },
  'Quantity Surveyor':   { bg: 'rgba(59,130,246,0.15)', text: '#60a5fa' },
  'Site Manager':        { bg: 'rgba(34,197,94,0.15)',  text: '#4ade80' },
  'Commercial Manager':  { bg: 'rgba(139,92,246,0.15)', text: '#a78bfa' },
  'Read-only User':      { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

export default function AppTeamPage() {
  const [search, setSearch] = useState('');
  const [inviteOpen, setInviteOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState('Read-only User');
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['team-users'],
    queryFn: () => api.get('/users').then(r => r.data),
  });

  const inviteMutation = useMutation({
    mutationFn: (payload: { email: string; role: string }) => api.post('/users/invite', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['team-users'] });
      setInviteOpen(false);
      setInviteEmail('');
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
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Team</h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Manage your company team members and their roles
          </p>
        </div>
        <button
          onClick={() => setInviteOpen(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <UserPlus size={15} />
          Invite Member
        </button>
      </div>

      {/* Search */}
      <div className="relative mb-5 max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search team…"
          className="w-full pl-9 pr-4 py-2.5 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Member', 'Role', 'Joined', ''].map(h => (
                <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(5)].map((_, i) => (
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
                <td colSpan={4} className="text-center py-12">
                  <Users size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No team members found</p>
                </td>
              </tr>
            ) : users.map((u: any, idx: number) => {
              const role = u.roles?.[0]?.name || u.roles?.[0] || 'Read-only User';
              const badge = roleBadge[role] || roleBadge['Read-only User'];
              const initials = u.name?.split(' ').map((p: string) => p[0]).slice(0, 2).join('').toUpperCase() || '?';
              return (
                <tr
                  key={u.id}
                  style={{ borderBottom: idx < users.length - 1 ? '1px solid var(--border)' : undefined, backgroundColor: 'var(--bg-surface)' }}
                >
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-3">
                      <div
                        className="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
                        style={{ backgroundColor: 'rgba(185,149,102,0.15)', color: 'var(--text-gold)' }}
                      >
                        {initials}
                      </div>
                      <div>
                        <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{u.name}</p>
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

      {/* Invite Modal */}
      {inviteOpen && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center"
          style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}
          onClick={() => setInviteOpen(false)}
        >
          <div
            className="w-full max-w-md rounded-2xl p-6"
            style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)' }}
            onClick={e => e.stopPropagation()}
          >
            <h2 className="text-lg font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>Invite Team Member</h2>
            <p className="text-xs mb-5" style={{ color: 'var(--text-muted)' }}>
              Send an invitation to add a new member to your company
            </p>
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
                  className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                >
                  {ROLES.map(r => <option key={r}>{r}</option>)}
                </select>
              </div>
            </div>
            <div className="flex gap-3 mt-6">
              <button
                onClick={() => setInviteOpen(false)}
                className="flex-1 py-2.5 rounded-lg text-sm font-medium"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
              >
                Cancel
              </button>
              <button
                onClick={() => inviteMutation.mutate({ email: inviteEmail, role: inviteRole })}
                disabled={!inviteEmail || inviteMutation.isPending}
                className="flex-1 py-2.5 rounded-lg text-sm font-medium disabled:opacity-60"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                {inviteMutation.isPending ? 'Sending…' : 'Send Invite'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
