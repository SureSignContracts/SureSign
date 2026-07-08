'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Link from 'next/link';
import {
  Box, ChevronRight, ClipboardList, Download, Eye, FileText, Folder, FolderOpen,
  Home, LayoutGrid, LayoutList, MoreVertical, Settings2, Trash2, Upload, Wand2,
} from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import toast from 'react-hot-toast';
import DocumentPreviewModal, { type PreviewTarget } from '@/components/documents/DocumentPreviewModal';
import GeneratePackageModal from '@/components/documents/GeneratePackageModal';
import GenerateTradePackageFolderModal from '@/components/documents/GenerateTradePackageFolderModal';

// ── helpers ────────────────────────────────────────────────────────────────

function formatBytes(bytes: number): string {
  if (!bytes) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const TYPE_COLOR: Record<string, string> = {
  pdf: '#f87171', docx: '#60a5fa', doc: '#60a5fa',
  xlsx: '#4ade80', xls: '#4ade80', csv: '#4ade80',
  png: '#c084fc', jpg: '#c084fc', jpeg: '#c084fc', gif: '#c084fc', webp: '#c084fc',
};

function FileTypeBadge({ name, mimeType }: { name?: string; mimeType?: string }) {
  const ext = name?.split('.').pop()?.toLowerCase() ?? '';
  const mime = mimeType?.includes('pdf') ? 'pdf'
    : mimeType?.includes('word') || mimeType?.includes('document') ? 'docx'
    : mimeType?.includes('sheet') || mimeType?.includes('excel') ? 'xlsx'
    : mimeType?.includes('image') ? 'img' : '';
  const key = ext || mime || 'doc';
  const color = TYPE_COLOR[key] || '#9a9490';
  return (
    <div className="w-8 h-8 rounded-md flex items-center justify-center flex-shrink-0 text-[10px] font-bold uppercase"
      style={{ backgroundColor: `${color}20`, color }}>
      {key.substring(0, 3)}
    </div>
  );
}

// ── types ──────────────────────────────────────────────────────────────────

type Level = 'projects' | 'modules' | 'files';

interface ProjectItem { id: number; name: string; code?: string | null; status?: string; }
interface ModuleFolder { key: string; name: string; files_count: number; last_updated?: string | null; }
interface TradePackageItem {
  id: number; key: string; name: string; files_count: number;
  package_code?: string | null; package_reference?: string | null;
  contractor_name?: string | null; description?: string | null;
}
interface FileItem {
  id: number; original_name: string; mime_type: string; file_size: number;
  created_at: string; uploader?: { id: number; name: string };
}
interface Crumb { label: string; level: Level; packagePath?: string; }

// ── URL state helpers ──────────────────────────────────────────────────────

function buildUrl(viewMode: 'folder' | 'list', projectId?: number, moduleKey?: string, _moduleKeyPath?: string, packageId?: string): string {
  const p = new URLSearchParams();
  if (viewMode !== 'folder') p.set('view', viewMode);
  if (projectId) p.set('projectId', String(projectId));
  if (moduleKey) p.set('module', moduleKey);
  if (packageId) p.set('packageId', packageId);
  const qs = p.toString();
  return qs ? `/app/documents?${qs}` : '/app/documents';
}

// ── Shared UI components ───────────────────────────────────────────────────

function FolderCard({ icon, title, subtitle, meta, fileCount, onClick }: {
  icon?: React.ReactNode; title: string; subtitle?: string; meta?: string;
  fileCount?: number; onClick: () => void;
}) {
  return (
    <button onClick={onClick}
      className="w-full text-left rounded-xl group transition-all duration-150"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 1px 2px rgba(0,0,0,0.04)' }}
      onMouseEnter={e => { const el = e.currentTarget as HTMLElement; el.style.borderColor = 'var(--gold-50)'; el.style.boxShadow = '0 4px 12px var(--gold-15)'; el.style.transform = 'translateY(-1px)'; }}
      onMouseLeave={e => { const el = e.currentTarget as HTMLElement; el.style.borderColor = 'var(--border)'; el.style.boxShadow = '0 1px 2px rgba(0,0,0,0.04)'; el.style.transform = 'translateY(0)'; }}
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
          </div>
          <ChevronRight size={14} className="mt-0.5 flex-shrink-0 opacity-40 group-hover:opacity-100 group-hover:translate-x-0.5 transition-all duration-150" style={{ color: 'var(--gold)' }} />
        </div>
        {(meta || fileCount !== undefined) && (
          <div className="mt-3 pt-3 flex items-center gap-3" style={{ borderTop: '1px solid var(--border)' }}>
            {fileCount !== undefined && (
              <span className="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full"
                style={{ backgroundColor: fileCount > 0 ? 'var(--gold-15)' : 'var(--bg-elevated)', color: fileCount > 0 ? 'var(--gold)' : 'var(--text-muted)' }}>
                <FileText size={9} />{fileCount} file{fileCount !== 1 ? 's' : ''}
              </span>
            )}
            {meta && <span className="text-[10px] ml-auto truncate" style={{ color: 'var(--text-muted)' }}>{meta}</span>}
          </div>
        )}
      </div>
    </button>
  );
}

function Breadcrumbs({ crumbs, onNavigate }: { crumbs: Crumb[]; onNavigate: (crumb: Crumb) => void }) {
  return (
    <nav className="flex items-center gap-0.5 flex-wrap min-w-0">
      {crumbs.map((crumb, i) => (
        <span key={i} className="flex items-center gap-0.5 min-w-0">
          {i > 0 && <ChevronRight size={12} className="flex-shrink-0 mx-0.5" style={{ color: 'var(--text-muted)', opacity: 0.4 }} />}
          {i < crumbs.length - 1 ? (
            <button onClick={() => onNavigate(crumb)}
              className="flex items-center gap-1 rounded-md px-1.5 py-1 text-xs transition-colors hover:bg-[var(--bg-hover)] max-w-[140px] truncate"
              style={{ color: 'var(--text-muted)' }}>
              {i === 0 ? <Home size={12} className="flex-shrink-0" /> : <span className="truncate">{crumb.label}</span>}
            </button>
          ) : (
            <span className="text-xs font-semibold px-1.5 py-1 truncate max-w-[200px]" style={{ color: 'var(--text-primary)' }}>
              {crumb.label}
            </span>
          )}
        </span>
      ))}
    </nav>
  );
}

function EmptyState({ title, body }: { title: string; body?: string }) {
  return (
    <div className="py-20 text-center">
      <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-4" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        <FolderOpen size={24} style={{ color: 'var(--text-muted)' }} />
      </div>
      <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>{title}</p>
      {body && <p className="text-xs max-w-sm mx-auto" style={{ color: 'var(--text-muted)' }}>{body}</p>}
    </div>
  );
}

function SkeletonCards({ count = 6, cols = 3 }: { count?: number; cols?: number }) {
  const cls = cols === 4 ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
  return (
    <div className={`grid ${cls} gap-4`}>
      {[...Array(count)].map((_, i) => (
        <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      ))}
    </div>
  );
}

function DeleteConfirmModal({ fileName, onClose, onConfirm, deleting }: {
  fileName: string; onClose: () => void; onConfirm: () => void; deleting: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-sm rounded-2xl shadow-xl p-6 space-y-4"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
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
          <button onClick={onConfirm} disabled={deleting} className="px-4 py-2 rounded-lg text-sm font-medium disabled:opacity-60"
            style={{ backgroundColor: '#ef4444', color: '#fff' }}>
            {deleting ? 'Deleting…' : 'Delete File'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Main page ──────────────────────────────────────────────────────────────

export default function DocumentsPage() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const queryClient = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);

  const [viewMode, setViewMode] = useState<'folder' | 'list'>(() =>
    (searchParams.get('view') as 'folder' | 'list') ?? 'folder'
  );
  const [uploading, setUploading] = useState(false);

  const [selectedProject, setSelectedProject] = useState<ProjectItem | null>(() => {
    const id = parseInt(searchParams.get('projectId') ?? '');
    return id ? { id, name: '' } : null;
  });
  const [selectedModule, setSelectedModule] = useState<ModuleFolder | null>(() => {
    const key = searchParams.get('module');
    return key ? { key, name: '', files_count: 0 } : null;
  });
  const [moduleKeyPath, setModuleKeyPath] = useState<string>(() => {
    const module = searchParams.get('module');
    const pkg = searchParams.get('packageId');
    if (!module) return '';
    return pkg ? `${module}/package/${pkg}` : module;
  });

  const level: Level = !selectedProject ? 'projects' : !selectedModule ? 'modules' : 'files';

  const [previewTarget, setPreviewTarget] = useState<PreviewTarget | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<{ id: number; name: string } | null>(null);
  const [activeMenu, setActiveMenu] = useState<number | null>(null);
  const [showGenerateModal, setShowGenerateModal] = useState(false);
  const [showFolderModal, setShowFolderModal] = useState(false);
  const [showOptionsMenu, setShowOptionsMenu] = useState(false);
  const optionsRef = useRef<HTMLDivElement>(null);

  // Sync URL
  useEffect(() => {
    const pkg = moduleKeyPath.match(/^.+\/package\/(\d+)$/)?.[1];
    const url = buildUrl(viewMode, selectedProject?.id, selectedModule?.key, moduleKeyPath, pkg);
    router.replace(url, { scroll: false });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [viewMode, selectedProject?.id, selectedModule?.key, moduleKeyPath]);

  // Close menus on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (activeMenu) setActiveMenu(null);
      if (showOptionsMenu && optionsRef.current && !optionsRef.current.contains(e.target as Node)) {
        setShowOptionsMenu(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [activeMenu, showOptionsMenu]);

  // ── Queries ──

  const { data: projectsData, isLoading: projectsLoading } = useQuery({
    queryKey: ['client-doc-projects'],
    queryFn: () => api.get('/projects').then(r => r.data),
    enabled: viewMode === 'folder' && level === 'projects',
  });

  const { data: modulesData, isLoading: modulesLoading } = useQuery({
    queryKey: ['client-doc-modules', selectedProject?.id],
    queryFn: () => api.get(`/projects/${selectedProject!.id}/documents/explorer`).then(r => r.data),
    enabled: viewMode === 'folder' && !!selectedProject?.id,
  });

  const { data: moduleFilesData, isLoading: moduleFilesLoading } = useQuery({
    queryKey: ['client-doc-files', selectedProject?.id, moduleKeyPath],
    queryFn: () => api.get(`/projects/${selectedProject!.id}/documents/module/${moduleKeyPath}`).then(r => r.data),
    enabled: viewMode === 'folder' && level === 'files' && !!selectedProject?.id && !!moduleKeyPath,
  });

  // List view uses the same folder-view queries — it's just a layout preference, not a separate data source.

  // Hydrate project name from module explorer response
  useEffect(() => {
    const p = modulesData?.project;
    if (p && selectedProject) setSelectedProject(prev => prev ? { ...prev, name: p.name, code: p.code } : null);
    if (selectedModule?.key && !selectedModule.name) {
      const match = (modulesData?.folders ?? []).find((f: ModuleFolder) => f.key === selectedModule.key);
      if (match) setSelectedModule(match);
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [modulesData]);

  // ── Derived data ──

  const projects: ProjectItem[]            = projectsData?.data ?? [];
  const modules: ModuleFolder[]            = modulesData?.folders ?? [];
  const subfolders: ModuleFolder[]         = moduleFilesData?.folders ?? [];
  const tradePackages: TradePackageItem[]  = moduleFilesData?.trade_packages ?? [];
  const files: FileItem[]                  = moduleFilesData?.data ?? [];
  const currentTradePackage: TradePackageItem | null = moduleFilesData?.trade_package ?? null;

  const isShowingFolders      = moduleFilesData?.type === 'folders';
  const isShowingTradePackages = moduleFilesData?.type === 'trade_packages';

  // ── Breadcrumbs ──
  const crumbs: Crumb[] = [{ label: 'Documents', level: 'projects' }];
  if (selectedProject?.name) crumbs.push({ label: selectedProject.name, level: 'modules' });
  if (selectedModule?.name)  crumbs.push({ label: selectedModule.name, level: 'files' });
  if (currentTradePackage && !crumbs.some(c => c.label === currentTradePackage.name)) {
    crumbs.push({ label: currentTradePackage.name, level: 'files', packagePath: currentTradePackage.key });
  }

  // ── Navigation ──
  const navigateTo = useCallback((crumb: Crumb) => {
    if (crumb.level === 'projects') {
      setSelectedProject(null); setSelectedModule(null); setModuleKeyPath('');
    } else if (crumb.level === 'modules') {
      setSelectedModule(null); setModuleKeyPath('');
    } else if (crumb.level === 'files') {
      if (crumb.packagePath) setModuleKeyPath(crumb.packagePath);
      else setModuleKeyPath(selectedModule?.key ?? '');
    }
  }, [selectedModule?.key]);

  // ── Upload ──
  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file || !selectedProject) return;
    setUploading(true);
    try {
      const fd = new FormData();
      fd.append('file', file);
      if (selectedModule?.key) fd.append('module_key', selectedModule.key);
      await api.post(`/projects/${selectedProject.id}/files`, fd, { headers: { 'Content-Type': 'multipart/form-data' } });
      queryClient.invalidateQueries({ queryKey: ['client-doc-files', selectedProject.id] });
      queryClient.invalidateQueries({ queryKey: ['client-doc-modules', selectedProject.id] });
      toast.success('File uploaded');
    } catch {
      toast.error('Upload failed');
    } finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  // ── Download ──
  const downloadFile = (fileId: number, fileName: string) => {
    api.get(`/file-uploads/${fileId}/download`, { responseType: 'blob' }).then(res => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a'); a.href = url; a.download = fileName; a.click();
      window.URL.revokeObjectURL(url);
    }).catch(() => toast.error('Download failed'));
  };

  // ── Delete ──
  const deleteMutation = useMutation({
    mutationFn: (fileId: number) => api.delete(`/file-uploads/${fileId}`).then(r => r.data),
    onSuccess: () => {
      toast.success('Document deleted.');
      setDeleteTarget(null);
      queryClient.invalidateQueries({ queryKey: ['client-doc-files', selectedProject?.id] });
      queryClient.invalidateQueries({ queryKey: ['client-doc-modules', selectedProject?.id] });
    },
    onError: () => toast.error('Failed to delete document.'),
  });


  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">

      {/* ── Header ── */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Documents</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Browse documents by project and module</p>
        </div>

        <div className="flex items-center gap-2">
          {/* Generate Trade Packages — visible standalone when at trade packages level */}
          {isShowingTradePackages && selectedProject && (
            <button onClick={() => setShowFolderModal(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              <Box size={13} />
              Generate Trade Packages
            </button>
          )}

          {/* Generate Document — visible standalone when inside a trade package */}
          {currentTradePackage && selectedProject && (
            <button onClick={() => setShowGenerateModal(true)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold transition-opacity hover:opacity-90"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              <Wand2 size={13} />
              Generate Document
            </button>
          )}

          {/* Options dropdown */}
          <div ref={optionsRef} className="relative">
            <button
              onClick={() => setShowOptionsMenu(v => !v)}
              className="flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-semibold transition-colors hover:bg-[var(--bg-hover)]"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)' }}>
              <Settings2 size={13} />
              Options
            </button>

            {showOptionsMenu && (
              <div className="absolute right-0 top-full mt-1 z-50 w-56 rounded-xl shadow-xl overflow-hidden"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>

                {/* View toggle */}
                <div className="px-4 py-2 border-b" style={{ borderColor: 'var(--border)' }}>
                  <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>View</p>
                  <div className="flex overflow-hidden rounded-lg" style={{ border: '1px solid var(--border)' }}>
                    {(['folder', 'list'] as const).map(mode => (
                      <button key={mode} onClick={() => setViewMode(mode)}
                        className="flex flex-1 items-center justify-center gap-1.5 py-1.5 text-xs font-medium transition-colors"
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

                {/* Upload file — only inside a project */}
                {selectedProject?.id && (
                  <button onClick={() => { fileInputRef.current?.click(); setShowOptionsMenu(false); }}
                    disabled={uploading}
                    className="flex w-full items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-[var(--bg-hover)] transition-colors disabled:opacity-50"
                    style={{ color: 'var(--text-primary)' }}>
                    <Upload size={14} style={{ color: 'var(--gold)' }} />
                    {uploading ? 'Uploading…' : 'Upload File'}
                  </button>
                )}

                {/* Document Register */}
                {selectedProject?.id && (
                  <>
                    <div style={{ borderTop: '1px solid var(--border)' }} />
                    <Link href={`/app/documents/register?projectId=${selectedProject.id}`}
                      onClick={() => setShowOptionsMenu(false)}
                      className="flex w-full items-center gap-2.5 px-4 py-2.5 text-sm hover:bg-[var(--bg-hover)] transition-colors"
                      style={{ color: 'var(--text-primary)' }}>
                      <ClipboardList size={14} style={{ color: 'var(--gold)' }} />
                      Document Register
                    </Link>
                  </>
                )}
              </div>
            )}
          </div>

          {/* Hidden file input */}
          <input ref={fileInputRef} type="file" className="hidden" onChange={handleUpload} />
        </div>
      </div>

      {/* ── FOLDER / LIST VIEW (same hierarchy, different layout) ── */}
      <>
          {/* Breadcrumbs */}
          <Breadcrumbs crumbs={crumbs} onNavigate={navigateTo} />

          {/* Projects level */}
          {level === 'projects' && (
            projectsLoading ? <SkeletonCards /> :
            projects.length === 0 ? (
              <EmptyState title="No projects found" body="Projects will appear here once they have been created." />
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {projects.map(proj => (
                  <FolderCard key={proj.id}
                    title={proj.name}
                    subtitle={proj.code ?? undefined}
                    meta={proj.status ?? undefined}
                    onClick={() => setSelectedProject(proj)} />
                ))}
              </div>
            )
          )}

          {/* Modules level */}
          {level === 'modules' && (
            modulesLoading ? <SkeletonCards cols={4} count={13} /> : (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                {modules.map(mod => (
                  <FolderCard key={mod.key}
                    title={mod.name}
                    fileCount={mod.files_count}
                    meta={mod.last_updated ? formatDate(mod.last_updated) : undefined}
                    onClick={() => { setSelectedModule(mod); setModuleKeyPath(mod.key); }} />
                ))}
              </div>
            )
          )}

          {/* Files level */}
          {level === 'files' && (
            moduleFilesLoading ? (
              <div className="space-y-2">
                {[...Array(5)].map((_, i) => <div key={i} className="h-16 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />)}
              </div>
            ) : isShowingFolders ? (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                {subfolders.map(folder => (
                  <FolderCard key={folder.key} title={folder.name} fileCount={folder.files_count}
                    onClick={() => setModuleKeyPath(folder.key)} />
                ))}
              </div>
            ) : isShowingTradePackages ? (
              tradePackages.length === 0 ? (
                <EmptyState title="No trade packages yet" body="Trade package folders will appear here once they are created." />
              ) : (
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                  {tradePackages.map(pkg => (
                    <FolderCard key={pkg.key}
                      title={pkg.name}
                      fileCount={pkg.files_count}
                      subtitle={pkg.package_reference ?? pkg.package_code ?? undefined}
                      meta={pkg.contractor_name ?? undefined}
                      onClick={() => setModuleKeyPath(pkg.key)} />
                  ))}
                </div>
              )
            ) : (
              <div className="space-y-4">
                {/* Trade package header with Generate button */}
                {currentTradePackage && (
                  <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl p-4"
                    style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                    <div>
                      <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{currentTradePackage.name}</p>
                      <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>
                        {currentTradePackage.package_reference || currentTradePackage.package_code || 'Trade package folder'}
                      </p>
                    </div>
                    <button onClick={() => setShowGenerateModal(true)}
                      className="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition-opacity hover:opacity-90"
                      style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                      <Wand2 size={14} />
                      Generate Package
                    </button>
                  </div>
                )}

                {files.length === 0 ? (
                  <EmptyState
                    title={currentTradePackage ? `No files in ${currentTradePackage.name}` : `No files in ${selectedModule?.name ?? 'this folder'}`}
                    body={currentTradePackage ? 'Generate a package from a template or upload files directly.' : undefined} />
                ) : (
                  <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                    <div className="grid grid-cols-[2.5fr_1.5fr_0.8fr_1fr_auto] gap-4 px-5 py-3 text-xs font-semibold uppercase tracking-wider rounded-t-2xl"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
                      <span>File Name</span><span>Uploaded by</span><span>Size</span><span>Date</span><span />
                    </div>
                    <div>
                      {files.map((file, idx) => (
                        <div key={file.id}
                          className="group grid grid-cols-[2.5fr_1.5fr_0.8fr_1fr_auto] gap-4 items-center px-5 py-3 transition-colors hover:bg-[var(--bg-hover)]"
                          style={{ borderBottom: idx < files.length - 1 ? '1px solid var(--border)' : undefined }}>
                          <div className="flex items-center gap-2.5 min-w-0">
                            <FileTypeBadge name={file.original_name} mimeType={file.mime_type} />
                            <span className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{file.original_name}</span>
                          </div>
                          <span className="text-xs truncate" style={{ color: 'var(--text-secondary)' }}>{file.uploader?.name ?? '—'}</span>
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{formatBytes(file.file_size)}</span>
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{formatDate(file.created_at)}</span>

                          {/* Actions dropdown */}
                          <div className="relative" onClick={e => e.stopPropagation()}>
                            <button onClick={() => setActiveMenu(activeMenu === file.id ? null : file.id)}
                              className="p-1.5 rounded-lg transition-all opacity-0 group-hover:opacity-100 hover:bg-[var(--bg-hover)]"
                              title="Actions">
                              <MoreVertical size={13} style={{ color: 'var(--text-muted)' }} />
                            </button>
                            {activeMenu === file.id && (
                              <div className="absolute right-0 top-full mt-1 z-50 w-40 rounded-xl shadow-lg overflow-hidden"
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
                    {moduleFilesData?.total > moduleFilesData?.per_page && (
                      <div className="px-5 py-3 text-xs" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                        Showing {moduleFilesData.from}–{moduleFilesData.to} of {moduleFilesData.total} files
                      </div>
                    )}
                  </div>
                )}
              </div>
            )
          )}
      </>

      {/* ── Modals ── */}
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

      {showGenerateModal && currentTradePackage && selectedProject && (
        <GeneratePackageModal
          projectId={String(selectedProject.id)}
          tradePackage={currentTradePackage}
          onClose={() => setShowGenerateModal(false)}
          onViewInPackage={() => {
            setShowGenerateModal(false);
            queryClient.invalidateQueries({ queryKey: ['client-doc-files', selectedProject.id, moduleKeyPath] });
          }} />
      )}

      {showFolderModal && selectedProject && (
        <GenerateTradePackageFolderModal
          isOpen={showFolderModal}
          onClose={() => setShowFolderModal(false)}
          projectId={selectedProject.id}
          projectReference={selectedProject.code ?? ''}
          existingPackageNames={tradePackages.map(p => p.name)}
          apiPath={`/projects/${selectedProject.id}/subcontracts/generate-trade-packages`}
          onSuccess={() => {
            setShowFolderModal(false);
            queryClient.invalidateQueries({ queryKey: ['client-doc-files', selectedProject.id, moduleKeyPath] });
          }} />
      )}
    </div>
  );
}
