'use client';

import { useId, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Toggle from '@/components/ui/Toggle';
import Modal from '@/components/ui/Modal';
import Select from '@/components/ui/Select';
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

// Shared grid so the (desktop-only) column header row and every entitlement
// row line up pixel-for-pixel. Below `sm`, `display` flips to flex (see
// ROW_LAYOUT) so these column utilities simply go inert — no separate mobile
// markup needed.
const ROW_COLUMNS = 'sm:grid-cols-[minmax(0,1fr)_180px_84px_84px_28px]';
const ROW_LAYOUT = `flex flex-wrap sm:flex-nowrap items-start sm:items-center gap-2 sm:gap-3 sm:grid ${ROW_COLUMNS}`;

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
      <div className="flex items-center gap-1.5">
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
          className="w-16 px-2 py-1 rounded-md text-sm outline-none motion-reduce:transition-none"
          style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
        {row.unit && <span className="text-xs whitespace-nowrap" style={{ color: 'var(--text-muted)' }}>{row.unit}</span>}
      </div>
    );
  }

  return (
    <input
      type="text"
      aria-label={`${row.display_name} value`}
      value={typeof row.value === 'string' ? row.value : ''}
      onChange={(e) => onChange(e.target.value)}
      className="w-32 px-2 py-1 rounded-md text-sm outline-none"
      style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
    />
  );
}

function EntitlementRow({ row, defaultRow, onChange }: {
  row: PlanEntitlementRow;
  defaultRow: PlanEntitlementRow;
  onChange: (next: PlanEntitlementRow) => void;
}) {
  const [detailsOpen, setDetailsOpen] = useState(false);
  const changed = !row.is_reserved && !rowsEqual([row], [defaultRow]);
  const detailsId = `entitlement-details-${row.feature_key}`;
  const showsUnlimited = !row.is_reserved && row.category === 'usage';

  return (
    <div
      style={{
        backgroundColor: 'transparent',
        boxShadow: changed ? 'inset 3px 0 0 0 var(--gold)' : 'none',
        opacity: row.is_reserved ? 0.7 : 1,
      }}
    >
      <div className={`${ROW_LAYOUT} px-3 py-2`}>
        {/* Entitlement name + badges */}
        <div className="min-w-0 flex-1 basis-full sm:basis-auto">
          <div className="flex items-center gap-2 flex-wrap">
            {row.is_reserved && <Lock size={12} aria-hidden style={{ color: 'var(--text-muted)' }} />}
            <span className="text-sm font-medium truncate" style={{ color: row.is_reserved ? 'var(--text-muted)' : 'var(--text-primary)' }}>
              {row.display_name}
            </span>
            {row.is_reserved && (
              <span className="text-[10px] px-1.5 py-0.5 rounded-full whitespace-nowrap" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                Reserved
              </span>
            )}
            {!row.is_reserved && !row.currently_sold && (
              <span className="text-[10px] px-1.5 py-0.5 rounded-full whitespace-nowrap" style={{ backgroundColor: 'rgba(250,204,21,0.15)', color: '#eab308' }}>
                Not yet sold
              </span>
            )}
          </div>
          {row.is_reserved && (
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              Reserved for a possible future capability — never enforced, sold, or shown to customers.
            </p>
          )}
        </div>

        {/* Value + unit + reset */}
        <div className="flex items-center gap-1.5 sm:justify-self-start">
          <ValueControl row={row} onChange={(value) => onChange({ ...row, value })} />
          {!row.is_reserved && (
            <button
              type="button"
              title="Reset to current default"
              aria-label={`Reset ${row.display_name} to its current default`}
              disabled={!changed}
              onClick={() => onChange(defaultRow)}
              className="p-1 rounded-md disabled:opacity-0 disabled:pointer-events-none flex-shrink-0"
              style={{ backgroundColor: 'var(--bg-elevated)' }}
            >
              <RotateCcw size={12} aria-hidden />
            </button>
          )}
        </div>

        {/* Applicable */}
        <div className="flex sm:flex-col sm:items-center gap-1.5 sm:gap-0.5">
          {!row.is_reserved ? (
            <>
              <span className="text-[10px] uppercase tracking-wide sm:hidden" style={{ color: 'var(--text-muted)' }}>Applicable</span>
              <Toggle checked={row.is_applicable} onChange={(v) => onChange({ ...row, is_applicable: v, is_unlimited: v ? row.is_unlimited : false, value: v ? row.value : null })} />
            </>
          ) : (
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>
          )}
        </div>

        {/* Unlimited (only meaningful for usage-category rows — a placeholder dash keeps every row's columns aligned) */}
        <div className="flex sm:flex-col sm:items-center gap-1.5 sm:gap-0.5">
          {showsUnlimited ? (
            <>
              <span className="text-[10px] uppercase tracking-wide sm:hidden" style={{ color: 'var(--text-muted)' }}>Unlimited</span>
              <Toggle
                checked={row.is_unlimited}
                disabled={!row.is_applicable}
                onChange={(v) => onChange({ ...row, is_unlimited: v, value: v ? null : (row.value_type === 'boolean' ? false : 0) })}
              />
            </>
          ) : (
            <span className="text-xs hidden sm:inline" style={{ color: 'var(--text-muted)' }}>—</span>
          )}
        </div>

        {/* Details */}
        <div className="flex sm:justify-center">
          <button
            type="button"
            onClick={() => setDetailsOpen(o => !o)}
            aria-expanded={detailsOpen}
            aria-controls={detailsId}
            title="Details"
            className="flex items-center gap-1 text-xs px-1 py-1 rounded-md"
            style={{ color: 'var(--text-muted)' }}
          >
            {detailsOpen ? <ChevronDown size={14} aria-hidden /> : <ChevronRight size={14} aria-hidden />}
            <span className="sm:sr-only">Details</span>
          </button>
        </div>
      </div>

      {detailsOpen && (
        <div id={detailsId} className="px-3 pb-3 pt-1 text-xs grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-1.5" style={{ color: 'var(--text-secondary)', backgroundColor: 'var(--bg-surface)' }}>
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

/** Column labels for the row grid above — desktop only; below `sm` each row shows its own inline labels instead. */
function ColumnHeader() {
  return (
    <div
      className={`hidden sm:grid ${ROW_COLUMNS} gap-3 px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wide rounded-t-lg`}
      style={{ color: 'var(--text-muted)', backgroundColor: 'var(--bg-surface)' }}
    >
      <span>Entitlement</span>
      <span>Value</span>
      <span className="text-center">Applicable</span>
      <span className="text-center">Unlimited</span>
      <span />
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
        className="w-full flex items-baseline gap-2 px-1 py-1.5 motion-reduce:transition-none"
      >
        {open ? <ChevronDown size={14} aria-hidden className="flex-shrink-0" /> : <ChevronRight size={14} aria-hidden className="flex-shrink-0" />}
        <span className="text-left flex items-baseline gap-2 flex-wrap">
          <span className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-primary)' }} aria-hidden>
            {category.label}
          </span>
          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{category.description}</span>
        </span>
      </button>

      {open && (
        <div id={regionId} role="region" aria-label={category.label} className="rounded-lg mb-3" style={{ border: '1px solid var(--border)' }}>
          <ColumnHeader />
          {rows.map((row, i) => (
            <div key={row.feature_key} style={i > 0 ? { borderTop: '1px solid var(--border)' } : undefined}>
              <EntitlementRow
                row={row}
                defaultRow={defaults.find(d => d.feature_key === row.feature_key)!}
                onChange={onChange}
              />
            </div>
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

  function clearFilters() {
    setSearch('');
    setCategoryFilter('all');
    setActiveQuickFilters(new Set());
    setShowReserved(true);
  }

  const categories = data?.categories ?? [];
  const filtersActive = !!search || categoryFilter !== 'all' || activeQuickFilters.size > 0 || !showReserved;

  return (
    <Modal title={`Entitlements — ${plan.name}`} icon={Layers} tone="neutral" size="xl" onClose={onClose} busy={mutation.isPending}>
      {(close) => (
        <div className="w-full h-full flex flex-col flex-1 min-h-0">
          <p className="text-xs mb-4 flex-shrink-0" style={{ color: 'var(--text-secondary)' }}>
            These are this plan&apos;s default entitlements, pulled live from the Feature registry. Changing a value
            only affects future activations, upgrades, and downgrades — it never alters an existing subscription&apos;s
            already-frozen entitlement snapshot.
          </p>

          <div className="space-y-2 mb-4 flex-shrink-0">
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
              <Select
                id="entitlement-category-filter"
                value={categoryFilter}
                onChange={(e) => setCategoryFilter(e.target.value)}
                size="sm"
              >
                <option value="all">All categories</option>
                {categories.map(c => <option key={c.key} value={c.key}>{c.label}</option>)}
              </Select>

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
                className="text-xs px-2.5 py-1 rounded-full motion-reduce:transition-none"
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

          {/* The one real scroll region — `flex-1 min-h-0` takes exactly the space Modal's body
             wrapper actually has left (never a guessed vh value), so it can never end up taller
             than its own content while still leaving dead space before the footer. */}
          <div className="flex-1 min-h-0 overflow-y-auto pr-1 -mr-1">
            {isLoading || !rows ? (
              <p className="text-sm" style={{ color: 'var(--text-muted)' }} role="status">Loading entitlements…</p>
            ) : (
              <>
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
                  <div className="text-sm py-6 text-center" role="status">
                    <p style={{ color: 'var(--text-muted)' }}>No entitlements match these filters.</p>
                    {filtersActive && (
                      <button type="button" onClick={clearFilters} className="text-xs mt-2 underline" style={{ color: 'var(--text-secondary)' }}>
                        Clear filters
                      </button>
                    )}
                  </div>
                )}
              </>
            )}
          </div>

          {mutation.isError && (
            <p className="text-xs mt-3 flex-shrink-0" role="alert" style={{ color: '#f87171' }}>
              Could not save — check every value matches its expected type and try again.
            </p>
          )}

          <div className="flex items-center justify-end gap-3 mt-4 pt-4 flex-shrink-0" style={{ borderTop: '1px solid var(--border)' }}>
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
