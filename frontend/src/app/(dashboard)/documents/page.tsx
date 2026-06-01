'use client';

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { FileText, Upload, Search, Eye, Download, FolderOpen } from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';

const typeColor: Record<string, string> = {
  pdf:  '#f87171',
  docx: '#60a5fa',
  xlsx: '#4ade80',
  png:  '#c084fc',
  jpg:  '#c084fc',
};

function FileIcon({ type }: { type: string }) {
  const color = typeColor[type?.toLowerCase()] || '#9a9490';
  return (
    <div className="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 text-xs font-bold uppercase"
         style={{ backgroundColor: `${color}20`, color }}>
      {type?.substring(0, 3) || 'DOC'}
    </div>
  );
}

export default function DocumentsPage() {
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['documents'],
    queryFn: () => api.get('/documents').then(r => r.data),
  });

  const docs = (data?.data ?? []).filter((d: any) =>
    d.title?.toLowerCase().includes(search.toLowerCase()) ||
    d.file_name?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-7xl mx-auto">
      <div className="flex items-center justify-between mb-6">
        <div>
          <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>Documents</h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>Document library and file management</p>
        </div>
        <button
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Upload size={16} />
          Upload
        </button>
      </div>

      {/* Search */}
      <div className="relative mb-6">
        <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search documents..."
          className="w-full pl-9 pr-4 py-2.5 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {isLoading ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {[...Array(6)].map((_, i) => (
            <div key={i} className="h-24 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : docs.length === 0 ? (
        <div className="flex flex-col items-center justify-center py-20">
          <div className="w-14 h-14 rounded-2xl flex items-center justify-center mb-4"
               style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <FolderOpen size={24} style={{ color: 'var(--text-muted)' }} />
          </div>
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>No documents yet</p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Upload documents or link them from a project</p>
        </div>
      ) : (
        <div className="space-y-2">
          {docs.map((doc: any) => {
            const ext = doc.file_name?.split('.').pop() || doc.file_type || 'doc';
            return (
              <div key={doc.id}
                className="flex items-center gap-4 p-3 rounded-xl border hover:border-[var(--gold)] transition-colors group cursor-pointer"
                style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)' }}
              >
                <FileIcon type={ext} />
                <div className="flex-1 min-w-0">
                  <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>
                    {doc.title || doc.file_name}
                  </p>
                  <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                    {doc.version ? `v${doc.version} · ` : ''}{doc.created_at ? formatDate(doc.created_at) : ''}
                  </p>
                </div>
                <div className="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                  <button className="p-1.5 rounded-lg hover:bg-[var(--bg-elevated)] transition-colors"
                          title="Preview">
                    <Eye size={14} style={{ color: 'var(--text-muted)' }} />
                  </button>
                  <button className="p-1.5 rounded-lg hover:bg-[var(--bg-elevated)] transition-colors"
                          title="Download">
                    <Download size={14} style={{ color: 'var(--text-muted)' }} />
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
