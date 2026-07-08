'use client';

/**
 * PromptContextModal — Smart, context-aware prompt modal.
 *
 * Handles:
 *  - Project selection (or auto-uses passed projectId)
 *  - Record selection based on template module
 *  - Live rendered prompt preview via POST /prompts/{id}/render
 *  - Copy Rendered Prompt (logs to backend)
 *  - Copy Raw Template (silent copy, no log)
 *  - Favorite toggle
 */

import { useState, useEffect, useCallback } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  X, Copy, Heart, Star, Tag, Check, ChevronDown, AlertTriangle,
  RefreshCw, BookOpen, Layers,
} from 'lucide-react';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import { useAuthStore } from '@/store/authStore';

// ── Types ────────────────────────────────────────────────────────────────────

interface PromptCategory {
  id: number;
  name: string;
  slug: string;
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

interface Project {
  id: number;
  name: string;
  code?: string;
  organization?: { name: string };
}

interface RecordOption {
  id: number;
  label: string;
}

export interface PromptContextModalProps {
  /** Pass either a full template object or just an ID (modal will fetch it) */
  template?: PromptTemplate;
  templateId?: number;
  /** Pre-select this project. If provided and inside a project page, skip selector. */
  projectId?: number | string | null;
  /** Lock the project (don't allow changing) */
  projectLocked?: boolean;
  /** Pre-select record */
  recordType?: string;
  recordId?: number;
  /** Filter templates dropdown by module when no template is pre-selected */
  module?: string;
  /** Use admin API routes (for /admin/prompts page) */
  adminRoute?: boolean;
  onClose: () => void;
}

// ── Module → record type mapping ─────────────────────────────────────────────

const MODULE_RECORD_TYPE: Record<string, string> = {
  'contracts':            'contract',
  'contract':             'contract',
  'commercial':           'payment_application',
  'payment applications': 'payment_application',
  'variations':           'variation',
  'variation':            'variation',
  'rfis':                 'rfi',
  'rfi':                  'rfi',
  'meetings':             'meeting',
  'meeting':              'meeting',
  'qa reports':           'qa_report',
  'qa':                   'qa_report',
  'snagging':             'snag',
  'snag':                 'snag',
  'adjudication':         'adjudication_case',
  'documents':            'document',
  'document':             'document',
};

const RECORD_TYPE_LABEL: Record<string, string> = {
  contract:            'Contract',
  payment_application: 'Payment Application',
  variation:           'Variation',
  rfi:                 'RFI',
  meeting:             'Meeting',
  qa_report:           'QA Report',
  snag:                'Snag',
  adjudication_case:   'Adjudication Case',
  document:            'Document',
};

/** Returns the API path to list records of a given type for a project */
function recordsEndpoint(projectId: number, recordType: string): string {
  const map: Record<string, string> = {
    contract:            `/projects/${projectId}/contracts`,
    payment_application: `/projects/${projectId}/payment-applications`,
    variation:           `/projects/${projectId}/variations`,
    rfi:                 `/projects/${projectId}/rfis`,
    meeting:             `/projects/${projectId}/meetings`,
    qa_report:           `/projects/${projectId}/qa-reports`,
    snag:                `/projects/${projectId}/snagging`,
    adjudication_case:   `/projects/${projectId}/adjudication-cases`,
    document:            `/projects/${projectId}/documents`,
  };
  return map[recordType] ?? '';
}

/** Build a human-readable label from a raw API record object */
function recordLabel(recordType: string, rec: Record<string, unknown>): string {
  switch (recordType) {
    case 'contract':
      return `${rec.reference_number ?? ''} ${rec.title ?? ''}`.trim() || `Contract #${rec.id}`;
    case 'payment_application':
      return `PA-${String(rec.application_number ?? '').padStart(3, '0')} ${rec.reference ?? ''}`.trim();
    case 'variation':
      return `VAR-${String(rec.variation_number ?? '').padStart(3, '0')} — ${rec.title ?? ''}`;
    case 'rfi':
      return `RFI-${String(rec.rfi_number ?? '').padStart(3, '0')} — ${rec.subject ?? ''}`;
    case 'meeting':
      return `#${rec.meeting_number} ${rec.title ?? ''}`.trim();
    case 'qa_report':
      return `QA-${String(rec.report_number ?? '').padStart(3, '0')} ${rec.title ?? ''}`.trim();
    case 'snag':
      return `SNAG-${String(rec.snag_number ?? '').padStart(3, '0')} — ${rec.title ?? ''}`;
    case 'adjudication_case':
      return `ADJ-${String(rec.case_number ?? '').padStart(3, '0')} — ${rec.title ?? ''}`;
    case 'document':
      return `${rec.reference_number ? rec.reference_number + ' — ' : ''}${rec.title ?? ''}`;
    default:
      return String(rec.title ?? rec.id);
  }
}

// ── Clipboard helper ──────────────────────────────────────────────────────────

async function copyToClipboard(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const el = document.createElement('textarea');
    el.value = text;
    Object.assign(el.style, { position: 'fixed', opacity: '0', top: '0', left: '0' });
    document.body.appendChild(el);
    el.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(el);
    return ok;
  }
}

// ── Main Component ────────────────────────────────────────────────────────────

export default function PromptContextModal({
  template: templateProp,
  templateId: templateIdProp,
  projectId: projectIdProp,
  projectLocked = false,
  recordType: recordTypeProp,
  recordId: recordIdProp,
  module: moduleProp,
  adminRoute = false,
  onClose,
}: PromptContextModalProps) {
  const { user } = useAuthStore();
  const queryClient = useQueryClient();

  // ── Local state ─────────────────────────────────────────────────────────────
  const [selectedProjectId, setSelectedProjectId] = useState<number | null>(
    projectIdProp ? Number(projectIdProp) : null
  );
  const [selectedRecordType, setSelectedRecordType] = useState<string | null>(recordTypeProp ?? null);
  const [selectedRecordId, setSelectedRecordId]     = useState<number | null>(recordIdProp ?? null);
  const [copiedRendered, setCopiedRendered] = useState(false);
  const [copiedRaw, setCopiedRaw]           = useState(false);

  // ── Derive template id ───────────────────────────────────────────────────────
  const templateId = templateProp?.id ?? templateIdProp ?? null;

  // ── Fetch template if not provided ──────────────────────────────────────────
  const { data: fetchedTemplate } = useQuery<PromptTemplate>({
    queryKey: ['prompt-template', templateId],
    queryFn: () => {
      const ep = adminRoute
        ? `/admin/prompts/templates/${templateId}`
        : `/admin/prompts/templates/${templateId}`;
      return api.get(ep).then(r => r.data);
    },
    enabled: !!templateId && !templateProp,
    staleTime: 60_000,
  });

  const template = templateProp ?? fetchedTemplate ?? null;

  // ── Derive record type from template module ──────────────────────────────────
  const derivedRecordType = selectedRecordType ?? (
    template?.module
      ? MODULE_RECORD_TYPE[template.module.toLowerCase()] ?? null
      : moduleProp
        ? MODULE_RECORD_TYPE[moduleProp.toLowerCase()] ?? null
        : null
  );

  // Sync recordType from template when template loads and no override
  useEffect(() => {
    if (!recordTypeProp && template?.module) {
      const rt = MODULE_RECORD_TYPE[template.module.toLowerCase()];
      if (rt) setSelectedRecordType(rt);
    }
  }, [template?.module, recordTypeProp]);

  // ── Fetch projects list (when no project locked/pre-set) ─────────────────────
  const { data: projectsData } = useQuery({
    queryKey: ['projects-list-for-prompt'],
    queryFn: () => api.get('/projects').then(r => r.data),
    enabled: !projectLocked && !projectIdProp,
    staleTime: 60_000,
  });
  const projects: Project[] = projectsData?.data ?? projectsData ?? [];

  // ── Fetch selected project details ──────────────────────────────────────────
  const { data: selectedProject } = useQuery<Project>({
    queryKey: ['project-detail', selectedProjectId],
    queryFn: () => api.get(`/projects/${selectedProjectId}`).then(r => r.data.data ?? r.data),
    enabled: !!selectedProjectId,
    staleTime: 60_000,
  });

  // ── Fetch records for the module ─────────────────────────────────────────────
  const recordsQueryKey = ['prompt-records', selectedProjectId, derivedRecordType];
  const { data: recordsData } = useQuery({
    queryKey: recordsQueryKey,
    queryFn: () => {
      if (!selectedProjectId || !derivedRecordType) return [];
      const ep = recordsEndpoint(selectedProjectId, derivedRecordType);
      if (!ep) return [];
      return api.get(ep).then(r => {
        const raw = r.data?.data ?? r.data;
        return Array.isArray(raw) ? raw : [];
      });
    },
    enabled: !!selectedProjectId && !!derivedRecordType,
    staleTime: 30_000,
  });

  const records: RecordOption[] = (recordsData ?? []).map((rec: Record<string, unknown>) => ({
    id:    Number(rec.id),
    label: recordLabel(derivedRecordType ?? '', rec),
  }));

  // ── Render prompt via backend ────────────────────────────────────────────────
  const renderBody: Record<string, unknown> = {};
  if (selectedProjectId)                          renderBody.project_id  = selectedProjectId;
  if (derivedRecordType && selectedRecordId) {
    renderBody.record_type = derivedRecordType;
    renderBody.record_id   = selectedRecordId;
  }

  const renderEndpoint = adminRoute
    ? `/admin/prompts/templates/${templateId}/render`
    : `/prompts/${templateId}/render`;

  const { data: renderResult, isFetching: renderLoading } = useQuery({
    queryKey: ['prompt-render', templateId, selectedProjectId, derivedRecordType, selectedRecordId],
    queryFn: () => api.post(renderEndpoint, renderBody).then(r => r.data),
    enabled: !!templateId,
    staleTime: 10_000,
  });

  const renderedPrompt: string     = renderResult?.rendered_prompt ?? template?.prompt_text ?? '';
  const missingPlaceholders: string[] = renderResult?.missing_placeholders ?? [];

  // ── Mutations ─────────────────────────────────────────────────────────────────
  const copyLogMutation = useMutation({
    mutationFn: () => {
      const ep = adminRoute
        ? `/admin/prompts/templates/${templateId}/copy`
        : `/admin/prompts/templates/${templateId}/copy`;
      return api.post(ep, {
        project_id:      selectedProjectId,
        rendered_prompt: renderedPrompt,
      });
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['prompt-templates'] });
    },
  });

  const favoriteMutation = useMutation({
    mutationFn: (isFavorited: boolean) => {
      const ep = `/admin/prompts/templates/${templateId}/favorite`;
      return isFavorited ? api.delete(ep) : api.post(ep);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['prompt-templates'] });
      queryClient.invalidateQueries({ queryKey: ['prompt-template', templateId] });
      queryClient.invalidateQueries({ queryKey: ['prompt-favorites'] });
    },
  });

  // ── Copy handlers ────────────────────────────────────────────────────────────
  const handleCopyRendered = useCallback(async () => {
    const ok = await copyToClipboard(renderedPrompt);
    if (ok) {
      setCopiedRendered(true);
      setTimeout(() => setCopiedRendered(false), 2500);
      copyLogMutation.mutate();
      const msg = selectedProjectId
        ? 'Prompt copied with project context'
        : 'Prompt copied to clipboard';
      toast.success(msg);
    } else {
      toast.error('Copy failed — please copy manually.');
    }
  }, [renderedPrompt, selectedProjectId, copyLogMutation]);

  const handleCopyRaw = useCallback(async () => {
    const raw = template?.prompt_text ?? '';
    const ok  = await copyToClipboard(raw);
    if (ok) {
      setCopiedRaw(true);
      setTimeout(() => setCopiedRaw(false), 2500);
      toast.success('Raw prompt template copied');
    } else {
      toast.error('Copy failed.');
    }
  }, [template]);

  const handleFavorite = () => {
    if (!template) return;
    favoriteMutation.mutate(!!template.is_favorited);
    toast.success(template.is_favorited ? 'Removed from favorites' : 'Added to favorites');
  };

  // ── Style helpers ────────────────────────────────────────────────────────────
  const inputStyle = {
    backgroundColor: 'var(--bg-elevated)',
    border:          '1px solid var(--border)',
    color:           'var(--text-primary)',
  };

  const selectStyle = {
    ...inputStyle,
    appearance: 'none' as const,
    cursor:     'pointer',
  };

  // ── Loading / not found state ────────────────────────────────────────────────
  if (!template && templateId) {
    return (
      <div
        className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm"
        style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}
      >
        <div
          className="rounded-2xl p-8 flex items-center gap-3 ss-animate-in"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        >
          <RefreshCw size={16} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
          <span className="text-sm" style={{ color: 'var(--text-secondary)' }}>Loading prompt…</span>
        </div>
      </div>
    );
  }

  if (!template) return null;

  // ── Render ───────────────────────────────────────────────────────────────────
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
        {/* ── Header ── */}
        <div
          className="flex items-start justify-between px-6 py-5 flex-shrink-0"
          style={{ borderBottom: '1px solid var(--border)' }}
        >
          <div className="flex-1 min-w-0 pr-4">
            <div className="flex items-center gap-2 flex-wrap mb-1.5">
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
              {template.module && (
                <span
                  className="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                >
                  <Layers size={9} /> {template.module}
                </span>
              )}
            </div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
              {template.title}
            </h2>
            {template.description && (
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{template.description}</p>
            )}
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)] flex-shrink-0"
          >
            <X size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {/* ── Scrollable body ── */}
        <div className="flex-1 overflow-y-auto">

          {/* ── Context resolver ── */}
          <div
            className="px-6 py-4"
            style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-elevated)' }}
          >
            <p className="text-[11px] font-semibold uppercase tracking-wider mb-3" style={{ color: 'var(--text-muted)' }}>
              Context
            </p>

            {/* Project selector or locked display */}
            {projectLocked && projectIdProp ? (
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>
                    Using current project
                  </p>
                  <p className="text-sm font-semibold mt-0.5" style={{ color: 'var(--text-primary)' }}>
                    {selectedProject?.name ?? `Project #${projectIdProp}`}
                  </p>
                  {selectedProject?.organization?.name && (
                    <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      {selectedProject.organization.name}
                    </p>
                  )}
                </div>
                <span
                  className="text-[10px] px-2 py-1 rounded-full"
                  style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)', border: '1px solid var(--gold-30)' }}
                >
                  Project context auto-filled
                </span>
              </div>
            ) : (
              <div>
                {selectedProjectId && selectedProject ? (
                  <div className="flex items-center justify-between mb-2">
                    <div>
                      <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>Project</p>
                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                        {selectedProject.name}
                      </p>
                      {selectedProject.organization?.name && (
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                          {selectedProject.organization.name}
                        </p>
                      )}
                    </div>
                    <button
                      onClick={() => { setSelectedProjectId(null); setSelectedRecordId(null); }}
                      className="text-xs px-3 py-1.5 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
                      style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
                    >
                      Change Project
                    </button>
                  </div>
                ) : (
                  <div>
                    <label className="block text-xs mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                      Project (optional — fills placeholders)
                    </label>
                    <div className="relative">
                      <select
                        value={selectedProjectId ?? ''}
                        onChange={e => {
                          setSelectedProjectId(e.target.value ? Number(e.target.value) : null);
                          setSelectedRecordId(null);
                        }}
                        className="w-full px-3 pr-8 py-2 rounded-lg text-sm outline-none"
                        style={selectStyle}
                      >
                        <option value="">No project selected — use fallback placeholders</option>
                        {projects.map(p => (
                          <option key={p.id} value={p.id}>
                            {p.name}{p.code ? ` (${p.code})` : ''}
                          </option>
                        ))}
                      </select>
                      <ChevronDown size={12} className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
                    </div>
                  </div>
                )}
              </div>
            )}

            {/* Record selector */}
            {derivedRecordType && selectedProjectId && (
              <div className="mt-3 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
                <label className="block text-xs mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                  {RECORD_TYPE_LABEL[derivedRecordType] ?? 'Record'} (optional)
                </label>
                {recordIdProp && !selectedRecordId ? (
                  // Pre-selected from button click — show loaded record
                  <div className="flex items-center justify-between">
                    <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                      {records.find(r => r.id === recordIdProp)?.label ?? `Record #${recordIdProp}`}
                    </p>
                  </div>
                ) : (
                  <div className="relative">
                    <select
                      value={selectedRecordId ?? ''}
                      onChange={e => setSelectedRecordId(e.target.value ? Number(e.target.value) : null)}
                      className="w-full px-3 pr-8 py-2 rounded-lg text-sm outline-none"
                      style={selectStyle}
                    >
                      <option value="">No {RECORD_TYPE_LABEL[derivedRecordType]?.toLowerCase() ?? 'record'} selected</option>
                      {records.map(r => (
                        <option key={r.id} value={r.id}>{r.label}</option>
                      ))}
                    </select>
                    <ChevronDown size={12} className="absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
                  </div>
                )}
              </div>
            )}
          </div>

          {/* ── Missing placeholders warning ── */}
          {missingPlaceholders.length > 0 && selectedProjectId && (
            <div
              className="px-6 py-3 flex items-start gap-2"
              style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'rgba(234,179,8,0.05)' }}
            >
              <AlertTriangle size={13} className="flex-shrink-0 mt-0.5" style={{ color: '#facc15' }} />
              <div>
                <p className="text-xs font-medium" style={{ color: '#facc15' }}>
                  {missingPlaceholders.length} placeholder{missingPlaceholders.length !== 1 ? 's' : ''} could not be filled
                </p>
                <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  {missingPlaceholders.map(p => `{{${p}}}`).join(', ')} — shown as [INSERT …] in the prompt
                </p>
              </div>
            </div>
          )}

          {/* ── Rendered prompt ── */}
          <div className="px-6 py-5">
            <div className="flex items-center justify-between mb-2">
              <p className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>
                {renderLoading ? 'Rendering…' : 'Prompt Preview'}
              </p>
              {selectedProjectId && !renderLoading && (
                <span
                  className="text-[10px] px-2 py-0.5 rounded-full"
                  style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
                >
                  Context filled
                </span>
              )}
            </div>
            <pre
              className="text-xs leading-relaxed whitespace-pre-wrap font-mono rounded-xl p-4 min-h-[140px]"
              style={{
                backgroundColor: 'var(--bg-elevated)',
                color:           renderLoading ? 'var(--text-muted)' : 'var(--text-primary)',
                border:          '1px solid var(--border)',
                transition:      'color 0.2s',
              }}
            >
              {renderLoading ? 'Rendering prompt…' : renderedPrompt}
            </pre>
          </div>
        </div>

        {/* ── Footer actions ── */}
        <div
          className="flex items-center justify-between px-6 py-4 flex-shrink-0 gap-3"
          style={{ borderTop: '1px solid var(--border)' }}
        >
          {/* Left: Favorite */}
          <button
            onClick={handleFavorite}
            className="flex items-center gap-2 text-sm px-3 py-2 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
            style={{
              color:  template.is_favorited ? '#e74c3c' : 'var(--text-secondary)',
              border: '1px solid var(--border)',
            }}
          >
            <Heart size={14} style={{ fill: template.is_favorited ? '#e74c3c' : 'none' }} />
            {template.is_favorited ? 'Unfavorite' : 'Favorite'}
          </button>

          {/* Right: Copy Raw + Copy Rendered */}
          <div className="flex items-center gap-2">
            <button
              onClick={handleCopyRaw}
              className="flex items-center gap-1.5 text-xs px-3 py-2 rounded-lg transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
            >
              {copiedRaw ? <Check size={12} style={{ color: '#4ade80' }} /> : <BookOpen size={12} />}
              Copy Raw Template
            </button>

            <button
              onClick={handleCopyRendered}
              disabled={renderLoading}
              className="flex items-center gap-1.5 text-sm px-4 py-2 rounded-lg font-medium transition-opacity hover:opacity-90 disabled:opacity-50 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {copiedRendered ? <Check size={14} /> : <Copy size={14} />}
              {copiedRendered ? 'Copied!' : 'Copy Rendered Prompt'}
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
