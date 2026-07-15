import type { LucideIcon } from 'lucide-react';

interface HeroChipProps {
  icon: LucideIcon;
  label: string;
  className?: string;
}

export function HeroChip({ icon: Icon, label, className = '' }: HeroChipProps) {
  return (
    <div
      className={`absolute z-10 hidden items-center gap-2.5 rounded-full border border-border bg-bg-base/90 py-2.5 pl-2.5 pr-4 text-xs font-medium text-text-primary shadow-[var(--shadow-card)] backdrop-blur md:flex ${className}`}
    >
      <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-bg-elevated">
        <Icon size={14} strokeWidth={1.75} />
      </span>
      {label}
    </div>
  );
}
