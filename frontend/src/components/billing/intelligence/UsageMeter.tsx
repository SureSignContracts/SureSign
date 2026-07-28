import HealthBadge from './HealthBadge';
import { UsageMetric } from '@/types/subscriptionIntelligence';

const BAR_COLOR: Record<string, string> = {
  unknown: 'var(--text-muted)',
  healthy: '#4ade80',
  warning: '#facc15',
  critical: '#fb923c',
  exceeded: '#f87171',
};

function formatValue(value: number, valueType: string): string {
  if (valueType === 'decimal') return value.toFixed(value < 10 ? 2 : 1);
  return String(Math.round(value));
}

/**
 * Stage 3-5 — the one generic meter every usage card (Usage dashboard,
 * Storage, AI) renders through. Never per-feature-key branching here: any
 * current or future `EntitlementCategory::USAGE` Feature key renders
 * correctly through this same component, driven entirely by the metric's
 * own fields.
 */
export default function UsageMeter({ metric }: { metric: UsageMetric }) {
  const { display_name, unit, used, limit, is_unlimited, percent_used, status, value_type } = metric;

  return (
    <div
      role="group"
      aria-label={`${display_name} usage`}
      className="p-4 rounded-xl"
      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
    >
      <div className="flex items-center justify-between gap-2 flex-wrap">
        <span className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{display_name}</span>
        <HealthBadge status={status} />
      </div>

      <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
        {used === null ? (
          'Not yet measurable.'
        ) : is_unlimited ? (
          <>Unlimited{unit ? ` ${unit}` : ''} — {formatValue(used, value_type)} used so far</>
        ) : limit === null ? (
          <>{formatValue(used, value_type)}{unit ? ` ${unit}` : ''} used</>
        ) : (
          <>{formatValue(used, value_type)} / {formatValue(limit, value_type)}{unit ? ` ${unit}` : ''}</>
        )}
      </p>

      {used !== null && !is_unlimited && limit !== null && (
        <div
          className="mt-2.5 h-2 rounded-full overflow-hidden"
          style={{ backgroundColor: 'var(--bg-surface)' }}
          role="progressbar"
          aria-valuenow={Math.min(100, percent_used ?? 0)}
          aria-valuemin={0}
          aria-valuemax={100}
        >
          <div
            className="h-full rounded-full transition-[width] duration-500 motion-reduce:transition-none"
            style={{ width: `${Math.min(100, percent_used ?? 0)}%`, backgroundColor: BAR_COLOR[status] }}
          />
        </div>
      )}

      {percent_used !== null && !is_unlimited && (
        <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{percent_used}% used</p>
      )}
    </div>
  );
}
