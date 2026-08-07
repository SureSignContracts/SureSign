'use client';

import { useState, useEffect, useMemo, useRef } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import PaginationBar from '@/components/ui/PaginationBar';
import Combobox from '@/components/ui/Combobox';
import Select from '@/components/ui/Select';
import {
  FileText, Plus, Search, Pencil, Trash2, Upload, X, Check, ChevronDown, Building2, Globe, Eye,
} from 'lucide-react';
import DocumentPreviewModal, { type PreviewTarget } from '@/components/documents/DocumentPreviewModal';
import { getErrorMessage } from '@/lib/getErrorMessage';

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

const SUBCONTRACT_TEMPLATE_TYPES: Record<string, string> = {
  master_package:        'Master Package',
  procurement_summary:   'Procurement Summary',
  tender_enquiry_letter: 'Tender Enquiry Letter',
  schedule_of_documents: 'Schedule of Documents',
  subcontract_draft:     'Subcontract Draft',
  other:                 'Other',
};

const ALL_TEMPLATE_TYPES: Record<string, string> = {
  ...SUBCONTRACT_TEMPLATE_TYPES,
  variation:            'Variation',
  payment_application:  'Payment Application',
  payment_certificate:  'Payment Certificate',
  payment_notice:       'Payment Notice',
  pay_less_notice:      'Pay Less Notice',
  variation_schedule:   'Variation Schedule',
  commercial_schedule:  'Commercial Schedule',
  eot:                  'EOT',
  rfi:                  'RFI',
  meeting_minutes:      'Meeting Minutes',
  site_report:          'Site Report',
};

const FILTER_TABS = ['All', ...Object.values(CATEGORY_LABELS)];

interface Template {
  id: number;
  name: string;
  slug: string;
  category: string;
  category_label: string;
  template_type: string | null;
  template_type_label: string | null;
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
  const [templateType, setTemplateType] = useState(template?.template_type ?? '');
  const [description, setDescription] = useState(template?.description ?? '');
  const [file, setFile] = useState<File | null>(null);
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

  const templateTypeOptions = category === 'subcontract' ? SUBCONTRACT_TEMPLATE_TYPES : ALL_TEMPLATE_TYPES;

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
      if (templateType) fd.append('template_type', templateType);
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
    } catch (err: unknown) {
      const apiErr = err as { response?: { data?: { message?: string } } };
      setError(getErrorMessage(apiErr, 'Failed to save template.'));
    } finally {
      setSaving(false);
    }
  }

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
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm"
      style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-lg rounded-2xl shadow-2xl ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
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
                placeholder="e.g. Star Pacific Master Trade Template"
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
            </div>

            {/* Category */}
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                Category <span style={{ color: '#ef4444' }}>*</span>
              </label>
              <Select value={category} onChange={e => { setCategory(e.target.value); setTemplateType(''); }} className="w-full">
                {Object.entries(CATEGORY_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </Select>
            </div>

            {/* Template Type */}
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                Template Type
                {category === 'subcontract' && <span style={{ color: '#ef4444' }}> *</span>}
              </label>
              <Select value={templateType} onChange={e => setTemplateType(e.target.value)} className="w-full">
                <option value="">— Select type —</option>
                {Object.entries(templateTypeOptions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </Select>
              {category === 'subcontract' && (
                <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                  Required for subcontract templates. Used to auto-select the correct template during document generation.
                </p>
              )}
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
                      backgroundColor: scope === s ? 'var(--gold-15)' : 'var(--bg-elevated)',
                      border: `1px solid ${scope === s ? 'var(--gold)' : 'var(--border)'}`,
                      color: scope === s ? 'var(--gold)' : 'var(--text-secondary)',
                    }}>
                    {s === 'global'
                      ? <><FileText size={14} /> All companies (global)</>
                      : <><Building2 size={14} /> Specific company</>}
                  </button>
                ))}
              </div>
              <p className="mt-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                {scope === 'global'
                  ? 'Global templates are shared across all companies. Company branding (logo, letterhead, colours) is applied automatically when documents are generated.'
                  : 'This template is only available to the selected company. Branding is still applied dynamically from that company\'s settings.'}
              </p>
            </div>

            {/* Company picker */}
            {scope === 'company' && (
              <div>
                <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>
                  Company <span style={{ color: '#ef4444' }}>*</span>
                </label>
                <Combobox
                  value={orgId}
                  onValueChange={setOrgId}
                  placeholder="— Select company —"
                  searchPlaceholder="Search companies…"
                  emptyMessage="No companies found."
                  options={orgs.map((o: Org) => ({ value: String(o.id), label: o.name }))}
                />
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

          <div className="flex justify-end gap-2 px-6 py-4" style={{ borderTop: '1px solid var(--border)' }}>
            <button type="button" onClick={onClose}
              className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
              Cancel
            </button>
            <button type="submit" disabled={saving}
              className="px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-1.5 disabled:opacity-60 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {saving ? 'Saving…' : <><Check size={14} /> {isEdit ? 'Save Changes' : 'Create Template'}</>}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function DeleteModal({ template, onClose, onDeleted }: { template: Template; onClose: () => void; onDeleted: () => void }) {
  const [deleting, setDeleting] = useState(false);
  async function confirm() {
    setDeleting(true);
    try { await api.delete(`/admin/templates/${template.id}`); onDeleted(); onClose(); }
    finally { setDeleting(false); }
  }
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.55)' }}>
      <div className="w-full max-w-sm rounded-2xl shadow-2xl p-6 space-y-4 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
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

// ── Template row (table layout) ───────────────────────────────────────────────
function TemplateRow({
  t,
  onPreview,
  onEdit,
  onDelete,
}: {
  t: Template;
  onPreview: (t: Template) => void;
  onEdit: (t: Template) => void;
  onDelete: (t: Template) => void;
}) {
  const colour = CATEGORY_COLOURS[t.category] ?? '#6b7280';
  return (
    <tr style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
      <td className="px-4 py-3">
        <div className="flex items-center gap-3">
          <div className="w-7 h-7 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ backgroundColor: colour + '18' }}>
            <FileText size={13} style={{ color: colour }} />
          </div>
          <div className="min-w-0">
            <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{t.name}</p>
            {t.description && (
              <p className="text-xs truncate mt-0.5" style={{ color: 'var(--text-muted)' }}>{t.description}</p>
            )}
          </div>
        </div>
      </td>
      <td className="px-4 py-3">
        <span className="text-xs px-2 py-0.5 rounded-full whitespace-nowrap"
          style={{ backgroundColor: colour + '18', color: colour }}>
          {t.category_label}
        </span>
      </td>
      <td className="px-4 py-3">
        <span className="text-xs" style={{ color: 'var(--text-secondary)' }}>
          {t.template_type_label ?? '—'}
        </span>
      </td>
      <td className="px-4 py-3">
        {t.has_file ? (
          <span className="text-xs px-2 py-0.5 rounded-full font-medium"
            style={{ backgroundColor: 'rgba(16,185,129,0.1)', color: '#10b981' }}>
            {t.type?.toUpperCase()}
          </span>
        ) : (
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>
        )}
      </td>
      <td className="px-4 py-3">
        <span className={`text-xs px-2 py-0.5 rounded-full font-medium`}
          style={{
            backgroundColor: t.is_active ? 'rgba(34,197,94,0.1)' : 'rgba(90,86,82,0.15)',
            color: t.is_active ? '#4ade80' : 'var(--text-muted)',
          }}>
          {t.is_active ? 'Active' : 'Inactive'}
        </span>
      </td>
      <td className="px-4 py-3 text-right">
        <div className="flex items-center gap-1 justify-end">
          {t.has_file && (
            <button onClick={() => onPreview(t)}
              className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]" title="Preview">
              <Eye size={13} style={{ color: 'var(--text-muted)' }} />
            </button>
          )}
          <button onClick={() => onEdit(t)}
            className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]" title="Edit">
            <Pencil size={13} style={{ color: 'var(--text-muted)' }} />
          </button>
          <button onClick={() => onDelete(t)}
            className="p-1.5 rounded-lg transition-colors hover:bg-[rgba(239,68,68,0.08)]" title="Delete">
            <Trash2 size={13} style={{ color: '#ef4444' }} />
          </button>
        </div>
      </td>
    </tr>
  );
}

// ── Company group section ─────────────────────────────────────────────────────
function CompanyGroup({
  name,
  orgId,
  templates,
  onPreview,
  onEdit,
  onDelete,
}: {
  name: string;
  orgId: number | null;
  templates: Template[];
  onPreview: (t: Template) => void;
  onEdit: (t: Template) => void;
  onDelete: (t: Template) => void;
}) {
  const [collapsed, setCollapsed] = useState(false);
  const [height, setHeight] = useState<number | 'auto'>('auto');
  const bodyRef = useRef<HTMLDivElement>(null);
  const isGlobal = orgId === null;

  // Animate open/close using measured height
  const toggle = () => {
    if (!bodyRef.current) { setCollapsed(c => !c); return; }
    if (!collapsed) {
      // Collapsing: fix height then animate to 0
      setHeight(bodyRef.current.scrollHeight);
      requestAnimationFrame(() => {
        requestAnimationFrame(() => setHeight(0));
      });
      setTimeout(() => setCollapsed(true), 220);
    } else {
      // Expanding: go from 0 to measured height
      setCollapsed(false);
      setHeight(0);
      requestAnimationFrame(() => {
        if (bodyRef.current) {
          setHeight(bodyRef.current.scrollHeight);
          setTimeout(() => setHeight('auto'), 220);
        }
      });
    }
  };

  return (
    <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      {/* Group header */}
      <button
        onClick={toggle}
        className="w-full flex items-center justify-between px-4 py-3 transition-colors hover:bg-[var(--bg-hover)]"
        style={{ backgroundColor: 'var(--bg-elevated)' }}
      >
        <div className="flex items-center gap-2.5">
          <div className="w-6 h-6 rounded-md flex items-center justify-center flex-shrink-0"
            style={{ backgroundColor: isGlobal ? 'var(--gold-15)' : 'rgba(99,102,241,0.12)' }}>
            {isGlobal
              ? <Globe size={12} style={{ color: 'var(--gold)' }} />
              : <Building2 size={12} style={{ color: '#6366f1' }} />}
          </div>
          <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{name}</span>
          <span className="text-xs px-1.5 py-0.5 rounded-md tabular-nums"
            style={{ backgroundColor: 'var(--bg-surface)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            {templates.length}
          </span>
        </div>
        <ChevronDown
          size={14}
          style={{
            color: 'var(--text-muted)',
            transform: collapsed ? 'rotate(-90deg)' : 'rotate(0deg)',
            transition: 'transform 200ms cubic-bezier(0.4,0,0.2,1)',
          }}
        />
      </button>

      {/* Template table — animated reveal */}
      <div
        ref={bodyRef}
        style={{
          height: collapsed ? 0 : height,
          overflow: 'hidden',
          transition: 'height 200ms cubic-bezier(0.4,0,0.2,1)',
        }}
      >
        <div className="overflow-x-auto">
        <table className="w-full min-w-[760px]">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)', borderTop: '1px solid var(--border)' }}>
              {['Template', 'Category', 'Type', 'File', 'Status', ''].map((h, i) => (
                <th key={i} className="text-left px-4 py-2 text-xs font-medium"
                  style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {templates.map(t => (
              <TemplateRow key={t.id} t={t} onPreview={onPreview} onEdit={onEdit} onDelete={onDelete} />
            ))}
          </tbody>
        </table>
        </div>
      </div>
    </div>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function AdminTemplatesPage() {
  const [search, setSearch]             = useState('');
  const [debouncedSearch, setDebounced] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('All');
  const [page, setPage]                 = useState(1);
  const [perPage, setPerPage]           = useState(25);
  const [newModalOpen, setNewModalOpen] = useState(false);
  const [editTarget, setEditTarget]       = useState<Template | null>(null);
  const [deleteTarget, setDeleteTarget]   = useState<Template | null>(null);
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);
  const queryClient = useQueryClient();

  useEffect(() => {
    const t = setTimeout(() => { setDebounced(search); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-templates', debouncedSearch, categoryFilter, page, perPage],
    queryFn: () => {
      const params: Record<string, string | number> = { page, per_page: perPage };
      if (debouncedSearch) params.search = debouncedSearch;
      if (categoryFilter !== 'All') {
        const key = Object.entries(CATEGORY_LABELS).find(([, v]) => v === categoryFilter)?.[0];
        if (key) params.category = key;
      }
      return api.get('/admin/templates', { params }).then(r => r.data).catch(() => ({ data: [], total: 0, last_page: 1 }));
    },
    placeholderData: (prev: any) => prev,
  });

  const templates: Template[] = data?.data ?? [];
  const total: number         = data?.total    ?? 0;
  const lastPage: number      = data?.last_page ?? 1;

  // Group templates by company
  const groups = useMemo(() => {
    const map = new Map<string, { orgId: number | null; name: string; templates: Template[] }>();

    // Global first
    map.set('__global__', { orgId: null, name: 'Global Templates', templates: [] });

    templates.forEach(t => {
      if (!t.organization_id) {
        map.get('__global__')!.templates.push(t);
      } else {
        const key = String(t.organization_id);
        if (!map.has(key)) {
          map.set(key, { orgId: t.organization_id, name: t.organization_name ?? 'Unknown Company', templates: [] });
        }
        map.get(key)!.templates.push(t);
      }
    });

    // Remove empty global group
    if (map.get('__global__')!.templates.length === 0) map.delete('__global__');

    return Array.from(map.values());
  }, [templates]);

  function invalidate() {
    queryClient.invalidateQueries({ queryKey: ['admin-templates'] });
  }

  const hasFilters = !!debouncedSearch || categoryFilter !== 'All';

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5 pb-10">

      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Document Templates</h1>
          <p className="mt-0.5 text-sm" style={{ color: 'var(--text-muted)' }}>
            Master templates available to all tenant companies
            {!isLoading && total > 0 && <span className="ml-1">· {total} total</span>}
          </p>
        </div>
        <button
          onClick={() => setNewModalOpen(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} /> New Template
        </button>
      </div>

      {/* Toolbar */}
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
        <div className="flex gap-1 p-1 rounded-full flex-wrap" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
          {FILTER_TABS.map(tab => (
            <button key={tab}
              onClick={() => { setCategoryFilter(tab); setPage(1); }}
              className="px-3 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
              style={categoryFilter === tab
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }}>
              {tab}
            </button>
          ))}
        </div>
        <div className="ml-auto flex items-center gap-2">
          <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{total} template{total !== 1 ? 's' : ''}</span>
        </div>
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="space-y-3">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-14 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : templates.length === 0 ? (
        <div className="rounded-2xl p-14 text-center"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <FileText size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>
            {hasFilters ? 'No templates match your filters.' : 'No templates yet.'}
          </p>
          <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
            {hasFilters ? 'Try adjusting the search or category.' : 'Create your first template to get started.'}
          </p>
          {!hasFilters && (
            <button onClick={() => setNewModalOpen(true)}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              <Plus size={14} /> New Template
            </button>
          )}
        </div>
      ) : (
        <div className="space-y-4">
          {groups.map(g => (
            <CompanyGroup
              key={g.orgId ?? '__global__'}
              name={g.name}
              orgId={g.orgId}
              templates={g.templates}
              onPreview={(t) => setPreviewTarget({
                id: t.id,
                name: t.name,
                mimeType: t.type === 'pdf' ? 'application/pdf' : t.type === 'docx' ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : undefined,
                previewEndpoint: `/admin/templates/${t.id}/preview`,
                downloadEndpoint: `/admin/templates/${t.id}/preview`,
                subtitle: t.category_label ?? undefined,
              })}
              onEdit={setEditTarget}
              onDelete={setDeleteTarget}
            />
          ))}
        </div>
      )}

      <PaginationBar
        page={page}
        lastPage={lastPage}
        total={total}
        perPage={perPage}
        onPage={setPage}
        onPerPage={n => { setPerPage(n); setPage(1); }}
      />

      {newModalOpen && <TemplateModal onClose={() => setNewModalOpen(false)} onSaved={invalidate} />}
      {editTarget && <TemplateModal template={editTarget} onClose={() => setEditTarget(null)} onSaved={invalidate} />}
      {deleteTarget && <DeleteModal template={deleteTarget} onClose={() => setDeleteTarget(null)} onDeleted={invalidate} />}
      {previewTarget && <DocumentPreviewModal target={previewTarget} onClose={() => setPreviewTarget(null)} />}
    </div>
  );
}
