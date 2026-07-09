'use client';

import { useState, useEffect, useCallback, useRef } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import { formatDate } from '@/lib/utils';
import Link from 'next/link';
import {
  FileText, Search, Building2, FolderOpen, Download, Eye,
  ChevronRight, Folder, LayoutList, LayoutGrid, Home, Wand2, Box,
  MoreVertical, Trash2, ClipboardList, MoreHorizontal,
} from 'lucide-react';
import GeneratePackageModal from '@/components/documents/GeneratePackageModal';
import GenerateTradePackageFolderModal from '@/components/documents/GenerateTradePackageFolderModal';
import DocumentPreviewModal, { type PreviewTarget } from '@/components/documents/DocumentPreviewModal';
import Button from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';

// ── helpers ────────────────────────────────────────────────────────────────

function formatBytes(bytes: number): string {
  if (!bytes) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function mimeIcon(mime: string) {
  if (mime?.includes('pdf')) return '📄';
  if (mime?.includes('word') || mime?.includes('document')) return '📝';
  if (mime?.includes('sheet') || mime?.includes('excel')) return '📊';
  if (mime?.includes('image')) return '🖼️';
  return '📎';
}

// ── types ──────────────────────────────────────────────────────────────────

type Level = 'companies' | 'projects' | 'modules' | 'files';

interface OrgItem { id: number; name: string; projects_count: number; files_count: number; storage_size: number; logo_url?: string | null; }
interface ProjectItem { id: number; name: string; code?: string; files_count: number; storage_size: number; last_uploaded?: string; }
interface ModuleFolder { key: string; name: string; files_count: number; last_updated?: string; }
interface FileItem {
  id: number; original_name: string; mime_type: string; file_size: number;
  module_key?: string; created_at: string;
  uploader?: { id: number; name: string };
}
interface ListDocumentItem {
  id: number; original_name?: string; mime_type: string; file_size: number; created_at: string;
  organization?: { name?: string } | null;
  project?: { name?: string; code?: string } | null;
}
interface Crumb { label: string; level: Level; orgId?: number; packagePath?: string; }
interface TradePackageItem {
  id: number; name: string; key: string; files_count: number;
  package_code?: string | null; package_reference?: string | null;
  contractor_name?: string | null; description?: string | null;
}

// ── Subfolder display labels ────────────────────────────────────────────────

const SUBFOLDER_LABELS: Record<string, string> = {
  'contracts/main_contract':        'Main Contract',
  'contracts/consultant_agreement': 'Consultant Agreements',
  'contracts/supplier_agreement':   'Supplier Agreements',
  'contracts/subcontract':          'Subcontract Agreements',
};

// ── URL helpers ────────────────────────────────────────────────────────────

function buildCleanUrl(
  viewMode: 'list' | 'folder',
  selectedProject: ProjectItem | null,
  selectedModule: ModuleFolder | null,
  moduleKeyPath: string,
): string {
  const params = new URLSearchParams();
  if (viewMode !== 'folder') params.set('view', viewMode);
  if (selectedProject?.id) params.set('projectId', String(selectedProject.id));
  if (selectedModule?.key) params.set('module', selectedModule.key);

  if (moduleKeyPath && selectedModule?.key && moduleKeyPath !== selectedModule.key) {
    const rest = moduleKeyPath.slice(selectedModule.key.length + 1);
    const pkgMatch = rest.match(/^package\/(\d+)$/);
    if (pkgMatch) {
      params.set('packageId', pkgMatch[1]);
    } else if (rest) {
      params.set('folder', rest);
    }
  }

  const qs = params.toString();
  return qs ? `/admin/documents?${qs}` : '/admin/documents';
}

function initModuleKeyPath(module: string | null, packageId: string | null, folder: string | null): string {
  if (!module) return '';
  if (packageId) return `${module}/package/${packageId}`;
  if (folder) return `${module}/${folder}`;
  return module;
}

// ── Folder card ────────────────────────────────────────────────────────────

function FolderCard({ icon, title, subtitle, meta, description, fileCount, onClick, index = 0 }: {
  icon?: React.ReactNode; title: string; subtitle?: string;
  meta?: string; description?: string; fileCount?: number; onClick: () => void; index?: number;
}) {
  return (
    <button
      onClick={onClick}
      className="w-full text-left rounded-xl group transition-all duration-150 ss-animate-in"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 1px 2px rgba(0,0,0,0.04)', animationDelay: `${Math.min(index * 45, 360)}ms` }}
      onMouseEnter={e => {
        const el = e.currentTarget as HTMLElement;
        el.style.borderColor = 'var(--gold-50)';
        el.style.boxShadow = '0 4px 12px var(--gold-15)';
        el.style.transform = 'translateY(-1px)';
      }}
      onMouseLeave={e => {
        const el = e.currentTarget as HTMLElement;
        el.style.borderColor = 'var(--border)';
        el.style.boxShadow = '0 1px 2px rgba(0,0,0,0.04)';
        el.style.transform = 'translateY(0)';
      }}
      onMouseDown={e => { (e.currentTarget as HTMLElement).style.transform = 'scale(0.99)'; }}
      onMouseUp={e => { (e.currentTarget as HTMLElement).style.transform = 'translateY(-1px)'; }}
    >
      <div className="p-4">
        <div className="flex items-start gap-3">
          <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
            style={{ backgroundColor: 'var(--gold-15)', border: '1px solid var(--gold-15)' }}>
            {icon ?? <Folder size={20} style={{ color: 'var(--gold)' }} />}
          </div>
          <div className="flex-1 min-w-0">
            <p className="text-sm font-semibold truncate leading-snug" style={{ color: 'var(--text-primary)' }}>{title}</p>
            {subtitle && <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-secondary)' }}>{subtitle}</p>}
            {description && <p className="text-xs mt-1 truncate" style={{ color: 'var(--text-muted)' }}>{description}</p>}
          </div>
          <ChevronRight size={14}
            className="mt-0.5 flex-shrink-0 transition-all duration-150 opacity-40 group-hover:opacity-100 group-hover:translate-x-0.5"
            style={{ color: 'var(--gold)' }} />
        </div>
        {(meta || fileCount !== undefined) && (
          <div className="mt-3 pt-3 flex items-center gap-3" style={{ borderTop: '1px solid var(--border)' }}>
            {fileCount !== undefined && (
              <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full tabular-nums"
                style={{ backgroundColor: fileCount > 0 ? 'var(--gold-15)' : 'var(--bg-elevated)', color: fileCount > 0 ? 'var(--gold)' : 'var(--text-muted)' }}>
                <FileText size={9} />
                {fileCount} file{fileCount !== 1 ? 's' : ''}
              </span>
            )}
            {meta && <span className="text-[10px] ml-auto truncate tabular-nums" style={{ color: 'var(--text-muted)' }}>{meta}</span>}
          </div>
        )}
      </div>
    </button>
  );
}

// ── Breadcrumbs ────────────────────────────────────────────────────────────

function Breadcrumbs({ crumbs, onNavigate }: { crumbs: Crumb[]; onNavigate: (crumb: Crumb) => void }) {
  return (
    <nav className="flex items-center gap-0.5 flex-wrap min-w-0">
      {crumbs.map((crumb, i) => (
        <span key={i} className="flex items-center gap-0.5 min-w-0">
          {i > 0 && (
            <ChevronRight size={12} className="flex-shrink-0 mx-0.5" style={{ color: 'var(--text-muted)', opacity: 0.4 }} />
          )}
          {i < crumbs.length - 1 ? (
            <button
              onClick={() => onNavigate(crumb)}
              className="flex items-center gap-1 rounded-md px-1.5 py-1 text-xs transition-colors hover:bg-[var(--bg-hover)] max-w-[140px] truncate"
              style={{ color: 'var(--text-muted)' }}
            >
              {i === 0 ? <Home size={12} className="flex-shrink-0" /> : <span className="truncate">{crumb.label}</span>}
            </button>
          ) : (
            <span className="text-xs font-semibold px-1.5 py-1 truncate max-w-[200px]"
              style={{ color: 'var(--text-primary)' }}>
              {crumb.label}
            </span>
          )}
        </span>
      ))}
    </nav>
  );
}

// ── Skeleton cards ──────────────────────────────────────────────────────────

function SkeletonCards({ count = 6, cols = 3 }: { count?: number; cols?: number }) {
  const gridClass = cols === 4 ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
  return (
    <div className={`grid ${gridClass} gap-4`}>
      {[...Array(count)].map((_, i) => (
        <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      ))}
    </div>
  );
}

// ── Delete confirm modal ───────────────────────────────────────────────────

function DeleteConfirmModal({ fileName, onClose, onConfirm, deleting }: {
  fileName: string; onClose: () => void; onConfirm: () => void; deleting: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-sm rounded-2xl shadow-xl p-6 space-y-4 ss-animate-in"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Delete Document</h2>
        <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>Are you sure you want to delete this document?</p>
        <div className="rounded-lg px-3 py-2 text-sm font-medium truncate"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
          {fileName}
        </div>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>This action can be restored later.</p>
        <div className="flex justify-end gap-2 pt-1">
          <button onClick={onClose} disabled={deleting} className="px-4 py-2 rounded-lg text-sm"
            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button onClick={onConfirm} disabled={deleting}
            className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60"
            style={{ backgroundColor: '#ef4444', color: '#fff' }}>
            {deleting ? 'Deleting…' : 'Delete File'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── More dropdown for secondary toolbar actions ────────────────────────────

function MoreMenu({ viewMode, onViewMode, totalLabel }: {
  viewMode: 'folder' | 'list'; onViewMode: (m: 'folder' | 'list') => void;
  totalLabel: string;
}) {
  const [open, setOpen] = useState(false);
  const [dropVisible, setDropVisible] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    function handle(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    if (open) document.addEventListener('mousedown', handle);
    return () => document.removeEventListener('mousedown', handle);
  }, [open]);

  useEffect(() => {
    if (open) requestAnimationFrame(() => setDropVisible(true));
    else setDropVisible(false);
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(o => !o)}
        className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium transition-all"
        style={{
          backgroundColor: open ? 'var(--bg-hover)' : 'var(--bg-elevated)',
          border: '1px solid var(--border)',
          color: 'var(--text-secondary)',
        }}
      >
        <MoreHorizontal size={14} />
        <span className="hidden sm:inline">Options</span>
      </button>

      {open && (
        <div
          className="absolute right-0 top-full mt-2 w-56 rounded-2xl overflow-hidden z-30"
          style={{
            backgroundColor: 'var(--bg-surface)',
            border: '1px solid var(--border)',
            boxShadow: '0 12px 32px rgba(0,0,0,0.10), 0 2px 8px rgba(0,0,0,0.06)',
            transformOrigin: 'top right',
            transform: dropVisible ? 'scale(1) translateY(0)' : 'scale(0.97) translateY(-4px)',
            opacity: dropVisible ? 1 : 0,
            transition: 'transform 150ms cubic-bezier(0.16,1,0.3,1), opacity 120ms ease',
          }}
        >
          {/* Count + view toggle header */}
          <div className="px-4 pt-3 pb-2">
            <div className="flex items-center justify-between mb-2.5">
              <span className="text-[10px] uppercase tracking-widest font-semibold" style={{ color: 'var(--text-muted)' }}>
                {totalLabel}
              </span>
            </div>
            {/* View toggle — pill style */}
            <div className="flex gap-1 p-0.5 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
              {(['folder', 'list'] as const).map(mode => (
                <button key={mode} onClick={() => { onViewMode(mode); setOpen(false); }}
                  className="flex-1 flex items-center justify-center gap-1.5 py-1.5 rounded-full text-xs font-medium transition-all active:scale-[0.97]"
                  style={{
                    backgroundColor: viewMode === mode ? 'var(--gold)' : 'transparent',
                    color: viewMode === mode ? 'var(--accent-fg)' : 'var(--text-muted)',
                  }}>
                  {mode === 'folder' ? <LayoutGrid size={12} /> : <LayoutList size={12} />}
                  {mode === 'folder' ? 'Folders' : 'List'}
                </button>
              ))}
            </div>
          </div>

          <div style={{ borderTop: '1px solid var(--border)' }} />

          {/* Actions */}
          <div className="py-1.5">
            <Link href="/admin/documents/register" onClick={() => setOpen(false)}
              className="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-[var(--bg-hover)]"
              style={{ color: 'var(--text-secondary)' }}>
              <ClipboardList size={13} style={{ color: 'var(--text-muted)' }} />
              Document Register
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}

// ── Main component ─────────────────────────────────────────────────────────

export default function AdminDocumentsPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();

  const [viewMode, setViewMode] = useState<'list' | 'folder'>(() =>
    (searchParams.get('view') as 'list' | 'folder') ?? 'folder'
  );
  const [search, setSearch] = useState('');

  const [selectedOrg, setSelectedOrg] = useState<OrgItem | null>(null);
  const [selectedProject, setSelectedProject] = useState<ProjectItem | null>(() => {
    const id = parseInt(searchParams.get('projectId') ?? '');
    return id ? { id, name: '', code: undefined, files_count: 0, storage_size: 0 } : null;
  });
  const [selectedModule, setSelectedModule] = useState<ModuleFolder | null>(() => {
    const key = searchParams.get('module');
    return key ? { key, name: '', files_count: 0 } : null;
  });
  const [moduleKeyPath, setModuleKeyPath] = useState<string>(() =>
    initModuleKeyPath(searchParams.get('module'), searchParams.get('packageId'), searchParams.get('folder'))
  );

  const level: Level = !selectedProject?.id
    ? (!selectedOrg ? 'companies' : 'projects')
    : !selectedModule?.key
      ? 'modules'
      : 'files';

  const [showGenerateModal, setShowGenerateModal] = useState(false);
  const [showGenerateFolderModal, setShowGenerateFolderModal] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<{ id: number; name: string } | null>(null);
  const [activeMenu, setActiveMenu] = useState<number | null>(null);
  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);

  // ── URL sync ──
  useEffect(() => {
    const url = buildCleanUrl(viewMode, selectedProject, selectedModule, moduleKeyPath);
    router.replace(url, { scroll: false });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewMode, selectedProject?.id, selectedModule?.key, moduleKeyPath]);

  // ── Queries ──

  const { data: listData, isLoading: listLoading } = useQuery({
    queryKey: ['admin-documents', search],
    queryFn: () => api.get('/admin/documents', { params: search ? { search } : {} }).then(r => r.data),
    enabled: viewMode === 'list',
  });

  const { data: companiesData, isLoading: companiesLoading } = useQuery({
    queryKey: ['admin-doc-explorer-companies'],
    queryFn: () => api.get('/admin/documents/explorer').then(r => r.data),
    enabled: viewMode === 'folder' && level === 'companies',
  });

  const { data: projectsData, isLoading: projectsLoading } = useQuery({
    queryKey: ['admin-doc-explorer-projects', selectedOrg?.id],
    queryFn: () => api.get(`/admin/documents/explorer/company/${selectedOrg!.id}`).then(r => r.data),
    enabled: viewMode === 'folder' && level === 'projects' && !!selectedOrg,
  });

  const { data: modulesData, isLoading: modulesLoading } = useQuery({
    queryKey: ['admin-doc-explorer-modules', selectedProject?.id],
    queryFn: () => api.get(`/admin/documents/explorer/project/${selectedProject!.id}`).then(r => r.data),
    enabled: viewMode === 'folder' && !!selectedProject?.id,
  });

  const { data: moduleFilesData, isLoading: moduleFilesLoading } = useQuery({
    queryKey: ['admin-doc-explorer-files', selectedProject?.id, moduleKeyPath],
    queryFn: () =>
      api.get(`/admin/documents/explorer/project/${selectedProject!.id}/module/${moduleKeyPath}`).then(r => r.data),
    enabled: viewMode === 'folder' && level === 'files' && !!selectedProject?.id && !!moduleKeyPath,
  });

  // ── Hydrate names from API ──
  useEffect(() => {
    if (!modulesData) return;
    const p = modulesData.project;
    if (p) {
      setSelectedProject(prev => prev ? { ...prev, name: p.name ?? prev.name, code: p.code ?? prev.code } : null);
      if (p.organization) {
        setSelectedOrg(prev => prev ?? { id: p.organization.id, name: p.organization.name, projects_count: 0, files_count: 0, storage_size: 0 });
      }
    }
    const folders: ModuleFolder[] = modulesData.folders ?? [];
    if (selectedModule?.key && !selectedModule.name) {
      const match = folders.find(f => f.key === selectedModule.key);
      if (match) setSelectedModule(match);
    }
  }, [modulesData]); // eslint-disable-line react-hooks/exhaustive-deps

  // ── Mutations ──

  const deleteMutation = useMutation({
    mutationFn: (fileId: number) => api.delete(`/file-uploads/${fileId}`).then(r => r.data),
    onSuccess: () => {
      toast.success('Document deleted successfully.');
      setDeleteTarget(null);
      queryClient.invalidateQueries({ queryKey: ['admin-doc-explorer-files'] });
      queryClient.invalidateQueries({ queryKey: ['admin-doc-explorer-modules'] });
      queryClient.invalidateQueries({ queryKey: ['admin-doc-explorer-projects'] });
      queryClient.invalidateQueries({ queryKey: ['admin-doc-explorer-companies'] });
      queryClient.invalidateQueries({ queryKey: ['admin-documents'] });
    },
    onError: () => toast.error('Failed to delete document.'),
  });

  // ── Handlers ──

  const navigateTo = useCallback((crumb: Crumb) => {
    if (crumb.level === 'companies') { setSelectedOrg(null); setSelectedProject(null); setSelectedModule(null); setModuleKeyPath(''); }
    else if (crumb.level === 'projects') { setSelectedProject(null); setSelectedModule(null); setModuleKeyPath(''); }
    else if (crumb.level === 'modules') { setSelectedModule(null); setModuleKeyPath(''); }
    else if (crumb.level === 'files') {
      if (crumb.packagePath) setModuleKeyPath(crumb.packagePath);
      else setModuleKeyPath(selectedModule?.key ?? '');
    }
  }, [selectedModule?.key]);

  function downloadFile(fileId: number, fileName: string) {
    api.get(`/file-uploads/${fileId}/download`, { responseType: 'blob' }).then(res => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a'); a.href = url; a.download = fileName; a.click();
      window.URL.revokeObjectURL(url);
    });
  }

  // ── Data ──

  const listDocuments: ListDocumentItem[] = listData?.data ?? [];
  const companies: OrgItem[]              = companiesData?.companies ?? [];
  const projects: ProjectItem[]           = projectsData?.projects ?? [];
  const modules: ModuleFolder[]           = modulesData?.folders ?? [];
  const subfolders: ModuleFolder[]        = moduleFilesData?.folders ?? [];
  const tradePackages: TradePackageItem[] = moduleFilesData?.trade_packages ?? [];
  const files: FileItem[]                 = moduleFilesData?.data ?? [];
  const currentTradePackage: TradePackageItem | null =
    moduleFilesData?.trade_package ?? tradePackages.find(pkg => pkg.key === moduleKeyPath) ?? null;

  const isShowingFolders       = moduleFilesData?.type === 'folders' && subfolders.length > 0;
  const isShowingTradePackages = moduleFilesData?.type === 'trade_packages' && tradePackages.length >= 0;
  const isAtSubcontractsLevel  = viewMode === 'folder' && level === 'files' && isShowingTradePackages;
  const existingPackageNames   = tradePackages.map(pkg => pkg.name);

  const totalLabel = viewMode === 'list'
    ? `${listData?.total ?? 0} total`
    : level === 'companies' ? `${companies.length} companies`
    : level === 'projects'  ? `${projects.length} projects`
    : level === 'modules'   ? `${modules.length} folders`
    : isShowingFolders ? `${subfolders.length} folders`
    : isShowingTradePackages ? `${tradePackages.length} packages`
    : `${moduleFilesData?.total ?? 0} files`;

  // ── Breadcrumbs ──
  const crumbs: Crumb[] = [{ label: 'Documents', level: 'companies' }];
  if (selectedOrg)           crumbs.push({ label: selectedOrg.name, level: 'projects', orgId: selectedOrg.id });
  if (selectedProject?.name) crumbs.push({ label: selectedProject.name, level: 'modules' });
  if (selectedModule?.name) {
    const inSubfolder = moduleKeyPath && moduleKeyPath !== selectedModule.key && !currentTradePackage;
    if (inSubfolder) {
      crumbs.push({ label: selectedModule.name, level: 'files', packagePath: selectedModule.key });
      const subLabel = SUBFOLDER_LABELS[moduleKeyPath] ?? moduleKeyPath.split('/').pop()?.replace(/_/g, ' ') ?? moduleKeyPath;
      crumbs.push({ label: subLabel, level: 'files' });
    } else {
      crumbs.push({ label: selectedModule.name, level: 'files' });
    }
  }
  if (currentTradePackage && !crumbs.some(c => c.label === currentTradePackage.name)) {
    crumbs.push({ label: currentTradePackage.name, level: 'files', packagePath: currentTradePackage.key });
  }

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">

      {/* ── Header + toolbar ── */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Documents</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            {viewMode === 'folder' ? 'Browse documents by company → project → module' : 'All uploaded documents across the platform'}
          </p>
        </div>

        {/* Toolbar — primary action visible, secondary in More menu */}
        <div className="flex items-center gap-2 flex-wrap">
          {isAtSubcontractsLevel && (
            <button onClick={() => setShowGenerateFolderModal(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold transition-opacity hover:opacity-90 active:scale-[0.98]"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              <Box size={13} />
              <span className="hidden sm:inline">Generate Trade Package Folder</span>
              <span className="sm:hidden">Generate Folder</span>
            </button>
          )}

          <MoreMenu
            viewMode={viewMode}
            onViewMode={setViewMode}
            totalLabel={totalLabel}
          />
        </div>
      </div>

      {/* ── LIST VIEW ── */}
      {viewMode === 'list' && (
        <>
          <div className="relative max-w-sm">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search documents…"
              className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="grid grid-cols-[2.5fr_1.5fr_1.5fr_1fr_1fr_auto] gap-4 px-5 py-3 text-xs font-medium uppercase tracking-wider"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
              <span>File Name</span><span>Company</span><span>Project</span><span>Size</span><span>Uploaded</span><span />
            </div>
            {listLoading ? (
              <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
                {[...Array(8)].map((_, i) => (
                  <div key={i} className="px-5 py-4 h-12 animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', opacity: 0.5 }} />
                ))}
              </div>
            ) : listDocuments.length === 0 ? (
              <EmptyState icon={FileText}
                title="No documents found"
                description="No documents uploaded yet. Documents uploaded from project modules will appear here automatically." />
            ) : (
              <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
                {listDocuments.map(doc => (
                  <div key={doc.id} className="group grid grid-cols-[2.5fr_1.5fr_1.5fr_1fr_1fr_auto] gap-4 items-center px-5 py-3 hover:bg-[var(--bg-hover)] transition-colors">
                    <div className="flex items-center gap-3 min-w-0">
                      <span className="text-base flex-shrink-0">{mimeIcon(doc.mime_type)}</span>
                      <span className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{doc.original_name || '—'}</span>
                    </div>
                    <div className="flex items-center gap-1.5 min-w-0">
                      <Building2 size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                      <span className="text-sm truncate" style={{ color: 'var(--text-secondary)' }}>{doc.organization?.name ?? '—'}</span>
                    </div>
                    <div className="flex items-center gap-1.5 min-w-0">
                      <FolderOpen size={12} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                      <span className="text-sm truncate" style={{ color: 'var(--text-secondary)' }}>
                        {doc.project ? `${doc.project.name}${doc.project.code ? ` (${doc.project.code})` : ''}` : '—'}
                      </span>
                    </div>
                    <span className="text-sm tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatBytes(doc.file_size)}</span>
                    <span className="text-sm tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(doc.created_at)}</span>
                    <button
                      onClick={() => setPreviewTarget({ id: doc.id, name: doc.original_name || 'document', mimeType: doc.mime_type, previewEndpoint: `/file-uploads/${doc.id}/preview`, downloadEndpoint: `/file-uploads/${doc.id}/download` })}
                      className="p-1.5 rounded-lg opacity-0 group-hover:opacity-100 transition-all hover:bg-[var(--bg-hover)]"
                      title="Preview">
                      <Eye size={13} style={{ color: 'var(--text-muted)' }} />
                    </button>
                  </div>
                ))}
              </div>
            )}
            {listData && listData.total > listData.per_page && (
              <div className="px-5 py-3 text-xs" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                Showing {listData.from}–{listData.to} of {listData.total} documents
              </div>
            )}
          </div>
        </>
      )}

      {/* ── FOLDER VIEW ── */}
      {viewMode === 'folder' && (
        <>
          <div className="flex items-center gap-1.5 min-w-0">
            <Breadcrumbs crumbs={crumbs} onNavigate={navigateTo} />
          </div>

          {/* Companies */}
          {level === 'companies' && (
            companiesLoading ? <SkeletonCards /> :
            companies.length === 0 ? (
              <EmptyState title="No documents uploaded yet" description="Documents uploaded from project modules will appear here automatically." />
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {companies.map((org, i) => (
                  <FolderCard key={org.id} index={i}
                    icon={
                      org.logo_url
                        ? <img src={org.logo_url} alt={org.name} className="w-full h-full object-contain p-1" />
                        : <Building2 size={20} style={{ color: 'var(--gold)' }} />
                    }
                    title={org.name}
                    subtitle={`${org.projects_count} project${org.projects_count !== 1 ? 's' : ''}`}
                    fileCount={org.files_count}
                    meta={formatBytes(org.storage_size)}
                    onClick={() => setSelectedOrg(org)} />
                ))}
              </div>
            )
          )}

          {/* Projects */}
          {level === 'projects' && (
            projectsLoading ? <SkeletonCards /> :
            projects.length === 0 ? (
              <EmptyState title="No project documents" description="No project documents found for this company." />
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {projects.map((proj, i) => (
                  <FolderCard key={proj.id} index={i}
                    title={proj.name}
                    subtitle={proj.code ?? undefined}
                    fileCount={proj.files_count}
                    meta={`${formatBytes(proj.storage_size)}${proj.last_uploaded ? ` · ${formatDate(proj.last_uploaded)}` : ''}`}
                    onClick={() => setSelectedProject(proj)} />
                ))}
              </div>
            )
          )}

          {/* Module folders */}
          {level === 'modules' && (
            modulesLoading ? <SkeletonCards cols={4} count={13} /> : (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                {modules.map((mod, i) => (
                  <FolderCard key={mod.key} index={i}
                    title={mod.name}
                    fileCount={mod.files_count}
                    meta={mod.last_updated ? formatDate(mod.last_updated) : undefined}
                    onClick={() => { setSelectedModule(mod); setModuleKeyPath(mod.key); }} />
                ))}
              </div>
            )
          )}

          {/* Files / nested folders */}
          {level === 'files' && (
            moduleFilesLoading ? (
              <div className="space-y-2">
                {[...Array(5)].map((_, i) => <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
              </div>
            ) : isShowingFolders ? (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                {subfolders.map((folder, i) => (
                  <FolderCard key={folder.key} index={i} title={folder.name} fileCount={folder.files_count}
                    onClick={() => setModuleKeyPath(folder.key)} />
                ))}
              </div>
            ) : isShowingTradePackages ? (
              tradePackages.length === 0 ? (
                <EmptyState title="No trade packages yet" description="Trade package folders will appear here once they are created." />
              ) : (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                  {tradePackages.map((pkg, i) => (
                    <FolderCard key={pkg.key} index={i}
                      title={pkg.name}
                      fileCount={pkg.files_count}
                      subtitle={pkg.package_reference ?? pkg.package_code ?? undefined}
                      description={pkg.contractor_name ?? undefined}
                      onClick={() => setModuleKeyPath(pkg.key)} />
                  ))}
                </div>
              )
            ) : files.length === 0 ? (
              <div className="space-y-4">
                {currentTradePackage && <TradePackageHeader pkg={currentTradePackage} onGenerate={() => setShowGenerateModal(true)} />}
                <EmptyState
                  icon={FileText}
                  title={currentTradePackage ? `No files in ${currentTradePackage.name}` : selectedModule?.key === 'contracts' ? 'No contract uploaded yet' : `No files in ${selectedModule?.name ?? 'this folder'}`}
                  description={currentTradePackage
                    ? 'Generate a package from a template or upload files directly into this trade package.'
                    : selectedModule?.key === 'contracts'
                      ? 'The main contract should be uploaded before payment, variation, notice, and adjudication workflows.'
                      : undefined} />
              </div>
            ) : (
              <div className="space-y-4">
                {currentTradePackage && <TradePackageHeader pkg={currentTradePackage} onGenerate={() => setShowGenerateModal(true)} />}
                <div className="rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
                  <div className="grid grid-cols-[2.5fr_1.5fr_0.8fr_1fr_auto] gap-4 px-5 py-3 text-xs font-semibold uppercase tracking-wider rounded-t-2xl"
                    style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                    <span>File Name</span><span>Uploaded by</span><span>Size</span><span>Date</span><span />
                  </div>
                  <div>
                    {files.map((file, idx) => (
                      <div key={file.id}
                        className="group grid grid-cols-[2.5fr_1.5fr_0.8fr_1fr_auto] gap-4 items-center px-5 py-3 transition-colors hover:bg-[var(--bg-hover)]"
                        style={{ borderBottom: idx < files.length - 1 ? '1px solid var(--border)' : undefined }}>
                        <div className="flex items-center gap-3 min-w-0">
                          <div className="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 text-sm"
                            style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                            {mimeIcon(file.mime_type)}
                          </div>
                          <span className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{file.original_name}</span>
                        </div>
                        <span className="text-xs truncate" style={{ color: 'var(--text-secondary)' }}>{file.uploader?.name ?? '—'}</span>
                        <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatBytes(file.file_size)}</span>
                        <span className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{formatDate(file.created_at)}</span>
                        <div className="relative">
                          <button
                            onClick={() => setActiveMenu(activeMenu === file.id ? null : file.id)}
                            className="p-1.5 rounded-lg transition-all opacity-0 group-hover:opacity-100 hover:bg-[var(--bg-hover)]"
                            title="Actions">
                            <MoreVertical size={13} style={{ color: 'var(--text-muted)' }} />
                          </button>
                          {activeMenu === file.id && (
                            <div className="absolute right-0 top-full mt-1 z-50 w-44 rounded-xl shadow-lg overflow-hidden"
                              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                              <button onClick={() => { setPreviewTarget({ id: file.id, name: file.original_name, mimeType: file.mime_type, previewEndpoint: `/file-uploads/${file.id}/preview`, downloadEndpoint: `/file-uploads/${file.id}/download` }); setActiveMenu(null); }}
                                className="flex w-full items-center gap-2 px-3 py-2.5 text-sm hover:bg-[var(--bg-hover)] transition-colors"
                                style={{ color: 'var(--text-primary)' }}>
                                <Eye size={13} /> Preview
                              </button>
                              <button onClick={() => { downloadFile(file.id, file.original_name); setActiveMenu(null); }}
                                className="flex w-full items-center gap-2 px-3 py-2.5 text-sm hover:bg-[var(--bg-hover)] transition-colors"
                                style={{ color: 'var(--text-primary)' }}>
                                <Download size={13} /> Download
                              </button>
                              <button onClick={() => { setDeleteTarget({ id: file.id, name: file.original_name }); setActiveMenu(null); }}
                                className="flex w-full items-center gap-2 px-3 py-2.5 text-sm hover:bg-[rgba(239,68,68,0.06)] transition-colors"
                                style={{ color: '#ef4444' }}>
                                <Trash2 size={13} /> Delete
                              </button>
                            </div>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                  {moduleFilesData && moduleFilesData.total > moduleFilesData.per_page && (
                    <div className="px-5 py-3 text-xs" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                      Showing {moduleFilesData.from}–{moduleFilesData.to} of {moduleFilesData.total} files
                    </div>
                  )}
                </div>
              </div>
            )
          )}
        </>
      )}

      {showGenerateFolderModal && selectedProject && (
        <GenerateTradePackageFolderModal
          isOpen={showGenerateFolderModal}
          onClose={() => setShowGenerateFolderModal(false)}
          projectReference={selectedProject.code ?? ''}
          projectId={selectedProject.id}
          existingPackageNames={existingPackageNames}
          onSuccess={() => {
            setShowGenerateFolderModal(false);
            queryClient.invalidateQueries({ queryKey: ['admin-doc-explorer-files'] });
          }} />
      )}

      {showGenerateModal && currentTradePackage && selectedProject && (
        <GeneratePackageModal
          projectId={String(selectedProject.id)}
          tradePackage={currentTradePackage}
          onClose={() => setShowGenerateModal(false)}
          onViewInPackage={() => {
            setShowGenerateModal(false);
            queryClient.invalidateQueries({ queryKey: ['admin-doc-explorer-files'] });
          }} />
      )}

      {deleteTarget && (
        <DeleteConfirmModal
          fileName={deleteTarget.name}
          onClose={() => setDeleteTarget(null)}
          onConfirm={() => deleteMutation.mutate(deleteTarget.id)}
          deleting={deleteMutation.isPending} />
      )}

      {previewTarget && (
        <DocumentPreviewModal target={previewTarget} onClose={() => setPreviewTarget(null)} />
      )}
    </div>
  );
}

// ── Trade package header card ──────────────────────────────────────────────

function TradePackageHeader({ pkg, onGenerate }: { pkg: TradePackageItem; onGenerate: () => void }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl p-4"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      <div>
        <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{pkg.name}</p>
        <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
          {pkg.package_reference || pkg.package_code || 'Trade package folder'}
        </p>
      </div>
      <button onClick={onGenerate}
        className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-opacity hover:opacity-90 active:scale-[0.98]"
        style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
        <Wand2 size={14} />
        Generate Package
      </button>
    </div>
  );
}
