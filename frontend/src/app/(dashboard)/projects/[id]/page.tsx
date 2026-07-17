'use client';

import { useState, useRef, useCallback } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import { parseDateOnly } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import {
  ArrowLeft, Building2, Calendar, DollarSign, FileText, Users,
  FolderOpen, Folder, Upload, Download, Trash2, Search,
  File, FileImage, ChevronRight, AlertCircle, CheckCircle,
  Clock, Archive, Shield, Banknote, ClipboardList, RefreshCw,
  BookOpen, Copy, X, Tag, ChevronDown,
} from 'lucide-react';
import PromptContextModal from '@/components/prompts/PromptContextModal';
import ProjectDocumentsExplorer from '@/components/documents/ProjectDocumentsExplorer';

// ─── Folder meta ──────────────────────────────────────────────────────────────
const FOLDER_META: Record<string, { icon: any; color: string }> = {
  '01_Contract_Documents':           { icon: FileText,      color: '#3b82f6' },
  '02_Contract_Summary':             { icon: ClipboardList, color: '#8b5cf6' },
  '03_Tender_Breakdown':             { icon: Banknote,      color: '#10b981' },
  '04_RAMS_Method_Statements':       { icon: AlertCircle,   color: '#f59e0b' },
  '05_Risk_Assessments':             { icon: Shield,        color: '#ef4444' },
  '06_Monthly_Payment_Applications': { icon: Banknote,      color: '#10b981' },
  '07_Main_Contractor_Valuations':   { icon: DollarSign,    color: '#10b981' },
  '08_QA_Reports':                   { icon: CheckCircle,   color: '#3b82f6' },
  '09_Letters':                      { icon: FileText,      color: '#6366f1' },
  '10_Notices':                      { icon: AlertCircle,   color: '#f59e0b' },
  '11_Snagging':                     { icon: Clock,         color: '#ef4444' },
  '12_Operation_Maintenance_Manual': { icon: Archive,       color: '#8b5cf6' },
  '13_Collateral_Warranties':        { icon: CheckCircle,   color: '#10b981' },
  '14_Other_Warranties':             { icon: CheckCircle,   color: '#6366f1' },
};

function fileIcon(mime: string) {
  if (mime?.includes('image')) return <FileImage size={16} className="text-purple-500" />;
  if (mime?.includes('pdf'))   return <FileText  size={16} className="text-red-500" />;
  if (mime?.includes('sheet') || mime?.includes('excel')) return <FileText size={16} className="text-green-500" />;
  if (mime?.includes('word'))  return <FileText  size={16} className="text-blue-500" />;
  return <File size={16} style={{ color: 'var(--text-muted)' }} />;
}
function fmtSize(bytes: number) {
  if (!bytes) return '—';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / 1024 / 1024).toFixed(1) + ' MB';
}
// Used for both DATE-only fields (project.start_date/end_date) and genuine
// DATETIME instants (file.created_at) — same distinction as lib/utils.ts's
// formatDate(), kept as its own function here only because this page's
// visual format ('numeric' day, no leading zero) differs from that one.
const DATE_ONLY_RE = /^\d{4}-\d{2}-\d{2}$/;
function fmtDate(d: string) {
  const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' };
  if (DATE_ONLY_RE.test(d)) {
    return parseDateOnly(d).toLocaleDateString('en-AU', opts);
  }
  return new Date(d).toLocaleDateString('en-AU', { ...opts, timeZone: useAuthStore.getState().user?.effective_timezone });
}

// ─── Folder grid ──────────────────────────────────────────────────────────────
function FolderGrid({ folders, onSelect }: { folders: any[]; onSelect: (f: any) => void }) {
  return (
    <div className="grid gap-3" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(180px, 1fr))' }}>
      {folders.map((folder) => {
        const meta = FOLDER_META[folder.path] ?? { icon: Folder, color: 'var(--text-muted)' };
        const Icon = meta.icon;
        return (
          <button key={folder.id} onClick={() => onSelect(folder)}
            className="group flex flex-col gap-3 p-4 rounded-xl text-left transition-all hover:scale-[1.02]"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
            onMouseEnter={e => (e.currentTarget.style.borderColor = meta.color)}
            onMouseLeave={e => (e.currentTarget.style.borderColor = 'var(--border)')}>
            <div className="flex items-center justify-between">
              <div className="w-9 h-9 rounded-lg flex items-center justify-center"
                   style={{ backgroundColor: meta.color + '18' }}>
                <Icon size={18} style={{ color: meta.color }} />
              </div>
              <span className="text-xs px-2 py-0.5 rounded-full"
                    style={{ backgroundColor: 'var(--bg-panel)', color: 'var(--text-muted)' }}>
                {folder.file_count ?? 0}
              </span>
            </div>
            <div>
              <p className="text-xs font-semibold leading-tight" style={{ color: 'var(--text-primary)' }}>
                {folder.name}
              </p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{folder.folder_number}</p>
            </div>
          </button>
        );
      })}
    </div>
  );
}

// ─── Folder detail ────────────────────────────────────────────────────────────
function FolderDetail({ project, folder, onBack }: { project: any; folder: any; onBack: () => void }) {
  const qc = useQueryClient();
  const fileRef = useRef<HTMLInputElement>(null);
  const [search, setSearch] = useState('');
  const [dragging, setDragging] = useState(false);

  const meta = FOLDER_META[folder.path] ?? { icon: Folder, color: 'var(--text-muted)' };
  const Icon = meta.icon;

  const { data: filesData, isLoading } = useQuery({
    queryKey: ['project-files', project.id, folder.path],
    queryFn: () =>
      api.get(`/projects/${project.id}/files`, { params: { folder: folder.path } })
         .then(r => r.data?.data ?? r.data),
  });
  const files: any[] = Array.isArray(filesData) ? filesData : (filesData?.data ?? []);
  const filtered = files.filter(f => f.original_name?.toLowerCase().includes(search.toLowerCase()));

  const uploadMutation = useMutation({
    mutationFn: (file: File) => {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('folder_path', folder.path);
      return api.post(`/projects/${project.id}/files`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-files', project.id, folder.path] });
      qc.invalidateQueries({ queryKey: ['project-folders', project.id] });
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (fileId: number) => api.delete(`/file-uploads/${fileId}`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['project-files', project.id, folder.path] });
      qc.invalidateQueries({ queryKey: ['project-folders', project.id] });
    },
  });

  const handleFiles = useCallback((fileList: FileList | null) => {
    if (!fileList) return;
    Array.from(fileList).forEach(f => uploadMutation.mutate(f));
  }, [uploadMutation]);

  return (
    <div>
      <div className="flex items-center gap-3 mb-5">
        <button onClick={onBack} className="flex items-center gap-1.5 text-xs transition-colors hover:text-[var(--text-primary)]"
                style={{ color: 'var(--text-muted)' }}>
          <ArrowLeft size={13} /> All Folders
        </button>
        <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />
        <div className="flex items-center gap-2">
          <Icon size={14} style={{ color: meta.color }} />
          <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{folder.name}</span>
        </div>
      </div>

      <div className="flex items-center gap-3 mb-4">
        <div className="relative flex-1">
          <Search size={13} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search files…"
            className="w-full pl-8 pr-3 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
        </div>
        <button onClick={() => fileRef.current?.click()} disabled={uploadMutation.isPending}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
          {uploadMutation.isPending ? <RefreshCw size={13} className="animate-spin" /> : <Upload size={13} />}
          Upload
        </button>
        <input ref={fileRef} type="file" multiple className="hidden" onChange={e => handleFiles(e.target.files)} />
      </div>

      {/* Drop zone overlay */}
      <div
        onDragOver={e => { e.preventDefault(); setDragging(true); }}
        onDragLeave={() => setDragging(false)}
        onDrop={e => { e.preventDefault(); setDragging(false); handleFiles(e.dataTransfer.files); }}
        className={`rounded-xl mb-4 flex items-center justify-center transition-all ${dragging ? 'py-6' : 'py-0 h-0 overflow-hidden'}`}
        style={{ border: dragging ? `2px dashed ${meta.color}` : 'none', backgroundColor: meta.color + '08' }}>
        <p className="text-sm font-medium" style={{ color: meta.color }}>Drop files here to upload</p>
      </div>

      {isLoading ? (
        <div className="space-y-2">{[...Array(3)].map((_, i) => (
          <div key={i} className="h-12 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ))}</div>
      ) : filtered.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-16 rounded-xl"
             style={{ border: '1px dashed var(--border)' }}>
          <FolderOpen size={32} style={{ color: 'var(--text-muted)' }} className="mb-3" />
          <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>No files yet</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Click Upload or drag & drop</p>
        </div>
      ) : (
        <div className="space-y-1">
          {filtered.map(file => (
            <div key={file.id}
                 className="group flex items-center gap-3 px-4 py-3 rounded-lg transition-colors hover:bg-[var(--bg-hover)]">
              <div className="flex-shrink-0">{fileIcon(file.mime_type)}</div>
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{file.original_name}</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  {fmtSize(file.file_size)} · {file.uploader?.name ?? 'Unknown'} · {fmtDate(file.created_at)}
                </p>
              </div>
              <div className="flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                <a href={`${process.env.NEXT_PUBLIC_API_URL?.replace('/api', '')}/api/file-uploads/${file.id}/download`}
                   target="_blank" rel="noopener noreferrer"
                   className="p-1.5 rounded-md hover:bg-[var(--bg-panel)]" title="Download">
                  <Download size={13} style={{ color: 'var(--text-secondary)' }} />
                </a>
                <button onClick={() => { if (confirm('Delete this file?')) deleteMutation.mutate(file.id); }}
                        className="p-1.5 rounded-md hover:bg-red-50" title="Delete">
                  <Trash2 size={13} className="text-red-400" />
                </button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── Project Prompts Modal ────────────────────────────────────────────────────
async function copyToClipboard(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
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

function ProjectPromptsModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const [activeCategory, setActiveCategory] = useState('');
  const [search, setSearch]                 = useState('');
  const [selectedTemplate, setSelectedTemplate] = useState<any>(null);

  const { data: categories = [] } = useQuery({
    queryKey: ['prompt-categories'],
    queryFn: () => api.get('/admin/prompts/categories').then(r => r.data),
  });

  const { data: templateData } = useQuery({
    queryKey: ['prompt-templates-modal', activeCategory, search],
    queryFn: () => {
      const params: Record<string, any> = {};
      if (activeCategory) params.category = activeCategory;
      if (search)         params.search   = search;
      return api.get('/admin/prompts/templates', { params }).then(r => r.data);
    },
  });

  const templates: any[] = templateData?.data ?? [];

  // Once a template is selected, hand off to PromptContextModal
  if (selectedTemplate) {
    return (
      <PromptContextModal
        template={selectedTemplate}
        projectId={projectId}
        projectLocked={true}
        adminRoute={false}
        onClose={onClose}
      />
    );
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4"
      style={{ backgroundColor: 'rgba(0,0,0,0.55)', backdropFilter: 'blur(4px)' }}
      onClick={e => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div
        className="w-full max-w-lg max-h-[82vh] flex flex-col rounded-2xl overflow-hidden"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
      >
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="flex items-center gap-2">
            <BookOpen size={16} style={{ color: 'var(--gold)' }} />
            <span className="font-semibold text-sm" style={{ color: 'var(--text-primary)' }}>Project Prompts</span>
            <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
              Context auto-filled
            </span>
          </div>
          <button onClick={onClose} className="p-1.5 rounded-lg hover:bg-[var(--bg-hover)]">
            <X size={15} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        {/* Filters */}
        <div className="flex items-center gap-2 px-4 py-3" style={{ borderBottom: '1px solid var(--border)' }}>
          <div className="relative flex-1">
            <Search size={12} className="absolute left-2.5 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder="Search prompts…"
              className="w-full pl-7 pr-3 py-1.5 rounded-lg text-xs outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>
          <div className="relative">
            <select
              value={activeCategory}
              onChange={e => setActiveCategory(e.target.value)}
              className="appearance-none pl-3 pr-7 py-1.5 rounded-lg text-xs outline-none cursor-pointer"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              <option value="">All categories</option>
              {(categories as any[]).map((c: any) => (
                <option key={c.id} value={c.slug}>{c.name}</option>
              ))}
            </select>
            <ChevronDown size={11} className="absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
          </div>
        </div>

        {/* Template list */}
        <div className="flex-1 overflow-y-auto py-2">
          {templates.length === 0 ? (
            <p className="text-xs text-center py-10" style={{ color: 'var(--text-muted)' }}>No prompts found</p>
          ) : templates.map((t: any) => (
            <button
              key={t.id}
              onClick={() => setSelectedTemplate(t)}
              className="w-full text-left px-4 py-3 transition-colors hover:bg-[var(--bg-hover)] flex items-start gap-3"
              style={{ borderBottom: '1px solid var(--border)' }}
            >
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium leading-snug" style={{ color: 'var(--text-primary)' }}>{t.title}</p>
                <div className="flex items-center gap-2 mt-1 flex-wrap">
                  {t.category && (
                    <span className="text-[10px] px-1.5 py-0.5 rounded" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
                      {t.category.name}
                    </span>
                  )}
                  {t.use_case && (
                    <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{t.use_case}</span>
                  )}
                </div>
              </div>
              <ChevronRight size={13} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--text-muted)' }} />
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────
type Tab = 'overview' | 'documents' | 'commercial' | 'site';

export default function ProjectDetailPage() {
  const { id } = useParams<{ id: string }>();
  const [activeTab, setActiveTab] = useState<Tab>('documents');
  const [selectedFolder, setSelectedFolder] = useState<any>(null);
  const [showPrompts, setShowPrompts] = useState(false);

  const { data: project, isLoading } = useQuery({
    queryKey: ['project', id],
    queryFn: () => api.get(`/projects/${id}`).then(r => r.data),
    enabled: !!id,
  });

  const { data: foldersData } = useQuery({
    queryKey: ['project-folders', id],
    queryFn: () => api.get(`/projects/${id}/folders`).then(r => r.data?.data ?? r.data),
    enabled: !!id,
  });
  const folders: any[] = Array.isArray(foldersData) ? foldersData : [];

  const { data: contracts } = useQuery({
    queryKey: ['project-contracts', id],
    queryFn: () => api.get(`/projects/${id}/contracts`).then(r => r.data?.data ?? r.data),
    enabled: !!id && activeTab === 'commercial',
  });

  const { data: rfis } = useQuery({
    queryKey: ['project-rfis', id],
    queryFn: () => api.get(`/projects/${id}/rfis`).then(r => r.data?.data ?? r.data),
    enabled: !!id && activeTab === 'site',
  });

  const statusColor: Record<string, string> = {
    active: '#10b981', on_hold: '#f59e0b', completed: '#3b82f6', cancelled: '#ef4444',
  };

  if (isLoading) return (
    <div className="p-6 max-w-7xl mx-auto space-y-4">
      <div className="h-40 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      <div className="h-10 w-80 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
    </div>
  );
  if (!project) return (
    <div className="p-6 text-center py-24">
      <p style={{ color: 'var(--text-muted)' }}>Project not found.</p>
    </div>
  );

  const tabs: { id: Tab; label: string }[] = [
    { id: 'documents',   label: 'Documents'   },
    { id: 'overview',    label: 'Overview'    },
    { id: 'commercial',  label: 'Commercial'  },
    { id: 'site',        label: 'Site Admin'  },
  ];

  return (
    <>
    <div className="p-6 max-w-7xl mx-auto">
      <Link href={project.client_id ? `/companies/${project.client_id}` : '/companies'}
            className="inline-flex items-center gap-1.5 text-xs mb-4 transition-colors hover:text-[var(--text-primary)]"
            style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={13} /> Back to Company
      </Link>

      {/* Hero */}
      <div className="rounded-2xl p-6 mb-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div className="flex items-center gap-4">
            <div className="w-11 h-11 rounded-xl flex items-center justify-center text-base font-bold flex-shrink-0"
                 style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {project.name?.charAt(0) ?? 'P'}
            </div>
            <div>
              <div className="flex items-center gap-2 flex-wrap">
                <h1 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>{project.name}</h1>
                <span className="text-xs px-2 py-0.5 rounded-full font-medium"
                      style={{ backgroundColor: (statusColor[project.status] ?? '#888') + '18',
                               color: statusColor[project.status] ?? '#888' }}>
                  {project.status?.replace('_', ' ')}
                </span>
              </div>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                {project.code && <span className="mr-2 font-mono">{project.code}</span>}
                {project.address}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-6 flex-wrap text-right">
            {project.contract_value && (
              <div><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Contract Value</p>
                <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                  ${Number(project.contract_value).toLocaleString()}
                </p></div>
            )}
            {project.start_date && (
              <div><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Start</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{fmtDate(project.start_date)}</p></div>
            )}
            {project.end_date && (
              <div><p className="text-xs" style={{ color: 'var(--text-muted)' }}>End</p>
                <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{fmtDate(project.end_date)}</p></div>
            )}
            <button
              onClick={() => setShowPrompts(true)}
              className="flex items-center gap-1.5 text-xs px-3 py-1.5 rounded-lg font-medium transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)', border: '1px solid var(--gold-30)' }}
            >
              <BookOpen size={13} />
              Project Prompts
            </button>
          </div>
        </div>
        {project.description && (
          <p className="text-sm mt-4 leading-relaxed" style={{ color: 'var(--text-secondary)' }}>{project.description}</p>
        )}
      </div>

      {/* Tabs */}
      <div className="flex gap-1 p-1 rounded-xl mb-5 w-fit" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {tabs.map(t => (
          <button key={t.id}
            onClick={() => { setActiveTab(t.id); setSelectedFolder(null); }}
            className="px-4 py-2 rounded-lg text-sm font-medium transition-all"
            style={activeTab === t.id
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }}>
            {t.label}
          </button>
        ))}
      </div>

      {/* Content */}
      <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>

        {activeTab === 'documents' && (
          <ProjectDocumentsExplorer compact />
        )}

        {activeTab === 'overview' && (
          <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
            <div>
              <h3 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-primary)' }}>Project Details</h3>
              <dl className="space-y-0">
                {[
                  ['Status',         project.status?.replace('_', ' ')],
                  ['Type',           project.type ?? '—'],
                  ['Contract Value', project.contract_value ? `$${Number(project.contract_value).toLocaleString()}` : '—'],
                  ['Payment Terms',  project.payment_terms_days ? `${project.payment_terms_days} days` : '—'],
                  ['Retention',      project.retention_percentage ? `${project.retention_percentage}%` : '—'],
                  ['Start Date',     project.start_date ? fmtDate(project.start_date) : '—'],
                  ['End Date',       project.end_date ? fmtDate(project.end_date) : '—'],
                  ['Created by',     project.creator?.name ?? '—'],
                ].map(([k, v]) => (
                  <div key={k} className="flex justify-between items-center py-2.5"
                       style={{ borderBottom: '1px solid var(--border)' }}>
                    <dt className="text-xs" style={{ color: 'var(--text-muted)' }}>{k}</dt>
                    <dd className="text-xs font-medium capitalize" style={{ color: 'var(--text-primary)' }}>{v}</dd>
                  </div>
                ))}
              </dl>
            </div>
            <div>
              <h3 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-primary)' }}>Team</h3>
              {project.users?.length ? (
                <div className="space-y-2">
                  {project.users.map((u: any) => (
                    <div key={u.id} className="flex items-center gap-3 p-3 rounded-lg"
                         style={{ backgroundColor: 'var(--bg-elevated)' }}>
                      <div className="w-7 h-7 rounded-full flex items-center justify-center text-xs font-semibold"
                           style={{ backgroundColor: 'var(--bg-panel)', color: 'var(--text-secondary)' }}>
                        {u.name?.charAt(0)}
                      </div>
                      <div>
                        <p className="text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{u.name}</p>
                        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{u.email}</p>
                      </div>
                    </div>
                  ))}
                </div>
              ) : <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No team members assigned.</p>}
            </div>
          </div>
        )}

        {activeTab === 'commercial' && (
          <div>
            <h3 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-primary)' }}>Contracts</h3>
            {!(Array.isArray(contracts) ? contracts : contracts?.data ?? []).length
              ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No contracts yet.</p>
              : (Array.isArray(contracts) ? contracts : contracts?.data ?? []).map((c: any) => (
                  <div key={c.id} className="flex items-center justify-between p-4 rounded-xl mb-2"
                       style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                    <div>
                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{c.title}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{c.contract_number}</p>
                    </div>
                    <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                      ${Number(c.value ?? 0).toLocaleString()}
                    </p>
                  </div>
                ))
            }
          </div>
        )}

        {activeTab === 'site' && (
          <div>
            <h3 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-primary)' }}>RFIs</h3>
            {!(Array.isArray(rfis) ? rfis : rfis?.data ?? []).length
              ? <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No RFIs yet.</p>
              : (Array.isArray(rfis) ? rfis : rfis?.data ?? []).map((r: any) => (
                  <div key={r.id} className="flex items-center justify-between p-4 rounded-xl mb-2"
                       style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                    <div>
                      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{r.subject}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>RFI-{r.rfi_number}</p>
                    </div>
                    <span className="text-xs px-2 py-0.5 rounded-full capitalize"
                          style={{ backgroundColor: 'var(--bg-panel)', color: 'var(--text-secondary)' }}>
                      {r.status}
                    </span>
                  </div>
                ))
            }
          </div>
        )}
      </div>
    </div>

      {showPrompts && (
        <ProjectPromptsModal projectId={id} onClose={() => setShowPrompts(false)} />
      )}
    </>
  );
}
