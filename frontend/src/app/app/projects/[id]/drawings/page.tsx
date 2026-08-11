'use client';

import { useEffect, useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from 'react-hot-toast';
import { Ruler, Plus, Search, FileText, Eye, Pencil, Trash2 } from 'lucide-react';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import PaginationBar from '@/components/ui/PaginationBar';
import EmptyState from '@/components/ui/EmptyState';
import DrawingModal, { type DrawingRecord } from '@/components/drawings/DrawingModal';
import { DISCIPLINE_OPTIONS, STATUS_OPTIONS, drawingStatusColor } from '@/components/drawings/drawingConstants';

type DrawingListResponse = {
  data: DrawingRecord[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
};

const inputStyle = { backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

function DisciplineBadge({ discipline }: { discipline: string | null }) {
  if (!discipline) return <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>;
  return (
    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
      {discipline}
    </span>
  );
}

function StatusPill({ status }: { status: string | null }) {
  if (!status) return <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>;
  const c = drawingStatusColor(status);
  return (
    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap" style={{ backgroundColor: c.bg, color: c.text }}>
      {status}
    </span>
  );
}

/**
 * "—" (no revision added yet — the Drawing relies entirely on its legacy
 * document fallback) is deliberately distinct from "Not recorded" (a real
 * revision exists but its code wasn't captured, e.g. a migrated legacy
 * Drawing) — never conflate the two states (Phase 4 Part R).
 */
function CurrentRevisionCell({ currentRevision }: { currentRevision: DrawingRecord['current_revision'] }) {
  if (!currentRevision) return <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>;
  return (
    <span className="font-mono text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>
      {currentRevision.revision_code ?? 'Not recorded'}
    </span>
  );
}

export default function ProjectDrawingsPage() {
  const { id: projectId } = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const { canOperate } = useProjectPermissions();

  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [discipline, setDiscipline] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const [modal, setModal] = useState<{ open: boolean; drawing?: DrawingRecord }>({ open: false });
  const [removeTarget, setRemoveTarget] = useState<DrawingRecord | null>(null);

  // Debounce search (350ms, matching the admin Document Register's own
  // existing pattern) — no new dependency, reset to page 1 on change.
  useEffect(() => {
    const t = setTimeout(() => { setDebouncedSearch(search); setPage(1); }, 350);
    return () => clearTimeout(t);
  }, [search]);

  const queryKey = ['project-drawings', projectId, page, perPage, debouncedSearch, discipline, status];

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery<DrawingListResponse>({
    queryKey,
    queryFn: () => api.get(`/projects/${projectId}/drawings`, {
      params: {
        page, per_page: perPage,
        search: debouncedSearch || undefined,
        discipline: discipline || undefined,
        status: status || undefined,
      },
    }).then(r => r.data),
    placeholderData: prev => prev,
  });

  const removeMutation = useMutation({
    mutationFn: (drawing: DrawingRecord) => api.delete(`/projects/${projectId}/drawings/${drawing.id}`),
    onSuccess: () => {
      toast.success('Drawing registration removed');
      // A Document freed by removal must become selectable again immediately.
      qc.invalidateQueries({ queryKey: ['project-drawings', projectId] });
      qc.invalidateQueries({ queryKey: ['project-drawings-eligible-documents', projectId] });
      // Removing the last item on a page beyond page 1 would otherwise strand
      // the view on a now-empty page until the next unrelated refetch.
      if (rows.length === 1 && page > 1) setPage(p => p - 1);
      setRemoveTarget(null);
      setModal({ open: false });
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, "Couldn't remove this drawing. Please try again.")),
  });

  const rows = data?.data ?? [];
  const total = data?.total ?? 0;
  const lastPage = data?.last_page ?? 1;
  const hasFilters = !!debouncedSearch || !!discipline || !!status;

  function clearFilters() {
    setSearch(''); setDebouncedSearch(''); setDiscipline(''); setStatus(''); setPage(1);
  }

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6 pb-12">
      {modal.open && (
        <DrawingModal
          projectId={projectId}
          drawing={modal.drawing}
          canOperate={canOperate}
          onClose={() => setModal({ open: false })}
          onRemove={(d) => setRemoveTarget(d)}
        />
      )}

      {removeTarget && (
        <div className="fixed inset-0 z-[60] flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="ss-animate-in w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <p className="text-sm mb-2" style={{ color: 'var(--text-primary)' }}>
              Remove this drawing from the Drawing Register?
            </p>
            <p className="text-xs mb-4" style={{ color: 'var(--text-muted)' }}>
              The linked document will remain available in Documents.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setRemoveTarget(null)} className="px-3 py-1.5 rounded-lg text-sm transition-all active:scale-[0.98]" style={{ color: 'var(--text-secondary)' }}>
                Cancel
              </button>
              <button
                onClick={() => removeMutation.mutate(removeTarget)}
                disabled={removeMutation.isPending}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white transition-all active:scale-[0.98] disabled:opacity-60"
                style={{ backgroundColor: '#a11a1a' }}
              >
                {removeMutation.isPending ? 'Removing…' : 'Remove'}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Header */}
      <div className="flex items-start justify-between gap-4 flex-wrap">
        <div>
          <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Drawing Register</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Manage structured drawing information linked to project documents.
          </p>
        </div>
        {canOperate && (
          <Button onClick={() => setModal({ open: true })}>
            <Plus size={15} /> Register Drawing
          </Button>
        )}
      </div>

      {/* Filters */}
      <div className="flex gap-3 flex-wrap items-center">
        <div className="relative">
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: 'var(--text-muted)' }} />
          <input
            value={search}
            onChange={e => setSearch(e.target.value)}
            placeholder="Search drawing number, title, or document…"
            className="pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
            style={{ ...inputStyle, minWidth: '260px' }}
          />
        </div>

        <Select value={discipline} onChange={e => { setDiscipline(e.target.value); setPage(1); }} className="min-w-[160px]">
          <option value="">All disciplines</option>
          {DISCIPLINE_OPTIONS.map(d => <option key={d} value={d}>{d}</option>)}
        </Select>

        <Select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }} className="min-w-[170px]">
          <option value="">All statuses</option>
          {STATUS_OPTIONS.map(s => <option key={s} value={s}>{s}</option>)}
        </Select>

        {hasFilters && (
          <button
            onClick={clearFilters}
            className="text-xs px-3 py-2 rounded-lg hover:bg-[var(--bg-hover)] transition-colors"
            style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}
          >
            Clear filters
          </button>
        )}
      </div>

      {/* Content */}
      {isLoading ? (
        <div className="space-y-2">
          {[...Array(5)].map((_, i) => (
            <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : isError ? (
        <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <Ruler size={32} className="mx-auto mb-3" style={{ color: '#f87171' }} />
          <p className="text-sm" style={{ color: 'var(--text-primary)' }}>We couldn&rsquo;t load the Drawing Register</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{getErrorMessage(error, 'Please try again.')}</p>
          <Button onClick={() => refetch()} variant="secondary" size="sm" className="mt-4">Try again</Button>
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          surface
          icon={Ruler}
          title={hasFilters ? 'No drawings match your filters.' : 'No drawings have been registered yet.'}
          description={hasFilters
            ? 'Try adjusting the search, discipline, or status filter.'
            : 'Register a project document as a drawing to track its drawing number, discipline, status, and location.'}
          action={hasFilters ? (
            <Button onClick={clearFilters} variant="secondary" size="sm">Clear filters</Button>
          ) : canOperate ? (
            <Button onClick={() => setModal({ open: true })} size="sm">
              <Plus size={14} /> Register Drawing
            </Button>
          ) : undefined}
        />
      ) : (
        <>
          {/* Desktop / tablet register */}
          <div className="hidden md:block rounded-2xl overflow-x-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', opacity: isFetching ? 0.6 : 1 }}>
            <table className="w-full min-w-[760px] text-sm">
              <thead>
                <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                  {['Drawing No.', 'Title', 'Discipline', 'Status', 'Location', 'Current Revision', 'Document', 'Updated', ''].map(h => (
                    <th key={h} className="text-left px-4 py-3 text-xs font-medium uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map(d => (
                  <tr
                    key={d.id}
                    className="hover:bg-[var(--bg-hover)] transition-colors cursor-pointer"
                    style={{ borderBottom: '1px solid var(--border)' }}
                    onClick={() => router.push(`/app/projects/${projectId}/drawings/${d.id}`)}
                  >
                    <td className="px-4 py-3 font-mono text-[11px] font-semibold whitespace-nowrap" style={{ color: 'var(--gold)' }}>{d.drawing_number}</td>
                    <td className="px-4 py-3 max-w-[220px] truncate font-medium" style={{ color: 'var(--text-primary)' }} title={d.title}>{d.title}</td>
                    <td className="px-4 py-3"><DisciplineBadge discipline={d.discipline} /></td>
                    <td className="px-4 py-3"><StatusPill status={d.status} /></td>
                    <td className="px-4 py-3 max-w-[160px] truncate text-xs" style={{ color: 'var(--text-secondary)' }} title={d.location_reference ?? undefined}>
                      {d.location_reference || '—'}
                    </td>
                    <td className="px-4 py-3">
                      <CurrentRevisionCell currentRevision={d.current_revision} />
                    </td>
                    <td className="px-4 py-3 max-w-[200px]">
                      <div className="flex items-center gap-1.5 min-w-0">
                        <FileText size={12} className="flex-shrink-0" style={{ color: 'var(--text-muted)' }} />
                        <span className="truncate text-xs" style={{ color: 'var(--text-secondary)' }} title={d.document.title}>{d.document.title}</span>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-xs tabular-nums whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{formatDate(d.updated_at)}</td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-1">
                        <button
                          onClick={(e) => { e.stopPropagation(); router.push(`/app/projects/${projectId}/drawings/${d.id}`); }}
                          title="View Drawing"
                          className="flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-lg hover:bg-[var(--bg-elevated)] transition-colors"
                          style={{ color: 'var(--text-secondary)' }}
                        >
                          <Eye size={12} /> View
                        </button>
                        {canOperate && (
                          <>
                            <button
                              onClick={(e) => { e.stopPropagation(); setModal({ open: true, drawing: d }); }}
                              title="Edit Drawing"
                              className="p-1.5 rounded-lg hover:bg-[var(--bg-elevated)] transition-colors"
                            >
                              <Pencil size={12} style={{ color: 'var(--text-muted)' }} />
                            </button>
                            <button
                              onClick={(e) => { e.stopPropagation(); setRemoveTarget(d); }}
                              title="Remove Drawing"
                              className="p-1.5 rounded-lg hover:bg-[var(--bg-elevated)] transition-colors"
                            >
                              <Trash2 size={12} style={{ color: '#f87171' }} />
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile cards */}
          <div className="md:hidden space-y-2" style={{ opacity: isFetching ? 0.6 : 1 }}>
            {rows.map(d => (
              <div
                key={d.id}
                onClick={() => router.push(`/app/projects/${projectId}/drawings/${d.id}`)}
                className="rounded-2xl p-4 space-y-2 cursor-pointer"
                style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="font-mono text-[11px] font-semibold" style={{ color: 'var(--gold)' }}>{d.drawing_number}</p>
                    <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{d.title}</p>
                  </div>
                  <StatusPill status={d.status} />
                </div>
                <div className="flex items-center gap-2 flex-wrap">
                  <DisciplineBadge discipline={d.discipline} />
                  {d.location_reference && (
                    <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{d.location_reference}</span>
                  )}
                  {d.current_revision && (
                    <span className="text-xs font-mono" style={{ color: 'var(--text-muted)' }}>
                      Rev {d.current_revision.revision_code ?? 'not recorded'}
                    </span>
                  )}
                </div>
                <div className="flex items-center justify-between pt-2" style={{ borderTop: '1px solid var(--border)' }}>
                  <div className="flex items-center gap-1.5 min-w-0">
                    <FileText size={12} className="flex-shrink-0" style={{ color: 'var(--text-muted)' }} />
                    <span className="truncate text-xs" style={{ color: 'var(--text-muted)' }}>{d.document.title}</span>
                  </div>
                  <span className="text-xs tabular-nums flex-shrink-0" style={{ color: 'var(--text-muted)' }}>{formatDate(d.updated_at)}</span>
                </div>
                {canOperate && (
                  <div className="flex items-center gap-2 pt-2" style={{ borderTop: '1px solid var(--border)' }}>
                    <button
                      onClick={(e) => { e.stopPropagation(); setModal({ open: true, drawing: d }); }}
                      className="flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-lg"
                      style={{ color: 'var(--text-secondary)' }}
                    >
                      <Pencil size={12} /> Edit
                    </button>
                    <button
                      onClick={(e) => { e.stopPropagation(); setRemoveTarget(d); }}
                      className="flex items-center gap-1 text-xs font-medium px-2 py-1 rounded-lg"
                      style={{ color: '#f87171' }}
                    >
                      <Trash2 size={12} /> Remove
                    </button>
                  </div>
                )}
              </div>
            ))}
          </div>

          <PaginationBar
            page={page}
            lastPage={lastPage}
            total={total}
            perPage={perPage}
            onPage={setPage}
            onPerPage={n => { setPerPage(n); setPage(1); }}
          />
        </>
      )}
    </div>
  );
}
