'use client';

import { useState } from 'react';
import { useSearchParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import { Plus, X, Trash2, ChevronDown, FileCheck, AlertTriangle, Lock, Download, FileOutput } from 'lucide-react';
import toast from 'react-hot-toast';

// ─── Types ───────────────────────────────────────────────────────────────────

type FinalAccountStatus =
  | 'draft' | 'submitted' | 'under_review' | 'agreed'
  | 'signed' | 'final_certificate_issued' | 'commercially_closed';

type FADocument = {
  id: number;
  title: string;
  type: string;
  reference_number: string | null;
  file_name: string | null;
  created_at: string | null;
};

type FAItem = {
  id: number;
  final_account_id: number;
  category: string;
  description: string;
  source_type: string | null;
  source_id: number | null;
  amount: number | string;
  is_auto_seeded: boolean;
  notes: string | null;
  sort_order: number;
};

type FinalAccountRecord = {
  id: number;
  project_id: number;
  contract_id: number | null;
  trade_package_id: number | null;
  is_trade_package: boolean;
  reference: string | null;
  status: FinalAccountStatus;
  // Snapshot columns — null until agreed
  original_contract_sum: number | string | null;
  approved_variations_total: number | string | null;
  loss_and_expense_total: number | string | null;
  dayworks_total: number | string | null;
  provisional_sum_adjustment: number | string | null;
  prime_cost_sum_adjustment: number | string | null;
  contra_charges_total: number | string | null;
  other_adjustments_total: number | string | null;
  certified_to_date: number | string | null;
  paid_to_date: number | string | null;
  retention_held: number | string | null;
  retention_released: number | string | null;
  // Computed accessors — null until agreed
  adjusted_contract_sum: number | string | null;
  retention_outstanding: number | string | null;
  final_balance_due: number | string | null;
  // Lifecycle flags
  is_locked: boolean;
  is_snapshotted: boolean;
  can_return_to_draft: boolean;
  submitted_at: string | null;
  agreed_at: string | null;
  signed_at: string | null;
  final_certificate_issued_at: string | null;
  dispute_window_expires_at: string | null;
  closed_at: string | null;
  notes: string | null;
  items?: FAItem[];
  documents?: FADocument[];
};

// Totals returned by GET /final-accounts/{id}/totals (always live)
type FATotals = {
  original_contract_sum: number;
  approved_variations_total: number;
  loss_and_expense_total: number;
  dayworks_total: number;
  provisional_sum_adjustment: number;
  prime_cost_sum_adjustment: number;
  contra_charges_total: number;
  other_adjustments_total: number;
  adjusted_contract_sum: number;
  certified_to_date: number;
  paid_to_date: number;
  retention_held: number;
  retention_released: number;
  retention_outstanding: number;
  final_balance_due: number;
};

type ContractOption = {
  id: number;
  title: string;
  reference_number?: string | null;
  party_name?: string | null;
  status?: string | null;
};

type TradePackageOption = {
  id: number;
  name: string;
  package_reference?: string | null;
  contractor_name?: string | null;
};

// ─── Constants ────────────────────────────────────────────────────────────────

const FA_STATUS: Record<FinalAccountStatus, { bg: string; text: string; label: string }> = {
  draft:                    { bg: 'rgba(90,86,82,0.2)',     text: '#9a9490', label: 'Draft' },
  submitted:                { bg: 'rgba(234,179,8,0.12)',   text: '#facc15', label: 'Submitted' },
  under_review:             { bg: 'rgba(251,146,60,0.12)',  text: '#fb923c', label: 'Under Review' },
  agreed:                   { bg: 'rgba(59,130,246,0.12)',  text: '#60a5fa', label: 'Agreed' },
  signed:                   { bg: 'rgba(167,139,250,0.12)', text: '#a78bfa', label: 'Signed' },
  final_certificate_issued: { bg: 'rgba(34,197,94,0.12)',   text: '#4ade80', label: 'Final Certificate' },
  commercially_closed:      { bg: 'rgba(34,197,94,0.2)',    text: '#4ade80', label: 'Commercially Closed' },
};

const FA_STEPS: { status: FinalAccountStatus; label: string }[] = [
  { status: 'draft',                    label: 'Draft' },
  { status: 'submitted',                label: 'Submitted' },
  { status: 'under_review',             label: 'Review' },
  { status: 'agreed',                   label: 'Agreed' },
  { status: 'signed',                   label: 'Signed' },
  { status: 'final_certificate_issued', label: 'Certificate' },
  { status: 'commercially_closed',      label: 'Closed' },
];

const FA_CATEGORY_ORDER = [
  'contract_sum', 'approved_variation', 'loss_and_expense', 'daywork',
  'provisional_sum', 'prime_cost_sum', 'contra_charge', 'deduction', 'other',
];

const FA_CATEGORY_LABELS: Record<string, string> = {
  contract_sum:       'Contract Sum',
  approved_variation: 'Approved Variations',
  loss_and_expense:   'Loss & Expense',
  daywork:            'Dayworks',
  provisional_sum:    'Provisional Sums',
  prime_cost_sum:     'Prime Cost Sums',
  contra_charge:      'Contra Charges',
  deduction:          'Deductions',
  other:              'Other Adjustments',
};

const FA_USER_CATEGORIES = [
  { key: 'loss_and_expense', label: 'Loss & Expense' },
  { key: 'daywork',          label: 'Daywork' },
  { key: 'provisional_sum',  label: 'Provisional Sum' },
  { key: 'prime_cost_sum',   label: 'Prime Cost Sum' },
  { key: 'contra_charge',    label: 'Contra Charge' },
  { key: 'deduction',        label: 'Deduction' },
  { key: 'other',            label: 'Other' },
];

// Categories displayed as negative (shown with parentheses)
const FA_NEGATIVE_CATEGORIES = new Set(['contra_charge', 'deduction']);

// ─── Lifecycle action definitions ─────────────────────────────────────────────

type LifecycleAction = {
  label: string;
  action: string;
  color: string;
  bg: string;
  confirm?: string;
};

function getLifecycleActions(status: FinalAccountStatus, canReturnToDraft: boolean): LifecycleAction[] {
  switch (status) {
    case 'draft':
      return [{ label: 'Submit', action: 'submit', color: '#facc15', bg: 'rgba(234,179,8,0.15)' }];

    case 'submitted':
      return [
        { label: 'Start Review', action: 'start-review', color: '#fb923c', bg: 'rgba(251,146,60,0.15)' },
        ...(canReturnToDraft ? [{ label: 'Return to Draft', action: 'revise', color: '#9a9490', bg: 'rgba(90,86,82,0.15)', confirm: 'Return this Final Account to draft?' }] : []),
      ];

    case 'under_review':
      return [
        {
          label: 'Agree Final Account', action: 'agree', color: '#60a5fa', bg: 'rgba(59,130,246,0.15)',
          confirm: 'This will lock all financial values into an agreed snapshot. This cannot be undone. Proceed?',
        },
        ...(canReturnToDraft ? [{ label: 'Return to Draft', action: 'revise', color: '#9a9490', bg: 'rgba(90,86,82,0.15)', confirm: 'Return this Final Account to draft?' }] : []),
      ];

    case 'agreed':
      return [{ label: 'Sign', action: 'sign', color: '#a78bfa', bg: 'rgba(167,139,250,0.15)' }];

    case 'signed':
      return [{
        label: 'Issue Final Certificate', action: 'issue-certificate', color: '#4ade80', bg: 'rgba(34,197,94,0.15)',
        confirm: 'Issue the Final Certificate? This sets the dispute window to 28 days from today and unlocks Half 2 retention.',
      }];

    case 'final_certificate_issued':
      return [{
        label: 'Commercially Close', action: 'close', color: '#4ade80', bg: 'rgba(34,197,94,0.2)',
        confirm: 'Mark this Final Account as Commercially Closed? This is the final step.',
      }];

    case 'commercially_closed':
      return [];
  }
}

// ─── Utility helpers (local, not exported) ────────────────────────────────────

function fmt(v: number | string | null | undefined): number {
  return typeof v === 'string' ? parseFloat(v) || 0 : Number(v) || 0;
}

function getErrMsg(error: unknown, fallback: string): string {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const resp = (error as Record<string, unknown>).response as Record<string, unknown>;
    if (resp && 'data' in resp) {
      const d = resp.data as Record<string, unknown>;
      if (d && 'message' in d && typeof d.message === 'string') return d.message;
    }
  }
  return fallback;
}

// Extract snapshot values from FA record into FATotals shape
function snapshotToTotals(fa: FinalAccountRecord): FATotals {
  return {
    original_contract_sum:      fmt(fa.original_contract_sum),
    approved_variations_total:  fmt(fa.approved_variations_total),
    loss_and_expense_total:     fmt(fa.loss_and_expense_total),
    dayworks_total:             fmt(fa.dayworks_total),
    provisional_sum_adjustment: fmt(fa.provisional_sum_adjustment),
    prime_cost_sum_adjustment:  fmt(fa.prime_cost_sum_adjustment),
    contra_charges_total:       fmt(fa.contra_charges_total),
    other_adjustments_total:    fmt(fa.other_adjustments_total),
    adjusted_contract_sum:      fmt(fa.adjusted_contract_sum),
    certified_to_date:          fmt(fa.certified_to_date),
    paid_to_date:               fmt(fa.paid_to_date),
    retention_held:             fmt(fa.retention_held),
    retention_released:         fmt(fa.retention_released),
    retention_outstanding:      fmt(fa.retention_outstanding),
    final_balance_due:          fmt(fa.final_balance_due),
  };
}

// ─── Shared UI ────────────────────────────────────────────────────────────────

function FAStatusBadge({ status }: { status: FinalAccountStatus }) {
  const s = FA_STATUS[status] ?? FA_STATUS.draft;
  return (
    <span className="px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: s.bg, color: s.text }}>
      {s.label}
    </span>
  );
}

function FinRow({ label, value, highlight, negative, indent }: {
  label: string; value: string; highlight?: boolean; negative?: boolean; indent?: boolean;
}) {
  return (
    <div className="flex justify-between items-center py-2" style={{ borderBottom: '1px solid var(--border)', paddingLeft: indent ? '12px' : undefined }}>
      <span className="text-xs" style={{ color: highlight ? 'var(--text-primary)' : 'var(--text-muted)' }}>{label}</span>
      <span className="text-sm font-semibold" style={{ color: negative ? '#f87171' : highlight ? 'var(--gold)' : 'var(--text-secondary)' }}>
        {negative ? `(${value})` : value}
      </span>
    </div>
  );
}

// ─── Lifecycle progress stepper ───────────────────────────────────────────────

function FALifecycleProgress({ status }: { status: FinalAccountStatus }) {
  const currentIdx = FA_STEPS.findIndex(s => s.status === status);

  return (
    <div className="flex items-start">
      {FA_STEPS.map((step, i) => {
        const isDone    = i < currentIdx;
        const isCurrent = i === currentIdx;
        return (
          <div key={step.status} className="flex items-start flex-1">
            <div className="flex flex-col items-center w-full">
              <div className="flex items-center w-full">
                <div className="w-2.5 h-2.5 rounded-full shrink-0" style={{
                  backgroundColor: isCurrent ? '#facc15' : isDone ? '#4ade80' : 'var(--bg-elevated)',
                  border: `1.5px solid ${isCurrent ? '#facc15' : isDone ? '#4ade80' : 'var(--border)'}`,
                }} />
                {i < FA_STEPS.length - 1 && (
                  <div className="flex-1 h-px" style={{ backgroundColor: isDone ? '#4ade80' : 'var(--border)' }} />
                )}
              </div>
              <span className="text-center mt-1 px-0.5" style={{
                fontSize: '9px',
                color: isCurrent ? 'var(--text-primary)' : isDone ? '#4ade80' : 'var(--text-muted)',
                whiteSpace: 'nowrap',
              }}>
                {step.label}
              </span>
            </div>
          </div>
        );
      })}
    </div>
  );
}

// ─── Commercial summary ───────────────────────────────────────────────────────

function FACommercialSummary({ totals, isSnapshotted }: { totals: FATotals; isSnapshotted: boolean }) {
  const formatCurrency = useCurrencyFormatter();
  const fc = (v: number) => formatCurrency(v);

  return (
    <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
      <div className="flex items-center justify-between mb-3">
        <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
          Commercial Summary
        </p>
        <span className="text-xs px-2 py-0.5 rounded" style={{
          backgroundColor: isSnapshotted ? 'rgba(59,130,246,0.12)' : 'rgba(251,146,60,0.1)',
          color:           isSnapshotted ? '#60a5fa'               : '#fb923c',
        }}>
          {isSnapshotted ? 'Agreed Snapshot' : 'Live Values'}
        </span>
      </div>

      <FinRow label="Original Contract Sum"   value={fc(totals.original_contract_sum)} highlight />
      <FinRow label="Approved Variations"     value={fc(totals.approved_variations_total)} indent />
      <FinRow label="Loss & Expense"          value={fc(totals.loss_and_expense_total)} indent />
      <FinRow label="Dayworks"               value={fc(totals.dayworks_total)} indent />
      <FinRow label="Provisional Sums"       value={fc(totals.provisional_sum_adjustment)} indent />
      <FinRow label="Prime Cost Sums"        value={fc(totals.prime_cost_sum_adjustment)} indent />
      <FinRow label="Contra Charges"         value={fc(totals.contra_charges_total)} negative={totals.contra_charges_total > 0} indent />
      <FinRow label="Other Adjustments"      value={fc(totals.other_adjustments_total)} indent />
      <FinRow label="Adjusted Contract Sum"  value={fc(totals.adjusted_contract_sum)} highlight />

      <div className="my-3" />

      <FinRow label="Certified To Date"      value={fc(totals.certified_to_date)} />
      <FinRow label="Paid To Date"           value={fc(totals.paid_to_date)} />
      <FinRow label="Retention Held"         value={fc(totals.retention_held)} />
      <FinRow label="Retention Released"     value={fc(totals.retention_released)} />
      <FinRow label="Retention Outstanding"  value={fc(totals.retention_outstanding)} />
      <FinRow label="Final Balance Due"      value={fc(totals.final_balance_due)} highlight />
    </div>
  );
}

// ─── Line items section ───────────────────────────────────────────────────────

function FAItemsSection({ items, isLocked, onAdd, onEdit, onDelete }: {
  items: FAItem[];
  isLocked: boolean;
  onAdd: () => void;
  onEdit: (item: FAItem) => void;
  onDelete: (item: FAItem) => void;
}) {
  const formatCurrency = useCurrencyFormatter();
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

  const grouped: Record<string, FAItem[]> = {};
  for (const cat of FA_CATEGORY_ORDER) {
    const catItems = items.filter(i => i.category === cat);
    if (catItems.length > 0) grouped[cat] = catItems;
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between mb-1">
        <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
          Line Items
        </p>
        {!isLocked && (
          <button onClick={onAdd}
            className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium"
            style={{ backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15' }}>
            <Plus size={11} /> Add Item
          </button>
        )}
      </div>

      {Object.keys(grouped).length === 0 ? (
        <p className="text-xs py-3 text-center" style={{ color: 'var(--text-muted)' }}>No line items yet.</p>
      ) : (
        Object.entries(grouped).map(([cat, catItems]) => {
          const label    = FA_CATEGORY_LABELS[cat] ?? cat;
          const total    = catItems.reduce((s, i) => s + fmt(i.amount), 0);
          const isNeg    = FA_NEGATIVE_CATEGORIES.has(cat);
          const isOpen   = collapsed[cat] !== false; // default open

          return (
            <div key={cat} className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              <button
                className="flex items-center justify-between w-full px-4 py-2.5 text-left"
                style={{ backgroundColor: 'var(--bg-elevated)' }}
                onClick={() => setCollapsed(c => ({ ...c, [cat]: !isOpen }))}
              >
                <span className="text-xs font-semibold" style={{ color: 'var(--text-primary)' }}>{label}</span>
                <div className="flex items-center gap-3">
                  <span className="text-xs font-semibold" style={{ color: isNeg ? '#f87171' : 'var(--gold)' }}>
                    {isNeg ? `(${formatCurrency(total)})` : formatCurrency(total)}
                  </span>
                  <ChevronDown size={13} style={{
                    color: 'var(--text-muted)',
                    transform: isOpen ? 'rotate(180deg)' : 'none',
                    transition: 'transform 0.15s',
                  }} />
                </div>
              </button>

              {isOpen && (
                <div style={{ backgroundColor: 'var(--bg-surface)' }}>
                  {catItems.map(item => (
                    <div key={item.id} className="flex items-start gap-3 px-4 py-2.5" style={{ borderTop: '1px solid var(--border)' }}>
                      <div className="flex-1 min-w-0">
                        <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>{item.description}</p>
                        {item.notes && (
                          <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{item.notes}</p>
                        )}
                        {item.source_type && (
                          <p className="mt-0.5 font-mono" style={{ color: 'var(--text-muted)', fontSize: '10px' }}>
                            {item.source_type} #{item.source_id}
                          </p>
                        )}
                      </div>
                      <div className="flex items-center gap-2 shrink-0">
                        {item.is_auto_seeded && (
                          <span className="px-1.5 py-0.5 rounded" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', fontSize: '10px' }}>
                            Auto
                          </span>
                        )}
                        <span className="text-sm font-semibold" style={{ color: isNeg ? '#f87171' : 'var(--text-secondary)' }}>
                          {isNeg ? `(${formatCurrency(fmt(item.amount))})` : formatCurrency(fmt(item.amount))}
                        </span>
                        {!isLocked && item.category !== 'contract_sum' && (
                          <div className="flex gap-1">
                            <button onClick={() => onEdit(item)} className="p-1 rounded hover:opacity-70" style={{ color: 'var(--text-muted)' }} title="Edit">
                              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                              </svg>
                            </button>
                            <button onClick={() => onDelete(item)} className="p-1 rounded hover:opacity-70" style={{ color: '#f87171' }} title="Delete">
                              <Trash2 size={12} />
                            </button>
                          </div>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </div>
          );
        })
      )}
    </div>
  );
}

// ─── Add / Edit item modal ────────────────────────────────────────────────────

function FAItemModal({ faId, item, onClose, onSaved }: {
  faId: number;
  item: FAItem | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [form, setForm] = useState({
    category:    item?.category ?? 'loss_and_expense',
    description: item?.description ?? '',
    amount:      item ? String(fmt(item.amount)) : '',
    notes:       item?.notes ?? '',
  });
  const [saving, setSaving] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      if (item) {
        await api.put(`/final-accounts/${faId}/items/${item.id}`, form);
        toast.success('Item updated');
      } else {
        await api.post(`/final-accounts/${faId}/items`, form);
        toast.success('Item added');
      }
      onSaved();
      onClose();
    } catch (err) {
      toast.error(getErrMsg(err, item ? 'Failed to update item' : 'Failed to add item'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-md rounded-2xl shadow-xl max-h-[92vh] overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>
            {item ? 'Edit Item' : 'Add Line Item'}
          </h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <form onSubmit={handleSubmit} className="p-5 space-y-4">
          {/* Category — only shown on add; edit shows read-only */}
          {!item ? (
            <div>
              <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Category *</label>
              <select
                value={form.category}
                onChange={e => setForm(f => ({ ...f, category: e.target.value }))}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
              >
                {FA_USER_CATEGORIES.map(c => (
                  <option key={c.key} value={c.key}>{c.label}</option>
                ))}
              </select>
            </div>
          ) : (
            <div>
              <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Category</label>
              <input
                value={FA_CATEGORY_LABELS[item.category] ?? item.category}
                readOnly
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'default' }}
              />
            </div>
          )}

          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Description *</label>
            <input
              required
              value={form.description}
              onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Amount *</label>
            <input
              required
              type="number"
              step="0.01"
              value={form.amount}
              onChange={e => setForm(f => ({ ...f, amount: e.target.value }))}
              placeholder="0.00"
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea
              value={form.notes}
              onChange={e => setForm(f => ({ ...f, notes: e.target.value }))}
              rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={onClose}
              className="px-4 py-2 rounded-lg text-sm"
              style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              Cancel
            </button>
            <button type="submit" disabled={saving}
              className="px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: saving ? 0.6 : 1 }}>
              {saving ? 'Saving...' : item ? 'Save Changes' : 'Add Item'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

// ─── Final Account Card ───────────────────────────────────────────────────────

function FinalAccountCard({ name, subtitle, contractId, tradePackageId, fa, projectId, canWrite, autoExpandId }: {
  name: string;
  subtitle?: string;
  contractId?: number;
  tradePackageId?: number;
  fa: FinalAccountRecord | null;
  projectId: string;
  canWrite: boolean;
  autoExpandId?: number | null;
}) {
  const formatCurrency = useCurrencyFormatter();
  const queryClient    = useQueryClient();
  // Deep-link support: ?tab=final-account&fa={id} auto-expands the matching card on load.
  const [expanded, setExpanded]     = useState(() => !!fa && fa.id === autoExpandId);
  const [itemModal, setItemModal]   = useState<{ open: boolean; item: FAItem | null }>({ open: false, item: null });
  // Inline confirm: stores the action key currently awaiting confirmation
  const [confirmPending, setConfirmPending] = useState<string | null>(null);

  // ── Create FA ──────────────────────────────────────────────────────────────
  const createMutation = useMutation({
    mutationFn: () => {
      if (contractId) {
        return api.post(`/contracts/${contractId}/final-account`).then(r => r.data);
      }
      return api.post(`/projects/${projectId}/trade-packages/${tradePackageId}/final-account`).then(r => r.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-final-accounts', projectId] });
      toast.success('Final Account created');
    },
    onError: (e: unknown) => toast.error(getErrMsg(e, 'Failed to create Final Account')),
  });

  // ── Full FA detail (with items) — only when expanded ───────────────────────
  const { data: faDetail } = useQuery<FinalAccountRecord>({
    queryKey: ['final-account-detail', fa?.id],
    queryFn:  () => api.get(`/final-accounts/${fa!.id}`).then(r => r.data),
    enabled:  !!fa && expanded,
  });

  // The most up-to-date FA record: faDetail has items and is refreshed on every action;
  // fall back to fa from the list when detail hasn't loaded yet.
  const activeFa = faDetail ?? fa;

  // ── Live totals ────────────────────────────────────────────────────────────
  //
  // DATA FLOW RULE (explicit):
  //   is_snapshotted === false  →  fetch GET /final-accounts/{id}/totals (live values)
  //   is_snapshotted === true   →  read snapshot columns from FA record (contractual)
  //
  // We use `activeFa.is_snapshotted` (not `fa.is_snapshotted`) so that after the
  // 'agree' action refreshes faDetail, the switch happens immediately without waiting
  // for the list query to refetch.
  const { data: liveTotals } = useQuery<FATotals>({
    queryKey: ['final-account-totals', fa?.id],
    queryFn:  () => api.get(`/final-accounts/${fa!.id}/totals`).then(r => r.data),
    enabled:  !!fa && expanded && !(activeFa?.is_snapshotted ?? false),
  });

  const displayTotals: FATotals | null = (() => {
    if (!activeFa) return null;
    if (activeFa.is_snapshotted) return snapshotToTotals(activeFa);
    return liveTotals ?? null;
  })();

  // ── Lifecycle mutation ─────────────────────────────────────────────────────
  const lifecycleMutation = useMutation({
    mutationFn: (action: string) => api.post(`/final-accounts/${fa!.id}/${action}`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-final-accounts', projectId] });
      queryClient.invalidateQueries({ queryKey: ['final-account-detail', fa?.id] });
      queryClient.invalidateQueries({ queryKey: ['final-account-totals', fa?.id] });
      toast.success('Final Account updated');
    },
    onError: (e: unknown) => toast.error(getErrMsg(e, 'Action failed')),
  });

  const handleLifecycleAction = (action: LifecycleAction) => {
    if (action.confirm) {
      setConfirmPending(action.action);
      return;
    }
    lifecycleMutation.mutate(action.action);
  };

  const handleConfirm = () => {
    if (!confirmPending) return;
    lifecycleMutation.mutate(confirmPending);
    setConfirmPending(null);
  };

  // ── Document generation ────────────────────────────────────────────────────
  const generateStatementMutation = useMutation({
    mutationFn: () => api.post(`/final-accounts/${fa!.id}/generate-statement`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['final-account-detail', fa?.id] });
      toast.success('Final Account Statement generated');
    },
    onError: (e: unknown) => toast.error(getErrMsg(e, 'Failed to generate statement')),
  });

  const generateCertificateMutation = useMutation({
    mutationFn: () => api.post(`/final-accounts/${fa!.id}/generate-certificate`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['final-account-detail', fa?.id] });
      toast.success('Final Certificate generated');
    },
    onError: (e: unknown) => toast.error(getErrMsg(e, 'Failed to generate certificate')),
  });

  const downloadDocument = (doc: FADocument) => {
    api.get(`/documents/${doc.id}/download`, { responseType: 'blob' }).then(res => {
      const url = URL.createObjectURL(res.data);
      const a = window.document.createElement('a');
      a.href = url;
      a.download = doc.file_name ?? `${doc.title}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
    }).catch(() => toast.error('Failed to download document'));
  };

  // ── Item delete ────────────────────────────────────────────────────────────
  const deleteItemMutation = useMutation({
    mutationFn: (itemId: number) => api.delete(`/final-accounts/${fa!.id}/items/${itemId}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['final-account-detail', fa?.id] });
      queryClient.invalidateQueries({ queryKey: ['final-account-totals', fa?.id] });
      toast.success('Item removed');
    },
    onError: (e: unknown) => toast.error(getErrMsg(e, 'Failed to remove item')),
  });

  const handleDeleteItem = (item: FAItem) => {
    setConfirmPending(`delete-item-${item.id}`);
  };

  const items           = faDetail?.items ?? [];
  const lifecycleActions = fa ? getLifecycleActions(fa.status, fa.can_return_to_draft) : [];

  // ── No FA yet ──────────────────────────────────────────────────────────────
  if (!fa) {
    return (
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-start justify-between">
          <div>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{name}</p>
            {subtitle && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
          </div>
          <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
            Not created
          </span>
        </div>
        <div className="mt-4 rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px dashed var(--border)' }}>
          <FileCheck size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>No Final Account has been created for this {contractId ? 'contract' : 'trade package'}.</p>
          {canWrite && (
            <button
              onClick={() => createMutation.mutate()}
              disabled={createMutation.isPending}
              className="px-4 py-2 rounded-lg text-xs font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: createMutation.isPending ? 0.6 : 1 }}
            >
              {createMutation.isPending ? 'Creating...' : 'Create Final Account'}
            </button>
          )}
        </div>
      </div>
    );
  }

  // ── FA exists ──────────────────────────────────────────────────────────────
  return (
    <div className="rounded-2xl overflow-hidden" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>

      {/* Clickable header — expands/collapses the card */}
      <button
        className="flex items-start justify-between w-full p-5 text-left hover:bg-[var(--bg-hover)] transition-colors"
        onClick={() => setExpanded(v => !v)}
      >
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{name}</p>
            {fa.reference && (
              <span className="font-mono text-xs" style={{ color: 'var(--text-muted)' }}>{fa.reference}</span>
            )}
            <FAStatusBadge status={fa.status} />
            {fa.is_locked && (
              <span className="flex items-center gap-1 text-xs px-1.5 py-0.5 rounded" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>
                <Lock size={9} /> Locked
              </span>
            )}
          </div>
          {subtitle && (
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>
          )}
          {fa.agreed_at && (
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
              Agreed {formatDate(fa.agreed_at)}
              {fa.final_certificate_issued_at && (
                <> · Certificate issued {formatDate(fa.final_certificate_issued_at)}</>
              )}
            </p>
          )}
        </div>

        <div className="flex items-center gap-4 ml-4 shrink-0">
          {/* Key figures in collapsed header */}
          {displayTotals && (
            <div className="hidden sm:flex gap-4 text-right">
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Adj. Contract Sum</p>
                <p className="text-sm font-bold" style={{ color: 'var(--gold)' }}>
                  {formatCurrency(displayTotals.adjusted_contract_sum)}
                </p>
              </div>
              <div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Final Balance Due</p>
                <p className="text-sm font-bold" style={{ color: displayTotals.final_balance_due >= 0 ? '#4ade80' : '#f87171' }}>
                  {formatCurrency(displayTotals.final_balance_due)}
                </p>
              </div>
            </div>
          )}
          <ChevronDown size={16} style={{
            color: 'var(--text-muted)',
            transform: expanded ? 'rotate(180deg)' : 'none',
            transition: 'transform 0.2s',
          }} />
        </div>
      </button>

      {/* Expanded body */}
      {expanded && (
        <div className="px-5 pb-5 space-y-5" style={{ borderTop: '1px solid var(--border)' }}>

          {/* Lifecycle progress stepper */}
          <div className="pt-4">
            <FALifecycleProgress status={fa.status} />
          </div>

          {/* Snapshot information panel */}
          {fa.is_snapshotted && (
            <div className="rounded-xl p-3 flex items-start gap-2.5" style={{ backgroundColor: 'rgba(59,130,246,0.07)', border: '1px solid rgba(59,130,246,0.18)' }}>
              <AlertTriangle size={13} style={{ color: '#60a5fa', marginTop: '1px', flexShrink: 0 }} />
              <p className="text-xs leading-relaxed" style={{ color: '#93c5fd' }}>
                Financial values were snapshotted when this Final Account was agreed
                {fa.agreed_at ? ` on ${formatDate(fa.agreed_at)}` : ''}.
                Subsequent Payment Applications, Variations, or Retention changes will not alter the agreed figures.
              </p>
            </div>
          )}

          {/* Commercial summary */}
          {displayTotals ? (
            <FACommercialSummary totals={displayTotals} isSnapshotted={activeFa?.is_snapshotted ?? false} />
          ) : (
            <div className="rounded-xl p-4 text-center" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading commercial totals...</p>
            </div>
          )}

          {/* Line items */}
          <FAItemsSection
            items={items}
            isLocked={activeFa?.is_locked ?? false}
            onAdd={() => setItemModal({ open: true, item: null })}
            onEdit={item => setItemModal({ open: true, item })}
            onDelete={handleDeleteItem}
          />

          {/* Documents */}
          <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <div className="flex items-center justify-between mb-3">
              <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
                Documents
              </p>
              {canWrite && (
                <div className="flex gap-2">
                  <button
                    onClick={() => generateStatementMutation.mutate()}
                    disabled={generateStatementMutation.isPending}
                    className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium"
                    style={{ backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15', opacity: generateStatementMutation.isPending ? 0.6 : 1 }}
                  >
                    <FileOutput size={11} />
                    {generateStatementMutation.isPending ? 'Generating...' : (fa.is_snapshotted ? 'Generate Statement' : 'Generate Draft Statement')}
                  </button>
                  {(fa.status === 'final_certificate_issued' || fa.status === 'commercially_closed') && (
                    <button
                      onClick={() => generateCertificateMutation.mutate()}
                      disabled={generateCertificateMutation.isPending}
                      className="flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-medium"
                      style={{ backgroundColor: 'rgba(74,222,128,0.12)', color: '#4ade80', opacity: generateCertificateMutation.isPending ? 0.6 : 1 }}
                    >
                      <FileOutput size={11} />
                      {generateCertificateMutation.isPending ? 'Generating...' : 'Generate Final Certificate'}
                    </button>
                  )}
                </div>
              )}
            </div>

            {(!activeFa?.documents || activeFa.documents.length === 0) ? (
              <p className="text-xs py-2 text-center" style={{ color: 'var(--text-muted)' }}>
                No documents generated yet.
              </p>
            ) : (
              <div className="space-y-1.5">
                {activeFa.documents.map(doc => (
                  <div key={doc.id} className="flex items-center justify-between gap-3 px-3 py-2 rounded-lg" style={{ backgroundColor: 'var(--bg-surface)' }}>
                    <div className="min-w-0 flex-1">
                      <p className="text-xs font-medium truncate" style={{ color: 'var(--text-secondary)' }}>{doc.title}</p>
                      <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                        {doc.reference_number ? `${doc.reference_number} · ` : ''}
                        {doc.created_at ? formatDate(doc.created_at) : ''}
                      </p>
                    </div>
                    <button
                      onClick={() => downloadDocument(doc)}
                      className="flex items-center gap-1 px-2 py-1 rounded text-xs font-medium shrink-0"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}
                    >
                      <Download size={11} /> Download
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Lifecycle actions */}
          {canWrite && lifecycleActions.length > 0 && (
            <div className="flex flex-wrap gap-2 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
              {lifecycleActions.map(action => {
                const isAwaiting = confirmPending === action.action;
                return isAwaiting ? (
                  <div key={action.action} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg" style={{ backgroundColor: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)' }}>
                    <span className="text-xs" style={{ color: '#fca5a5' }}>Confirm?</span>
                    <button
                      onClick={handleConfirm}
                      disabled={lifecycleMutation.isPending}
                      className="px-2 py-0.5 rounded text-xs font-semibold"
                      style={{ backgroundColor: '#ef4444', color: '#fff' }}
                    >
                      Yes
                    </button>
                    <button
                      onClick={() => setConfirmPending(null)}
                      className="px-2 py-0.5 rounded text-xs"
                      style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}
                    >
                      Cancel
                    </button>
                  </div>
                ) : (
                  <button
                    key={action.action}
                    onClick={() => handleLifecycleAction(action)}
                    disabled={lifecycleMutation.isPending}
                    className="px-3 py-1.5 rounded-lg text-xs font-medium transition-opacity"
                    style={{ backgroundColor: action.bg, color: action.color, opacity: lifecycleMutation.isPending ? 0.6 : 1 }}
                  >
                    {action.label}
                  </button>
                );
              })}
            </div>
          )}

          {/* Inline delete-item confirm banner */}
          {confirmPending?.startsWith('delete-item-') && (() => {
            const itemId = parseInt(confirmPending.replace('delete-item-', ''));
            const target = items.find(i => i.id === itemId);
            return (
              <div className="flex items-center gap-3 px-4 py-2.5 rounded-xl" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.25)' }}>
                <span className="text-xs flex-1 truncate" style={{ color: '#fca5a5' }}>
                  Remove &ldquo;{target?.description ?? 'this item'}&rdquo;?
                </span>
                <button
                  onClick={() => { deleteItemMutation.mutate(itemId); setConfirmPending(null); }}
                  className="px-2.5 py-1 rounded text-xs font-semibold shrink-0"
                  style={{ backgroundColor: '#ef4444', color: '#fff' }}
                >
                  Remove
                </button>
                <button
                  onClick={() => setConfirmPending(null)}
                  className="px-2.5 py-1 rounded text-xs shrink-0"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}
                >
                  Cancel
                </button>
              </div>
            );
          })()}

          {/* Commercially closed state */}
          {fa.status === 'commercially_closed' && (
            <div className="flex items-center gap-2 pt-1">
              <div className="w-2 h-2 rounded-full" style={{ backgroundColor: '#4ade80' }} />
              <p className="text-xs" style={{ color: '#4ade80' }}>
                This Final Account is commercially closed.
                {fa.closed_at ? ` Closed on ${formatDate(fa.closed_at)}.` : ''}
              </p>
            </div>
          )}

          {/* Dispute window */}
          {fa.dispute_window_expires_at && fa.status !== 'commercially_closed' && (
            <div className="rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                JCT dispute window expires:{' '}
                <span style={{ color: 'var(--text-secondary)' }}>{formatDate(fa.dispute_window_expires_at)}</span>
              </p>
            </div>
          )}
        </div>
      )}

      {/* Item add/edit modal */}
      {itemModal.open && (
        <FAItemModal
          faId={fa.id}
          item={itemModal.item}
          onClose={() => setItemModal({ open: false, item: null })}
          onSaved={() => {
            queryClient.invalidateQueries({ queryKey: ['final-account-detail', fa.id] });
            queryClient.invalidateQueries({ queryKey: ['final-account-totals', fa.id] });
          }}
        />
      )}
    </div>
  );
}

// ─── Final Account Tab ────────────────────────────────────────────────────────

export function FinalAccountTab({ contracts, tradePackages, projectId }: {
  contracts: ContractOption[];
  tradePackages: TradePackageOption[];
  projectId: string;
}) {
  const { canWrite } = useProjectPermissions();
  const searchParams = useSearchParams();
  const faParam = searchParams.get('fa');
  const autoExpandId = faParam ? parseInt(faParam, 10) : null;

  const { data: faList = [] } = useQuery<FinalAccountRecord[]>({
    queryKey: ['project-final-accounts', projectId],
    queryFn:  () => api.get(`/projects/${projectId}/final-accounts`).then(r => {
      const d = r.data;
      return Array.isArray(d) ? d : (d.data ?? []);
    }),
  });

  const getFaForContract     = (id: number) => faList.find(fa => fa.contract_id === id) ?? null;
  const getFaForTradePackage = (id: number) => faList.find(fa => fa.trade_package_id === id) ?? null;

  const mainContracts = contracts.filter(c => c.status !== 'terminated');

  if (mainContracts.length === 0 && tradePackages.length === 0) {
    return (
      <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <FileCheck size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>No contracts or trade packages</p>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Create a contract or trade package to start a Final Account.</p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {mainContracts.length > 0 && (
        <div className="space-y-3">
          {(mainContracts.length > 1 || tradePackages.length > 0) && (
            <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
              Main Contracts
            </p>
          )}
          {mainContracts.map(contract => (
            <FinalAccountCard
              key={`contract-${contract.id}`}
              name={contract.title}
              subtitle={contract.party_name ?? contract.reference_number ?? undefined}
              contractId={contract.id}
              fa={getFaForContract(contract.id)}
              projectId={projectId}
              canWrite={canWrite}
              autoExpandId={autoExpandId}
            />
          ))}
        </div>
      )}

      {tradePackages.length > 0 && (
        <div className="space-y-3">
          {tradePackages.length > 0 && (
            <p className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
              Trade Packages
            </p>
          )}
          {tradePackages.map(tp => (
            <FinalAccountCard
              key={`tp-${tp.id}`}
              name={tp.name}
              subtitle={tp.contractor_name ?? tp.package_reference ?? undefined}
              tradePackageId={tp.id}
              fa={getFaForTradePackage(tp.id)}
              projectId={projectId}
              canWrite={canWrite}
              autoExpandId={autoExpandId}
            />
          ))}
        </div>
      )}
    </div>
  );
}
