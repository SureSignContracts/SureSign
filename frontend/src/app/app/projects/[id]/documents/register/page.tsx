'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { FileText, Search } from 'lucide-react';

const DOCUMENT_TYPE_LABELS: Record<string, string> = {
  drawing:       'Drawing',
  specification:  'Specification',
  report:        'Report',
  contract:      'Contract',
  correspondence: 'Correspondence',
  certificate:   'Certificate',
  programme:     'Programme',
  other:         'Other',
};

const TYPE_COLORS: Record<string, { bg: string; text: string }> = {
  drawing:        { bg: 'rgba(59,130,246,0.12)',  text: '#60a5fa' },
  specification:  { bg: 'rgba(168,85,247,0.12)',  text: '#c084fc' },
  report:         { bg: 'rgba(34,197,94,0.12)',   text: '#4ade80' },
  contract:       { bg: 'rgba(234,179,8,0.12)',   text: '#facc15' },
  correspondence: { bg: 'rgba(249,115,22,0.12)',  text: '#fb923c' },
  certificate:    { bg: 'rgba(20,184,166,0.12)',  text: '#2dd4bf' },
  programme:      { bg: 'rgba(236,72,153,0.12)',  text: '#f472b6' },
  other:          { bg: 'rgba(90,86,82,0.2)',     text: '#9a9490' },
};

const PER_PAGE_OPTIONS = [25, 50, 100];

type RegisterEntry = {
  id: number;
  document_number: string;
  title: string;
  document_type: string;
  package_name?: string;
  document_date?: string;
};

type PaginatedResponse = {
  data: RegisterEntry[];
  meta: {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
  };
};

export default function DocumentRegisterPage() {
  const { id: projectId } = useParams<{ id: string }>();

  const [search, setSearch]           = useState('');
  const [typeFilter, setTypeFilter]   = useState('all');
  const [page, setPage]               = useState(1);
  const [perPage, setPerPage]         = useState(25);

  const { data, isLoading } = useQuery<PaginatedResponse>({
    queryKey: ['document-register', projectId, search, typeFilter, page, perPage],
    queryFn: () =>
      api.get(`/projects/${projectId}/document-register`, {
        params: {
          ...(search        ? { search }                          : {}),
          ...(typeFilter !== 'all' ? { document_type: typeFilter } : {}),
          page,
          per_page: perPage,
        },
      }).then(r => r.data),
    placeholderData: prev => prev,
  });

  const entries  = data?.data  ?? [];
  const meta     = data?.meta;
  const lastPage = meta?.last_page ?? 1;

  const inputStyle = {
    backgroundColor: 'var(--bg-surface)',
    border: '1px solid var(--border)',
    color: 'var(--text-primary)',
  };

  function handleSearch(value: string) {
    setSearch(value);
    setPage(1);
  }

  function handleTypeFilter(value: string) {
    setTypeFilter(value);
    setPage(1);
  }

  const typeOptions = ['all', ...Object.keys(DOCUMENT_TYPE_LABELS)];

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Document Register</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          {meta ? `${meta.total} document${meta.total !== 1 ? 's' : ''}` : 'All project documents'}
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-center">
        {/* Search */}
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => handleSearch(e.target.value)}
            placeholder="Search documents…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ ...inputStyle, minWidth: '220px' }}
          />
        </div>

        {/* Type filter pills */}
        <div className="flex flex-wrap gap-1 p-1 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {typeOptions.map(t => (
            <button
              key={t}
              onClick={() => handleTypeFilter(t)}
              className="px-3 py-1.5 rounded-md text-xs font-medium transition-all"
              style={
                typeFilter === t
                  ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                  : { color: 'var(--text-secondary)' }
              }
            >
              {t === 'all' ? 'All Types' : (DOCUMENT_TYPE_LABELS[t] ?? t)}
            </button>
          ))}
        </div>

        {/* Per page */}
        <select
          value={perPage}
          onChange={e => { setPerPage(Number(e.target.value)); setPage(1); }}
          className="px-3 py-2 rounded-lg text-sm outline-none"
          style={{ ...inputStyle }}
        >
          {PER_PAGE_OPTIONS.map(n => (
            <option key={n} value={n}>{n} per page</option>
          ))}
        </select>
      </div>

      {/* Table */}
      <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full min-w-[720px] text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Document No.', 'Title', 'Type', 'Package', 'Date'].map(h => (
                <th
                  key={h}
                  className="text-left px-5 py-3 text-xs font-medium"
                  style={{ color: 'var(--text-muted)' }}
                >
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
            {isLoading ? (
              [...Array(8)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                  {[...Array(5)].map((_, j) => (
                    <td key={j} className="px-5 py-4">
                      <div
                        className="h-4 rounded animate-pulse"
                        style={{ backgroundColor: 'var(--bg-elevated)', width: j === 1 ? '80%' : '55%' }}
                      />
                    </td>
                  ))}
                </tr>
              ))
            ) : entries.length === 0 ? (
              <tr>
                <td colSpan={5} className="px-5 py-14 text-center">
                  <FileText size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                    {search || typeFilter !== 'all' ? 'No documents match your filters.' : 'No documents in the register yet.'}
                  </p>
                  {(search || typeFilter !== 'all') && (
                    <button
                      onClick={() => { setSearch(''); setTypeFilter('all'); setPage(1); }}
                      className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                    >
                      Clear filters
                    </button>
                  )}
                </td>
              </tr>
            ) : (
              entries.map((entry) => {
                const badge = TYPE_COLORS[entry.document_type] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr
                    key={entry.id}
                    className="hover:bg-[var(--bg-hover)] transition-colors"
                    style={{ borderBottom: '1px solid var(--border)' }}
                  >
                    <td className="px-5 py-3 font-mono text-xs font-semibold" style={{ color: 'var(--gold)' }}>
                      {entry.document_number ?? '—'}
                    </td>
                    <td className="px-5 py-3 font-medium max-w-[280px] truncate" style={{ color: 'var(--text-primary)' }}>
                      {entry.title}
                    </td>
                    <td className="px-5 py-3">
                      <span
                        className="px-2 py-0.5 rounded-full text-xs font-medium"
                        style={{ backgroundColor: badge.bg, color: badge.text }}
                      >
                        {DOCUMENT_TYPE_LABELS[entry.document_type] ?? entry.document_type}
                      </span>
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>
                      {entry.package_name ?? '—'}
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                      {entry.document_date ? formatDate(entry.document_date) : '—'}
                    </td>
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {!isLoading && lastPage > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            Page {meta?.current_page} of {lastPage} &mdash; {meta?.total} total
          </p>
          <div className="flex gap-2">
            <button
              disabled={page <= 1}
              onClick={() => setPage(p => p - 1)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity disabled:opacity-40"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
            >
              Previous
            </button>
            {[...Array(Math.min(lastPage, 7))].map((_, i) => {
              const pageNum = i + 1;
              return (
                <button
                  key={pageNum}
                  onClick={() => setPage(pageNum)}
                  className="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                  style={
                    page === pageNum
                      ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                      : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }
                  }
                >
                  {pageNum}
                </button>
              );
            })}
            {lastPage > 7 && page < lastPage - 3 && (
              <span className="px-1 text-xs self-center" style={{ color: 'var(--text-muted)' }}>…</span>
            )}
            {lastPage > 7 && (
              <button
                onClick={() => setPage(lastPage)}
                className="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                style={
                  page === lastPage
                    ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                    : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }
                }
              >
                {lastPage}
              </button>
            )}
            <button
              disabled={page >= lastPage}
              onClick={() => setPage(p => p + 1)}
              className="px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity disabled:opacity-40"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
