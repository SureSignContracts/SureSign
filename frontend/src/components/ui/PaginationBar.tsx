'use client';

const PER_PAGE_OPTIONS = [25, 50, 100] as const;

interface PaginationBarProps {
  page: number;
  lastPage: number;
  total: number;
  perPage: number;
  onPage: (p: number) => void;
  onPerPage: (n: number) => void;
}

export default function PaginationBar({
  page, lastPage, total, perPage,
  onPage, onPerPage,
}: PaginationBarProps) {
  if (lastPage <= 1 && total <= PER_PAGE_OPTIONS[0]) return null;

  const from = Math.min((page - 1) * perPage + 1, total);
  const to   = Math.min(page * perPage, total);

  const pages: number[] = [];
  if (lastPage <= 7) {
    for (let i = 1; i <= lastPage; i++) pages.push(i);
  } else {
    pages.push(1);
    if (page > 3) pages.push(-1);
    for (let i = Math.max(2, page - 1); i <= Math.min(lastPage - 1, page + 1); i++) pages.push(i);
    if (page < lastPage - 2) pages.push(-1);
    pages.push(lastPage);
  }

  return (
    <div className="flex items-center justify-between pt-4 flex-wrap gap-3">
      <div className="flex items-center gap-2">
        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
          {from}–{to} of {total}
        </span>
        <select
          value={perPage}
          onChange={e => onPerPage(Number(e.target.value))}
          className="text-xs px-2 py-1 rounded-lg outline-none"
          style={{
            backgroundColor: 'var(--bg-elevated)',
            border: '1px solid var(--border)',
            color: 'var(--text-secondary)',
          }}
        >
          {PER_PAGE_OPTIONS.map(n => (
            <option key={n} value={n}>{n} per page</option>
          ))}
        </select>
      </div>
      <div className="flex items-center gap-1">
        <button
          onClick={() => onPage(page - 1)}
          disabled={page === 1}
          className="px-2.5 py-1.5 rounded-lg text-xs font-medium disabled:opacity-40 transition-colors hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
        >
          ‹
        </button>
        {pages.map((p, i) =>
          p === -1 ? (
            <span key={`ellipsis-${i}`} className="px-1 text-xs" style={{ color: 'var(--text-muted)' }}>…</span>
          ) : (
            <button
              key={p}
              onClick={() => onPage(p)}
              className="w-7 h-7 rounded-lg text-xs font-medium transition-colors"
              style={
                p === page
                  ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                  : { color: 'var(--text-secondary)', border: '1px solid var(--border)' }
              }
            >
              {p}
            </button>
          )
        )}
        <button
          onClick={() => onPage(page + 1)}
          disabled={page === lastPage}
          className="px-2.5 py-1.5 rounded-lg text-xs font-medium disabled:opacity-40 transition-colors hover:bg-[var(--bg-hover)]"
          style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
        >
          ›
        </button>
      </div>
    </div>
  );
}
