import { forwardRef } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-all duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] disabled:opacity-50 disabled:pointer-events-none active:scale-[0.98]',
  {
    variants: {
      variant: {
        primary: 'hover:opacity-90',
        secondary: 'hover:bg-[var(--bg-hover)]',
        ghost: 'hover:bg-[var(--bg-hover)]',
        danger: 'bg-red-500 text-white hover:opacity-90',
      },
      size: {
        sm: 'px-3 py-1.5 text-xs',
        md: 'px-4 py-2 text-sm',
        lg: 'px-5 py-2.5 text-sm',
      },
    },
    defaultVariants: {
      variant: 'primary',
      size: 'md',
    },
  },
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {}

const VARIANT_STYLE: Record<string, React.CSSProperties> = {
  primary: { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' },
  secondary: { backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' },
  ghost: { color: 'var(--text-secondary)' },
};

const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant = 'primary', size, style, ...props }, ref) => (
    <button
      ref={ref}
      className={cn(buttonVariants({ variant, size }), className)}
      style={variant && variant !== 'danger' ? { ...VARIANT_STYLE[variant], ...style } : style}
      {...props}
    />
  ),
);
Button.displayName = 'Button';

export default Button;
