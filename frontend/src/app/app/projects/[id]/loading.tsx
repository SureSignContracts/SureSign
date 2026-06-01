export default function ProjectLoading() {
  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      {/* Header skeleton */}
      <div className="flex items-start justify-between">
        <div className="space-y-2">
          <div className="h-7 w-64 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          <div className="h-4 w-40 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        </div>
        <div className="h-6 w-20 rounded-full animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
      </div>
      {/* Stat cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        {[...Array(4)].map((_, i) => (
          <div key={i} className="h-14 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        ))}
      </div>
      {/* Detail cards */}
      <div className="grid lg:grid-cols-2 gap-5">
        {[...Array(2)].map((_, i) => (
          <div
            key={i}
            className="rounded-2xl p-5 space-y-3"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}
          >
            <div className="h-4 w-28 rounded-lg animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            {[...Array(5)].map((_, j) => (
              <div
                key={j}
                className="flex justify-between py-2"
                style={{ borderBottom: '1px solid var(--border)' }}
              >
                <div className="h-3 w-28 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
                <div className="h-3 w-32 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
              </div>
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}
