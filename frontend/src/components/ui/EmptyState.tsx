import { cn } from '@/lib/utils';

export default function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: {
  icon?: React.ElementType;
  title: string;
  description?: string;
  action?: React.ReactNode;
  className?: string;
}) {
  return (
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
}
