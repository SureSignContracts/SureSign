import { forwardRef } from 'react';
import { cn } from '@/lib/utils';

export interface InputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  error?: string;
}

const Input = forwardRef<HTMLInputElement, InputProps>(
  ({ className, error, style, ...props }, ref) => (
    <div className="space-y-1">
      <input
        ref={ref}
        className={cn(
          'w-full px-3.5 py-2.5 rounded-lg text-sm transition-all outline-none',
          'focus:ring-2 focus:ring-[var(--gold)]/30',
          className,
        )}
        style={{
          backgroundColor: 'var(--bg-surface)',
          border: `1px solid ${error ? '#ef4444' : 'var(--border)'}`,
          color: 'var(--text-primary)',
          ...style,
        }}
        aria-invalid={!!error}
        {...props}
      />
      {error && <p className="text-xs" style={{ color: '#ef4444' }}>{error}</p>}
    </div>
  ),
);
Input.displayName = 'Input';

export default Input;
