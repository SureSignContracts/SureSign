'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Sparkles, Plus, Pencil, Trash2, X, Eye, EyeOff, Radio, FileEdit } from 'lucide-react';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Select from '@/components/ui/Select';
import ProductUpdateContent from '@/components/product-updates/ProductUpdateContent';
import PlatformPageHero from '@/components/admin/PlatformPageHero';
import {
  CATEGORY_LABELS, AUDIENCE_LABELS,
  type AdminProductUpdate, type ProductUpdateCategory, type ProductUpdateAudience, type ProductUpdateStatus,
} from '@/lib/productUpdates';

const CATEGORIES: ProductUpdateCategory[] = ['new_feature', 'improvement', 'important_update', 'tip'];
const AUDIENCES: ProductUpdateAudience[] = ['all', 'client', 'operator'];
const STATUSES: ProductUpdateStatus[] = ['draft', 'published', 'archived'];

const STATUS_STYLES: Record<ProductUpdateStatus, { bg: string; text: string }> = {
  draft:     { bg: 'rgba(148,163,184,0.14)', text: '#94a3b8' },
  published: { bg: 'rgba(74,222,128,0.14)',  text: '#4ade80' },
  archived:  { bg: 'rgba(148,163,184,0.10)', text: '#64748b' },
};

interface FormState {
  title: string;
  summary: string;
  content: string;
  category: ProductUpdateCategory;
  audience: ProductUpdateAudience;
  status: ProductUpdateStatus;
  cta_label: string;
  cta_url: string;
}

const EMPTY_FORM: FormState = {
  title: '', summary: '', content: '', category: 'new_feature', audience: 'client', status: 'draft',
  cta_label: '', cta_url: '',
};

export default function AdminProductUpdatesPage() {
  const qc = useQueryClient();
  const [editing, setEditing] = useState<AdminProductUpdate | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [showPreview, setShowPreview] = useState(false);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [error, setError] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'product-updates'],
    queryFn: () => api.get('/admin/product-updates').then(r => r.data.data as AdminProductUpdate[]),
  });
  const updates = data ?? [];

  const saveMutation = useMutation({
    mutationFn: () => {
      const payload = {
        title: form.title,
        summary: form.summary,
        content: form.content,
        category: form.category,
        audience: form.audience,
        status: form.status,
        cta_label: form.cta_label || null,
        cta_url: form.cta_url || null,
      };
      return editing
        ? api.put(`/admin/product-updates/${editing.id}`, payload)
        : api.post('/admin/product-updates', payload);
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'product-updates'] });
      closeForm();
    },
    onError: (err) => setError(getErrorMessage(err, 'Could not save this update.')),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/product-updates/${id}`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'product-updates'] }),
  });

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setError('');
    setShowPreview(false);
    setShowForm(true);
  }

  function openEdit(u: AdminProductUpdate) {
    setEditing(u);
    setForm({
      title: u.title, summary: u.summary, content: u.content, category: u.category, audience: u.audience,
      status: u.status, cta_label: u.cta_label ?? '', cta_url: u.cta_url ?? '',
    });
    setError('');
    setShowPreview(false);
    setShowForm(true);
  }

  function closeForm() {
    setShowForm(false);
    setEditing(null);
    setError('');
  }

  return (
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero
        eyebrow="Release communications"
        title="Product Updates"
        description="Prepare and publish concise release notes that explain what changed across the SureSign product."
        loading={isLoading}
        metrics={[
          { label: 'Updates', value: updates.length, detail: 'all release notes', icon: Sparkles },
          { label: 'Published', value: updates.filter(update => update.status === 'published').length, detail: 'visible to users', icon: Radio },
          { label: 'Drafts', value: updates.filter(update => update.status === 'draft').length, detail: 'being prepared', icon: FileEdit },
        ]}
        action={<button
          onClick={openCreate}
          className="flex flex-shrink-0 items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-sm font-semibold text-[#18211d] transition-colors hover:bg-[#b3efc6] active:scale-[0.98]"
        >
          <Plus size={14} /> New update
        </button>}
      />

      {showForm && (
        <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{editing ? 'Edit Update' : 'New Update'}</h2>
            <button onClick={closeForm} aria-label="Close"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
          </div>
          {error && <p className="text-xs" style={{ color: '#f87171' }}>{error}</p>}

          <input
            value={form.title}
            onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
            placeholder="Title (e.g. Drawing Locations are now available)"
            className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
          <textarea
            value={form.summary}
            onChange={e => setForm(f => ({ ...f, summary: e.target.value }))}
            placeholder="Short summary shown in the modal"
            rows={2}
            className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />
          <textarea
            value={form.content}
            onChange={e => setForm(f => ({ ...f, content: e.target.value }))}
            placeholder="Full body content"
            rows={4}
            className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
          />

          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="text-xs" style={{ color: 'var(--text-muted)' }}>Category</label>
              <Select
                value={form.category}
                onChange={e => setForm(f => ({ ...f, category: e.target.value as ProductUpdateCategory }))}
                className="mt-1"
              >
                {CATEGORIES.map(c => <option key={c} value={c}>{CATEGORY_LABELS[c]}</option>)}
              </Select>
            </div>
            <div>
              <label className="text-xs" style={{ color: 'var(--text-muted)' }}>Audience</label>
              <Select
                value={form.audience}
                onChange={e => setForm(f => ({ ...f, audience: e.target.value as ProductUpdateAudience }))}
                className="mt-1"
              >
                {AUDIENCES.map(a => <option key={a} value={a}>{AUDIENCE_LABELS[a]}</option>)}
              </Select>
            </div>
            <div>
              <label className="text-xs" style={{ color: 'var(--text-muted)' }}>Status</label>
              <Select
                value={form.status}
                onChange={e => setForm(f => ({ ...f, status: e.target.value as ProductUpdateStatus }))}
                className="mt-1"
              >
                {STATUSES.map(s => <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>)}
              </Select>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <input
              value={form.cta_label}
              onChange={e => setForm(f => ({ ...f, cta_label: e.target.value }))}
              placeholder="Optional CTA label (e.g. Explore Drawings)"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            <input
              value={form.cta_url}
              onChange={e => setForm(f => ({ ...f, cta_url: e.target.value }))}
              placeholder="Optional CTA link (internal path or https:// URL)"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          <button
            onClick={() => setShowPreview(v => !v)}
            className="flex items-center gap-1.5 text-xs font-medium"
            style={{ color: 'var(--text-secondary)' }}
          >
            {showPreview ? <EyeOff size={13} /> : <Eye size={13} />}
            {showPreview ? 'Hide preview' : 'Preview how this will appear'}
          </button>
          {showPreview && (
            <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              <ProductUpdateContent
                update={{
                  id: editing?.id ?? 0,
                  title: form.title || 'Untitled update',
                  summary: form.summary,
                  content: form.content,
                  category: form.category,
                  cta_label: form.cta_label || null,
                  cta_url: form.cta_url || null,
                  published_at: null,
                }}
              />
            </div>
          )}

          <div className="flex justify-end">
            <button
              onClick={() => saveMutation.mutate()}
              disabled={!form.title.trim() || !form.summary.trim() || !form.content.trim() || saveMutation.isPending}
              className="px-4 py-2 rounded-lg text-xs font-medium disabled:opacity-50"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {saveMutation.isPending ? 'Saving…' : 'Save'}
            </button>
          </div>
        </div>
      )}

      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {isLoading ? (
          <div className="p-5 space-y-2">
            {[...Array(3)].map((_, i) => <div key={i} className="h-12 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
          </div>
        ) : !data?.length ? (
          <div className="px-5 py-10 text-center">
            <Sparkles size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No Product Updates yet.</p>
          </div>
        ) : (
          data.map(u => {
            const style = STATUS_STYLES[u.status];
            return (
              <div key={u.id} className="flex items-center justify-between gap-3 px-5 py-3.5" style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="px-2 py-0.5 rounded-full text-[11px] font-medium" style={{ backgroundColor: style.bg, color: style.text }}>
                      {u.status.charAt(0).toUpperCase() + u.status.slice(1)}
                    </span>
                    <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{CATEGORY_LABELS[u.category]}</span>
                    <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>· {AUDIENCE_LABELS[u.audience]}</span>
                    <span className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{u.title}</span>
                  </div>
                  <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{u.summary}</p>
                </div>
                <div className="flex items-center gap-1.5 flex-shrink-0">
                  <button onClick={() => openEdit(u)} aria-label="Edit" className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
                    <Pencil size={13} style={{ color: 'var(--text-secondary)' }} />
                  </button>
                  <button onClick={() => deleteMutation.mutate(u.id)} aria-label="Delete" className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
                    <Trash2 size={13} style={{ color: '#f87171' }} />
                  </button>
                </div>
              </div>
            );
          })
        )}
      </div>
    </div>
  );
}
