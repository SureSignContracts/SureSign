'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { parseDateOnly } from '@/lib/dateTime';
import {
  ArrowLeft, Building2, Mail, Phone, MapPin, Plus, Search,
  FolderOpen, Calendar, DollarSign, ChevronRight, X,
} from 'lucide-react';
import Select from '@/components/ui/Select';

const STATUS_COLORS: Record<string, string> = {
  active: '#10b981', on_hold: '#f59e0b', completed: '#3b82f6', cancelled: '#ef4444',
};

// project.start_date/end_date are DATE-only fields — parsed via
// parseDateOnly() (local calendar components), never `new Date(dateString)`
// (which the spec parses as UTC midnight and can roll the date back a day
// for a negative-UTC-offset viewer).
function fmtDate(d: string) {
  return parseDateOnly(d).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' });
}

// ─── New Project Modal ────────────────────────────────────────────────────────
function NewProjectModal({ clientId, onClose }: { clientId: string; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    name: '', code: '', description: '', type: '', status: 'active',
    contract_value: '', start_date: '', end_date: '', address: '',
  });

  const mutation = useMutation({
    mutationFn: (data: typeof form) =>
      api.post('/projects', { ...data, client_id: clientId }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['client-projects', clientId] });
      qc.invalidateQueries({ queryKey: ['clients'] });
      onClose();
    },
  });

  const set = (k: string, v: string) => setForm((f) => ({ ...f, [k]: v }));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4"
         style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-lg rounded-2xl p-6 space-y-4 max-h-[90vh] overflow-y-auto"
           style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Project</h2>
          <button onClick={onClose} className="p-1 rounded-md hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <div className="col-span-2">
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Project Name *</label>
            <input value={form.name} onChange={e => set('name', e.target.value)}
              placeholder="City Centre Fitout"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Project Code</label>
            <input value={form.code} onChange={e => set('code', e.target.value)}
              placeholder="SS-001"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Type</label>
            <Select value={form.type} onChange={e => set('type', e.target.value)} className="w-full">
              <option value="">Select type</option>
              <option value="new_build">New Build</option>
              <option value="refurbishment">Refurbishment</option>
              <option value="fitout">Fitout</option>
              <option value="infrastructure">Infrastructure</option>
              <option value="other">Other</option>
            </Select>
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Contract Value</label>
            <input value={form.contract_value} onChange={e => set('contract_value', e.target.value)}
              placeholder="500000"
              type="number"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
            <Select value={form.status} onChange={e => set('status', e.target.value)} className="w-full">
              <option value="active">Active</option>
              <option value="on_hold">On Hold</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </Select>
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Start Date</label>
            <input value={form.start_date} onChange={e => set('start_date', e.target.value)}
              type="date"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>End Date</label>
            <input value={form.end_date} onChange={e => set('end_date', e.target.value)}
              type="date"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div className="col-span-2">
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Address</label>
            <input value={form.address} onChange={e => set('address', e.target.value)}
              placeholder="123 Main St, Sydney NSW"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div className="col-span-2">
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Description</label>
            <textarea value={form.description} onChange={e => set('description', e.target.value)}
              placeholder="Brief project description…"
              rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
        </div>

        <div className="flex gap-2 justify-end pt-2">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
                  style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutation.mutate(form)}
                  disabled={!form.name.trim() || mutation.isPending}
                  className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            {mutation.isPending ? 'Creating…' : 'Create Project'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────
export default function CompanyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [search, setSearch] = useState('');
  const [showNew, setShowNew] = useState(false);

  const { data: client, isLoading: clientLoading } = useQuery({
    queryKey: ['client', id],
    queryFn: () => api.get(`/clients/${id}`).then((r) => r.data?.data ?? r.data),
    enabled: !!id,
  });

  const { data: projectsData, isLoading: projectsLoading } = useQuery({
    queryKey: ['client-projects', id],
    queryFn: () => api.get(`/clients/${id}/projects`).then((r) => r.data?.data ?? r.data),
    enabled: !!id,
  });

  const projects: any[] = Array.isArray(projectsData) ? projectsData : [];
  const filtered = projects.filter((p) =>
    p.name?.toLowerCase().includes(search.toLowerCase()) ||
    (p.code ?? '').toLowerCase().includes(search.toLowerCase())
  );

  if (clientLoading) return (
    <div className="p-8 max-w-7xl mx-auto space-y-4">
      <div className="h-32 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
    </div>
  );

  if (!client) return (
    <div className="p-8 text-center py-24">
      <p style={{ color: 'var(--text-muted)' }}>Company not found.</p>
    </div>
  );

  return (
    <div className="p-8 max-w-7xl mx-auto space-y-6">
      {showNew && <NewProjectModal clientId={id} onClose={() => setShowNew(false)} />}

      {/* Back */}
      <Link href="/companies"
            className="inline-flex items-center gap-1.5 text-xs transition-colors hover:text-[var(--text-primary)]"
            style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={13} /> All Companies
      </Link>

      {/* Company hero */}
      <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div className="flex items-center gap-4">
            <div className="w-12 h-12 rounded-xl flex items-center justify-center text-base font-bold text-white flex-shrink-0"
                 style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {client.name?.charAt(0)?.toUpperCase()}
            </div>
            <div>
              <h1 className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>{client.name}</h1>
              {client.abn && (
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>ABN {client.abn}</p>
              )}
            </div>
          </div>
          <div className="flex items-center gap-5 flex-wrap text-sm">
            {client.contact_name && (
              <div className="flex items-center gap-1.5" style={{ color: 'var(--text-secondary)' }}>
                <Building2 size={13} style={{ color: 'var(--text-muted)' }} /> {client.contact_name}
              </div>
            )}
            {client.contact_email && (
              <a href={`mailto:${client.contact_email}`}
                 className="flex items-center gap-1.5 hover:underline"
                 style={{ color: 'var(--text-secondary)' }}>
                <Mail size={13} style={{ color: 'var(--text-muted)' }} /> {client.contact_email}
              </a>
            )}
            {client.contact_phone && (
              <div className="flex items-center gap-1.5" style={{ color: 'var(--text-secondary)' }}>
                <Phone size={13} style={{ color: 'var(--text-muted)' }} /> {client.contact_phone}
              </div>
            )}
            {client.address && (
              <div className="flex items-center gap-1.5" style={{ color: 'var(--text-secondary)' }}>
                <MapPin size={13} style={{ color: 'var(--text-muted)' }} /> {client.address}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* Projects section */}
      <div>
        <div className="flex items-center justify-between mb-4">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Projects</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              {projects.length} {projects.length === 1 ? 'project' : 'projects'}
            </p>
          </div>
          <div className="flex items-center gap-3">
            <div className="relative">
              <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2"
                      style={{ color: 'var(--text-muted)' }} />
              <input value={search} onChange={e => setSearch(e.target.value)}
                placeholder="Search…"
                className="pl-8 pr-3 py-2 rounded-lg text-sm outline-none w-44"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
            </div>
            <button onClick={() => setShowNew(true)}
                    className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
                    style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              <Plus size={14} /> New Project
            </button>
          </div>
        </div>

        {projectsLoading ? (
          <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))' }}>
            {[...Array(4)].map((_, i) => (
              <div key={i} className="h-40 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ))}
          </div>
        ) : filtered.length === 0 ? (
          <div className="flex flex-col items-center justify-center py-20 rounded-2xl"
               style={{ border: '1px dashed var(--border)' }}>
            <FolderOpen size={36} style={{ color: 'var(--text-muted)' }} className="mb-3" />
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              {search ? 'No projects match your search' : 'No projects yet for this company'}
            </p>
            {!search && (
              <button onClick={() => setShowNew(true)} className="mt-3 text-xs underline"
                      style={{ color: 'var(--gold)' }}>
                Create first project
              </button>
            )}
          </div>
        ) : (
          <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(280px, 1fr))' }}>
            {filtered.map((project) => (
              <Link key={project.id} href={`/projects/${project.id}`}
                    className="group flex flex-col gap-4 p-5 rounded-2xl transition-all hover:scale-[1.01]"
                    style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
                    onMouseEnter={e => (e.currentTarget.style.borderColor = STATUS_COLORS[project.status] ?? 'var(--border)')}
                    onMouseLeave={e => (e.currentTarget.style.borderColor = 'var(--border)')}>
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>
                      {project.name}
                    </p>
                    {project.code && (
                      <p className="text-xs font-mono mt-0.5" style={{ color: 'var(--text-muted)' }}>{project.code}</p>
                    )}
                  </div>
                  <span className="text-xs px-2 py-0.5 rounded-full capitalize flex-shrink-0 font-medium"
                        style={{ backgroundColor: (STATUS_COLORS[project.status] ?? '#888') + '18',
                                 color: STATUS_COLORS[project.status] ?? '#888' }}>
                    {project.status?.replace('_', ' ')}
                  </span>
                </div>

                {project.description && (
                  <p className="text-xs leading-relaxed line-clamp-2" style={{ color: 'var(--text-secondary)' }}>
                    {project.description}
                  </p>
                )}

                <div className="space-y-1.5">
                  {project.contract_value && (
                    <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      <DollarSign size={11} style={{ color: 'var(--text-muted)' }} />
                      ${Number(project.contract_value).toLocaleString()}
                    </div>
                  )}
                  {project.address && (
                    <div className="flex items-center gap-2 text-xs truncate" style={{ color: 'var(--text-secondary)' }}>
                      <MapPin size={11} style={{ color: 'var(--text-muted)' }} />
                      {project.address}
                    </div>
                  )}
                  {(project.start_date || project.end_date) && (
                    <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      <Calendar size={11} style={{ color: 'var(--text-muted)' }} />
                      {project.start_date ? fmtDate(project.start_date) : '—'}
                      {project.end_date && <> → {fmtDate(project.end_date)}</>}
                    </div>
                  )}
                </div>

                <div className="flex items-center justify-between pt-1"
                     style={{ borderTop: '1px solid var(--border)' }}>
                  <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    {project.creator?.name ?? 'Unknown'}
                  </span>
                  <ChevronRight size={13} style={{ color: 'var(--text-muted)' }}
                                className="opacity-0 group-hover:opacity-100 transition-opacity" />
                </div>
              </Link>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
