import type { ElementType, ReactNode } from 'react';

type ProjectModuleHeaderProps = {
  category: string;
  title: string;
  description: string;
  icon: ElementType;
  tour?: ReactNode;
  action?: ReactNode;
  children?: ReactNode;
  metricColumns?: 3 | 4 | 5 | 6;
};

export function ProjectModuleHeader({
  category,
  title,
  description,
  icon: Icon,
  tour,
  action,
  children,
  metricColumns = 4,
}: ProjectModuleHeaderProps) {
  return (
    <section className="ss-animate-in relative overflow-hidden rounded-2xl bg-[#18211d] text-white shadow-[0_24px_60px_rgba(24,33,29,0.16)]">
      <div className="pointer-events-none absolute -right-16 -top-20 h-64 w-64 rounded-full bg-[#9ee5b5]/10 blur-3xl" />
      <div className="relative flex flex-col gap-6 px-6 pb-7 pt-6 sm:px-8 sm:pt-8 lg:flex-row lg:items-end lg:justify-between lg:px-9 lg:pb-8">
        <div className="max-w-2xl">
          <div className="mb-5 flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.14em] text-[#9ee5b5]">
            <Icon size={15} strokeWidth={1.8} />
            {category}
          </div>
          <div className="flex items-center gap-2">
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">{title}</h1>
            {tour}
          </div>
          <p className="mt-3 max-w-xl text-sm leading-6 text-white/60">{description}</p>
        </div>
        {action && <div className="flex shrink-0 items-center">{action}</div>}
      </div>
      {children && (
        <div className={`relative grid grid-cols-2 border-t border-white/10 ${metricColumns === 6 ? 'sm:grid-cols-3 lg:grid-cols-6' : metricColumns === 5 ? 'sm:grid-cols-3 lg:grid-cols-5' : metricColumns === 3 ? 'sm:grid-cols-3' : 'sm:grid-cols-4'}`}>
          {children}
        </div>
      )}
    </section>
  );
}

export function ProjectModuleMetric({
  label,
  value,
  tone = '#9ee5b5',
  active = false,
  onClick,
  index = 0,
}: {
  label: string;
  value: number | string;
  tone?: string;
  active?: boolean;
  onClick?: () => void;
  index?: number;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="ss-animate-in group min-h-[100px] border-r border-white/10 px-5 py-4 text-left transition-colors duration-300 last:border-r-0 hover:bg-white/[0.055] active:bg-white/[0.08]"
      style={{ backgroundColor: active ? 'rgba(255,255,255,0.065)' : undefined, animationDelay: `${index * 55}ms` }}
    >
      <span className="block text-3xl font-semibold leading-none tabular-nums tracking-[-0.04em]" style={{ color: tone }}>{value}</span>
      <span className="mt-2 block text-xs capitalize text-white/55 transition-colors group-hover:text-white/80">{label}</span>
    </button>
  );
}
