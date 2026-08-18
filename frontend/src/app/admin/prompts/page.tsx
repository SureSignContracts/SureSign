'use client';

import { useState, useMemo, useCallback, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  BookOpen, Search, Copy, Heart, Star, Plus, X,
  Edit, Trash2, Tag, Layers,
} from 'lucide-react';
import api from '@/lib/api';
import { useAuthStore } from '@/store/authStore';
import toast from '@/lib/toast';
import PromptContextModal from '@/components/prompts/PromptContextModal';
import PaginationBar from '@/components/ui/PaginationBar';
import Select from '@/components/ui/Select';
import { getErrorMessage } from '@/lib/getErrorMessage';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

// ── Types ────────────────────────────────────────────────────────────────────

interface PromptCategory {
  id: number;
  name: string;
  slug: string;
  icon?: string;
  active_templates_count?: number;
}

interface PromptTemplate {
  id: number;
  title: string;
  description?: string;
  prompt_text: string;
  module?: string;
  use_case?: string;
  variables?: string[];
  is_featured: boolean;
  copied_count: number;
  is_favorited?: boolean;
  category?: PromptCategory;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

async function copyToClipboard(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    // Fallback
    const el = document.createElement('textarea');
    el.value = text;
    el.style.position = 'fixed';
    el.style.opacity = '0';
    document.body.appendChild(el);
    el.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(el);
    return ok;
  }
}

// ── Prompt Card ───────────────────────────────────────────────────────────────

function PromptCard({
  template,
  onCopy,
  onFavorite,
  onView,
  onEdit,
  onDelete,
  isSuperAdmin,
  index = 0,
}: {
  template: PromptTemplate;
  onCopy: (t: PromptTemplate) => void;
  onFavorite: (t: PromptTemplate) => void;
  onView: (t: PromptTemplate) => void;
  onEdit: (t: PromptTemplate) => void;
  onDelete: (t: PromptTemplate) => void;
  isSuperAdmin: boolean;
  index?: number;
}) {
  const preview = template.prompt_text.slice(0, 140).trim() + (template.prompt_text.length > 140 ? '…' : '');

  return (
    <div
      className="rounded-2xl p-5 flex flex-col gap-3 transition-all shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] ss-animate-in"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${Math.min(index * 45, 360)}ms` }}
    >
      {/* Header row */}
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap mb-1">
            {template.is_featured && (
              <span
                className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full"
                style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
              >
                <Star size={9} /> Featured
              </span>
            )}
            {template.category && (
              <span
                className="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
              >
                <Tag size={9} /> {template.category.name}
              </span>
            )}
            {template.module && template.module !== template.category?.name && (
              <span
                className="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
              >
                <Layers size={9} /> {template.module}
              </span>
            )}
          </div>
          <h3 className="text-sm font-semibold leading-snug" style={{ color: 'var(--text-primary)' }}>
            {template.title}
          </h3>
          {template.use_case && (
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{template.use_case}</p>
          )}
        </div>

        {/* Favorite button */}
        <button
          onClick={() => onFavorite(template)}
          className="flex-shrink-0 p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
          title={template.is_favorited ? 'Remove favorite' : 'Add to favorites'}
        >
          <Heart
            size={15}
            style={{
              color: template.is_favorited ? '#e74c3c' : 'var(--text-muted)',
              fill: template.is_favorited ? '#e74c3c' : 'none',
            }}
          />
        </button>
      </div>

      {/* Description */}
      {template.description && (
        <p className="text-xs leading-relaxed" style={{ color: 'var(--text-secondary)' }}>
          {template.description}
        </p>
      )}

      {/* Prompt preview */}
      <div
        className="rounded-lg p-3 text-xs font-mono leading-relaxed"
        style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
      >
        {preview}
      </div>

      {/* Footer row */}
      <div className="flex items-center justify-between pt-1">
        <span className="text-[11px] tabular-nums" style={{ color: 'var(--text-muted)' }}>
          {template.copied_count > 0 ? `Copied ${template.copied_count}×` : ''}
        </span>

        <div className="flex items-center gap-2">
          {isSuperAdmin && (
            <>
              <button
                onClick={() => onEdit(template)}
                className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                title="Edit prompt"
              >
                <Edit size={13} style={{ color: 'var(--text-muted)' }} />
              </button>
              <button
                onClick={() => onDelete(template)}
                className="p-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                title="Delete prompt"
              >
                <Trash2 size={13} style={{ color: 'var(--text-muted)' }} />
              </button>
            </>
          )}
          <button
            onClick={() => onView(template)}
            className="text-xs px-3 py-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
            style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
          >
            View
          </button>
          <button
            onClick={() => onCopy(template)}
            className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            <Copy size={12} />
            Copy
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Detail Modal (now delegates to PromptContextModal) ──────────────────────
// ── Create/Edit Prompt Modal ──────────────────────────────────────────────────

function PromptFormModal({
  template,
  categories,
  onClose,
  onSaved,
}: {
  template?: PromptTemplate | null;
  categories: PromptCategory[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const isEdit = !!template;
  const [form, setForm] = useState({
    title:              template?.title ?? '',
    prompt_category_id: template?.category?.id?.toString() ?? '',
    module:             template?.module ?? '',
    use_case:           template?.use_case ?? '',
    description:        template?.description ?? '',
    prompt_text:        template?.prompt_text ?? '',
    is_featured:        template?.is_featured ?? false,
    is_active:          true,
  });
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = {
        ...form,
        prompt_category_id: form.prompt_category_id ? parseInt(form.prompt_category_id) : null,
      };
      if (isEdit) {
        await api.put(`/admin/prompts/templates/${template!.id}`, payload);
        toast.success('Prompt updated.');
      } else {
        await api.post('/admin/prompts/templates', payload);
        toast.success('Prompt created.');
      }
      onSaved();
      onClose();
    } catch (err) {
      toast.error(getErrorMessage(err, 'Failed to save prompt.'));
    } finally {
      setSaving(false);
    }
  };

  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    border: '1px solid var(--border)',
    color: 'var(--text-primary)',
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ backgroundColor: 'rgba(0,0,0,0.5)', backdropFilter: 'blur(4px)' }}
      onClick={e => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        className="w-full max-w-2xl max-h-[90vh] flex flex-col rounded-2xl overflow-hidden ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
      >
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {isEdit ? 'Edit Prompt' : 'Create Prompt'}
          </h2>
          <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto p-5 space-y-4">
          {/* Title */}
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Title *</label>
            <input
              required
              value={form.title}
              onChange={e => setForm(f => ({ ...f, title: e.target.value }))}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={inputStyle}
              placeholder="e.g. Summarize Construction Contract"
            />
          </div>

          {/* Category + Module row */}
          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Category</label>
              <Select
                value={form.prompt_category_id}
                onChange={e => setForm(f => ({ ...f, prompt_category_id: e.target.value }))}
                className="w-full"
              >
                <option value="">No category</option>
                {categories.map(c => (
                  <option key={c.id} value={c.id}>{c.name}</option>
                ))}
              </Select>
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Module</label>
              <input
                value={form.module}
                onChange={e => setForm(f => ({ ...f, module: e.target.value }))}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={inputStyle}
                placeholder="e.g. Contracts"
              />
            </div>
          </div>

          {/* Use case */}
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Use Case</label>
            <input
              value={form.use_case}
              onChange={e => setForm(f => ({ ...f, use_case: e.target.value }))}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={inputStyle}
              placeholder="e.g. Contract Review"
            />
          </div>

          {/* Description */}
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Description</label>
            <textarea
              value={form.description}
              onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
              rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={inputStyle}
              placeholder="Short description of what this prompt does"
            />
          </div>

          {/* Prompt text */}
          <div>
            <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>Prompt Text *</label>
            <textarea
              required
              value={form.prompt_text}
              onChange={e => setForm(f => ({ ...f, prompt_text: e.target.value }))}
              rows={10}
              className="w-full px-3 py-2 rounded-lg text-xs font-mono outline-none resize-y"
              style={inputStyle}
              placeholder="Write the full prompt here. Use {{variable_name}} for placeholders."
            />
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
              Use {'{{project_name}}'}, {'{{company_name}}'}, {'{{contract_value}}'} etc. for auto-fill placeholders.
            </p>
          </div>

          {/* Toggles */}
          <div className="flex items-center gap-6">
            <label className="flex items-center gap-2 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={form.is_featured}
                onChange={e => setForm(f => ({ ...f, is_featured: e.target.checked }))}
                className="w-4 h-4 rounded"
              />
              <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>Featured</span>
            </label>
            <label className="flex items-center gap-2 cursor-pointer select-none">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={e => setForm(f => ({ ...f, is_active: e.target.checked }))}
                className="w-4 h-4 rounded"
              />
              <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>Active</span>
            </label>
          </div>
        </form>

        <div
          className="flex items-center justify-end gap-3 p-5"
          style={{ borderTop: '1px solid var(--border)' }}
        >
          <button
            type="button"
            onClick={onClose}
            className="px-4 py-2 text-sm rounded-lg"
            style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
          >
            Cancel
          </button>
          <button
            onClick={handleSubmit as any}
            disabled={saving}
            className="px-4 py-2 text-sm rounded-lg font-medium transition-opacity hover:opacity-90 disabled:opacity-50 active:scale-[0.98]"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {saving ? 'Saving…' : isEdit ? 'Save Changes' : 'Create Prompt'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Main Page ─────────────────────────────────────────────────────────────────

export default function AdminPromptsPage() {
  const { user } = useAuthStore();
  const queryClient = useQueryClient();

  const isSuperAdmin = user?.roles?.includes('Super Admin') ?? false;

  const [search, setSearch]               = useState('');
  const [debouncedSearch, setDebounced]   = useState('');
  const [activeCategory, setActiveCategory] = useState<string>('');
  const [activeModule, setActiveModule]   = useState('');
  const [featuredOnly, setFeaturedOnly]   = useState(false);
  const [favoritesOnly, setFavoritesOnly] = useState(false);
  const [page, setPage]                   = useState(1);
  const [perPage, setPerPage]             = useState(25);
  const [viewTemplate, setViewTemplate]   = useState<PromptTemplate | null>(null);
  const [editTemplate, setEditTemplate]   = useState<PromptTemplate | null | undefined>(undefined);
  const [copiedId, setCopiedId]           = useState<number | null>(null);

  // Debounce search
  useEffect(() => {
    const t = setTimeout(() => { setDebounced(search); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [search]);

  // Fetch categories
  const { data: categories = [] } = useQuery<PromptCategory[]>({
    queryKey: ['prompt-categories'],
    queryFn: () => api.get('/admin/prompts/categories').then(r => r.data),
  });

  // Fetch templates (server-side pagination)
  const { data: templateData, isLoading } = useQuery({
    queryKey: ['prompt-templates', activeCategory, activeModule, debouncedSearch, featuredOnly, page, perPage],
    queryFn: () => {
      const params: Record<string, any> = { page, per_page: perPage };
      if (activeCategory)   params.category = activeCategory;
      if (activeModule)     params.module   = activeModule;
      if (debouncedSearch)  params.search   = debouncedSearch;
      if (featuredOnly)     params.featured = 1;
      return api.get('/admin/prompts/templates', { params }).then(r => r.data);
    },
    placeholderData: (prev: any) => prev,
  });

  // Fetch favorites
  const { data: favorites = [] } = useQuery<PromptTemplate[]>({
    queryKey: ['prompt-favorites'],
    queryFn: () => api.get('/admin/prompts/favorites').then(r => r.data),
  });

  const templates: PromptTemplate[] = useMemo(() => templateData?.data ?? [], [templateData]);
  const promptTotal: number  = templateData?.total    ?? 0;
  const promptLastPage: number = templateData?.last_page ?? 1;

  const displayedTemplates = useMemo(() => {
    if (favoritesOnly) return favorites;
    return templates;
  }, [templates, favorites, favoritesOnly]);

  // Copy mutation
  const copyMutation = useMutation({
    mutationFn: (templateId: number) =>
      api.post(`/admin/prompts/templates/${templateId}/copy`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['prompt-templates'] });
    },
  });

  // Favorite mutation
  const favoriteMutation = useMutation({
    mutationFn: ({ id, favorited }: { id: number; favorited: boolean }) =>
      favorited
        ? api.delete(`/admin/prompts/templates/${id}/favorite`)
        : api.post(`/admin/prompts/templates/${id}/favorite`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['prompt-templates'] });
      queryClient.invalidateQueries({ queryKey: ['prompt-favorites'] });
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/prompts/templates/${id}`),
    onSuccess: () => {
      toast.success('Prompt deleted.');
      queryClient.invalidateQueries({ queryKey: ['prompt-templates'] });
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete prompt.')),
  });

  const handleCopy = useCallback(async (template: PromptTemplate) => {
    const ok = await copyToClipboard(template.prompt_text);
    if (ok) {
      toast.success('Prompt copied to clipboard');
      setCopiedId(template.id);
      setTimeout(() => setCopiedId(null), 2000);
      copyMutation.mutate(template.id);
    } else {
      toast.error('Copy failed — please copy the text manually.');
    }
  }, [copyMutation]);

  const handleFavorite = useCallback((template: PromptTemplate) => {
    favoriteMutation.mutate({ id: template.id, favorited: !!template.is_favorited });
    if (template.is_favorited) {
      toast.success('Removed from favorites');
    } else {
      toast.success('Added to favorites');
    }
  }, [favoriteMutation]);

  const handleDelete = useCallback((template: PromptTemplate) => {
    if (!confirm(`Delete "${template.title}"? This cannot be undone.`)) return;
    deleteMutation.mutate(template.id);
  }, [deleteMutation]);

  const modules = useMemo(() => {
    const seen = new Set<string>();
    templates.forEach(t => { if (t.module) seen.add(t.module); });
    return Array.from(seen).sort();
  }, [templates]);

  return (
    <div className="flex h-full" style={{ backgroundColor: 'var(--bg-base)' }}>
      {/* Left sidebar — categories */}
      <aside
        className="w-56 flex-shrink-0 flex flex-col overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-surface)', borderRight: '1px solid var(--border)' }}
      >
        <div className="px-4 py-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <BookOpen size={15} style={{ color: 'var(--gold)' }} />
            <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Categories</span>
          </div>
        </div>

        <nav className="flex-1 py-3 px-2 space-y-0.5">
          {/* All */}
          <button
            onClick={() => { setActiveCategory(''); setFavoritesOnly(false); setPage(1); }}
            className={`w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all ${
              !activeCategory && !favoritesOnly ? 'font-medium' : 'hover:bg-[var(--bg-hover)]'
            }`}
            style={!activeCategory && !favoritesOnly
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <span>All Prompts</span>
            <span className="text-xs opacity-70">
              {categories.reduce((a, c) => a + (c.active_templates_count ?? 0), 0)}
            </span>
          </button>

          {/* Featured */}
          <button
            onClick={() => { setFeaturedOnly(f => !f); setFavoritesOnly(false); setActiveCategory(''); setPage(1); }}
            className={`w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-all ${
              featuredOnly ? 'font-medium' : 'hover:bg-[var(--bg-hover)]'
            }`}
            style={featuredOnly
              ? { backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <Star size={13} />
            <span>Featured</span>
          </button>

          {/* Favorites */}
          <button
            onClick={() => { setFavoritesOnly(f => !f); setActiveCategory(''); setFeaturedOnly(false); setPage(1); }}
            className={`w-full flex items-center gap-2 px-3 py-2 rounded-lg text-sm transition-all ${
              favoritesOnly ? 'font-medium' : 'hover:bg-[var(--bg-hover)]'
            }`}
            style={favoritesOnly
              ? { backgroundColor: 'rgba(231,76,60,0.1)', color: '#e74c3c' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <Heart size={13} />
            <span>Favorites</span>
            {favorites.length > 0 && (
              <span className="ml-auto text-xs opacity-70">{favorites.length}</span>
            )}
          </button>

          {/* Divider */}
          <div className="pt-2 pb-1 px-3">
            <div className="text-[10px] font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
              By Category
            </div>
          </div>

          {categories.map(cat => (
            <button
              key={cat.id}
              onClick={() => { setActiveCategory(cat.slug); setFavoritesOnly(false); setFeaturedOnly(false); setPage(1); }}
              className={`w-full flex items-center justify-between px-3 py-2 rounded-lg text-sm transition-all ${
                activeCategory === cat.slug ? 'font-medium' : 'hover:bg-[var(--bg-hover)]'
              }`}
              style={activeCategory === cat.slug
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                : { color: 'var(--text-secondary)' }
              }
            >
              <span className="truncate">{cat.name}</span>
              {cat.active_templates_count != null && cat.active_templates_count > 0 && (
                <span className="text-xs opacity-70 flex-shrink-0 ml-1">{cat.active_templates_count}</span>
              )}
            </button>
          ))}
        </nav>
      </aside>

      {/* Main content */}
      <main className="flex-1 flex flex-col overflow-hidden">
        <div className="flex-shrink-0 p-5 lg:p-6">
          <PlatformPageHero
            eyebrow="Knowledge systems"
            title="Prompt Library"
            description="Curated construction workflows for contract review, correspondence, adjudication and document drafting."
            loading={isLoading}
            metrics={[
              { label: 'Prompts', value: promptTotal, detail: 'available workflows', icon: BookOpen },
              { label: 'Categories', value: categories.length, detail: 'areas of practice', icon: Tag },
              { label: 'Favourites', value: favorites.length, detail: 'saved by you', icon: Heart },
            ]}
            action={isSuperAdmin ? (
            <button
              onClick={() => setEditTemplate(null)}
              className="flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-sm font-semibold text-[#18211d] transition-colors hover:bg-[#b3efc6] active:scale-[0.98]"
            >
              <Plus size={15} /> New prompt
            </button>
            ) : undefined}
          />
        </div>

        {/* Toolbar */}
        <div
          className="flex items-center gap-3 px-6 py-3 flex-shrink-0 flex-wrap"
          style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}
        >
          {/* Search */}
          <div className="relative flex-1 min-w-[200px] max-w-sm">
            <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder="Search prompts…"
              className="w-full pl-8 pr-3 py-1.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          {/* Module filter */}
          {modules.length > 0 && (
            <Select
              value={activeModule}
              onChange={e => setActiveModule(e.target.value)}
              size="sm"
            >
              <option value="">All modules</option>
              {modules.map(m => <option key={m} value={m}>{m}</option>)}
            </Select>
          )}

          {/* Result count */}
          <div className="flex items-center gap-2 ml-auto">
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {favoritesOnly ? displayedTemplates.length : promptTotal} prompt{(favoritesOnly ? displayedTemplates.length : promptTotal) !== 1 ? 's' : ''}
            </span>
          </div>
        </div>

        {/* Grid */}
        <div className="flex-1 overflow-y-auto p-6">
          {isLoading ? (
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              {[...Array(6)].map((_, i) => (
                <div key={i} className="h-56 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
              ))}
            </div>
          ) : displayedTemplates.length === 0 ? (
            <div
              className="rounded-2xl p-16 text-center"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
            >
              <BookOpen size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
              <p className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>No prompts found</p>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
                {search ? 'Try a different search term.' : 'No prompts in this category yet.'}
              </p>
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
              {displayedTemplates.map((t, i) => (
                <PromptCard
                  key={t.id}
                  index={i}
                  template={copiedId === t.id ? { ...t, title: t.title } : t}
                  onCopy={handleCopy}
                  onFavorite={handleFavorite}
                  onView={setViewTemplate}
                  onEdit={setEditTemplate}
                  onDelete={handleDelete}
                  isSuperAdmin={isSuperAdmin}
                />
              ))}
            </div>
          )}

          {/* Pagination */}
          {!favoritesOnly && (
            <div className="pt-2 pb-4 px-6">
              <PaginationBar
                page={page}
                lastPage={promptLastPage}
                total={promptTotal}
                perPage={perPage}
                onPage={setPage}
                onPerPage={n => { setPerPage(n); setPage(1); }}
              />
            </div>
          )}
        </div>
      </main>

      {/* Detail modal — smart context-aware */}
      {viewTemplate && (
        <PromptContextModal
          template={viewTemplate}
          adminRoute={true}
          onClose={() => setViewTemplate(null)}
        />
      )}

      {/* Create/Edit modal */}
      {editTemplate !== undefined && isSuperAdmin && (
        <PromptFormModal
          template={editTemplate}
          categories={categories}
          onClose={() => setEditTemplate(undefined)}
          onSaved={() => queryClient.invalidateQueries({ queryKey: ['prompt-templates'] })}
        />
      )}
    </div>
  );
}
