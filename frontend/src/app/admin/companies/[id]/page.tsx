'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import toast from 'react-hot-toast';
import {
  ArrowLeft, Building2, Users, FolderKanban, Search, Plus, X,
  Calendar, DollarSign, ChevronRight, MapPin, Phone, Mail, Hash, User,
} from 'lucide-react';

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  active:    { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  on_hold:   { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  completed: { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  cancelled: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};

const EMPTY_FORM = {
  name: '', code: '', description: '', status: 'active',
  contract_value: '', start_date: '', end_date: '',
  address: '', city: '', state: '', postcode: '', country: '',
};

export default function AdminCompanyDetailPage() {
  const formatCurrency = useCurrencyFormatter();
  const queryClient = useQueryClient();
  const { id } = useParams<{ id: string }>();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('all');
  const [showModal, setShowModal] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  const createMutation = useMutation({
    mutationFn: (data: typeof EMPTY_FORM) =>
      api.post(`/admin/companies/${id}/projects`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-company-projects', id] });
      toast.success(`Project created for ${org?.name ?? 'company'}.`);
      setShowModal(false);
      setForm(EMPTY_FORM);
      setFormErrors({});
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors ?? {};
      setFormErrors(errors);
      if (!Object.keys(errors).length) {
        toast.error(err?.response?.data?.message ?? 'Failed to create project.');
      }
    },
  });

  const { data: org, isLoading: orgLoading } = useQuery({
    queryKey: ['admin-company', id],
    queryFn: () => api.get(`/organizations/${id}`).then(r => r.data?.data ?? r.data),
    enabled: !!id,
  });

  const { data: projectsData, isLoading: projectsLoading } = useQuery({
    queryKey: ['admin-company-projects', id],
    queryFn: () => api.get('/projects', { params: { organization_id: id } }).then(r => r.data),
    enabled: !!id,
  });

  const allProjects = projectsData?.data ?? [];
  const projects = allProjects.filter((p: any) => {
    const matchSearch = p.name?.toLowerCase().includes(search.toLowerCase()) ||
      (p.code ?? '').toLowerCase().includes(search.toLowerCase());
    const matchStatus = statusFilter === 'all' || p.status === statusFilter;
    return matchSearch && matchStatus;
  });

  if (orgLoading) {
    return (
      <div className="p-8 max-w-6xl mx-auto space-y-4">
        <div className="h-32 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      </div>
    );
  }

  if (!org) {
    return (
      <div className="p-8 text-center py-24">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Company not found.</p>
      </div>
    );
  }

  const activeCount    = allProjects.filter((p: any) => p.status === 'active').length;
  const completedCount = allProjects.filter((p: any) => p.status === 'completed').length;
  const totalValue     = allProjects.reduce((s: number, p: any) => s + parseFloat(p.contract_value ?? 0), 0);

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Back */}
      <Link
        href="/admin/companies"
        className="inline-flex items-center gap-1.5 text-xs transition-colors hover:text-[var(--text-primary)]"
        style={{ color: 'var(--text-muted)' }}
      >
        <ArrowLeft size={13} /> All Companies
      </Link>

      {/* Company header */}
      <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-start gap-5">
          <div
            className="w-14 h-14 rounded-2xl flex items-center justify-center text-xl font-bold flex-shrink-0 overflow-hidden"
            style={org.logo_url ? { border: '1px solid var(--border)' } : { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
          >
            {org.logo_url
              ? <img src={org.logo_url} alt={org.name} className="w-full h-full object-contain p-1" />
              : org.name?.charAt(0)?.toUpperCase()
            }
          </div>
          <div className="flex-1 min-w-0">
            <h1 className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>{org.name}</h1>
            {org.slug && (
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{org.slug}</p>
            )}
            <span
              className="inline-flex mt-2 px-2.5 py-0.5 rounded-full text-xs font-medium"
              style={{ backgroundColor: 'rgba(34,197,94,0.1)', color: '#4ade80' }}
            >
              {org.is_active !== false ? 'Active' : 'Inactive'}
            </span>
          </div>
        </div>

        {/* Company info grid */}
        <div className="mt-5 grid grid-cols-2 sm:grid-cols-3 gap-x-8 gap-y-3">
          {org.contact_name && (
            <div className="flex items-center gap-2">
              <User size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Contact Name</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{org.contact_name}</p>
              </div>
            </div>
          )}
          {org.email && (
            <div className="flex items-center gap-2">
              <Mail size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Email</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{org.email}</p>
              </div>
            </div>
          )}
          {org.phone && (
            <div className="flex items-center gap-2">
              <Phone size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Contact Number</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{org.phone}</p>
              </div>
            </div>
          )}
          {org.address && (
            <div className="flex items-center gap-2">
              <MapPin size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Address</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                  {[org.address, org.city, org.state, org.postcode, org.country].filter(Boolean).join(', ')}
                </p>
              </div>
            </div>
          )}
          {org.acn && (
            <div className="flex items-center gap-2">
              <Hash size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Company Number (ACN)</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{org.acn}</p>
              </div>
            </div>
          )}
          {org.abn && (
            <div className="flex items-center gap-2">
              <Hash size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>VAT / ABN</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{org.abn}</p>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* Stats row */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '0ms' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total Projects</p>
          <p className="text-2xl font-bold mt-1 tabular-nums" style={{ color: 'var(--gold)' }}>{allProjects.length}</p>
        </div>
        <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '50ms' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Active</p>
          <p className="text-2xl font-bold mt-1 tabular-nums" style={{ color: '#4ade80' }}>{activeCount}</p>
        </div>
        <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '100ms' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Completed</p>
          <p className="text-2xl font-bold mt-1 tabular-nums" style={{ color: '#60a5fa' }}>{completedCount}</p>
        </div>
        <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '150ms' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total Contract Value</p>
          <p className="text-lg font-bold mt-1 tabular-nums" style={{ color: 'var(--text-primary)' }}>{formatCurrency(totalValue)}</p>
        </div>
      </div>

      {/* Projects section */}
      <div className="space-y-4">
        <div className="flex items-center justify-between flex-wrap gap-3">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Projects</h2>
          <div className="flex items-center gap-3 flex-wrap">
            <button
              onClick={() => { setForm(EMPTY_FORM); setFormErrors({}); setShowModal(true); }}
              className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-opacity hover:opacity-80 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Plus size={13} /> New Project
            </button>
            {/* Status filter */}
            <div className="flex gap-1 p-1 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              {['all', 'active', 'on_hold', 'completed', 'cancelled'].map(s => (
                <button
                  key={s}
                  onClick={() => setStatusFilter(s)}
                  className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
                  style={statusFilter === s
                    ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                    : { color: 'var(--text-secondary)' }
                  }
                >
                  {s === 'all' ? 'All' : s.replace(/_/g, ' ')}
                </button>
              ))}
            </div>
            {/* Search */}
            <div className="relative">
              <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
              <input
                value={search}
                onChange={e => setSearch(e.target.value)}
                placeholder="Search projects…"
                className="pl-8 pr-4 py-2 rounded-lg text-xs outline-none"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '180px' }}
              />
            </div>
          </div>
        </div>

        {projectsLoading ? (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {[...Array(4)].map((_, i) => (
              <div key={i} className="h-32 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
            ))}
          </div>
        ) : projects.length === 0 ? (
          <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <FolderKanban size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No projects found for this company</p>
          </div>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            {projects.map((p: any, i: number) => {
              const badge = STATUS_COLORS[p.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
              return (
                <Link
                  key={p.id}
                  href={`/app/projects/${p.id}/overview`}
                  className="group block rounded-2xl p-5 hover:-translate-y-0.5 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] ss-animate-in"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
                >
                  <div className="flex items-start justify-between mb-3">
                    <div
                      className="w-9 h-9 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"
                      style={{ backgroundColor: 'rgba(59,130,246,0.1)', color: '#3b82f6' }}
                    >
                      <FolderKanban size={15} />
                    </div>
                    <span
                      className="text-xs px-2 py-0.5 rounded-full font-medium capitalize"
                      style={{ backgroundColor: badge.bg, color: badge.text }}
                    >
                      {p.status?.replace(/_/g, ' ')}
                    </span>
                  </div>

                  <p className="font-semibold text-sm leading-snug" style={{ color: 'var(--text-primary)' }}>{p.name}</p>
                  {p.code && (
                    <p className="text-[11px] mt-0.5 font-mono" style={{ color: 'var(--text-muted)' }}>{p.code}</p>
                  )}

                  <div className="mt-3 space-y-1">
                    {p.contract_value && (
                      <div className="flex items-center gap-1.5 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                        <DollarSign size={11} />
                        {formatCurrency(p.contract_value)}
                      </div>
                    )}
                    {p.end_date && (
                      <div className="flex items-center gap-1.5 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                        <Calendar size={11} />
                        Due {formatDate(p.end_date)}
                      </div>
                    )}
                  </div>

                  <div className="mt-3 pt-3 flex items-center justify-between" style={{ borderTop: '1px solid var(--border)' }}>
                    <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                      {p.users_count ?? 0} members
                    </span>
                    <ChevronRight size={13} className="opacity-0 group-hover:opacity-100 transition-opacity" style={{ color: 'var(--text-muted)' }} />
                  </div>
                </Link>
              );
            })}
          </div>
        )}
      </div>

      {/* ── Create Project Modal ── */}
      {showModal && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
          <div className="w-full max-w-lg rounded-2xl shadow-2xl ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            {/* Header */}
            <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
              <div>
                <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                  Create Project for {org?.name}
                </h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  Project will be owned by this company.
                </p>
              </div>
              <button onClick={() => setShowModal(false)} className="p-1.5 rounded-lg hover:opacity-70" style={{ color: 'var(--text-muted)' }}>
                <X size={16} />
              </button>
            </div>

            {/* Body */}
            <div className="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
              {/* Name */}
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                  Project Name <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <input
                  value={form.name}
                  onChange={e => { setForm(f => ({ ...f, name: e.target.value })); setFormErrors(p => ({ ...p, name: '' })); }}
                  placeholder="e.g. Colchester Phase 2"
                  className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: `1px solid ${formErrors.name ? '#ef4444' : 'var(--border)'}`, color: 'var(--text-primary)' }}
                />
                {formErrors.name && <p className="mt-1 text-xs" style={{ color: '#ef4444' }}>{formErrors.name}</p>}
              </div>

              {/* Code + Status */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Reference / Code</label>
                  <input
                    value={form.code}
                    onChange={e => setForm(f => ({ ...f, code: e.target.value }))}
                    placeholder="e.g. SP-COL-002"
                    className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Status</label>
                  <select
                    value={form.status}
                    onChange={e => setForm(f => ({ ...f, status: e.target.value }))}
                    className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  >
                    <option value="active">Active</option>
                    <option value="on_hold">On Hold</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                  </select>
                </div>
              </div>

              {/* Contract Value */}
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Contract Value</label>
                <input
                  type="number"
                  value={form.contract_value}
                  onChange={e => setForm(f => ({ ...f, contract_value: e.target.value }))}
                  placeholder="0.00"
                  className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                />
              </div>

              {/* Dates */}
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Start Date</label>
                  <input
                    type="date"
                    value={form.start_date}
                    onChange={e => setForm(f => ({ ...f, start_date: e.target.value }))}
                    className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  />
                </div>
                <div>
                  <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>End Date</label>
                  <input
                    type="date"
                    value={form.end_date}
                    onChange={e => setForm(f => ({ ...f, end_date: e.target.value }))}
                    className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  />
                </div>
              </div>

              {/* Description */}
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Description</label>
                <textarea
                  value={form.description}
                  onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
                  placeholder="Brief project description…"
                  rows={3}
                  className="w-full px-3 py-2.5 rounded-lg text-sm outline-none resize-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                />
              </div>

              {/* Location */}
              <div style={{ borderTop: '1px solid var(--border)', paddingTop: '1rem' }}>
                <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Location (optional)</p>
                <div className="space-y-2">
                  <input
                    value={form.address}
                    onChange={e => setForm(f => ({ ...f, address: e.target.value }))}
                    placeholder="Street address"
                    className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  />
                  <div className="grid grid-cols-3 gap-2">
                    <input value={form.city}    onChange={e => setForm(f => ({ ...f, city: e.target.value }))}    placeholder="City"     className="px-3 py-2.5 rounded-lg text-sm outline-none" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
                    <input value={form.state}   onChange={e => setForm(f => ({ ...f, state: e.target.value }))}   placeholder="County"   className="px-3 py-2.5 rounded-lg text-sm outline-none" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
                    <input value={form.postcode} onChange={e => setForm(f => ({ ...f, postcode: e.target.value }))} placeholder="Postcode" className="px-3 py-2.5 rounded-lg text-sm outline-none" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
                  </div>
                </div>
              </div>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-end gap-3 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
              <button
                onClick={() => setShowModal(false)}
                className="px-4 py-2 rounded-lg text-sm font-medium"
                style={{ color: 'var(--text-secondary)', backgroundColor: 'var(--bg-elevated)' }}
              >
                Cancel
              </button>
              <button
                onClick={() => createMutation.mutate(form)}
                disabled={!form.name.trim() || createMutation.isPending}
                className="flex items-center gap-2 px-5 py-2 rounded-lg text-sm font-medium transition-opacity disabled:opacity-50 active:scale-[0.98]"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                <Plus size={14} />
                {createMutation.isPending ? 'Creating…' : 'Create Project'}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
