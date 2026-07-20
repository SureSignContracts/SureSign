import { cn } from '@/lib/utils';

/**
 * `surface` wraps the content in the standard SureSign content-surface
 * treatment (rounded-2xl, bg-surface, border, shadow-card, entrance
 * animation) — Level 1 in the app's canvas → surface → row hierarchy.
 * Extracted here (rather than left as an inline wrapper repeated at each
 * call site) after the same exact wrapper turned up independently in
 * Dashboard, Commercial, Reports, Documents, and Projects: every one of
 * those pages' "nothing to show" states needs this, so the component itself
 * should provide it instead of every page reimplementing it. Pass `surface`
 * for a standalone empty state that IS the whole of a section (nothing else
 * around it supplies the surface); omit it when the empty state already sits
 * inside a surface a caller provides for other reasons (e.g. inside a table).
 */
export default function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
  surface,
}: {
  icon?: React.ElementType;
  title: string;
  description?: string;
  action?: React.ReactNode;
  className?: string;
  surface?: boolean;
}) {
  const content = (
    <div className={cn('flex flex-col items-center justify-center text-center px-6 py-12 gap-3', className)}>
      {Icon && (
        <div
          className="w-11 h-11 rounded-xl flex items-center justify-center"
          style={{ backgroundColor: 'var(--bg-elevated)' }}
        >
          <Icon size={20} style={{ color: 'var(--text-muted)' }} />
        </div>
      )}
      <div className="space-y-1">
        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{title}</p>
        {description && (
          <p className="text-xs max-w-xs" style={{ color: 'var(--text-muted)' }}>{description}</p>
        )}
      </div>
      {action}
    </div>
  );

  if (!surface) return content;

  return (
    <div className="ss-animate-in rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
      {content}
    </div>
  );
}
