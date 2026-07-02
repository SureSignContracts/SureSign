'use client';

interface SureSignLoaderProps {
  message?: string;
}

export default function SureSignLoader({
  message = 'Loading your workspace…',
}: SureSignLoaderProps) {
  return (
    <div
      className="min-h-screen flex flex-col items-center justify-center gap-5"
      style={{ backgroundColor: 'var(--bg-base)' }}
    >
      <div className="relative flex items-center justify-center">
        <div
          className="absolute w-16 h-16 rounded-full border-2 animate-spin"
          style={{ borderColor: 'var(--border)', borderTopColor: 'var(--gold)' }}
        />
        <img
          src="/logo_black/SureSign_BLOGO.png"
          alt="SureSign"
          className="w-8 h-8 object-contain"
          style={{ filter: 'var(--logo-filter, none)' }}
        />
      </div>

      {message && (
        <div className="flex flex-col items-center gap-1">
          <span className="text-sm font-semibold tracking-tight" style={{ color: 'var(--text-primary)' }}>
            SureSign
          </span>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {message}
          </span>
        </div>
      )}
    </div>
  );
}
