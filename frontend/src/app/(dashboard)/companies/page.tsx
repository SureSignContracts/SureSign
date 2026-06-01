'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import Link from 'next/link';
import {
  Plus, Search, Building2, Mail, Phone, MapPin, X, ChevronRight,
} from 'lucide-react';

function InitialAvatar({ name }: { name: string }) {
  const initials = name
    .split(' ')
    .slice(0, 2)
    .map((w) => w[0]?.toUpperCase())
    .join('');
  // Simple hash for a consistent hue
  const hue = name.split('').reduce((acc, c) => acc + c.charCodeAt(0), 0) % 360;
  return (
    <div
      className="w-12 h-12 rounded-xl flex items-center justify-center text-sm font-bold text-white flex-shrink-0"
      style={{ backgroundColor: `hsl(${hue}, 55%, 45%)` }}
    >
      {initials}
    </div>
  );
}

// ─── New Company Modal ────────────────────────────────────────────────────────
function NewCompanyModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    name: '', abn: '', contact_name: '', contact_email: '', contact_phone: '', address: '',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) => api.post('/clients', data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['clients'] });
      onClose();
    },
  });

  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4"
         style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-md rounded-2xl p-6 space-y-4"
           style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Company</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="space-y-3">
          {[
            { key: 'name',          label: 'Company Name *', placeholder: 'Acme Corp' },
            { key: 'abn',           label: 'ABN',            placeholder: '12 345 678 901' },
            { key: 'contact_name',  label: 'Contact Name',   placeholder: 'John Smith' },
            { key: 'contact_email', label: 'Contact Email',  placeholder: 'john@acme.com' },
            { key: 'contact_phone', label: 'Contact Phone',  placeholder: '+61 400 000 000' },
            { key: 'address',       label: 'Address',        placeholder: '123 Main St, Sydney NSW' },
          ].map(({ key, label, placeholder }) => (
            <div key={key}>
              <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}</label>
              <input
                value={(form as any)[key]}
                onChange={(e) => set(key, e.target.value)}
                placeholder={placeholder}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              />
            </div>
          ))}
        </div>

        <div className="flex gap-2 justify-end pt-2">
          <button onClick={onClose}
                  className="px-4 py-2 rounded-lg text-sm"
                  style={{ color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => mutation.mutate(form)}
            disabled={!form.name.trim() || mutation.isPending}
            className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Creating…' : 'Create Company'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────
export default function CompaniesPage() {
  const [search, setSearch] = useState('');
  const [showNew, setShowNew] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['clients'],
    queryFn: () => api.get('/clients').then((r) => r.data?.data ?? r.data),
  });

  const clients: any[] = Array.isArray(data) ? data : [];
  const filtered = clients.filter((c) =>
    c.name?.toLowerCase().includes(search.toLowerCase()) ||
    c.contact_name?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-8 space-y-6 max-w-7xl mx-auto">
      {showNew && <NewCompanyModal onClose={() => setShowNew(false)} />}

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Companies</h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-secondary)' }}>
            {clients.length} {clients.length === 1 ? 'company' : 'companies'} total
          </p>
        </div>
        <button
          onClick={() => setShowNew(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} /> New Company
        </button>
      </div>

      {/* Search */}
      <div className="relative max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          type="text"
          placeholder="Search companies…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {/* Grid */}
      {isLoading ? (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))' }}>
          {[...Array(6)].map((_, i) => (
            <div key={i} className="h-36 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : filtered.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-24 rounded-2xl"
             style={{ border: '1px dashed var(--border)' }}>
          <Building2 size={40} style={{ color: 'var(--text-muted)' }} className="mb-4" />
          <p className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>
            {search ? 'No companies match your search' : 'No companies yet'}
          </p>
          {!search && (
            <button onClick={() => setShowNew(true)}
                    className="mt-3 text-xs underline"
                    style={{ color: 'var(--gold)' }}>
              Add your first company
            </button>
          )}
        </div>
      ) : (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))' }}>
          {filtered.map((client) => (
            <Link key={client.id} href={`/companies/${client.id}`}
                  className="group flex flex-col gap-4 p-5 rounded-2xl transition-all hover:scale-[1.01]"
                  style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
                  onMouseEnter={(e) => (e.currentTarget.style.borderColor = 'var(--gold)')}
                  onMouseLeave={(e) => (e.currentTarget.style.borderColor = 'var(--border)')}>
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                  <InitialAvatar name={client.name} />
                  <div className="min-w-0">
                    <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>
                      {client.name}
                    </p>
                    {client.abn && (
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>ABN {client.abn}</p>
                    )}
                  </div>
                </div>
                <ChevronRight size={14} style={{ color: 'var(--text-muted)' }}
                              className="flex-shrink-0 mt-1 opacity-0 group-hover:opacity-100 transition-opacity" />
              </div>

              <div className="space-y-1.5">
                {client.contact_name && (
                  <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
                    <Building2 size={11} style={{ color: 'var(--text-muted)' }} />
                    {client.contact_name}
                  </div>
                )}
                {client.contact_email && (
                  <div className="flex items-center gap-2 text-xs truncate" style={{ color: 'var(--text-secondary)' }}>
                    <Mail size={11} style={{ color: 'var(--text-muted)' }} />
                    {client.contact_email}
                  </div>
                )}
                {client.contact_phone && (
                  <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
                    <Phone size={11} style={{ color: 'var(--text-muted)' }} />
                    {client.contact_phone}
                  </div>
                )}
                {client.address && (
                  <div className="flex items-center gap-2 text-xs truncate" style={{ color: 'var(--text-secondary)' }}>
                    <MapPin size={11} style={{ color: 'var(--text-muted)' }} />
                    {client.address}
                  </div>
                )}
              </div>

              <div className="flex items-center justify-between pt-1"
                   style={{ borderTop: '1px solid var(--border)' }}>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  {client.projects_count ?? 0} {client.projects_count === 1 ? 'project' : 'projects'}
                </span>
                <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                      style={{ backgroundColor: client.status === 'active' ? '#10b98118' : '#88888818',
                               color: client.status === 'active' ? '#10b981' : '#888' }}>
                  {client.status}
                </span>
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
