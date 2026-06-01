'use client';

import { useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import {
  FileText, Plus, Search, Pencil, Trash2, Upload, X, Check, ChevronDown, Building2,
} from 'lucide-react';

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const CATEGORY_LABELS: Record<string, string> = {
  subcontract:         'Subcontract',
  payment_application: 'Payment Application',
  variation:           'Variation',
  rfi:                 'RFI',
  notice:              'Notice',
  meeting_minutes:     'Meeting Minutes',
  site_report:         'Site Report',
  letter:              'Letter',
  eot:                 'EOT',
  other:               'Other',
};

const CATEGORY_COLOURS: Record<string, string> = {
  subcontract:         '#6366f1',
  payment_application: '#10b981',
  variation:           '#f59e0b',
  rfi:                 '#3b82f6',
  notice:              '#ef4444',
  meeting_minutes:     '#8b5cf6',
  site_report:         '#14b8a6',
  letter:              '#ec4899',
  eot:                 '#f97316',
  other:               '#6b7280',
};

const FILTER_TABS = ['All', ...Object.values(CATEGORY_LABELS)];

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

interface Template {
  id: number;
  name: string;
  slug: string;
  category: string;
  category_label: string;
  type: string;
  description: string | null;
  has_file: boolean;
  file_path: string | null;
  variables: string[];
  is_global: boolean;
  is_active: boolean;
  organization_id: number | null;
  organization_name: string | null;
  created_at: string;
}

interface Org { id: number; name: string; }

// ---------------------------------------------------------------------------
// Template Form Modal
// ---------------------------------------------------------------------------

function TemplateModal({
  template,
  onClose,
  onSaved,
}: {
  template?: Template;
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = !!template;
  const [name, setName] = useState(template?.name ?? '');
  const [category, setCategory] = useState(template?.category ?? 'subcontract');
  const [description, setDescription] = useState(template?.description ?? '');
  const [file, setFile] = useState<File | null>(null);
  // if template has an org_id it's company-specific, else global
  const [scope, setScope] = useState<'global' | 'company'>(
    template?.organization_id ? 'company' : 'global'
  );
  const [orgId, setOrgId] = useState<string>(template?.organization_id?.toString() ?? '');
  const [isActive, setIsActive] = useState(template?.is_active ?? true);
  const [error, setError] = useState('');
  const [saving, setSaving] = useState(false);

  const { data: orgsData } = useQuery({
    queryKey: ['admin-orgs-picker'],
    queryFn: () => api.get('/admin/organizations').then(r => r.data?.data ?? r.data ?? []),
  });
  const orgs: Org[] = orgsData ?? [];

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim()) { setError('Name is required.'); return; }
    if (scope === 'company' && !orgId) { setError('Select a company.'); return; }
    setError('');
    setSaving(true);
    try {
      const fd = new FormData();
      fd.append('name', name.trim());
      fd.append('category', category);
      fd.append('description', description);
      fd.append('is_global', scope === 'global' ? '1' : '0');
      fd.append('is_active', isActive ? '1' : '0');
      if (scope === 'company' && orgId) fd.append('organization_id', orgId);
      else fd.append('organization_id', '');
      if (file) fd.append('file', file);
      if (isEdit) {
        await api.post(`/admin/templates/${template.id}`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      } else {
        await api.post('/admin/templates', fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      }
      onSaved();
      onClose();
    } catch (err: any) {
      setError(err?.response?.data?.message ?? 'Failed to save template.');
    } finally {
      setSaving(false);
    }
  }

  // Simple toggle component — using div+onClick avoids the label→button double-fire bug
  function Toggle({ value, onChange, colour = 'var(--gold)' }: { value: boolean; onChange: (v: boolean) => void; colour?: string }) {
    return (
      <div
        role="switch" aria-checked={value}
        onClick={() => onChange(!value)}
        className="w-9 h-5 rounded-full relative cursor-pointer flex-shrink-0 transition-colors"
        style={{ backgroundColor: value ? colour : 'var(--bg-elevated)', border: '1px solid var(--border)' }}
      >
        <span
          className={`absolute top-0.5 w-4 h-4 rounded-full transition-transform ${value ? 'translate-x-4' : 'translate-x-0.5'}`}
          style={{ backgroundColor: value ? '#fff' : 'var(--text-muted)' }}
        />
      </div>
    );
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-lg rounded-2xl shadow-2xl"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between px-6 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {isEdit ? 'Edit Template' : 'New Template'}
          </h2>
          <button type="button" onClick={onClose} className="p-1 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>
        <form onSubmit={submit}>
          <div className="px-6 py-5 space-y-4 max-h-[70vh] overflow-y-auto">
            {error && (
              <div className="text-sm px-3 py-2 rounded-lg"
                style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.3)' }}>
                {error}
              </div>
            )}

            {/* Name */}
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                Template Name <span style={{ color: '#ef4444' }}>*</span>
              </label>
              <input value={name} onChange={e => setName(e.target.value)}
                placeholder="e.g. Master Subcontract Agreement"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
            </div>

            {/* Category */}
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                Category <span style={{ color: '#ef4444' }}>*</span>
              </label>
              <div className="relative">
                <select value={category} onChange={e => setCategory(e.target.value)}
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none appearance-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
                  {Object.entries(CATEGORY_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                </select>
                <ChevronDown size={14} className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
              </div>
            </div>

            {/* Description */}
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Description</label>
              <textarea value={description} onChange={e => setDescription(e.target.value)}
                rows={2} placeholder="Brief description of this template…"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
            </div>

            {/* File upload */}
            <div>
              <label className="block text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>
                Template File <span className="font-normal" style={{ color: 'var(--text-muted)' }}>(DOCX or PDF)</span>
                {isEdit && template.has_file && !file && (
                  <span className="ml-2 px-2 py-0.5 rounded-full text-xs"
                    style={{ backgroundColor: 'rgba(16,185,129,0.1)', color: '#10b981', border: '1px solid rgba(16,185,129,0.3)' }}>
                    file attached
                  </span>
                )}
              </label>
              <label className="flex items-center gap-2 px-3 py-2 rounded-lg cursor-pointer"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px dashed var(--border)', color: 'var(--text-secondary)' }}>
                <Upload size={14} />
                <span className="text-sm">{file ? file.name : (isEdit ? 'Replace file…' : 'Choose file…')}</span>
                <input type="file" accept=".docx,.pdf,.doc" className="hidden"
                  onChange={e => setFile(e.target.files?.[0] ?? null)} />
              </label>
            </div>

            {/* Scope selector */}
            <div>
              <label className="block text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Availability</label>
              <div className="grid grid-cols-2 gap-2">
                {(['global', 'company'] as const).map(s => (
                  <button key={s} type="button"
                    onClick={() => setScope(s)}
                    className="flex items-center gap-2 px-3 py-2.5 rounded-lg text-sm transition-colors"
                    style={{
                      backgroundColor: scope === s ? 'rgba(185,149,102,0.12)' : 'var(--bg-elevated)',
                      border: `1px solid ${scope === s ? 'var(--gold)' : 'var(--border)'}`,
                      color: scope === s ? 'var(--gold)' : 'var(--text-secondary)',
                    }}>
                    {s === 'global'
                      ? <><FileText size={14} /> All companies (global)</>
                      : <><Building2 size={14} /> Specific company</>}
                  </button>
                ))}
              </div>
            </div>

            {/* Company picker — shown when scope=company */}
            {scope === 'company' && (
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                  Company <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <div className="relative">
                  <select value={orgId} onChange={e => setOrgId(e.target.value)}
                    className="w-full px-3 py-2 rounded-lg text-sm outline-none appearance-none"
                    style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
                    <option value="">— Select company —</option>
                    {orgs.map((o: Org) => <option key={o.id} value={o.id}>{o.name}</option>)}
                  </select>
                  <ChevronDown size={14} className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
                </div>
              </div>
            )}

            {/* Active toggle */}
            <div className="flex items-center gap-3">
              <Toggle value={isActive} onChange={setIsActive} colour="#10b981" />
              <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>
                {isActive ? 'Active — visible to users' : 'Inactive — hidden from users'}
              </span>
            </div>
          </div>

          {/* Footer */}
          <div className="flex justify-end gap-2 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose}
              className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
              Cancel
            </button>
            <button type="submit" disabled={saving}
              className="px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-1.5 disabled:opacity-60"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {saving ? 'Saving…' : <><Check size={14} /> {isEdit ? 'Save Changes' : 'Create Template'}</>}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Delete modal
// ---------------------------------------------------------------------------

function DeleteModal({ template, onClose, onDeleted }: { template: Template; onClose: () => void; onDeleted: () => void }) {
  const [deleting, setDeleting] = useState(false);
  async function confirm() {
    setDeleting(true);
    try { await api.delete(`/admin/templates/${template.id}`); onDeleted(); onClose(); }
    finally { setDeleting(false); }
  }
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-sm rounded-2xl shadow-2xl p-6 space-y-4"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Delete Template</h2>
        <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
          Are you sure you want to delete <strong>{template.name}</strong>? This cannot be undone.
        </p>
        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button onClick={confirm} disabled={deleting}
            className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60"
            style={{ backgroundColor: '#ef4444', color: '#fff' }}>
            {deleting ? 'Deleting…' : 'Delete'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main page
// ---------------------------------------------------------------------------

export default function AdminTemplatesPage() {
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [newModalOpen, setNewModalOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Template | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Template | null>(null);
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['admin-templates'],
    queryFn: () => api.get('/admin/templates').then(r => r.data).catch(() => ({ data: [] })),
  });

  const templates: Template[] = (data?.data ?? []).filter((t: Template) => {
    const matchSearch = !search ||
      t.name.toLowerCase().includes(search.toLowerCase()) ||
      (t.description ?? '').toLowerCase().includes(search.toLowerCase());
    const matchCat = categoryFilter === 'All' || CATEGORY_LABELS[t.category] === categoryFilter;
    return matchSearch && matchCat;
  });

  const colour = (cat: string) => CATEGORY_COLOURS[cat] ?? '#6b7280';
  function invalidate() { queryClient.invalidateQueries({ queryKey: ['admin-templates'] }); }

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Document Templates</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Master templates available to all tenant companies
          </p>
        </div>
        <button
          onClick={() => setNewModalOpen(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} /> New Template
        </button>
      </div>

      {/* Filters */}
      <div className="flex gap-3 flex-wrap items-center">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search templates…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '220px' }}
          />
        </div>
        <div className="flex gap-1 p-1 rounded-lg flex-wrap" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {FILTER_TABS.map(t => (
            <button key={t} onClick={() => setCategoryFilter(t)}
              className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
              style={categoryFilter === t
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }}>
              {t}
            </button>
          ))}
        </div>
      </div>

      {/* Template grid */}
      {isLoading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="h-40 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : templates.length === 0 ? (
        <div className="rounded-2xl p-14 text-center"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <FileText size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>
            {search || categoryFilter !== 'All' ? 'No templates match your filters.' : 'No templates yet.'}
          </p>
          <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
            {search || categoryFilter !== 'All' ? 'Try adjusting the search or category.' : 'Create your first template to get started.'}
          </p>
          {!search && categoryFilter === 'All' && (
            <button onClick={() => setNewModalOpen(true)}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              <Plus size={14} /> New Template
            </button>
          )}
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {templates.map((t) => (
            <div key={t.id} className="rounded-2xl p-5 flex flex-col gap-3"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
              {/* Card header */}
              <div className="flex items-start justify-between gap-2">
                <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                  style={{ backgroundColor: colour(t.category) + '1a' }}>
                  <FileText size={16} style={{ color: colour(t.category) }} />
                </div>
                <div className="flex items-center gap-1.5 flex-wrap justify-end">
                  <span className="text-xs px-2 py-0.5 rounded-full"
                    style={{ backgroundColor: colour(t.category) + '1a', color: colour(t.category), border: `1px solid ${colour(t.category)}33` }}>
                    {t.category_label}
                  </span>
                  {t.has_file && (
                    <span className="text-xs px-2 py-0.5 rounded-full"
                      style={{ backgroundColor: 'rgba(16,185,129,0.1)', color: '#10b981', border: '1px solid rgba(16,185,129,0.3)' }}>
                      {t.type?.toUpperCase()}
                    </span>
                  )}
                  {t.organization_name && (
                    <span className="text-xs px-2 py-0.5 rounded-full flex items-center gap-1"
                      style={{ backgroundColor: 'rgba(99,102,241,0.1)', color: '#6366f1', border: '1px solid rgba(99,102,241,0.3)' }}>
                      <Building2 size={10} />{t.organization_name}
                    </span>
                  )}
                  {!t.is_active && (
                    <span className="text-xs px-2 py-0.5 rounded-full"
                      style={{ backgroundColor: 'rgba(239,68,68,0.1)', color: '#ef4444', border: '1px solid rgba(239,68,68,0.3)' }}>
                      Inactive
                    </span>
                  )}
                </div>
              </div>
              {/* Card body */}
              <div className="flex-1">
                <p className="text-sm font-semibold leading-snug" style={{ color: 'var(--text-primary)' }}>{t.name}</p>
                {t.description && (
                  <p className="text-xs mt-1 line-clamp-2" style={{ color: 'var(--text-muted)' }}>{t.description}</p>
                )}
              </div>
              {/* Card footer */}
              <div className="flex items-center justify-between pt-1" style={{ borderTop: '1px solid var(--border)' }}>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{t.created_at}</span>
                <div className="flex items-center gap-1">
                  <button onClick={() => setEditTarget(t)}
                    className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-elevated)]" title="Edit">
                    <Pencil size={13} style={{ color: 'var(--text-muted)' }} />
                  </button>
                  <button onClick={() => setDeleteTarget(t)}
                    className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(239,68,68,0.1)]" title="Delete">
                    <Trash2 size={13} style={{ color: '#ef4444' }} />
                  </button>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* Modals */}
      {newModalOpen && <TemplateModal onClose={() => setNewModalOpen(false)} onSaved={invalidate} />}
      {editTarget && <TemplateModal template={editTarget} onClose={() => setEditTarget(null)} onSaved={invalidate} />}
      {deleteTarget && <DeleteModal template={deleteTarget} onClose={() => setDeleteTarget(null)} onDeleted={invalidate} />}
    </div>
  );
}
