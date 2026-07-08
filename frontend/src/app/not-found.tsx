import Link from 'next/link';

export default function NotFound() {
  return (
    <div
      className="min-h-dvh flex flex-col items-center justify-center gap-6 px-6 text-center"
      style={{ backgroundColor: 'var(--bg-base)' }}
    >
      <img
        src="/logo_black/SureSign_BLOGO.png"
        alt="SureSign"
        className="w-9 h-9 object-contain"
        style={{ filter: 'var(--logo-filter, none)' }}
      />

      <div className="space-y-2">
        <p className="tabular-nums text-sm font-semibold tracking-widest" style={{ color: 'var(--text-muted)' }}>
          404
        </p>
        <h1 className="text-2xl font-bold tracking-tight" style={{ color: 'var(--text-primary)' }}>
          This page doesn&apos;t exist
        </h1>
        <p className="text-sm max-w-sm" style={{ color: 'var(--text-secondary)' }}>
          The link may be outdated, or the project, contract, or document it points to has moved.
        </p>
      </div>

      <Link
        href="/app"
        className="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition-opacity hover:opacity-85"
        style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
      >
        Back to workspace
      </Link>
    </div>
  );
}
