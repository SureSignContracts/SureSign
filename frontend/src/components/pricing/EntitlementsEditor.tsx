'use client';

import { useId, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Toggle from '@/components/ui/Toggle';
import Modal from '@/components/ui/Modal';
import { ChevronDown, ChevronRight, Layers, Lock, RotateCcw, Save } from 'lucide-react';
import { EntitlementCategoryMeta, PlanEntitlementRow, PlanEntitlementsPayload, PricingPlan } from '@/types/pricing';

const ENFORCEMENT_LABEL: Record<string, string> = {
  informational: 'Informational',
  warning: 'Warning',
  soft_limit: 'Soft limit',
  approval_required: 'Approval required',
  hard_limit: 'Hard limit',
  unavailable: 'Unavailable',
};

type QuickFilter = 'enabled' | 'unlimited' | 'configurable';

function rowsEqual(a: PlanEntitlementRow[], b: PlanEntitlementRow[]): boolean {
  return JSON.stringify(a) === JSON.stringify(b);
}

function isEnabled(row: PlanEntitlementRow): boolean {
  if (!row.is_applicable) return false;
  if (row.is_unlimited) return true;
  return row.value_type === 'boolean' ? !!row.value : typeof row.value === 'number' && row.value > 0;
}

/** Renders the correct control for a row's value_type — never a generic JSON editor. */
function ValueControl({ row, onChange }: { row: PlanEntitlementRow; onChange: (value: boolean | number | string | null) => void }) {
  if (row.is_reserved) {
    return <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Not configurable</span>;
  }

  if (!row.is_applicable || row.is_unlimited) {
    return <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>;
  }

  if (row.value_type === 'boolean') {
    return (
      <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-primary)' }}>
        <Toggle checked={!!row.value} onChange={(v) => onChange(v)} />
        <span aria-hidden>{row.value ? 'On' : 'Off'}</span>
      </label>
    );
  }

  if (row.value_type === 'integer' || row.value_type === 'decimal') {
    return (
      <div className="flex items-center gap-2">
        <label className="sr-only" htmlFor={`value-${row.feature_key}`}>{row.display_name} value</label>
        <input
          id={`value-${row.feature_key}`}
          type="number"
          step={row.value_type === 'decimal' ? '0.1' : '1'}
          min={0}
          value={typeof row.value === 'number' ? row.value : ''}
          onChange={(e) => {
            const raw = e.target.value;
            if (raw === '') { onChange(0); return; }
            onChange(row.value_type === 'decimal' ? parseFloat(raw) : parseInt(raw, 10));
          }}
          className="w-24 px-2 py-1.5 rounded-lg text-sm outline-none motion-reduce:transition-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
        {row.unit && <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{row.unit}</span>}
      </div>
    );
  }

  return (
    <input
      type="text"
      aria-label={`${row.display_name} value`}
      value={typeof row.value === 'string' ? row.value : ''}
      onChange={(e) => onChange(e.target.value)}
      className="w-40 px-2 py-1.5 rounded-lg text-sm outline-none"
      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
    />
  );
}

function EntitlementRowCard({ row, defaultRow, onChange }: {
  row: PlanEntitlementRow;
  defaultRow: PlanEntitlementRow;
  onChange: (next: PlanEntitlementRow) => void;
}) {
  const [detailsOpen, setDetailsOpen] = useState(false);
  const changed = !row.is_reserved && !rowsEqual([row], [defaultRow]);
  const detailsId = `entitlement-details-${row.feature_key}`;

  return (
    <div
      className="rounded-lg"
      style={{
        backgroundColor: row.is_reserved ? 'var(--bg-surface)' : 'var(--bg-elevated)',
        border: `1px dashed transparent`,
        borderStyle: row.is_reserved ? 'dashed' : 'solid',
        borderColor: changed ? 'var(--gold)' : 'var(--border)',
        opacity: row.is_reserved ? 0.85 : 1,
      }}
    >
      <div className="p-3 flex flex-wrap items-center gap-4">
        <div className="min-w-[200px] flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            {row.is_reserved && <Lock size={12} aria-hidden style={{ color: 'var(--text-muted)' }} />}
            <span className="text-sm font-medium" style={{ color: row.is_reserved ? 'var(--text-muted)' : 'var(--text-primary)' }}>
              {row.display_name}
            </span>
            {row.is_reserved && (
              <span className="text-[10px] px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                Reserved — not sold
              </span>
            )}
            {!row.is_reserved && !row.currently_sold && (
              <span className="text-[10px] px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(250,204,21,0.15)', color: '#eab308' }}>
                Not yet sold
              </span>
            )}
          </div>
          {row.is_reserved && (
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              Reserved for a possible future platform capability — never enforced, sold, or shown to customers today.
            </p>
          )}
        </div>

        {!row.is_reserved && (
          <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
            <Toggle checked={row.is_applicable} onChange={(v) => onChange({ ...row, is_applicable: v, is_unlimited: v ? row.is_unlimited : false, value: v ? row.value : null })} />
            Applicable
          </label>
        )}

        {!row.is_reserved && row.category === 'usage' && (
          <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
            <Toggle
              checked={row.is_unlimited}
              disabled={!row.is_applicable}
              onChange={(v) => onChange({ ...row, is_unlimited: v, value: v ? null : (row.value_type === 'boolean' ? false : 0) })}
            />
            Unlimited
          </label>
        )}

        <ValueControl row={row} onChange={(value) => onChange({ ...row, value })} />

        {!row.is_reserved && (
          <button
            type="button"
            title="Reset to current default"
            aria-label={`Reset ${row.display_name} to its current default`}
            disabled={!changed}
            onClick={() => onChange(defaultRow)}
            className="p-1.5 rounded-md disabled:opacity-30"
            style={{ backgroundColor: 'var(--bg-surface)' }}
          >
            <RotateCcw size={13} aria-hidden />
          </button>
        )}

        <button
          type="button"
          onClick={() => setDetailsOpen(o => !o)}
          aria-expanded={detailsOpen}
          aria-controls={detailsId}
          className="flex items-center gap-1 text-xs px-1.5 py-1 rounded-md"
          style={{ color: 'var(--text-muted)' }}
        >
          {detailsOpen ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
          Details
        </button>
      </div>

      {detailsOpen && (
        <div id={detailsId} className="px-3 pb-3 pt-0 text-xs grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1.5" style={{ color: 'var(--text-secondary)' }}>
          <p className="col-span-2 sm:col-span-3" style={{ color: 'var(--text-muted)' }}>{row.description}</p>
          <p><span style={{ color: 'var(--text-muted)' }}>Key:</span> {row.feature_key}</p>
          <p><span style={{ color: 'var(--text-muted)' }}>Enforcement (read-only):</span> {ENFORCEMENT_LABEL[row.enforcement_level ?? ''] ?? '—'}</p>
          <p><span style={{ color: 'var(--text-muted)' }}>Customer-visible:</span> {row.customer_visible ? 'Yes' : 'No'}</p>
          <p><span style={{ color: 'var(--text-muted)' }}>Currently sold:</span> {row.currently_sold ? 'Yes' : 'No'}</p>
          <p><span style={{ color: 'var(--text-muted)' }}>Overrideable:</span> {row.overrideable ? 'Yes' : 'No'}</p>
        </div>
      )}
    </div>
  );
}

function CategorySection({ category, rows, defaults, onChange, defaultOpen }: {
  category: EntitlementCategoryMeta;
  rows: PlanEntitlementRow[];
  defaults: PlanEntitlementRow[];
  onChange: (next: PlanEntitlementRow) => void;
  defaultOpen: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const headingId = useId();
  const regionId = useId();

  if (rows.length === 0) return null;

  return (
    <section aria-labelledby={headingId}>
      <h3 id={headingId} className="sr-only">{category.label}</h3>
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        aria-controls={regionId}
        className="w-full flex items-center justify-between px-1 py-1.5 motion-reduce:transition-none"
      >
        <span className="text-left">
          <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-primary)' }} aria-hidden>
            {category.label}
          </span>
          <span className="block text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>{category.description}</span>
        </span>
        {open ? <ChevronDown size={16} aria-hidden /> : <ChevronRight size={16} aria-hidden />}
      </button>

      {open && (
        <div id={regionId} role="region" aria-label={category.label} className="space-y-2 mt-2">
          {rows.map(row => (
            <EntitlementRowCard
              key={row.feature_key}
              row={row}
              defaultRow={defaults.find(d => d.feature_key === row.feature_key)!}
              onChange={onChange}
            />
          ))}
        </div>
      )}
    </section>
  );
}

export default function EntitlementsEditorModal({ plan, onClose }: { plan: PricingPlan; onClose: () => void }) {
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState<string>('all');
  const [activeQuickFilters, setActiveQuickFilters] = useState<Set<QuickFilter>>(new Set());
  const [showReserved, setShowReserved] = useState(true);
  const [rows, setRows] = useState<PlanEntitlementRow[] | null>(null);
  const [seededFrom, setSeededFrom] = useState<PlanEntitlementRow[] | undefined>(undefined);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-pricing-plan-entitlements', plan.id],
    queryFn: () => api.get(`/admin/pricing/plans/${plan.id}/entitlements`).then(r => r.data.data as PlanEntitlementsPayload),
  });

  const defaultRows = data?.entitlements;

  // Seeds local editable `rows` the first time `data` arrives — adjusted during
  // render (React's endorsed pattern for this), not in an effect, so there is
  // no cascading-render tick between the fetch resolving and the form appearing.
  if (defaultRows !== seededFrom) {
    setSeededFrom(defaultRows);
    if (rows === null && defaultRows) setRows(defaultRows);
  }

  const mutation = useMutation({
    mutationFn: (entitlements: PlanEntitlementRow[]) =>
      api.put(`/admin/pricing/plans/${plan.id}/entitlements`, {
        // Reserved rows are never part of the editable/PUT set — the backend
        // rejects them outright, matching how they never get a database row.
        entitlements: entitlements.filter(r => !r.is_reserved).map(r => ({
          feature_key: r.feature_key,
          is_applicable: r.is_applicable,
          is_unlimited: r.is_unlimited,
          value: r.is_applicable && !r.is_unlimited ? r.value : null,
        })),
      }),
    onSuccess: (res) => {
      const payload = res.data.data as PlanEntitlementsPayload;
      setRows(payload.entitlements);
      qc.invalidateQueries({ queryKey: ['admin-pricing-plan-entitlements', plan.id] });
    },
  });

  function toggleQuickFilter(f: QuickFilter) {
    setActiveQuickFilters(prev => {
      const next = new Set(prev);
      if (next.has(f)) next.delete(f); else next.add(f);
      return next;
    });
  }

  const filtered = useMemo(() => {
    if (!rows) return [];
    const q = search.trim().toLowerCase();

    return rows.filter(r => {
      if (r.is_reserved && !showReserved) return false;
      if (categoryFilter !== 'all' && r.category !== categoryFilter) return false;
      if (q && !r.display_name.toLowerCase().includes(q) && !r.feature_key.toLowerCase().includes(q) && !r.description.toLowerCase().includes(q)) return false;
      if (activeQuickFilters.has('enabled') && !isEnabled(r)) return false;
      if (activeQuickFilters.has('unlimited') && !r.is_unlimited) return false;
      if (activeQuickFilters.has('configurable') && (r.is_reserved || !r.currently_sold)) return false;
      return true;
    });
  }, [rows, search, categoryFilter, activeQuickFilters, showReserved]);

  const dirty = !!(rows && defaultRows && !rowsEqual(rows, defaultRows));

  function updateRow(next: PlanEntitlementRow) {
    setRows(prev => (prev ? prev.map(r => (r.feature_key === next.feature_key ? next : r)) : prev));
  }

  const categories = data?.categories ?? [];

  return (
    <Modal title={`Entitlements — ${plan.name}`} icon={Layers} tone="neutral" onClose={onClose} busy={mutation.isPending}>
      {(close) => (
        <div className="space-y-4 w-[92vw] sm:w-[640px]" style={{ maxWidth: '90vw' }}>
          <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>
            These are this plan&apos;s default entitlements — every key and category comes directly from the Feature
            registry, so a new key or category appears here automatically. Changing a value only affects future
            activations, upgrades, and downgrades; it never alters an existing subscription&apos;s already-frozen
            entitlement snapshot.
          </p>

          <div className="space-y-2">
            <label className="sr-only" htmlFor="entitlement-search">Search entitlements</label>
            <input
              id="entitlement-search"
              placeholder="Search entitlements…"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />

            <div className="flex flex-wrap items-center gap-2" role="group" aria-label="Filter entitlements">
              <label className="sr-only" htmlFor="entitlement-category-filter">Filter by category</label>
              <select
                id="entitlement-category-filter"
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value)}
                className="px-2 py-1.5 rounded-lg text-xs outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              >
                <option value="all">All categories</option>
                {categories.map(c => <option key={c.key} value={c.key}>{c.label}</option>)}
              </select>

              {([
                ['enabled', 'Enabled only'],
                ['unlimited', 'Unlimited only'],
                ['configurable', 'Configurable only'],
              ] as [QuickFilter, string][]).map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  aria-pressed={activeQuickFilters.has(key)}
                  onClick={() => toggleQuickFilter(key)}
                  className="text-xs px-2.5 py-1 rounded-full motion-reduce:transition-none"
                  style={{
                    border: `1px solid ${activeQuickFilters.has(key) ? 'var(--gold)' : 'var(--border)'}`,
                    color: activeQuickFilters.has(key) ? 'var(--text-primary)' : 'var(--text-muted)',
                    backgroundColor: activeQuickFilters.has(key) ? 'rgba(234,179,8,0.1)' : 'transparent',
                  }}
                >
                  {label}
                </button>
              ))}

              <button
                type="button"
                aria-pressed={showReserved}
                onClick={() => setShowReserved(v => !v)}
                className="text-xs px-2.5 py-1 rounded-full ml-auto motion-reduce:transition-none"
                style={{
                  border: `1px solid ${showReserved ? 'var(--gold)' : 'var(--border)'}`,
                  color: showReserved ? 'var(--text-primary)' : 'var(--text-muted)',
                  backgroundColor: showReserved ? 'rgba(234,179,8,0.1)' : 'transparent',
                }}
              >
                {showReserved ? 'Hide reserved' : 'Show reserved'}
              </button>
            </div>
          </div>

          {isLoading || !rows ? (
            <p className="text-sm" style={{ color: 'var(--text-muted)' }} role="status">Loading entitlements…</p>
          ) : (
            <div className="space-y-4 max-h-[55vh] overflow-y-auto pr-1">
              {categories.map((category, i) => (
                <CategorySection
                  key={category.key}
                  category={category}
                  rows={filtered.filter(r => r.category === category.key)}
                  defaults={defaultRows ?? []}
                  onChange={updateRow}
                  defaultOpen={category.key !== 'reserved' && i < 2}
                />
              ))}
              {filtered.length === 0 && (
                <p className="text-sm" style={{ color: 'var(--text-muted)' }} role="status">
                  No entitlements match the current search/filters.
                </p>
              )}
            </div>
          )}

          {mutation.isError && (
            <p className="text-xs" role="alert" style={{ color: '#f87171' }}>
              Could not save — check every value matches its expected type and try again.
            </p>
          )}

          <div className="flex items-center justify-end gap-3 pt-2">
            <Button variant="secondary" size="sm" onClick={close} disabled={mutation.isPending}>
              {dirty ? 'Discard & close' : 'Close'}
            </Button>
            <Button
              variant="primary"
              size="sm"
              disabled={!rows || !dirty || mutation.isPending}
              onClick={() => rows && mutation.mutate(rows)}
            >
              <Save size={14} aria-hidden /> {mutation.isPending ? 'Saving…' : 'Save entitlements'}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
