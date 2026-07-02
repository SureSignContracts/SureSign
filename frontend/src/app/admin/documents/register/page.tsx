'use client';

import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from 'react-hot-toast';
import { formatDate } from '@/lib/utils';
import { FileText, Search, ChevronDown } from 'lucide-react';
import PaginationBar from '@/components/ui/PaginationBar';

// ── Types ─────────────────────────────────────────────────────────────────────

interface DocumentRegisterEntry {
  id: number;
  document_number: string;
  title: string;
  document_type: string;
  document_type_label: string;
  project_id: number | null;
  project_name: string | null;
  project_code: string | null;
  package_name: string | null;
  document_date: string | null;
  created_at: string;
}

interface RegisterResponse {
  data: DocumentRegisterEntry[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
  from: number | null;
  to: number | null;
}

interface ProjectOption {
  id: number;
  name: string;
  code: string | null;
}

// ── Constants ─────────────────────────────────────────────────────────────────


const DOC_TYPE_COLOURS: Record<string, string> = {
  contract:            '#6366f1',
  subcontract:         '#8b5cf6',
  payment_application: '#10b981',
  variation:           '#f59e0b',
  rfi:                 '#3b82f6',
  notice:              '#ef4444',
  meeting_minutes:     '#ec4899',
  site_report:         '#14b8a6',
  letter:              '#f97316',
  eot:                 '#0ea5e9',
  adjudication:        '#a855f7',
  other:               '#6b7280',
};

// ── Helpers ───────────────────────────────────────────────────────────────────

function typeBadge(type: string, label: string) {
  const colour = DOC_TYPE_COLOURS[type] ?? '#6b7280';
  return (
    <span
      className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
      style={{ backgroundColor: colour + '18', color: colour }}
    >
      {label || type}
    </span>
  );
}

// ── Skeleton rows ─────────────────────────────────────────────────────────────

function SkeletonRows({ count = 10 }: { count?: number }) {
  return (
    <>
      {[...Array(count)].map((_, i) => (
        <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
          {[...Array(6)].map((__, j) => (
            <td key={j} className="px-4 py-3">
              <div
                className="h-4 rounded animate-pulse"
                style={{ backgroundColor: 'var(--bg-elevated)', width: j === 0 ? '90px' : j === 1 ? '60%' : '70%' }}
              />
            </td>
          ))}
        </tr>
      ))}
    </>
  );
}

// ── Empty state ───────────────────────────────────────────────────────────────

function EmptyState({ filtered }: { filtered: boolean }) {
  return (
    <tr>
      <td colSpan={6}>
        <div className="py-16 text-center">
          <div
            className="w-12 h-12 rounded-2xl flex items-center justify-center mx-auto mb-4"
            style={{ backgroundColor: 'var(--bg-elevated)' }}
          >
            <FileText size={22} style={{ color: 'var(--text-muted)' }} />
          </div>
          <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-primary)' }}>
            {filtered ? 'No entries match your filters.' : 'No document register entries yet.'}
          </p>
          <p className="text-xs max-w-xs mx-auto" style={{ color: 'var(--text-muted)' }}>
            {filtered
              ? 'Try adjusting the search, type, or project filter.'
              : 'Document register entries will appear here once documents are issued on projects.'}
          </p>
        </div>
      </td>
    </tr>
  );
}

// ── Main page ─────────────────────────────────────────────────────────────────

export default function AdminDocumentRegisterPage() {
  const [search, setSearch]           = useState('');
  const [debouncedSearch, setDebounced] = useState('');
  const [typeFilter, setTypeFilter]   = useState('');
  const [projectFilter, setProjectFilter] = useState('');
  const [page, setPage]               = useState(1);
  const [perPage, setPerPage]         = useState(25);

  // Debounce search
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebounced(search);
      setPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [search]);

  // Fetch register entries
  const { data, isLoading, isError } = useQuery<RegisterResponse>({
    queryKey: ['admin-document-register', debouncedSearch, typeFilter, projectFilter, page, perPage],
    queryFn: async () => {
      const params: Record<string, string | number> = { page, per_page: perPage };
      if (debouncedSearch) params.search = debouncedSearch;
      if (typeFilter)      params.document_type = typeFilter;
      if (projectFilter)   params.project_id = projectFilter;
      const r = await api.get('/admin/document-register', { params });
      return r.data;
    },
    placeholderData: (prev: RegisterResponse | undefined) => prev,
  });

  // Fetch project options for filter dropdown
  const { data: projectsData } = useQuery<ProjectOption[]>({
    queryKey: ['admin-register-projects'],
    queryFn: () =>
      api.get('/admin/document-register/projects').then(r => r.data?.data ?? r.data ?? []).catch(() => []),
  });

  // Fetch doc type options — uses the global /document-types endpoint
  const { data: typesData } = useQuery<{ code: string; label: string }[]>({
    queryKey: ['admin-register-types'],
    queryFn: () =>
      api.get('/document-types').then(r => r.data?.data ?? r.data ?? []).catch(() => []),
  });

  useEffect(() => {
    if (isError) toast.error('Failed to load document register.');
  }, [isError]);

  const entries: DocumentRegisterEntry[] = data?.data ?? [];
  const total      = data?.total      ?? 0;
  const lastPage   = data?.last_page  ?? 1;
  const projects: ProjectOption[]            = projectsData ?? [];
  const types: { code: string; label: string }[] = typesData ?? [];

  const hasFilters = !!debouncedSearch || !!typeFilter || !!projectFilter;

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5 pb-10">

      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Document Register</h1>
          <p className="mt-0.5 text-sm" style={{ color: 'var(--text-muted)' }}>
            All issued documents across every project
            {!isLoading && total > 0 && (
              <span className="ml-1">· {total} total</span>
            )}
          </p>
        </div>
        {!isLoading && total > 0 && (
          <div
            className="text-xs px-3 py-1.5 rounded-full font-medium"
            style={{ backgroundColor: 'rgba(185,149,102,0.12)', color: 'var(--gold)', border: '1px solid rgba(185,149,102,0.3)' }}
          >
            {total} {total === 1 ? 'document' : 'documents'}
          </div>
        )}
      </div>

      {/* Toolbar */}
      <div className="flex gap-3 flex-wrap items-center">
        {/* Search */}
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search by number or title…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{
              backgroundColor: 'var(--bg-surface)',
              border: '1px solid var(--border)',
              color: 'var(--text-primary)',
              minWidth: '240px',
            }}
          />
        </div>

        {/* Document type filter */}
        <div className="relative">
          <select
            value={typeFilter}
            onChange={e => { setTypeFilter(e.target.value); setPage(1); }}
            className="appearance-none pl-3 pr-8 py-2 rounded-lg text-sm outline-none"
            style={{
              backgroundColor: 'var(--bg-surface)',
              border: '1px solid var(--border)',
              color: typeFilter ? 'var(--text-primary)' : 'var(--text-muted)',
              minWidth: '160px',
            }}
          >
            <option value="">All types</option>
            {types.map(t => (
              <option key={t.code} value={t.code}>{t.label}</option>
            ))}
          </select>
          <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
        </div>

        {/* Project filter */}
        <div className="relative">
          <select
            value={projectFilter}
            onChange={e => { setProjectFilter(e.target.value); setPage(1); }}
            className="appearance-none pl-3 pr-8 py-2 rounded-lg text-sm outline-none"
            style={{
              backgroundColor: 'var(--bg-surface)',
              border: '1px solid var(--border)',
              color: projectFilter ? 'var(--text-primary)' : 'var(--text-muted)',
              minWidth: '200px',
            }}
          >
            <option value="">All projects</option>
            {projects.map(p => (
              <option key={p.id} value={p.id}>
                {p.code ? `${p.code} – ${p.name}` : p.name}
              </option>
            ))}
          </select>
          <ChevronDown size={13} className="absolute right-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
        </div>

        {/* Clear filters */}
        {hasFilters && (
          <button
            onClick={() => { setSearch(''); setDebounced(''); setTypeFilter(''); setProjectFilter(''); setPage(1); }}
            className="text-xs px-3 py-2 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}
          >
            Clear filters
          </button>
        )}
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-x-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <table className="w-full min-w-[720px]">
          <thead>
            <tr
              style={{
                backgroundColor: 'var(--bg-elevated)',
                borderBottom: '1px solid var(--border)',
              }}
            >
              {['Doc Number', 'Title', 'Type', 'Project', 'Package', 'Date'].map((h, i) => (
                <th
                  key={h}
                  className={`px-4 py-3 text-left text-xs font-medium uppercase tracking-wider ${i === 0 ? 'w-36' : ''}`}
                  style={{ color: 'var(--text-muted)' }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              <SkeletonRows count={10} />
            ) : entries.length === 0 ? (
              <EmptyState filtered={hasFilters} />
            ) : (
              entries.map(entry => (
                <tr
                  key={entry.id}
                  className="hover:bg-[var(--bg-hover)] transition-colors"
                  style={{ borderBottom: '1px solid var(--border)' }}
                >
                  {/* Document Number */}
                  <td className="px-4 py-3">
                    <span
                      className="inline-block font-mono text-xs px-2 py-1 rounded-md"
                      style={{
                        backgroundColor: 'rgba(185,149,102,0.10)',
                        color: 'var(--gold)',
                        border: '1px solid rgba(185,149,102,0.25)',
                        letterSpacing: '0.03em',
                      }}
                    >
                      {entry.document_number || '—'}
                    </span>
                  </td>

                  {/* Title */}
                  <td className="px-4 py-3 max-w-xs">
                    <span
                      className="text-sm font-medium truncate block"
                      style={{ color: 'var(--text-primary)' }}
                      title={entry.title}
                    >
                      {entry.title || '—'}
                    </span>
                  </td>

                  {/* Type */}
                  <td className="px-4 py-3">
                    {typeBadge(entry.document_type, entry.document_type_label)}
                  </td>

                  {/* Project */}
                  <td className="px-4 py-3">
                    {entry.project_name ? (
                      <div className="min-w-0">
                        <p className="text-sm truncate" style={{ color: 'var(--text-secondary)' }}>
                          {entry.project_name}
                        </p>
                        {entry.project_code && (
                          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{entry.project_code}</p>
                        )}
                      </div>
                    ) : (
                      <span className="text-sm" style={{ color: 'var(--text-muted)' }}>—</span>
                    )}
                  </td>

                  {/* Package */}
                  <td className="px-4 py-3">
                    {entry.package_name ? (
                      <span className="text-sm truncate block max-w-[180px]" style={{ color: 'var(--text-secondary)' }}>
                        {entry.package_name}
                      </span>
                    ) : (
                      <span className="text-sm" style={{ color: 'var(--text-muted)' }}>—</span>
                    )}
                  </td>

                  {/* Date */}
                  <td className="px-4 py-3">
                    <span className="text-sm" style={{ color: 'var(--text-muted)' }}>
                      {entry.document_date
                        ? formatDate(entry.document_date)
                        : entry.created_at
                          ? formatDate(entry.created_at)
                          : '—'}
                    </span>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      <PaginationBar
        page={page}
        lastPage={lastPage}
        total={total}
        perPage={perPage}
        onPage={setPage}
        onPerPage={n => { setPerPage(n); setPage(1); }}
      />
    </div>
  );
}
