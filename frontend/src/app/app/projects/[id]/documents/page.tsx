'use client';

import { useRef, useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import {
  FolderOpen, FileText, Plus, Search, ChevronRight,
  Download, Trash2, Upload, File, Folder, LayoutList, LayoutGrid,
} from 'lucide-react';
import toast from 'react-hot-toast';

type DocSource = 'generated' | 'uploaded';
type ViewMode  = 'list' | 'folder';

const TYPE_LABELS: Record<string, string> = {
  payment_app: 'Payment App',
  contract:    'Contract',
  variation:   'Variation',
  rfi:         'RFI',
  report:      'Report',
  other:       'Other',
};

function FileSize({ bytes }: { bytes: number }) {
  if (!bytes) return null;
  if (bytes < 1024) return <>{bytes} B</>;
  if (bytes < 1024 * 1024) return <>{(bytes / 1024).toFixed(1)} KB</>;
  return <>{(bytes / (1024 * 1024)).toFixed(1)} MB</>;
}

function formatBytes(bytes: number): string {
  if (!bytes) return '—';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

interface ModuleFolder { key: string; name: string; files_count: number; last_updated?: string; }
interface FileItem {
  id: number; original_name: string; mime_type: string; file_size: number;
  module_key?: string; created_at: string;
  uploader?: { id: number; name: string };
}

function FolderCard({ title, subtitle, meta, onClick }: {
  title: string; subtitle?: string; meta?: string; onClick: () => void;
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
          <Folder size={20} style={{ color: 'var(--gold)' }} />
        </div>
        <div className="flex-1 min-w-0">
          <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{title}</p>
          {subtitle && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
          {meta && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{meta}</p>}
        </div>
        <ChevronRight size={14} className="mt-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity"
          style={{ color: 'var(--text-muted)' }} />
      </div>
    </button>
  );
}

export default function ProjectDocumentsPage() {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [viewMode, setViewMode]         = useState<ViewMode>('folder');
  const [search, setSearch]             = useState('');
  const [selectedFolder, setSelectedFolder] = useState<string | null>(null);
  const [docSource, setDocSource]       = useState<DocSource>('uploaded');
  const [selectedModule, setSelectedModule] = useState<ModuleFolder | null>(null);
  const fileInputRef                    = useRef<HTMLInputElement>(null);
  const [uploading, setUploading]       = useState(false);

  // ── Queries ─────────────────────────────────────────────────────────────

  const { data: foldersData, isLoading: foldersLoading } = useQuery({
    queryKey: ['project-folders', id],
    queryFn: () => api.get(`/projects/${id}/folders`).then(r => r.data).catch(() => ({ data: [] })),
    enabled: viewMode === 'list',
  });

  const { data: filesData, isLoading: filesLoading } = useQuery({
    queryKey: ['project-files', id, selectedFolder],
    queryFn: () => {
      const params: Record<string, string> = {};
      if (selectedFolder) params.folder = selectedFolder;
      return api.get(`/projects/${id}/files`, { params }).then(r => r.data).catch(() => ({ data: [] }));
    },
    enabled: viewMode === 'list' && docSource === 'uploaded',
  });

  const { data: generatedDocsData, isLoading: generatedLoading } = useQuery({
    queryKey: ['project-documents', id, selectedFolder],
    queryFn: () => {
      const params: Record<string, string> = {};
      if (selectedFolder) params.category = selectedFolder;
      return api.get(`/projects/${id}/documents`, { params }).then(r => r.data).catch(() => ({ data: [] }));
    },
    enabled: viewMode === 'list' && docSource === 'generated',
  });

  // Module explorer queries
  const { data: explorerData, isLoading: explorerLoading } = useQuery({
    queryKey: ['project-doc-explorer', id],
    queryFn: () => api.get(`/projects/${id}/documents/explorer`).then(r => r.data),
    enabled: viewMode === 'folder' && !selectedModule,
  });

  const { data: moduleFilesData, isLoading: moduleFilesLoading } = useQuery({
    queryKey: ['project-module-files', id, selectedModule?.key],
    queryFn: () => api.get(`/projects/${id}/documents/module/${selectedModule!.key}`).then(r => r.data),
    enabled: viewMode === 'folder' && !!selectedModule,
  });

  // ── Data ─────────────────────────────────────────────────────────────────

  const folders      = Array.isArray(foldersData) ? foldersData : (foldersData?.data ?? []);
  const files        = filesData?.data ?? [];
  const docs         = generatedDocsData?.data ?? [];
  const moduleFolders: ModuleFolder[] = explorerData?.folders ?? [];
  const moduleFiles: FileItem[]       = moduleFilesData?.data ?? [];

  const displayItems = docSource === 'uploaded'
    ? files.filter((f: any) => f.original_name?.toLowerCase().includes(search.toLowerCase()))
    : docs.filter((d: any) => d.title?.toLowerCase().includes(search.toLowerCase()));

  const currentFolder = folders.find((f: any) => f.path === selectedFolder);

  // ── Mutations ─────────────────────────────────────────────────────────────

  const deleteMutation = useMutation({
    mutationFn: (fileId: number) => api.delete(`/file-uploads/${fileId}`).then(r => r.data),
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ['project-files', id] }); toast.success('File deleted'); },
    onError: () => toast.error('Failed to delete'),
  });

  // ── Handlers ──────────────────────────────────────────────────────────────

  const handleUpload = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const formData = new FormData();
      formData.append('file', file);
      if (selectedFolder) formData.append('folder_path', selectedFolder);
      await api.post(`/projects/${id}/files`, formData, { headers: { 'Content-Type': 'multipart/form-data' } });
      queryClient.invalidateQueries({ queryKey: ['project-files', id] });
      queryClient.invalidateQueries({ queryKey: ['project-doc-explorer', id] });
      toast.success('File uploaded');
    } catch { toast.error('Upload failed'); }
    finally {
      setUploading(false);
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  const downloadDoc = async (docId: number, fileName: string) => {
    try {
      const response = await api.get(`/documents/${docId}/download`, { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const a = document.createElement('a'); a.href = url; a.download = fileName; a.click();
      window.URL.revokeObjectURL(url);
    } catch { toast.error('Download failed'); }
  };

  const downloadFile = async (fileId: number, fileName: string) => {
    try {
      const response = await api.get(`/file-uploads/${fileId}/download`, { responseType: 'blob' });
      const url = window.URL.createObjectURL(new Blob([response.data]));
      const a = document.createElement('a'); a.href = url; a.download = fileName; a.click();
      window.URL.revokeObjectURL(url);
    } catch { toast.error('Download failed'); }
  };

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Documents</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Project document library</p>
        </div>
        <div className="flex gap-2 items-center">
          {/* View toggle */}
          <div className="flex rounded-lg overflow-hidden" style={{ border: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
            {(['folder', 'list'] as const).map(mode => (
              <button
                key={mode}
                onClick={() => { setViewMode(mode); setSelectedModule(null); }}
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
          <input ref={fileInputRef} type="file" className="hidden" onChange={handleUpload} />
          <button
            onClick={() => fileInputRef.current?.click()}
            disabled={uploading}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: uploading ? 0.7 : 1 }}
          >
            <Upload size={15} />
            {uploading ? 'Uploading…' : 'Upload File'}
          </button>
        </div>
      </div>

      {/* ── FOLDER VIEW ── */}
      {viewMode === 'folder' && (
        <>
          {/* Breadcrumb */}
          <nav className="flex items-center gap-1 text-sm">
            <button
              className="hover:underline"
              style={{ color: selectedModule ? 'var(--gold)' : 'var(--text-primary)' }}
              onClick={() => setSelectedModule(null)}
            >
              Modules
            </button>
            {selectedModule && (
              <>
                <ChevronRight size={12} style={{ color: 'var(--text-muted)' }} />
                <span className="font-medium" style={{ color: 'var(--text-primary)' }}>{selectedModule.name}</span>
              </>
            )}
          </nav>

          {!selectedModule ? (
            /* Module folder grid */
            explorerLoading ? (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                {[...Array(13)].map((_, i) => <div key={i} className="h-20 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />)}
              </div>
            ) : moduleFolders.length === 0 ? (
              <div className="py-20 text-center">
                <FolderOpen size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm" style={{ color: 'var(--text-primary)' }}>No documents uploaded for this project yet</p>
                <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Upload the main contract to get started.</p>
              </div>
            ) : (
              <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4">
                {moduleFolders.map(mod => (
                  <FolderCard
                    key={mod.key}
                    title={mod.name}
                    subtitle={`${mod.files_count} file${mod.files_count !== 1 ? 's' : ''}`}
                    meta={mod.last_updated ? formatDate(mod.last_updated) : undefined}
                    onClick={() => setSelectedModule(mod)}
                  />
                ))}
              </div>
            )
          ) : (
            /* Module files list */
            moduleFilesLoading ? (
              <div className="space-y-2">
                {[...Array(5)].map((_, i) => <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />)}
              </div>
            ) : moduleFiles.length === 0 ? (
              <div className="py-20 text-center">
                <FileText size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
                <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>
                  {selectedModule.key === 'contracts' ? 'No contract uploaded yet' : `No files in ${selectedModule.name}`}
                </p>
                {selectedModule.key === 'contracts' && (
                  <p className="text-xs max-w-sm mx-auto" style={{ color: 'var(--text-muted)' }}>
                    The main contract should be uploaded before payment, variation, notice, and adjudication workflows.
                  </p>
                )}
              </div>
            ) : (
              <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                <table className="w-full text-sm">
                  <thead>
                    <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                      {['Name', 'Size', 'Uploaded by', 'Date', 'Actions'].map(h => (
                        <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                    {moduleFiles.map(f => (
                      <tr key={f.id} className="hover:bg-[var(--bg-elevated)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                        <td className="px-4 py-3">
                          <div className="flex items-center gap-2">
                            <FileText size={14} style={{ color: 'var(--text-muted)' }} />
                            <span className="text-xs font-medium truncate max-w-[240px]" style={{ color: 'var(--text-primary)' }}>{f.original_name}</span>
                          </div>
                        </td>
                        <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{formatBytes(f.file_size)}</td>
                        <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{f.uploader?.name ?? '—'}</td>
                        <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{formatDate(f.created_at)}</td>
                        <td className="px-4 py-3">
                          <button onClick={() => downloadFile(f.id, f.original_name)} title="Download"
                            className="p-1 rounded hover:opacity-80" style={{ color: 'var(--text-muted)' }}>
                            <Download size={13} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )
          )}
        </>
      )}

      {/* ── LIST VIEW ── */}
      {viewMode === 'list' && (
        <>
          {/* Source toggle + search */}
          <div className="flex items-center justify-between gap-4 flex-wrap">
            <div className="flex gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              {(['uploaded', 'generated'] as const).map(s => (
                <button key={s} onClick={() => setDocSource(s)}
                  className="px-3 py-1.5 rounded-md text-xs font-medium capitalize transition-all"
                  style={docSource === s ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
                  {s === 'uploaded' ? 'Uploaded Files' : 'Generated PDFs'}
                </button>
              ))}
            </div>
            <div className="relative">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
              <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search…"
                className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)', minWidth: '200px' }} />
            </div>
          </div>

          {/* Breadcrumb */}
          <div className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
            <button onClick={() => setSelectedFolder(null)} className="hover:underline"
              style={{ color: selectedFolder ? 'var(--text-muted)' : 'var(--gold)' }}>
              All
            </button>
            {currentFolder && (
              <>
                <ChevronRight size={12} />
                <span style={{ color: 'var(--gold)' }}>{currentFolder.name}</span>
              </>
            )}
          </div>

          <div className="grid grid-cols-4 gap-4 items-start">
            {/* Folder tree */}
            <div className="space-y-1">
              <p className="text-xs font-semibold uppercase tracking-wide px-1 mb-2" style={{ color: 'var(--text-muted)' }}>Folders</p>
              <button
                onClick={() => setSelectedFolder(null)}
                className="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-xs transition-all"
                style={!selectedFolder
                  ? { backgroundColor: 'rgba(185,149,102,0.15)', color: 'var(--gold)', border: '1px solid rgba(185,149,102,0.3)' }
                  : { backgroundColor: 'var(--bg-surface)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }
                }
              >
                <FolderOpen size={13} />
                All Files
              </button>
              {foldersLoading ? (
                [...Array(6)].map((_, i) => <div key={i} className="h-8 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />)
              ) : folders.map((f: any) => (
                <button
                  key={f.id}
                  onClick={() => setSelectedFolder(f.path === selectedFolder ? null : f.path)}
                  className="w-full flex items-center gap-2 px-3 py-2 rounded-lg text-left text-xs transition-all"
                  style={f.path === selectedFolder
                    ? { backgroundColor: 'rgba(185,149,102,0.15)', color: 'var(--gold)', border: '1px solid rgba(185,149,102,0.3)' }
                    : { backgroundColor: 'var(--bg-surface)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }
                  }
                >
                  <FolderOpen size={13} />
                  <span className="truncate flex-1">{f.name}</span>
                  {f.file_count > 0 && (
                    <span className="text-xs rounded-full px-1.5" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>{f.file_count}</span>
                  )}
                </button>
              ))}
            </div>

            {/* File / doc list */}
            <div className="col-span-3">
              {(filesLoading || generatedLoading) ? (
                <div className="space-y-2">
                  {[...Array(5)].map((_, i) => <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />)}
                </div>
              ) : displayItems.length === 0 ? (
                <div className="rounded-2xl p-10 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                  <File size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                    {docSource === 'uploaded' ? 'No files uploaded yet' : 'No generated PDFs yet'}
                  </p>
                </div>
              ) : (
                <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                  <table className="w-full text-sm">
                    <thead>
                      <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                        {['Name', 'Type / Category', docSource === 'uploaded' ? 'Size' : 'Version', 'Status / Date', 'By', 'Actions'].map(h => (
                          <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                      {docSource === 'uploaded' ? displayItems.map((f: any) => (
                        <tr key={f.id} className="hover:bg-[var(--bg-elevated)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              <FileText size={14} style={{ color: 'var(--text-muted)' }} />
                              <span className="text-xs font-medium truncate max-w-[220px]" style={{ color: 'var(--text-primary)' }}>{f.original_name}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{f.folder_path?.replace(/_/g, ' ') ?? 'general'}</td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}><FileSize bytes={f.file_size} /></td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{f.created_at ? formatDate(f.created_at) : '—'}</td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{f.uploader?.name ?? '—'}</td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              <button onClick={() => downloadFile(f.id, f.original_name)} title="Download" className="p-1 rounded hover:opacity-80" style={{ color: 'var(--text-muted)' }}>
                                <Download size={13} />
                              </button>
                              <button onClick={() => deleteMutation.mutate(f.id)} title="Delete" className="p-1 rounded hover:opacity-80" style={{ color: '#f87171' }}>
                                <Trash2 size={13} />
                              </button>
                            </div>
                          </td>
                        </tr>
                      )) : displayItems.map((d: any) => (
                        <tr key={d.id} className="hover:bg-[var(--bg-elevated)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-2">
                              <FileText size={14} style={{ color: '#a78bfa' }} />
                              <span className="text-xs font-medium truncate max-w-[220px]" style={{ color: 'var(--text-primary)' }}>{d.title}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                            {TYPE_LABELS[d.type] ?? d.type}
                            {d.category && <span className="ml-1 opacity-60">· {d.category.replace(/_/g, ' ')}</span>}
                          </td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>v{d.version ?? 1}</td>
                          <td className="px-4 py-3">
                            <span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: 'rgba(167,139,250,0.12)', color: '#a78bfa' }}>
                              {d.status}
                            </span>
                          </td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{d.creator?.name ?? '—'}</td>
                          <td className="px-4 py-3">
                            {d.file_path && (
                              <button onClick={() => downloadDoc(d.id, d.file_name ?? d.title + '.pdf')} title="Download"
                                className="p-1 rounded hover:opacity-80" style={{ color: 'var(--text-muted)' }}>
                                <Download size={13} />
                              </button>
                            )}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
