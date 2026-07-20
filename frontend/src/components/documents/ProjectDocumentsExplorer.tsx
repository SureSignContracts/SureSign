'use client';

import { useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { useParams } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ChevronRight,
  ClipboardList,
  Download,
  Eye,
  Folder,
  FolderOpen,
  LayoutGrid,
  LayoutList,
  Search,
  Trash2,
  Upload,
  Wand2,
  X,
} from 'lucide-react';
import DocumentPreviewModal, { type PreviewTarget } from '@/components/documents/DocumentPreviewModal';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import GeneratePackageModal from '@/components/documents/GeneratePackageModal';
import Button from '@/components/ui/Button';
import PageTourButton from '@/components/tours/PageTourButton';

type DocSource = 'generated' | 'uploaded';
type ViewMode = 'list' | 'folder';

type ModuleFolder = { key: string; name: string; files_count: number; last_updated?: string };
type ExplorerFolder = { key: string; name: string; files_count: number };
type TradePackageItem = {
  id: number;
  key: string;
  name: string;
  files_count: number;
  package_code?: string | null;
  package_reference?: string | null;
  contractor_name?: string | null;
  description?: string | null;
};
type FileItem = {
  id: number;
  original_name: string;
  mime_type: string;
  file_size: number;
  created_at: string;
  uploader?: { id: number; name: string };
};
type ProjectFolderListItem = { path: string; name: string };
type GeneratedDocItem = { id: number; title?: string; file_name?: string; type?: string; created_at: string };
// Generated PDFs returned alongside FileUploads in the module files response
type ModuleGeneratedDoc = {
  id: number;
  title: string;
  file_name: string | null;
  type: string | null;
  reference_number: string | null;
  mime_type: string;
  file_size: number;
  created_at: string;
  creator: { id: number; name: string } | null;
};
type ListItem = { id: number; created_at: string; original_name?: string; title?: string; file_name?: string; type?: string; mime_type?: string };
type FolderCrumb = { key: string; label: string };

function formatBytes(bytes: number): string {
  if (!bytes) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const MODULE_LABELS: Record<string, string> = {
  contracts:            'Contracts',
  subcontracts:         'Subcontracts',
  commercial:           'Commercial',
  payment_applications: 'Payment Applications',
  variations:           'Variations',
  notices:              'Notices',
  rfis:                 'RFIs',
  meetings:             'Meetings',
  qa_reports:           'QA Reports',
  snagging:             'Snagging',
  closeout:             'Closeout',
  site_reports:         'Site Reports',
  adjudication:         'Adjudication',
  general:              'General Documents',
};

const FOLDER_LABELS: Record<string, string> = {
  'contracts/main_contract':        'Main Contract',
  'contracts/consultant_agreement': 'Consultant Agreements',
  'contracts/supplier_agreement':   'Supplier Agreements',
  'contracts/subcontract':          'Subcontract Agreements',
};

function resolveUploadContext(moduleKeyPath: string | null): { moduleKey: string; folderKey: string; moduleLabel: string; folderLabel: string } {
  if (!moduleKeyPath) return { moduleKey: 'general', folderKey: 'general', moduleLabel: 'General Documents', folderLabel: 'General Documents' };

  // Trade package path: subcontracts/package/{id}
  if (moduleKeyPath.startsWith('subcontracts/package/')) {
    return { moduleKey: 'subcontracts', folderKey: moduleKeyPath, moduleLabel: 'Subcontracts', folderLabel: 'Trade Package' };
  }

  // Contract sub-folder
  if (FOLDER_LABELS[moduleKeyPath]) {
    return { moduleKey: 'contracts', folderKey: moduleKeyPath, moduleLabel: 'Contracts', folderLabel: FOLDER_LABELS[moduleKeyPath] };
  }

  // Uploading from the bare "Contracts" folder itself, not yet inside a
  // named subfolder (e.g. Main Contract) — the backend only ever displays
  // Contracts as subfolders, never a flat file list at this level, so
  // tagging the file with folder_key='contracts' (the module key) would
  // silently orphan it: invisible in every subfolder view, admin included,
  // even though the module-level file count still includes it. Default to
  // Main Contract, the subfolder every other upload path already treats as
  // the sensible default for an untargeted contract file.
  if (moduleKeyPath === 'contracts') {
    return { moduleKey: 'contracts', folderKey: 'contracts/main_contract', moduleLabel: 'Contracts', folderLabel: 'Main Contract' };
  }

  const moduleLabel = MODULE_LABELS[moduleKeyPath] ?? moduleKeyPath;
  return { moduleKey: moduleKeyPath, folderKey: moduleKeyPath, moduleLabel, folderLabel: moduleLabel };
}

function UploadModal({
  projectId,
  moduleKeyPath,
  onClose,
  onUploaded,
}: {
  projectId: string;
  moduleKeyPath: string | null;
  onClose: () => void;
  onUploaded: () => void;
}) {
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [title, setTitle] = useState('');
  const [uploading, setUploading] = useState(false);

  const ctx = resolveUploadContext(moduleKeyPath);

  const handleSubmit = async (e: React.SyntheticEvent<HTMLFormElement>) => {
    e.preventDefault();
    if (!file) return;

    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      fd.append('module_key', ctx.moduleKey);
      fd.append('folder_key', ctx.folderKey);
      fd.append('folder_path', ctx.moduleKey);
      if (title.trim()) fd.append('title', title.trim());

      await api.post(`/projects/${projectId}/files`, fd, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      toast.success('File uploaded successfully.');
      onUploaded();
      onClose();
    } catch {
      toast.error('Upload failed. Please try again.');
    } finally {
      setUploading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="ss-animate-in w-full max-w-md rounded-2xl p-6 space-y-5"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Upload Document</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              {ctx.moduleLabel}{ctx.folderLabel !== ctx.moduleLabel ? ` › ${ctx.folderLabel}` : ''}
            </p>
          </div>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Destination (read-only context) */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Destination Folder
            </label>
            <div
              className="px-3 py-2.5 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}
            >
              {ctx.moduleLabel}{ctx.folderLabel !== ctx.moduleLabel ? ` / ${ctx.folderLabel}` : ''}
            </div>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              This document will appear in the project document library under the above folder.
            </p>
          </div>

          {/* Document title */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Document Title <span style={{ color: 'var(--text-muted)' }}>(optional — defaults to filename)</span>
            </label>
            <input
              type="text"
              value={title}
              onChange={e => setTitle(e.target.value)}
              placeholder="e.g. Signed Contract Agreement"
              className="w-full px-3 py-2.5 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          {/* File picker */}
          <div>
            <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              File <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <input
              ref={fileInputRef}
              type="file"
              className="hidden"
              onChange={e => {
                const f = e.target.files?.[0] ?? null;
                setFile(f);
                if (f && !title) setTitle(f.name.replace(/\.[^.]+$/, ''));
              }}
            />
            {file ? (
              <div
                className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg"
                style={{ backgroundColor: 'var(--gold-8)', border: '1px solid var(--gold-30)' }}
              >
                <span className="text-xs truncate" style={{ color: 'var(--text-primary)' }}>{file.name}</span>
                <button
                  type="button"
                  onClick={() => { setFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }}
                  className="text-xs flex-shrink-0"
                  style={{ color: 'var(--text-muted)' }}
                >
                  Remove
                </button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="w-full flex items-center justify-center gap-2 px-3 py-3 rounded-lg border-dashed text-sm transition-colors hover:border-[var(--gold)]"
                style={{ border: '1.5px dashed var(--border)', color: 'var(--text-muted)' }}
              >
                <Upload size={15} />
                Click to select a file
              </button>
            )}
          </div>

          <div className="flex gap-3 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 py-2.5 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={!file || uploading}
              className="flex-1 py-2.5 rounded-lg text-sm font-medium disabled:opacity-50"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              {uploading ? 'Uploading…' : 'Upload Document'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function DeleteConfirmModal({
  fileName,
  onClose,
  onConfirm,
  deleting,
}: {
  fileName: string;
  onClose: () => void;
  onConfirm: () => void;
  deleting: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="ss-animate-in w-full max-w-sm rounded-2xl p-6 space-y-4"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Delete Document</h2>
        <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
          Are you sure you want to delete this document?
        </p>
        <div className="rounded-lg px-3 py-2 text-sm font-medium truncate"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
          {fileName}
        </div>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>This action can be restored later.</p>
        <div className="flex justify-end gap-2 pt-1">
          <button onClick={onClose} disabled={deleting}
            className="px-4 py-2 rounded-lg text-sm"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button onClick={onConfirm} disabled={deleting}
            className="px-4 py-2 rounded-lg text-sm font-medium transition-all active:scale-[0.98] disabled:opacity-60"
            style={{ backgroundColor: '#ef4444', color: '#fff' }}>
            {deleting ? 'Deleting…' : 'Delete File'}
          </button>
        </div>
      </div>
    </div>
  );
}

function FolderCard({
  title,
  subtitle,
  meta,
  onClick,
  index = 0,
}: {
  title: string;
  subtitle?: string;
  meta?: string;
  onClick: () => void;
  index?: number;
}) {
  return (
    <button
      onClick={onClick}
      className="ss-animate-in w-full rounded-xl p-4 text-left transition-all duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)] hover:border-[var(--gold)] hover:-translate-y-0.5 group"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', animationDelay: `${Math.min(index * 45, 360)}ms` }}
    >
      <div className="flex items-start gap-3">
        <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg" style={{ backgroundColor: 'var(--gold-15)' }}>
          <Folder size={20} style={{ color: 'var(--gold)' }} />
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</p>
          {subtitle && <p className="mt-0.5 text-xs" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
          {meta && <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>{meta}</p>}
        </div>
        <ChevronRight size={14} className="mt-1 flex-shrink-0 opacity-0 transition-opacity group-hover:opacity-100" style={{ color: 'var(--text-muted)' }} />
      </div>
    </button>
  );
}

const TYPE_COLOR: Record<string, string> = {
  pdf:  '#f87171',
  docx: '#60a5fa',
  doc:  '#60a5fa',
  xlsx: '#4ade80',
  xls:  '#4ade80',
  csv:  '#4ade80',
  png:  '#c084fc',
  jpg:  '#c084fc',
  jpeg: '#c084fc',
  gif:  '#c084fc',
  webp: '#c084fc',
};

function FileTypeBadge({ name, mimeType }: { name?: string; mimeType?: string }) {
  const ext = name?.split('.').pop()?.toLowerCase() || '';
  // derive extension from mime as fallback
  const mimeExt = mimeType?.includes('pdf') ? 'pdf'
    : mimeType?.includes('word') || mimeType?.includes('document') ? 'docx'
    : mimeType?.includes('sheet') || mimeType?.includes('excel') ? 'xlsx'
    : mimeType?.includes('image') ? 'img'
    : '';
  const key = ext || mimeExt || 'doc';
  const color = TYPE_COLOR[key] || '#9a9490';
  return (
    <div
      className="w-8 h-8 rounded-md flex items-center justify-center flex-shrink-0 text-[10px] font-bold uppercase"
      style={{ backgroundColor: `${color}20`, color }}
    >
      {key.substring(0, 3)}
    </div>
  );
}

function EmptyState({ title, body }: { title: string; body?: string }) {
  return (
    <div className="py-20 text-center">
      <FolderOpen size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{title}</p>
      {body && <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>{body}</p>}
    </div>
  );
}

function getFolderLabel(key: string, moduleFolders: ModuleFolder[], subfolders: ExplorerFolder[], packages: TradePackageItem[]) {
  return (
    moduleFolders.find((item) => item.key === key)?.name
    ?? subfolders.find((item) => item.key === key)?.name
    ?? packages.find((item) => item.key === key)?.name
    ?? key.split('/').pop()?.replace(/_/g, ' ')?.replace(/\b\w/g, (char) => char.toUpperCase())
    ?? key
  );
}

export default function ProjectDocumentsExplorer({ compact = false }: { compact?: boolean }) {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [viewMode, setViewMode] = useState<ViewMode>('folder');
  const [search, setSearch] = useState('');
  const [selectedFolder, setSelectedFolder] = useState<string | null>(null);
  const [docSource, setDocSource] = useState<DocSource>('uploaded');
  const [moduleKeyPath, setModuleKeyPath] = useState<string | null>(null);
  const [showGenerateModal, setShowGenerateModal] = useState(false);
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<{ id: number; name: string } | null>(null);
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);

  const { data: foldersData } = useQuery({
    queryKey: ['project-folders', id],
    queryFn: () => api.get(`/projects/${id}/folders`).then((r) => r.data).catch(() => ({ data: [] })),
    enabled: viewMode === 'list',
  });

  const { data: filesData } = useQuery({
    queryKey: ['project-files', id, selectedFolder],
    queryFn: () => {
      const params: Record<string, string> = {};
      if (selectedFolder) params.folder = selectedFolder;
      return api.get(`/projects/${id}/files`, { params }).then((r) => r.data).catch(() => ({ data: [] }));
    },
    enabled: viewMode === 'list' && docSource === 'uploaded',
  });

  const { data: generatedDocsData } = useQuery({
    queryKey: ['project-documents', id, selectedFolder],
    queryFn: () => {
      const params: Record<string, string> = {};
      if (selectedFolder) params.category = selectedFolder;
      return api.get(`/projects/${id}/documents`, { params }).then((r) => r.data).catch(() => ({ data: [] }));
    },
    enabled: viewMode === 'list' && docSource === 'generated',
  });

  const { data: explorerData, isLoading: explorerLoading } = useQuery({
    queryKey: ['project-doc-explorer', id],
    queryFn: () => api.get(`/projects/${id}/documents/explorer`).then((r) => r.data),
    enabled: viewMode === 'folder' && !moduleKeyPath,
  });

  const { data: moduleFilesData, isLoading: moduleFilesLoading } = useQuery({
    queryKey: ['project-module-files', id, moduleKeyPath],
    queryFn: () => api.get(`/projects/${id}/documents/module/${moduleKeyPath}`).then((r) => r.data),
    enabled: viewMode === 'folder' && !!moduleKeyPath,
  });

  const deleteMutation = useMutation({
    mutationFn: (fileId: number) => api.delete(`/file-uploads/${fileId}`).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-files', id] });
      queryClient.invalidateQueries({ queryKey: ['project-module-files', id] });
      queryClient.invalidateQueries({ queryKey: ['project-doc-explorer', id] });
      toast.success('Document deleted successfully.');
    },
    onError: () => toast.error('Failed to delete'),
  });

  const folders = useMemo<ProjectFolderListItem[]>(
    () => (Array.isArray(foldersData) ? foldersData : (foldersData?.data ?? [])),
    [foldersData]
  );
  const files = useMemo<FileItem[]>(() => filesData?.data ?? [], [filesData]);
  const docs = useMemo<GeneratedDocItem[]>(() => generatedDocsData?.data ?? [], [generatedDocsData]);
  const moduleFolders = useMemo<ModuleFolder[]>(() => explorerData?.folders ?? [], [explorerData]);
  const subfolders = useMemo<ExplorerFolder[]>(() => moduleFilesData?.folders ?? [], [moduleFilesData]);
  const tradePackages = useMemo<TradePackageItem[]>(() => moduleFilesData?.trade_packages ?? [], [moduleFilesData]);
  const moduleFiles = useMemo<FileItem[]>(() => moduleFilesData?.data ?? [], [moduleFilesData]);
  // Generated PDFs (Document records) returned alongside FileUploads for relevant modules
  const moduleGeneratedDocs = useMemo<ModuleGeneratedDoc[]>(
    () => moduleFilesData?.generated_docs ?? [],
    [moduleFilesData],
  );
  const currentTradePackage = moduleFilesData?.trade_package ?? null;

  const filteredItems = (docSource === 'uploaded'
    ? files.filter((item) => item.original_name?.toLowerCase().includes(search.toLowerCase()))
    : docs.filter((item) => (item.title || item.file_name || '').toLowerCase().includes(search.toLowerCase()))
  ) as ListItem[];

  const currentFolder = folders.find((folder) => folder.path === selectedFolder);

  const crumbs: FolderCrumb[] = useMemo(() => {
    const base: FolderCrumb[] = [{ key: '', label: 'Documents' }];
    if (!moduleKeyPath) return base;

    const segments: FolderCrumb[] = [];
    const parts = moduleKeyPath.split('/');

    if (parts[0]) {
      segments.push({ key: parts[0], label: getFolderLabel(parts[0], moduleFolders, subfolders, tradePackages) });
    }

    // Nested contract sub-path (e.g. contracts/main_contract, contracts/consultant_agreement)
    if (parts[1] && parts[0] === 'contracts' && parts[1] !== 'package') {
      const key = `${parts[0]}/${parts[1]}`;
      segments.push({ key, label: getFolderLabel(key, moduleFolders, subfolders, tradePackages) });
    }

    // Old path: contracts/subcontract/package/{id}
    if (parts[2] === 'package' && parts[3]) {
      segments.push({
        key: moduleKeyPath,
        label: currentTradePackage?.name ?? getFolderLabel(moduleKeyPath, moduleFolders, subfolders, tradePackages),
      });
    }

    // New path: subcontracts/package/{id}
    if (parts[0] === 'subcontracts' && parts[1] === 'package' && parts[2]) {
      segments.push({
        key: moduleKeyPath,
        label: currentTradePackage?.name ?? getFolderLabel(moduleKeyPath, moduleFolders, subfolders, tradePackages),
      });
    }

    return [...base, ...segments];
  }, [currentTradePackage?.name, moduleFolders, moduleKeyPath, subfolders, tradePackages]);

  const showFolderGrid = viewMode === 'folder' && !moduleKeyPath;
  const showSubfolders = moduleFilesData?.type === 'folders';
  const showTradePackages = moduleFilesData?.type === 'trade_packages';
  const inTradePackageFolder = Boolean(
    moduleKeyPath?.startsWith('subcontracts/package/') ||
    moduleKeyPath?.startsWith('contracts/subcontract/package/')
  ) && currentTradePackage;

  const downloadDoc = async (docId: number, fileName: string) => {
    try {
      const response = await api.get(`/documents/${docId}/download`, { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = fileName;
      anchor.click();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Download failed');
    }
  };

  const downloadFile = async (fileId: number, fileName: string) => {
    try {
      const response = await api.get(`/file-uploads/${fileId}/download`, { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = fileName;
      anchor.click();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Download failed');
    }
  };

  return (
    <div className={compact ? 'space-y-5' : 'mx-auto max-w-7xl space-y-5 p-6'}>
      {!compact && (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <div className="flex items-center gap-1.5">
              <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Documents</h1>
              <PageTourButton tourKey="page-documents" label="Take a tour of this page" />
            </div>
            <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Project document library</p>
          </div>
          <div className="flex items-center gap-2" data-tour="documents-header-actions">
            <Link
              href={`/app/projects/${id}/documents/register`}
              data-tour="documents-register-link"
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold transition-all active:scale-[0.98] hover:bg-[var(--bg-hover)]"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}
            >
              <ClipboardList size={13} />
              Document Register
            </Link>
            <div className="flex overflow-hidden rounded-full" style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
              {(['folder', 'list'] as const).map((mode) => (
                <button
                  key={mode}
                  onClick={() => {
                    setViewMode(mode);
                    if (mode === 'folder') setModuleKeyPath(null);
                  }}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium transition-all active:scale-[0.97]"
                  style={{
                    backgroundColor: viewMode === mode ? 'var(--gold)' : 'transparent',
                    color: viewMode === mode ? 'var(--accent-fg)' : 'var(--text-muted)',
                  }}
                >
                  {mode === 'folder' ? <LayoutGrid size={13} /> : <LayoutList size={13} />}
                  {mode === 'folder' ? 'Folder View' : 'List View'}
                </button>
              ))}
            </div>

            <button
              onClick={() => setShowUploadModal(true)}
              className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-all active:scale-[0.98] hover:opacity-90"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Upload size={15} />
              Upload File
            </button>
          </div>
        </div>
      )}

      {compact && (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Project Documents</h3>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              Browse folders, open subcontracts, and generate package documents.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <div className="flex overflow-hidden rounded-full" style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
              {(['folder', 'list'] as const).map((mode) => (
                <button
                  key={mode}
                  onClick={() => {
                    setViewMode(mode);
                    if (mode === 'folder') setModuleKeyPath(null);
                  }}
                  className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium transition-all active:scale-[0.97]"
                  style={{
                    backgroundColor: viewMode === mode ? 'var(--gold)' : 'transparent',
                    color: viewMode === mode ? 'var(--accent-fg)' : 'var(--text-muted)',
                  }}
                >
                  {mode === 'folder' ? <LayoutGrid size={13} /> : <LayoutList size={13} />}
                  {mode === 'folder' ? 'Folder View' : 'List View'}
                </button>
              ))}
            </div>
            <button
              onClick={() => setShowUploadModal(true)}
              className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-all active:scale-[0.98] hover:opacity-90"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
              <Upload size={15} />
              Upload File
            </button>
          </div>
        </div>
      )}

      {viewMode === 'folder' && (
        <>
          <nav className="flex flex-wrap items-center gap-1 text-sm">
            {crumbs.map((crumb, index) => (
              <span key={crumb.key || 'root'} className="flex items-center gap-1">
                {index > 0 && <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />}
                {index === crumbs.length - 1 ? (
                  <span className="font-medium" style={{ color: 'var(--text-primary)' }}>{crumb.label}</span>
                ) : (
                  <button className="hover:underline" style={{ color: 'var(--gold)' }} onClick={() => setModuleKeyPath(crumb.key || null)}>
                    {crumb.label}
                  </button>
                )}
              </span>
            ))}
          </nav>

          {showFolderGrid ? (
            explorerLoading ? (
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {[...Array(13)].map((_, index) => <div key={index} className="h-20 animate-pulse rounded-xl" style={{ backgroundColor: 'var(--bg-surface)' }} />)}
              </div>
            ) : moduleFolders.length === 0 ? (
              <EmptyState title="No documents uploaded for this project yet" body="Upload the main contract to get started." />
            ) : (
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {moduleFolders.map((module, index) => (
                  <FolderCard
                    key={module.key}
                    title={module.name}
                    subtitle={`${module.files_count} file${module.files_count !== 1 ? 's' : ''}`}
                    meta={module.last_updated ? formatDate(module.last_updated) : undefined}
                    onClick={() => setModuleKeyPath(module.key)}
                    index={index}
                  />
                ))}
              </div>
            )
          ) : moduleFilesLoading ? (
            <div className="space-y-2">
              {[...Array(5)].map((_, index) => <div key={index} className="h-14 animate-pulse rounded-xl" style={{ backgroundColor: 'var(--bg-surface)' }} />)}
            </div>
          ) : showSubfolders ? (
            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
              {subfolders.map((folder, index) => (
                <FolderCard
                  key={folder.key}
                  title={folder.name}
                  subtitle={`${folder.files_count} file${folder.files_count !== 1 ? 's' : ''}`}
                  onClick={() => setModuleKeyPath(folder.key)}
                  index={index}
                />
              ))}
            </div>
          ) : showTradePackages ? (
            tradePackages.length === 0 ? (
              <EmptyState title="No trade packages yet" body="Trade package folders will appear here once they are created." />
            ) : (
              <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {tradePackages.map((tradePackage, index) => (
                  <FolderCard
                    key={tradePackage.id}
                    title={tradePackage.name}
                    subtitle={`${tradePackage.files_count} file${tradePackage.files_count !== 1 ? 's' : ''}`}
                    meta={tradePackage.package_reference ?? tradePackage.package_code ?? undefined}
                    onClick={() => setModuleKeyPath(tradePackage.key)}
                    index={index}
                  />
                ))}
              </div>
            )
          ) : (
            <div className="space-y-4">
              {inTradePackageFolder && (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                  <div>
                    <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{currentTradePackage.name}</p>
                    <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                      {currentTradePackage.package_reference || currentTradePackage.package_code || 'Trade package folder'}
                    </p>
                  </div>
                  <button
                    onClick={() => setShowGenerateModal(true)}
                    className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium"
                    style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                  >
                    <Wand2 size={14} />
                    Generate Package
                  </button>
                </div>
              )}

              {moduleFiles.length === 0 && moduleGeneratedDocs.length === 0 ? (
                <EmptyState
                  title={inTradePackageFolder ? `No files in ${currentTradePackage.name}` : 'No files in this folder'}
                  body={inTradePackageFolder ? 'Generate a package from a template or upload files directly into this trade package.' : undefined}
                />
              ) : (
                <>
                  {/* Uploaded files */}
                  {moduleFiles.length > 0 && (
                    <div className="overflow-hidden rounded-2xl" style={{ border: '1px solid var(--border)' }}>
                      <table className="w-full text-sm">
                        <thead>
                          <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                            {['Name', 'Size', 'Uploaded by', 'Date', 'Actions'].map((heading) => (
                              <th key={heading} className="px-4 py-3 text-left text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{heading}</th>
                            ))}
                          </tr>
                        </thead>
                        <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                          {moduleFiles.map((file) => (
                            <tr key={file.id} className="transition-colors hover:bg-[var(--bg-hover)]" style={{ borderBottom: '1px solid var(--border)' }}>
                              <td className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                  <FileTypeBadge name={file.original_name} mimeType={file.mime_type} />
                                  <span className="max-w-[240px] truncate text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{file.original_name}</span>
                                </div>
                              </td>
                              <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatBytes(file.file_size)}</td>
                              <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{file.uploader?.name ?? '—'}</td>
                              <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(file.created_at)}</td>
                              <td className="px-4 py-3">
                                <div className="flex items-center gap-2">
                                  <button
                                    onClick={() => setPreviewTarget({
                                      id: file.id,
                                      name: file.original_name,
                                      mimeType: file.mime_type,
                                      previewEndpoint: `/file-uploads/${file.id}/preview`,
                                      downloadEndpoint: `/file-uploads/${file.id}/download`,
                                    })}
                                    title="Preview"
                                    className="rounded p-1 hover:opacity-80"
                                    style={{ color: 'var(--text-muted)' }}
                                  >
                                    <Eye size={13} />
                                  </button>
                                  <button onClick={() => downloadFile(file.id, file.original_name)} title="Download" className="rounded p-1 hover:opacity-80" style={{ color: 'var(--text-muted)' }}>
                                    <Download size={13} />
                                  </button>
                                  <button onClick={() => setDeleteTarget({ id: file.id, name: file.original_name })} title="Delete" className="rounded p-1 hover:opacity-80" style={{ color: '#ef4444' }}>
                                    <Trash2 size={13} />
                                  </button>
                                </div>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}

                  {/* Generated PDFs — Document records produced by DocumentGenerationService */}
                  {moduleGeneratedDocs.length > 0 && (
                    <div>
                      <p className="mb-2 text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
                        Generated PDFs
                      </p>
                      <div className="overflow-hidden rounded-2xl" style={{ border: '1px solid var(--border)' }}>
                        <table className="w-full text-sm">
                          <thead>
                            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                              {['Document', 'Ref', 'Size', 'Generated by', 'Date', 'Actions'].map((h) => (
                                <th key={h} className="px-4 py-3 text-left text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                              ))}
                            </tr>
                          </thead>
                          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                            {moduleGeneratedDocs.map((doc) => {
                              const displayName = doc.title || doc.file_name || 'Generated PDF';
                              const fileName    = doc.file_name || `${displayName}.pdf`;
                              return (
                                <tr key={doc.id} className="transition-colors hover:bg-[var(--bg-hover)]" style={{ borderBottom: '1px solid var(--border)' }}>
                                  <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                      <FileTypeBadge name={fileName} mimeType={doc.mime_type} />
                                      <span className="max-w-[240px] truncate text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{displayName}</span>
                                    </div>
                                  </td>
                                  <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{doc.reference_number ?? '—'}</td>
                                  <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatBytes(doc.file_size)}</td>
                                  <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{doc.creator?.name ?? '—'}</td>
                                  <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(doc.created_at)}</td>
                                  <td className="px-4 py-3">
                                    <div className="flex items-center gap-2">
                                      <button
                                        onClick={() => setPreviewTarget({
                                          id: doc.id,
                                          name: displayName,
                                          mimeType: doc.mime_type,
                                          previewEndpoint:  `/documents/${doc.id}/preview`,
                                          downloadEndpoint: `/documents/${doc.id}/download`,
                                        })}
                                        title="Preview"
                                        className="rounded p-1 hover:opacity-80"
                                        style={{ color: 'var(--text-muted)' }}
                                      >
                                        <Eye size={13} />
                                      </button>
                                      <button
                                        onClick={() => downloadDoc(doc.id, fileName)}
                                        title="Download"
                                        className="rounded p-1 hover:opacity-80"
                                        style={{ color: 'var(--text-muted)' }}
                                      >
                                        <Download size={13} />
                                      </button>
                                    </div>
                                  </td>
                                </tr>
                              );
                            })}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}
                </>
              )}
            </div>
          )}
        </>
      )}

      {viewMode === 'list' && (
        <>
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div className="flex gap-1 rounded-lg p-1" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              {(['uploaded', 'generated'] as const).map((source) => (
                <button
                  key={source}
                  onClick={() => setDocSource(source)}
                  className="rounded-md px-3 py-1.5 text-xs font-medium capitalize transition-all"
                  style={docSource === source ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}
                >
                  {source === 'uploaded' ? 'Uploaded Files' : 'Generated PDFs'}
                </button>
              ))}
            </div>
            <div className="relative">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
              <input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder="Search…"
                className="rounded-lg py-2 pl-9 pr-4 text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '200px', boxShadow: 'var(--shadow-card)' }}
              />
            </div>
          </div>

          <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
            <button onClick={() => setSelectedFolder(null)} className="hover:underline" style={{ color: selectedFolder ? 'var(--text-muted)' : 'var(--gold)' }}>
              All
            </button>
            {currentFolder && (
              <>
                <ChevronRight size={12} />
                <span style={{ color: 'var(--gold)' }}>{currentFolder.name}</span>
              </>
            )}
          </div>

          <div className="overflow-hidden rounded-2xl" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="grid grid-cols-[2.3fr_1fr_1fr_1fr_auto] gap-4 px-5 py-3 text-xs font-medium uppercase tracking-wider" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
              <span>Name</span>
              <span>Type</span>
              <span>Folder</span>
              <span>Date</span>
              <span />
            </div>
            {filteredItems.length === 0 ? (
              <EmptyState title="No matching documents" body="Try another search or upload a document." />
            ) : (
              <div className="divide-y" style={{ borderColor: 'var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                {filteredItems.map((item) => (
                  <div key={item.id} className="grid grid-cols-[2.3fr_1fr_1fr_1fr_auto] gap-4 items-center px-5 py-3 transition-colors hover:bg-[var(--bg-hover)]">
                    <div className="flex items-center gap-2.5 min-w-0">
                      <FileTypeBadge name={item.original_name || item.file_name} mimeType={item.mime_type} />
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                          {item.original_name || item.title || item.file_name}
                        </p>
                        {docSource === 'generated' && (item as any).version && (
                          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>v{(item as any).version}</p>
                        )}
                      </div>
                    </div>
                    <span className="text-sm" style={{ color: 'var(--text-muted)' }}>
                      {docSource === 'uploaded' ? 'Upload' : item.type || 'Document'}
                    </span>
                    <span className="text-sm" style={{ color: 'var(--text-muted)' }}>{currentFolder?.name || 'All'}</span>
                    <span className="text-sm tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(item.created_at)}</span>
                    <div className="flex items-center gap-1">
                      <button
                        onClick={() => setPreviewTarget({
                          id: item.id,
                          name: item.original_name || item.file_name || item.title || 'document',
                          mimeType: item.mime_type,
                          previewEndpoint: docSource === 'uploaded'
                            ? `/file-uploads/${item.id}/preview`
                            : `/documents/${item.id}/preview`,
                          downloadEndpoint: docSource === 'uploaded'
                            ? `/file-uploads/${item.id}/download`
                            : `/documents/${item.id}/download`,
                        })}
                        className="rounded p-1.5 hover:bg-[var(--bg-hover)]"
                        title="Preview"
                      >
                        <Eye size={13} style={{ color: 'var(--text-muted)' }} />
                      </button>
                      <button
                        onClick={() => docSource === 'uploaded' ? downloadFile(item.id, item.original_name || 'file') : downloadDoc(item.id, item.file_name || item.title || 'document')}
                        className="rounded p-1.5 hover:bg-[var(--bg-hover)]"
                        title="Download"
                      >
                        <Download size={13} style={{ color: 'var(--text-muted)' }} />
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        </>
      )}

      {deleteTarget && (
        <DeleteConfirmModal
          fileName={deleteTarget.name}
          onClose={() => setDeleteTarget(null)}
          onConfirm={() => deleteMutation.mutate(deleteTarget.id)}
          deleting={deleteMutation.isPending}
        />
      )}

      {previewTarget && (
        <DocumentPreviewModal target={previewTarget} onClose={() => setPreviewTarget(null)} />
      )}

      {showUploadModal && (
        <UploadModal
          projectId={id!}
          moduleKeyPath={moduleKeyPath}
          onClose={() => setShowUploadModal(false)}
          onUploaded={() => {
            queryClient.invalidateQueries({ queryKey: ['project-files', id] });
            queryClient.invalidateQueries({ queryKey: ['project-module-files', id] });
            queryClient.invalidateQueries({ queryKey: ['project-doc-explorer', id] });
          }}
        />
      )}

      {showGenerateModal && currentTradePackage && (
        <GeneratePackageModal
          projectId={id}
          tradePackage={currentTradePackage}
          onClose={() => setShowGenerateModal(false)}
          onViewInPackage={() => {
            setShowGenerateModal(false);
            queryClient.invalidateQueries({ queryKey: ['project-module-files', id, moduleKeyPath] });
          }}
        />
      )}
    </div>
  );
}
