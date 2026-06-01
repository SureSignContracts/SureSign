'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import {
  FileText, Search, Building2, FolderOpen, Download,
  ChevronRight, Folder, LayoutList, LayoutGrid, Home,
} from 'lucide-react';

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

interface OrgItem { id: number; name: string; projects_count: number; files_count: number; storage_size: number; }
interface ProjectItem { id: number; name: string; code?: string; files_count: number; storage_size: number; last_uploaded?: string; }
interface ModuleFolder { key: string; name: string; files_count: number; last_updated?: string; }
interface FileItem {
  id: number; original_name: string; mime_type: string; file_size: number;
  module_key?: string; created_at: string;
  uploader?: { id: number; name: string };
}
interface Crumb { label: string; level: Level; orgId?: number; projectId?: number; }

// ── Folder card ────────────────────────────────────────────────────────────

function FolderCard({ icon, title, subtitle, meta, onClick }: {
  icon?: React.ReactNode; title: string; subtitle?: string; meta?: string; onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      className="w-full text-left p-4 rounded-xl transition-colors hover:border-[var(--gold)] group"
      style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
    >
      <div className="flex items-start gap-3">
        <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0"
          style={{ backgroundColor: 'rgba(185,149,102,0.12)' }}>
          {icon ?? <Folder size={20} style={{ color: 'var(--gold)' }} />}
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{title}</p>
          {subtitle && <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
          {meta && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{meta}</p>}
        </div>
        <ChevronRight size={14} className="mt-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
          style={{ color: 'var(--text-muted)' }} />
      </div>
    </button>
  );
}

// ── Breadcrumbs ────────────────────────────────────────────────────────────

function Breadcrumbs({ crumbs, onNavigate }: { crumbs: Crumb[]; onNavigate: (crumb: Crumb) => void }) {
  return (
    <nav className="flex items-center gap-1 text-sm flex-wrap">
      {crumbs.map((crumb, i) => (
        <span key={i} className="flex items-center gap-1">
          {i > 0 && <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />}
          {i < crumbs.length - 1 ? (
            <button className="hover:underline" style={{ color: 'var(--gold)' }} onClick={() => onNavigate(crumb)}>
              {i === 0 ? <Home size={14} className="inline" /> : crumb.label}
            </button>
          ) : (
            <span style={{ color: 'var(--text-primary)' }} className="font-medium">{crumb.label}</span>
          )}
        </span>
      ))}
    </nav>
  );
}

// ── Empty state ────────────────────────────────────────────────────────────

function EmptyState({ icon, title, body }: { icon?: React.ReactNode; title: string; body?: string }) {
  return (
    <div className="py-20 text-center">
      <div className="w-14 h-14 rounded-2xl flex items-center justify-center mx-auto mb-4"
        style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {icon ?? <FolderOpen size={24} style={{ color: 'var(--text-muted)' }} />}
      </div>
      <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>{title}</p>
      {body && <p className="text-xs max-w-sm mx-auto" style={{ color: 'var(--text-muted)' }}>{body}</p>}
    </div>
  );
}

// ── Skeleton rows ──────────────────────────────────────────────────────────

function SkeletonCards({ count = 6, cols = 3 }: { count?: number; cols?: number }) {
  const gridClass = cols === 4 ? 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-4' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
  return (
    <div className={`grid ${gridClass} gap-4`}>
      {[...Array(count)].map((_, i) => (
        <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
      ))}
    </div>
  );
}

// ── Main component ─────────────────────────────────────────────────────────

export default function AdminDocumentsPage() {
  const [viewMode, setViewMode] = useState<'list' | 'folder'>('folder');
  const [search, setSearch] = useState('');

  // Folder explorer state
  const [level, setLevel] = useState<Level>('companies');
  const [selectedOrg, setSelectedOrg] = useState<OrgItem | null>(null);
  const [selectedProject, setSelectedProject] = useState<ProjectItem | null>(null);
  const [selectedModule, setSelectedModule] = useState<ModuleFolder | null>(null);

  const crumbs: Crumb[] = [{ label: 'Documents', level: 'companies' }];
  if (selectedOrg)     crumbs.push({ label: selectedOrg.name, level: 'projects', orgId: selectedOrg.id });
  if (selectedProject) crumbs.push({ label: selectedProject.name, level: 'modules', projectId: selectedProject.id });
  if (selectedModule)  crumbs.push({ label: selectedModule.name, level: 'files' });

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
    enabled: viewMode === 'folder' && level === 'modules' && !!selectedProject,
  });

  const { data: moduleFilesData, isLoading: moduleFilesLoading } = useQuery({
    queryKey: ['admin-doc-explorer-files', selectedProject?.id, selectedModule?.key],
    queryFn: () =>
      api.get(`/admin/documents/explorer/project/${selectedProject!.id}/module/${selectedModule!.key}`)
        .then(r => r.data),
    enabled: viewMode === 'folder' && level === 'files' && !!selectedProject && !!selectedModule,
  });

  // ── Handlers ──

  function navigateTo(crumb: Crumb) {
    setLevel(crumb.level);
    if (crumb.level === 'companies') { setSelectedOrg(null); setSelectedProject(null); setSelectedModule(null); }
    else if (crumb.level === 'projects') { setSelectedProject(null); setSelectedModule(null); }
    else if (crumb.level === 'modules') { setSelectedModule(null); }
  }

  function downloadFile(fileId: number, fileName: string) {
    api.get(`/file-uploads/${fileId}/download`, { responseType: 'blob' }).then(res => {
      const url = window.URL.createObjectURL(new Blob([res.data]));
      const a = document.createElement('a'); a.href = url; a.download = fileName; a.click();
      window.URL.revokeObjectURL(url);
    });
  }

  // ── Data ──

  const listDocuments: any[]       = listData?.data ?? [];
  const companies: OrgItem[]       = companiesData?.companies ?? [];
  const projects: ProjectItem[]    = projectsData?.projects   ?? [];
  const modules: ModuleFolder[]    = modulesData?.folders     ?? [];
  const files: FileItem[]          = moduleFilesData?.data    ?? [];

  const totalLabel = viewMode === 'list'
    ? `${listData?.total ?? 0} total`
    : level === 'companies' ? `${companies.length} companies`
    : level === 'projects'  ? `${projects.length} projects`
    : level === 'modules'   ? `${modules.length} folders`
    : `${moduleFilesData?.total ?? 0} files`;

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-6">

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Documents</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            {viewMode === 'folder'
              ? 'Browse documents by company → project → module'
              : 'All uploaded documents across the platform'}
          </p>
        </div>
        <div className="flex items-center gap-3">
          <div
            className="text-xs px-3 py-1.5 rounded-full font-medium"
            style={{ backgroundColor: 'rgba(185,149,102,0.12)', color: 'var(--gold)', border: '1px solid rgba(185,149,102,0.3)' }}
          >
            {totalLabel}
          </div>
          <div className="flex rounded-lg overflow-hidden" style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
            {(['folder', 'list'] as const).map(mode => (
              <button
                key={mode}
                onClick={() => setViewMode(mode)}
                className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium transition-colors"
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
        </div>
      </div>

      {/* ── LIST VIEW ── */}
      {viewMode === 'list' && (
        <>
          <div className="relative max-w-sm">
            <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
            <input
              value={search}
              onChange={e => setSearch(e.target.value)}
              placeholder="Search documents…"
              className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>
          <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <div
              className="grid grid-cols-[2.5fr_1.5fr_1.5fr_1fr_1fr] gap-4 px-5 py-3 text-xs font-medium uppercase tracking-wider"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}
            >
              <span>File Name</span><span>Company</span><span>Project</span><span>Size</span><span>Uploaded</span>
            </div>
            {listLoading ? (
              <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
                {[...Array(8)].map((_, i) => (
                  <div key={i} className="px-5 py-4 h-12 animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', opacity: 0.5 }} />
                ))}
              </div>
            ) : listDocuments.length === 0 ? (
              <EmptyState
                icon={<FileText size={24} style={{ color: 'var(--text-muted)' }} />}
                title="No documents found"
                body="No documents uploaded yet. Documents uploaded from project modules will appear here automatically."
              />
            ) : (
              <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
                {listDocuments.map((doc: any) => (
                  <div key={doc.id} className="grid grid-cols-[2.5fr_1.5fr_1.5fr_1fr_1fr] gap-4 items-center px-5 py-3 hover:bg-[var(--bg-elevated)] transition-colors">
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
                    <span className="text-sm" style={{ color: 'var(--text-muted)' }}>{formatBytes(doc.file_size)}</span>
                    <span className="text-sm" style={{ color: 'var(--text-muted)' }}>{formatDate(doc.created_at)}</span>
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
          <Breadcrumbs crumbs={crumbs} onNavigate={navigateTo} />

          {/* Level 1: Companies */}
          {level === 'companies' && (
            companiesLoading ? <SkeletonCards /> :
            companies.length === 0 ? (
              <EmptyState
                title="No documents uploaded yet"
                body="Documents uploaded from project modules will appear here automatically."
              />
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {companies.map(org => (
                  <FolderCard
                    key={org.id}
                    icon={<Building2 size={20} style={{ color: 'var(--gold)' }} />}
                    title={org.name}
                    subtitle={`${org.projects_count} project${org.projects_count !== 1 ? 's' : ''}`}
                    meta={`${org.files_count} files · ${formatBytes(org.storage_size)}`}
                    onClick={() => { setSelectedOrg(org); setLevel('projects'); }}
                  />
                ))}
              </div>
            )
          )}

          {/* Level 2: Projects */}
          {level === 'projects' && (
            projectsLoading ? <SkeletonCards /> :
            projects.length === 0 ? (
              <EmptyState title="No project documents" body="No project documents found for this company." />
            ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                {projects.map(proj => (
                  <FolderCard
                    key={proj.id}
                    title={proj.name}
                    subtitle={proj.code ?? undefined}
                    meta={`${proj.files_count} files · ${formatBytes(proj.storage_size)}${proj.last_uploaded ? ` · ${formatDate(proj.last_uploaded)}` : ''}`}
                    onClick={() => { setSelectedProject(proj); setLevel('modules'); }}
                  />
                ))}
              </div>
            )
          )}

          {/* Level 3: Module folders */}
          {level === 'modules' && (
            modulesLoading ? <SkeletonCards cols={4} count={13} /> : (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                {modules.map(mod => (
                  <FolderCard
                    key={mod.key}
                    title={mod.name}
                    subtitle={`${mod.files_count} file${mod.files_count !== 1 ? 's' : ''}`}
                    meta={mod.last_updated ? formatDate(mod.last_updated) : undefined}
                    onClick={() => { setSelectedModule(mod); setLevel('files'); }}
                  />
                ))}
              </div>
            )
          )}

          {/* Level 4: Files */}
          {level === 'files' && (
            moduleFilesLoading ? (
              <div className="space-y-2">
                {[...Array(5)].map((_, i) => <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />)}
              </div>
            ) : files.length === 0 ? (
              <EmptyState
                icon={<FileText size={24} style={{ color: 'var(--text-muted)' }} />}
                title={selectedModule?.key === 'contracts' ? 'No contract uploaded yet' : `No files in ${selectedModule?.name ?? 'this folder'}`}
                body={
                  selectedModule?.key === 'contracts'
                    ? 'The main contract should be uploaded before payment, variation, notice, and adjudication workflows.'
                    : undefined
                }
              />
            ) : (
              <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                <div
                  className="grid grid-cols-[2.5fr_1.5fr_1fr_1fr_auto] gap-4 px-5 py-3 text-xs font-medium uppercase tracking-wider"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}
                >
                  <span>File Name</span><span>Uploaded by</span><span>Size</span><span>Date</span><span />
                </div>
                <div className="divide-y" style={{ borderColor: 'var(--border)' }}>
                  {files.map(file => (
                    <div key={file.id} className="grid grid-cols-[2.5fr_1.5fr_1fr_1fr_auto] gap-4 items-center px-5 py-3 hover:bg-[var(--bg-elevated)] transition-colors">
                      <div className="flex items-center gap-3 min-w-0">
                        <span className="text-base flex-shrink-0">{mimeIcon(file.mime_type)}</span>
                        <span className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{file.original_name}</span>
                      </div>
                      <span className="text-sm truncate" style={{ color: 'var(--text-secondary)' }}>{file.uploader?.name ?? '—'}</span>
                      <span className="text-sm" style={{ color: 'var(--text-muted)' }}>{formatBytes(file.file_size)}</span>
                      <span className="text-sm" style={{ color: 'var(--text-muted)' }}>{formatDate(file.created_at)}</span>
                      <button
                        onClick={() => downloadFile(file.id, file.original_name)}
                        className="p-1.5 rounded-lg hover:bg-[var(--bg-elevated)] transition-colors"
                        title="Download"
                      >
                        <Download size={13} style={{ color: 'var(--text-muted)' }} />
                      </button>
                    </div>
                  ))}
                </div>
                {moduleFilesData && moduleFilesData.total > moduleFilesData.per_page && (
                  <div className="px-5 py-3 text-xs" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                    Showing {moduleFilesData.from}–{moduleFilesData.to} of {moduleFilesData.total} files
                  </div>
                )}
              </div>
            )
          )}
        </>
      )}
    </div>
  );
}
