'use client';

import { useState, useMemo } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import {
  ArrowLeft, Save, Send, Download, FileSpreadsheet, FileText,
  Plus, Trash2, ToggleLeft, ToggleRight,
  AlertCircle, AlertTriangle, Loader2, Link2, Link2Off, RotateCcw,
} from 'lucide-react';
import toast from 'react-hot-toast';
import Link from 'next/link';

// ─── Types ────────────────────────────────────────────────────────────────────

type PA = {
  id: number;
  application_number: number;
  reference?: string | null;
  application_date?: string | null;
  valuation_period_start?: string | null;
  valuation_period_end?: string | null;
  due_date?: string | null;
  final_date_for_payment?: string | null;
  payment_notice_deadline?: string | null;
  pay_less_notice_deadline?: string | null;
  gross_valuation?: number | string | null;
  less_retention?: number | string | null;
  less_previous_payments?: number | string | null;
  amount_due?: number | string | null;
  previous_certified_value?: number | string | null;
  previous_paid_value?: number | string | null;
  previous_retention_held?: number | string | null;
  certified_amount?: number | string | null;
  paid_amount?: number | string | null;
  payment_date?: string | null;
  status?: string | null;
  withdrawal_count?: number | null;
  notes?: string | null;
  use_breakdown?: boolean;
  vat_rate?: number | string | null;
  vat_amount?: number | string | null;
  total_due_including_vat?: number | string | null;
  measured_works_total?: number | string | null;
  variations_total?: number | string | null;
  materials_on_site_total?: number | string | null;
  linked_variations_total?: number | string | null;
  linked_variations?: LinkedVariation[];
  breakdown?: {
    measured_works?: MeasuredWorkRow[];
    variations?: VariationRow[];
    materials_on_site?: MaterialRow[];
  } | null;
  contract?: {
    id: number;
    title: string;
    reference_number?: string | null;
    contract_sum?: number | string | null;
    retention_percentage?: number | string | null;
    payment_terms_days?: number | null;
    party_name?: string | null;
  } | null;
  trade_package?: { id: number; name: string; package_reference?: string | null; contractor_name?: string | null } | null;
  project?: { id: number; name: string; address?: string | null; code?: string | null } | null;
  creator?: { id: number; name: string } | null;
  payment_notices?: {
    id: number;
    reference?: string | null;
    notice_date?: string | null;
    notified_sum?: number | string | null;
    basis_of_assessment?: string | null;
    issued_by?: string | null;
    status?: string | null;
    documents?: { id: number; file_name?: string; file_size?: number; created_at?: string }[];
  }[];
  pay_less_notices?: {
    id: number;
    reference?: string | null;
    notice_date?: string | null;
    notified_sum?: number | string | null;
    original_amount_due?: number | string | null;
    total_deductions?: number | string | null;
    revised_amount_payable?: number | string | null;
    deduction_reason?: string | null;
    issued_by?: string | null;
    status?: string | null;
    documents?: { id: number; file_name?: string; file_size?: number; created_at?: string }[];
  }[];
};

type MeasuredWorkRow = {
  _id?: string;
  item_number?: number | string;
  description?: string;
  qty?: number | string;
  unit?: string;
  rate?: number | string;
  contract_value?: number | string;
  pct_complete?: number | string;
  valuation?: number | string;
  notes?: string;
};

type VariationRow = {
  _id?: string;
  ref?: string | number;
  instruction_ref?: string;
  description?: string;
  date_issued?: string;
  variation_value?: number | string;
  pct_complete?: number | string;
  valuation?: number | string;
  status?: string;
  notes?: string;
};

type MaterialRow = {
  _id?: string;
  item_number?: number | string;
  description?: string;
  supplier?: string;
  delivery_ref?: string;
  storage_location?: string;
  qty?: number | string;
  unit?: string;
  rate?: number | string;
  material_value?: number | string;
  claim_pct?: number | string;
  valuation?: number | string;
  notes?: string;
};

type LinkedVariation = {
  id: number;
  payment_application_id: number;
  variation_id: number;
  variation_number_at_inclusion: string;
  title_at_inclusion: string;
  description_at_inclusion?: string | null;
  amount_at_inclusion: number | string;
  status_at_inclusion: string;
};

type EligibleVariation = {
  id: number;
  variation_number: number | string;
  title: string;
  description?: string | null;
  agreed_amount?: number | string | null;
  status: string;
  is_linked: boolean;
};

type DocRecord = {
  id: number;
  title?: string;
  file_name?: string;
  mime_type?: string;
  created_at?: string;
  file_size?: number;
};

// ─── Helpers ──────────────────────────────────────────────────────────────────

function fmt(v: number | string | null | undefined): number {
  return typeof v === 'string' ? parseFloat(v) || 0 : Number(v) || 0;
}

function newId(): string {
  return Math.random().toString(36).slice(2);
}

function getError(e: unknown, fallback: string): string {
  if (typeof e === 'object' && e !== null && 'response' in e) {
    const r = (e as Record<string, unknown>).response as Record<string, unknown>;
    if (r?.data && typeof (r.data as Record<string, unknown>).message === 'string')
      return (r.data as Record<string, unknown>).message as string;
  }
  return fallback;
}

const VARIATION_STATUSES = ['Draft', 'Submitted', 'Agreed', 'Approved', 'Rejected', 'Included'];

const PA_STATUS: Record<string, { bg: string; text: string; label: string }> = {
  draft:     { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490', label: 'Draft' },
  submitted: { bg: 'rgba(234,179,8,0.12)',  text: '#facc15', label: 'Submitted' },
  certified: { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80', label: 'Certified' },
  paid:      { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa', label: 'Paid' },
  cancelled: { bg: 'rgba(239,68,68,0.08)',  text: '#f87171', label: 'Cancelled' },
};

// ─── Shared cell component ────────────────────────────────────────────────────

function Cell({ value, onChange, type = 'text', readOnly = false, placeholder = '', className = '' }: {
  value: string; onChange?: (v: string) => void; type?: string;
  readOnly?: boolean; placeholder?: string; className?: string;
}) {
  return (
    <input
      type={type}
      value={value}
      onChange={e => onChange?.(e.target.value)}
      placeholder={placeholder}
      readOnly={readOnly}
      step={type === 'number' ? '0.01' : undefined}
      className={`w-full px-2 py-1 text-xs rounded outline-none ${className}`}
      style={{
        backgroundColor: readOnly ? 'transparent' : 'var(--bg-base)',
        border: readOnly ? 'none' : '1px solid var(--border)',
        color: readOnly ? 'var(--gold)' : 'var(--text-primary)',
        fontWeight: readOnly ? 600 : undefined,
        minWidth: 0,
      }}
    />
  );
}

function SelectCell({ value, onChange, options }: { value: string; onChange: (v: string) => void; options: string[] }) {
  return (
    <select value={value} onChange={e => onChange(e.target.value)}
      className="w-full px-2 py-1 text-xs rounded outline-none"
      style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
      <option value="">—</option>
      {options.map(o => <option key={o} value={o}>{o}</option>)}
    </select>
  );
}

// ─── Measured Works table ─────────────────────────────────────────────────────

function MeasuredWorksTable({ rows, onChange, readOnly }: {
  rows: MeasuredWorkRow[];
  onChange: (rows: MeasuredWorkRow[]) => void;
  readOnly: boolean;
}) {
  const formatCurrency = useCurrencyFormatter();

  const update = (idx: number, field: keyof MeasuredWorkRow, val: string) => {
    const next = rows.map((r, i) => {
      if (i !== idx) return r;
      const updated = { ...r, [field]: val };
      // Auto-calc contract_value and valuation
      const cv  = field === 'contract_value' ? parseFloat(val) || 0
        : field === 'qty' || field === 'rate'
          ? (parseFloat(field === 'qty' ? val : String(r.qty ?? 1)) || 1) * (parseFloat(field === 'rate' ? val : String(r.rate ?? 0)) || 0)
          : fmt(r.contract_value);
      if (field === 'qty' || field === 'rate') updated.contract_value = String(cv);
      const pct = field === 'pct_complete' ? parseFloat(val) || 0 : fmt(r.pct_complete);
      const cvFinal = field === 'contract_value' ? parseFloat(val) || 0 : cv;
      updated.valuation = String(Math.round(cvFinal * (pct / 100) * 100) / 100);
      return updated;
    });
    onChange(next);
  };

  const addRow = () => onChange([...rows, {
    _id: newId(), item_number: rows.length + 1, description: '', qty: 1, unit: 'item',
    rate: '', contract_value: '', pct_complete: '100', valuation: '', notes: '',
  }]);

  const del = (idx: number) => onChange(rows.filter((_, i) => i !== idx));

  const total = rows.reduce((s, r) => s + fmt(r.valuation), 0);

  const headers = ['Item', 'Description', 'Qty', 'Unit', 'Rate (£)', 'Contract Value (£)', '% Complete', 'Valuation (£)', 'Notes'];
  const widths  = ['60px', '1fr', '50px', '50px', '100px', '130px', '90px', '110px', '120px'];

  return (
    <div>
      <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid var(--border)' }}>
        <div style={{ minWidth: 900 }}>
          {/* Header */}
          <div className="grid" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
            {headers.map(h => <div key={h} className="px-2 py-2 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</div>)}
            {!readOnly && <div />}
          </div>
          {/* Rows */}
          {rows.map((row, i) => (
            <div key={row._id ?? i} className="grid items-center py-1 px-0" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: i % 2 === 1 ? 'var(--bg-elevated)' : 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
              <div className="px-1"><Cell value={String(row.item_number ?? i + 1)} onChange={v => update(i, 'item_number', v)} readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={row.description ?? ''} onChange={v => update(i, 'description', v)} readOnly={readOnly} placeholder="Description" /></div>
              <div className="px-1"><Cell value={String(row.qty ?? 1)} onChange={v => update(i, 'qty', v)} type="number" readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={row.unit ?? 'item'} onChange={v => update(i, 'unit', v)} readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={String(row.rate ?? '')} onChange={v => update(i, 'rate', v)} type="number" readOnly={readOnly} placeholder="0.00" /></div>
              <div className="px-1"><Cell value={String(fmt(row.contract_value) || '')} onChange={v => update(i, 'contract_value', v)} type="number" readOnly={readOnly} placeholder="0.00" /></div>
              <div className="px-1"><Cell value={String(row.pct_complete ?? '100')} onChange={v => update(i, 'pct_complete', v)} type="number" readOnly={readOnly} placeholder="100" /></div>
              <div className="px-1"><Cell value={fmt(row.valuation) ? String(fmt(row.valuation)) : ''} readOnly placeholder={formatCurrency(fmt(row.valuation))} /></div>
              <div className="px-1"><Cell value={row.notes ?? ''} onChange={v => update(i, 'notes', v)} readOnly={readOnly} placeholder="Notes" /></div>
              {!readOnly && (
                <div className="flex justify-center">
                  <button onClick={() => del(i)} className="p-1 rounded hover:bg-red-900/20"><Trash2 size={13} style={{ color: '#f87171' }} /></button>
                </div>
              )}
            </div>
          ))}
          {rows.length === 0 && (
            <div className="py-8 text-center text-sm" style={{ color: 'var(--text-muted)', backgroundColor: 'var(--bg-surface)' }}>
              No items — add a row to begin
            </div>
          )}
          {/* Total */}
          <div className="grid items-center px-0 py-2" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: 'var(--bg-elevated)', borderTop: '2px solid var(--border)' }}>
            <div />
            <div className="col-span-6 px-2 text-xs font-bold" style={{ color: 'var(--text-primary)' }}>TOTAL MEASURED WORKS</div>
            <div className="px-2 text-sm font-bold" style={{ color: 'var(--gold)' }}>{formatCurrency(total)}</div>
            <div />
            {!readOnly && <div />}
          </div>
        </div>
      </div>
      {!readOnly && (
        <button onClick={addRow} className="mt-3 flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium" style={{ backgroundColor: 'rgba(185,149,102,0.12)', color: 'var(--gold)' }}>
          <Plus size={13} /> Add Row
        </button>
      )}
    </div>
  );
}

// ─── Variations table ─────────────────────────────────────────────────────────

function VariationsTable({ rows, onChange, readOnly }: {
  rows: VariationRow[];
  onChange: (rows: VariationRow[]) => void;
  readOnly: boolean;
}) {
  const formatCurrency = useCurrencyFormatter();

  const update = (idx: number, field: keyof VariationRow, val: string) => {
    const next = rows.map((r, i) => {
      if (i !== idx) return r;
      const updated = { ...r, [field]: val };
      const vv  = field === 'variation_value' ? parseFloat(val) || 0 : fmt(r.variation_value);
      const pct = field === 'pct_complete'    ? parseFloat(val) || 0 : fmt(r.pct_complete ?? 100);
      updated.valuation = String(Math.round(vv * (pct / 100) * 100) / 100);
      return updated;
    });
    onChange(next);
  };

  const addRow = () => onChange([...rows, {
    _id: newId(), ref: String(rows.length + 1), instruction_ref: '', description: '',
    date_issued: '', variation_value: '', pct_complete: '100', valuation: '', status: '', notes: '',
  }]);

  const del = (idx: number) => onChange(rows.filter((_, i) => i !== idx));

  const total = rows.reduce((s, r) => s + fmt(r.valuation), 0);

  const headers = ['No.', 'Ref (COI/SI)', 'Description', 'Date Issued', 'Variation Value (£)', '% Complete', 'Valuation (£)', 'Status', 'Notes'];
  const widths  = ['50px', '90px', '1fr', '100px', '130px', '90px', '110px', '100px', '120px'];

  return (
    <div>
      <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid var(--border)' }}>
        <div style={{ minWidth: 900 }}>
          <div className="grid" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
            {headers.map(h => <div key={h} className="px-2 py-2 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</div>)}
            {!readOnly && <div />}
          </div>
          {rows.map((row, i) => (
            <div key={row._id ?? i} className="grid items-center py-1" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: i % 2 === 1 ? 'var(--bg-elevated)' : 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
              <div className="px-1"><Cell value={String(row.ref ?? i + 1)} onChange={v => update(i, 'ref', v)} readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={row.instruction_ref ?? ''} onChange={v => update(i, 'instruction_ref', v)} readOnly={readOnly} placeholder="Ref" /></div>
              <div className="px-1"><Cell value={row.description ?? ''} onChange={v => update(i, 'description', v)} readOnly={readOnly} placeholder="Description" /></div>
              <div className="px-1"><Cell value={row.date_issued ?? ''} onChange={v => update(i, 'date_issued', v)} type="date" readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={String(row.variation_value ?? '')} onChange={v => update(i, 'variation_value', v)} type="number" readOnly={readOnly} placeholder="0.00" /></div>
              <div className="px-1"><Cell value={String(row.pct_complete ?? '100')} onChange={v => update(i, 'pct_complete', v)} type="number" readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={fmt(row.valuation) ? String(fmt(row.valuation)) : ''} readOnly placeholder={formatCurrency(fmt(row.valuation))} /></div>
              <div className="px-1">{readOnly ? <span className="text-xs" style={{ color: 'var(--text-secondary)' }}>{row.status ?? '—'}</span> : <SelectCell value={row.status ?? ''} onChange={v => update(i, 'status', v)} options={VARIATION_STATUSES} />}</div>
              <div className="px-1"><Cell value={row.notes ?? ''} onChange={v => update(i, 'notes', v)} readOnly={readOnly} placeholder="Notes" /></div>
              {!readOnly && (
                <div className="flex justify-center">
                  <button onClick={() => del(i)} className="p-1 rounded hover:bg-red-900/20"><Trash2 size={13} style={{ color: '#f87171' }} /></button>
                </div>
              )}
            </div>
          ))}
          {rows.length === 0 && (
            <div className="py-8 text-center text-sm" style={{ color: 'var(--text-muted)', backgroundColor: 'var(--bg-surface)' }}>
              No variations yet
            </div>
          )}
          <div className="grid items-center px-0 py-2" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: 'var(--bg-elevated)', borderTop: '2px solid var(--border)' }}>
            <div /><div /><div /><div />
            <div className="col-span-2 px-2 text-xs font-bold" style={{ color: 'var(--text-primary)' }}>TOTAL VARIATIONS</div>
            <div className="px-2 text-sm font-bold" style={{ color: 'var(--gold)' }}>{formatCurrency(total)}</div>
            <div /><div />
            {!readOnly && <div />}
          </div>
        </div>
      </div>
      {!readOnly && (
        <button onClick={addRow} className="mt-3 flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium" style={{ backgroundColor: 'rgba(167,139,250,0.12)', color: '#a78bfa' }}>
          <Plus size={13} /> Add Variation
        </button>
      )}
    </div>
  );
}

// ─── Materials on Site table ──────────────────────────────────────────────────

function MaterialsTable({ rows, onChange, readOnly }: {
  rows: MaterialRow[];
  onChange: (rows: MaterialRow[]) => void;
  readOnly: boolean;
}) {
  const formatCurrency = useCurrencyFormatter();

  const update = (idx: number, field: keyof MaterialRow, val: string) => {
    const next = rows.map((r, i) => {
      if (i !== idx) return r;
      const updated = { ...r, [field]: val };
      const qty   = field === 'qty'  ? parseFloat(val) || 1 : fmt(r.qty ?? 1);
      const rate  = field === 'rate' ? parseFloat(val) || 0 : fmt(r.rate ?? 0);
      const mv    = field === 'material_value' ? parseFloat(val) || 0 : (qty * rate);
      if (field === 'qty' || field === 'rate') updated.material_value = String(mv);
      const pct   = field === 'claim_pct' ? parseFloat(val) || 0 : fmt(r.claim_pct ?? 100);
      const mvFinal = field === 'material_value' ? parseFloat(val) || 0 : mv;
      updated.valuation = String(Math.round(mvFinal * (pct / 100) * 100) / 100);
      return updated;
    });
    onChange(next);
  };

  const addRow = () => onChange([...rows, {
    _id: newId(), item_number: rows.length + 1, description: '', supplier: '', delivery_ref: '',
    storage_location: '', qty: 1, unit: 'item', rate: '', material_value: '', claim_pct: '90', valuation: '', notes: '',
  }]);

  const del = (idx: number) => onChange(rows.filter((_, i) => i !== idx));

  const total = rows.reduce((s, r) => s + fmt(r.valuation), 0);

  const headers = ['Item', 'Description', 'Qty', 'Unit', 'Rate (£)', 'Material Value (£)', '% Claimed', 'Valuation (£)', 'Notes'];
  const widths  = ['50px', '1fr', '50px', '50px', '100px', '130px', '90px', '110px', '120px'];

  return (
    <div>
      <div className="overflow-x-auto rounded-xl" style={{ border: '1px solid var(--border)' }}>
        <div style={{ minWidth: 900 }}>
          <div className="grid" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
            {headers.map(h => <div key={h} className="px-2 py-2 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</div>)}
            {!readOnly && <div />}
          </div>
          {rows.map((row, i) => (
            <div key={row._id ?? i} className="grid items-center py-1" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: i % 2 === 1 ? 'var(--bg-elevated)' : 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
              <div className="px-1"><Cell value={String(row.item_number ?? i + 1)} onChange={v => update(i, 'item_number', v)} readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={row.description ?? ''} onChange={v => update(i, 'description', v)} readOnly={readOnly} placeholder="Description" /></div>
              <div className="px-1"><Cell value={String(row.qty ?? 1)} onChange={v => update(i, 'qty', v)} type="number" readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={row.unit ?? 'item'} onChange={v => update(i, 'unit', v)} readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={String(row.rate ?? '')} onChange={v => update(i, 'rate', v)} type="number" readOnly={readOnly} placeholder="0.00" /></div>
              <div className="px-1"><Cell value={String(fmt(row.material_value) || '')} onChange={v => update(i, 'material_value', v)} type="number" readOnly={readOnly} placeholder="0.00" /></div>
              <div className="px-1"><Cell value={String(row.claim_pct ?? '90')} onChange={v => update(i, 'claim_pct', v)} type="number" readOnly={readOnly} /></div>
              <div className="px-1"><Cell value={fmt(row.valuation) ? String(fmt(row.valuation)) : ''} readOnly placeholder={formatCurrency(fmt(row.valuation))} /></div>
              <div className="px-1"><Cell value={row.notes ?? ''} onChange={v => update(i, 'notes', v)} readOnly={readOnly} placeholder="Notes" /></div>
              {!readOnly && (
                <div className="flex justify-center">
                  <button onClick={() => del(i)} className="p-1 rounded hover:bg-red-900/20"><Trash2 size={13} style={{ color: '#f87171' }} /></button>
                </div>
              )}
            </div>
          ))}
          {rows.length === 0 && (
            <div className="py-8 text-center text-sm" style={{ color: 'var(--text-muted)', backgroundColor: 'var(--bg-surface)' }}>
              No materials on site yet
            </div>
          )}
          <div className="grid items-center px-0 py-2" style={{ gridTemplateColumns: readOnly ? widths.join(' ') : [...widths, '36px'].join(' '), backgroundColor: 'var(--bg-elevated)', borderTop: '2px solid var(--border)' }}>
            <div /><div /><div /><div />
            <div className="col-span-2 px-2 text-xs font-bold" style={{ color: 'var(--text-primary)' }}>TOTAL MATERIALS ON SITE</div>
            <div className="px-2 text-sm font-bold" style={{ color: 'var(--gold)' }}>{formatCurrency(total)}</div>
            <div /><div />
            {!readOnly && <div />}
          </div>
        </div>
      </div>
      {!readOnly && (
        <button onClick={addRow} className="mt-3 flex items-center gap-2 px-3 py-2 rounded-lg text-xs font-medium" style={{ backgroundColor: 'rgba(34,197,94,0.12)', color: '#4ade80' }}>
          <Plus size={13} /> Add Material
        </button>
      )}
    </div>
  );
}

// ─── Linked Approved Variations panel ────────────────────────────────────────

function LinkedVariationsPanel({ appId, projectId, canEdit, onSaved }: {
  appId: string; projectId: string; canEdit: boolean; onSaved: (data: PA) => void;
}) {
  const formatCurrency = useCurrencyFormatter();
  const queryClient   = useQueryClient();
  const [selected, setSelected]       = useState<Set<number>>(new Set());
  const [initialized, setInitialized] = useState(false);

  const { data, isLoading } = useQuery<{ data: EligibleVariation[] }>({
    queryKey: ['pa-eligible-variations', appId],
    queryFn: () => api.get(`/payment-applications/${appId}/eligible-variations`).then(r => r.data),
  });

  const eligible = data?.data ?? [];

  // Seed selection from is_linked (once, after data arrives)
  if (!initialized && eligible.length > 0) {
    setSelected(new Set(eligible.filter(v => v.is_linked).map(v => v.id)));
    setInitialized(true);
  }

  const toggle = (id: number) => {
    if (!canEdit) return;
    setSelected(prev => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  };

  const syncMutation = useMutation({
    mutationFn: () => api.post(`/payment-applications/${appId}/sync-variations`, {
      variation_ids: Array.from(selected),
    }).then(r => r.data),
    onSuccess: (data: PA) => {
      queryClient.setQueryData(['payment-application', appId], data);
      queryClient.invalidateQueries({ queryKey: ['pa-eligible-variations', appId] });
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Linked variations saved');
      onSaved(data);
    },
    onError: (e: unknown) => toast.error(getError(e, 'Failed to save linked variations')),
  });

  const linkedTotal = eligible
    .filter(v => selected.has(v.id))
    .reduce((s, v) => s + fmt(v.agreed_amount), 0);

  if (isLoading) {
    return (
      <div className="flex items-center gap-2 py-4 text-sm" style={{ color: 'var(--text-muted)' }}>
        <Loader2 size={14} className="animate-spin" /> Loading eligible variations…
      </div>
    );
  }

  if (eligible.length === 0) {
    return (
      <div className="rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <Link2Off size={20} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No approved variations found</p>
        <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>
          Only approved variations from the same contract or trade package appear here.
        </p>
      </div>
    );
  }

  const cols = '36px 80px 1fr 130px 90px';

  return (
    <div className="space-y-3">
      <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        {/* Header */}
        <div className="grid px-3 py-2 text-xs font-medium"
          style={{ gridTemplateColumns: cols, backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}>
          <div />
          <div>Var #</div>
          <div>Title</div>
          <div>Agreed Amount</div>
          <div>Status</div>
        </div>
        {/* Rows */}
        {eligible.map((v, i) => {
          const isSelected = selected.has(v.id);
          return (
            <div key={v.id}
              onClick={() => toggle(v.id)}
              className={canEdit ? 'cursor-pointer' : ''}
              style={{
                display: 'grid', gridTemplateColumns: cols,
                alignItems: 'center', padding: '8px 12px',
                backgroundColor: isSelected
                  ? 'rgba(185,149,102,0.08)'
                  : i % 2 === 1 ? 'var(--bg-elevated)' : 'var(--bg-surface)',
                borderBottom: '1px solid var(--border)',
              }}>
              <div>
                <div className="w-4 h-4 rounded flex items-center justify-center"
                  style={{
                    backgroundColor: isSelected ? 'var(--gold)' : 'var(--bg-base)',
                    border: `2px solid ${isSelected ? 'var(--gold)' : 'var(--border)'}`,
                  }}>
                  {isSelected && <span style={{ color: 'var(--accent-fg)', fontSize: 9, fontWeight: 700, lineHeight: 1 }}>✓</span>}
                </div>
              </div>
              <div className="text-xs font-mono" style={{ color: 'var(--text-secondary)' }}>
                VAR-{String(v.variation_number).padStart(3, '0')}
              </div>
              <div className="text-xs truncate" style={{ color: 'var(--text-primary)' }}>{v.title}</div>
              <div className="text-xs font-semibold" style={{ color: isSelected ? 'var(--gold)' : 'var(--text-secondary)' }}>
                {formatCurrency(fmt(v.agreed_amount))}
              </div>
              <div className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>{v.status}</div>
            </div>
          );
        })}
        {/* Total */}
        <div className="grid px-3 py-2.5 items-center"
          style={{ gridTemplateColumns: cols, backgroundColor: 'var(--bg-elevated)', borderTop: '2px solid var(--border)' }}>
          <div /><div />
          <div className="text-xs font-bold" style={{ color: 'var(--text-primary)' }}>LINKED VARIATIONS TOTAL</div>
          <div className="text-sm font-bold" style={{ color: 'var(--gold)' }}>{formatCurrency(linkedTotal)}</div>
          <div />
        </div>
      </div>

      {canEdit && (
        <div className="flex items-center justify-between">
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            {selected.size} variation{selected.size !== 1 ? 's' : ''} selected — {formatCurrency(linkedTotal)}
          </p>
          <button onClick={() => syncMutation.mutate()} disabled={syncMutation.isPending}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: syncMutation.isPending ? 0.6 : 1 }}>
            {syncMutation.isPending ? <Loader2 size={13} className="animate-spin" /> : <Link2 size={13} />}
            {syncMutation.isPending ? 'Saving…' : 'Save Linked Variations'}
          </button>
        </div>
      )}
    </div>
  );
}

// ─── Financial summary row ────────────────────────────────────────────────────

function FinRow({ label, value, sub, highlight, negative, large }: {
  label: string; value: string; sub?: string; highlight?: boolean; negative?: boolean; large?: boolean;
}) {
  return (
    <div className="flex items-center justify-between py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
      <div>
        <p className={large ? 'text-sm font-semibold' : 'text-xs'} style={{ color: highlight ? 'var(--text-primary)' : 'var(--text-secondary)' }}>{label}</p>
        {sub && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
      </div>
      <span className={large ? 'text-base font-bold' : 'text-sm font-semibold'} style={{ color: negative ? '#f87171' : highlight ? 'var(--gold)' : 'var(--text-secondary)' }}>
        {negative && value !== '£0.00' ? `(${value})` : value}
      </span>
    </div>
  );
}

// ─── Payment Cycle Timeline ───────────────────────────────────────────────────

type StageStatus = 'complete' | 'overdue' | 'current' | 'pending' | 'na';

const STAGE_DOT: Record<StageStatus, string> = {
  complete: '#4ade80',
  overdue:  '#f87171',
  current:  '#facc15',
  pending:  'var(--border)',
  na:       'var(--border)',
};

const STAGE_GLOW: Record<StageStatus, string | undefined> = {
  complete: undefined,
  overdue:  '0 0 0 3px rgba(248,113,113,0.2)',
  current:  '0 0 0 3px rgba(250,204,21,0.2)',
  pending:  undefined,
  na:       undefined,
};

function PaymentCycleTimeline({ pa, formatCurrency }: { pa: PA; formatCurrency: (v: number | string) => string }) {
  const today   = new Date().toISOString().split('T')[0];
  const hasPN   = (pa.payment_notices?.length ?? 0) > 0;
  const hasPLN  = (pa.pay_less_notices?.length ?? 0) > 0;
  const isPaid  = pa.status === 'paid';
  const isActive = !['draft', 'cancelled'].includes(pa.status ?? '');

  const pn  = pa.payment_notices?.[0];
  const pln = pa.pay_less_notices?.[0];

  const pnDeadlinePassed  = isActive && !hasPN && !!pa.payment_notice_deadline  && pa.payment_notice_deadline  < today;
  const plnDeadlinePassed = hasPN    && !hasPLN && !!pa.pay_less_notice_deadline && pa.pay_less_notice_deadline < today;
  const finalDatePassed   = !isPaid  && !!pa.final_date_for_payment              && pa.final_date_for_payment   < today;

  const stages: {
    id: string; label: string; status: StageStatus;
    date?: string | null; deadline?: string | null;
    detail?: string | null; advisory?: string | null;
  }[] = [
    {
      id: 'submitted', label: 'Application Submitted',
      status: isActive ? 'complete' : 'current',
      date: pa.application_date,
      detail: `Application #${pa.application_number}`,
    },
    {
      id: 'pn', label: 'Payment Notice',
      status: hasPN ? 'complete' : pnDeadlinePassed ? 'overdue' : isActive ? 'current' : 'pending',
      date: hasPN ? pn?.notice_date : null,
      deadline: !hasPN ? pa.payment_notice_deadline : null,
      detail: hasPN ? `${pn?.reference ?? 'Issued'} — ${formatCurrency(fmt(pn?.notified_sum))}` : null,
      advisory: pnDeadlinePassed
        ? 'Payment Notice deadline has passed. No Payment Notice has been issued. Under the applicable contract and legislation, the contractor\'s Payment Application may now constitute the Default Payment Notice. Please review this application.'
        : null,
    },
    {
      id: 'pln', label: 'Pay Less Notice',
      status: hasPLN ? 'complete' : plnDeadlinePassed ? 'overdue' : hasPN ? 'current' : 'na',
      date: hasPLN ? pln?.notice_date : null,
      deadline: hasPN && !hasPLN ? pa.pay_less_notice_deadline : null,
      detail: hasPLN
        ? `${pln?.reference ?? 'Issued'} — Revised: ${formatCurrency(fmt(pln?.revised_amount_payable ?? pln?.notified_sum))}`
        : null,
      advisory: plnDeadlinePassed
        ? 'The Pay Less Notice deadline has passed. If a valid Payment Notice has been served, the payer may now be required to pay the full Notified Sum. Review before making payment decisions.'
        : null,
    },
    {
      id: 'final', label: 'Final Date for Payment',
      status: isPaid ? 'complete' : finalDatePassed ? 'overdue' : isActive ? 'current' : 'pending',
      date: pa.final_date_for_payment,
      advisory: finalDatePassed
        ? 'Final Date for Payment has passed. Payment may now be overdue.'
        : null,
    },
    {
      id: 'paid', label: 'Payment',
      status: isPaid ? 'complete' : 'pending',
      date: isPaid ? pa.payment_date : null,
      detail: isPaid && fmt(pa.paid_amount) > 0 ? formatCurrency(fmt(pa.paid_amount)) : null,
    },
  ];

  return (
    <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <p className="text-xs font-semibold mb-4" style={{ color: 'var(--text-muted)' }}>PAYMENT CYCLE</p>
      <div>
        {stages.map((stage, i) => (
          <div key={stage.id} style={{ display: 'flex', gap: 12 }}>
            {/* Dot + connector */}
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', width: 20, flexShrink: 0 }}>
              <div style={{
                width: 10, height: 10, borderRadius: '50%', flexShrink: 0, marginTop: 3,
                backgroundColor: STAGE_DOT[stage.status],
                boxShadow: STAGE_GLOW[stage.status],
              }} />
              {i < stages.length - 1 && (
                <div style={{ width: 2, flex: 1, minHeight: 20, backgroundColor: 'var(--border)', margin: '3px 0' }} />
              )}
            </div>
            {/* Content */}
            <div style={{ flex: 1, paddingBottom: i < stages.length - 1 ? 14 : 0 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 8 }}>
                <span className="text-xs font-semibold" style={{
                  color: stage.status === 'complete' ? 'var(--text-primary)'
                    : stage.status === 'overdue'  ? '#f87171'
                    : stage.status === 'current'  ? '#facc15'
                    : 'var(--text-muted)',
                }}>
                  {stage.label}
                </span>
                {(stage.date || stage.deadline) && (
                  <span className="text-xs flex-shrink-0" style={{ color: stage.status === 'overdue' ? '#f87171' : 'var(--text-muted)' }}>
                    {stage.date
                      ? formatDate(stage.date)
                      : stage.deadline ? `Deadline: ${formatDate(stage.deadline)}` : null}
                  </span>
                )}
              </div>
              {stage.detail && (
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{stage.detail}</p>
              )}
              {stage.status === 'na' && (
                <p className="text-xs mt-0.5 italic" style={{ color: 'var(--text-muted)' }}>Not applicable — no Payment Notice issued</p>
              )}
              {stage.advisory && (
                <div className="mt-2 p-2.5 rounded-lg flex gap-2" style={{ backgroundColor: 'rgba(249,115,22,0.07)', border: '1px solid rgba(249,115,22,0.2)' }}>
                  <AlertTriangle size={13} className="flex-shrink-0 mt-0.5" style={{ color: '#fb923c' }} />
                  <p className="text-xs leading-relaxed" style={{ color: '#fb923c' }}>{stage.advisory}</p>
                </div>
              )}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

type TabId = 'summary' | 'measured-works' | 'variations' | 'materials' | 'documents' | 'notices';

export default function PaymentApplicationDetailPage() {
  const { id: projectId, appId } = useParams<{ id: string; appId: string }>();
  const formatCurrency = useCurrencyFormatter();
  const queryClient = useQueryClient();

  const [tab, setTab] = useState<TabId>('summary');

  // ─── Fetch application ──────────────────────────────────────────────────────

  const { data: pa, isLoading } = useQuery<PA>({
    queryKey: ['payment-application', appId],
    queryFn: () => api.get(`/payment-applications/${appId}`).then(r => r.data),
  });

  // ─── Fetch documents linked to this application ─────────────────────────────

  const { data: docsData } = useQuery<{ data?: DocRecord[] }>({
    queryKey: ['pa-documents', appId],
    queryFn: () => api.get(`/payment-applications/${appId}/documents`).then(r => r.data),
    enabled: tab === 'documents',
  });
  const docs = docsData?.data ?? [];

  // ─── Local breakdown state ──────────────────────────────────────────────────

  const [measuredWorks, setMeasuredWorks] = useState<MeasuredWorkRow[]>([]);
  const [variations, setVariations] = useState<VariationRow[]>([]);
  const [materials, setMaterials] = useState<MaterialRow[]>([]);
  const [useBreakdown, setUseBreakdown] = useState(false);
  const [vatRate, setVatRate] = useState(20);
  const [initialized, setInitialized] = useState(false);

  // Seed from loaded PA (once)
  if (pa && !initialized) {
    setMeasuredWorks((pa.breakdown?.measured_works ?? []).map(r => ({ ...r, _id: r._id ?? newId() })));
    setVariations((pa.breakdown?.variations ?? []).map(r => ({ ...r, _id: r._id ?? newId() })));
    setMaterials((pa.breakdown?.materials_on_site ?? []).map(r => ({ ...r, _id: r._id ?? newId() })));
    setUseBreakdown(pa.use_breakdown ?? false);
    setVatRate(fmt(pa.vat_rate) || 20);
    setInitialized(true);
  }

  const isDraft = pa?.status === 'draft';
  const canEdit = isDraft;

  // ─── Live totals (no API call) ──────────────────────────────────────────────

  const mwTotal      = useMemo(() => measuredWorks.reduce((s, r) => s + fmt(r.valuation), 0), [measuredWorks]);
  const varTotal     = useMemo(() => variations.reduce((s, r) => s + fmt(r.valuation), 0), [variations]);
  const matTotal     = useMemo(() => materials.reduce((s, r) => s + fmt(r.valuation), 0), [materials]);
  const linkedVarTotal = fmt(pa?.linked_variations_total);

  const grossVal   = useBreakdown ? (mwTotal + varTotal + matTotal + linkedVarTotal) : fmt(pa?.gross_valuation);
  const retention  = useBreakdown
    ? Math.round(grossVal * (fmt(pa?.contract?.retention_percentage) / 100) * 100) / 100
    : fmt(pa?.less_retention);
  const prevCert   = fmt(pa?.less_previous_payments);
  const netVal     = grossVal - retention;
  const amountDue  = Math.max(0, netVal - prevCert);
  const vatAmt     = Math.round(amountDue * (vatRate / 100) * 100) / 100;
  const totalDue   = amountDue + vatAmt;

  // ─── Save breakdown mutation ────────────────────────────────────────────────

  const saveMutation = useMutation({
    mutationFn: () => api.post(`/payment-applications/${appId}/breakdown`, {
      breakdown: {
        measured_works: measuredWorks,
        variations,
        materials_on_site: materials,
      },
      use_breakdown: useBreakdown,
      vat_rate: vatRate,
    }).then(r => r.data),
    onSuccess: (data: PA) => {
      queryClient.setQueryData(['payment-application', appId], data);
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Breakdown saved');
    },
    onError: (e: unknown) => toast.error(getError(e, 'Save failed')),
  });

  // ─── PDF generation ─────────────────────────────────────────────────────────

  const pdfMutation = useMutation({
    mutationFn: () => api.post(`/payment-applications/${appId}/generate-pdf`).then(r => r.data),
    onSuccess: (doc: DocRecord) => {
      queryClient.invalidateQueries({ queryKey: ['pa-documents', appId] });
      downloadDoc(doc);
      toast.success('PDF generated');
    },
    onError: (e: unknown) => toast.error(getError(e, 'PDF generation failed')),
  });

  // ─── Excel generation ────────────────────────────────────────────────────────

  const excelMutation = useMutation({
    mutationFn: () => api.post(`/payment-applications/${appId}/generate-excel`).then(r => r.data),
    onSuccess: (doc: DocRecord) => {
      queryClient.invalidateQueries({ queryKey: ['pa-documents', appId] });
      downloadDoc(doc);
      toast.success('Excel workbook generated');
    },
    onError: (e: unknown) => toast.error(getError(e, 'Excel generation failed')),
  });

  // ─── Submit mutation ─────────────────────────────────────────────────────────

  const submitMutation = useMutation({
    mutationFn: () => api.post(`/payment-applications/${appId}/submit`).then(r => r.data),
    onSuccess: (data: PA) => {
      queryClient.setQueryData(['payment-application', appId], data);
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Application submitted');
    },
    onError: (e: unknown) => toast.error(getError(e, 'Submit failed')),
  });

  const withdrawMutation = useMutation({
    mutationFn: () => api.post(`/payment-applications/${appId}/withdraw`).then(r => r.data),
    onSuccess: (data: PA) => {
      queryClient.setQueryData(['payment-application', appId], data);
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Application withdrawn to draft');
    },
    onError: (e: unknown) => toast.error(getError(e, 'Withdraw failed')),
  });

  function downloadDoc(doc: DocRecord) {
    api.get(`/documents/${doc.id}/download`, { responseType: 'blob' }).then(res => {
      const url = URL.createObjectURL(res.data);
      const a = window.document.createElement('a');
      a.href = url;
      a.download = doc.file_name ?? 'document';
      a.click();
      URL.revokeObjectURL(url);
    });
  }

  // ─── Tabs ────────────────────────────────────────────────────────────────────

  const TABS: { id: TabId; label: string }[] = [
    { id: 'summary',       label: 'Summary' },
    { id: 'measured-works', label: 'Measured Works' },
    { id: 'variations',    label: 'Variations' },
    { id: 'materials',     label: 'Materials on Site' },
    { id: 'documents',     label: 'Documents' },
    { id: 'notices',       label: 'Notices' },
  ];

  // ─── Loading / not found ─────────────────────────────────────────────────────

  if (isLoading) {
    return (
      <div className="p-8 flex items-center gap-2" style={{ color: 'var(--text-muted)' }}>
        <Loader2 size={18} className="animate-spin" /> Loading application…
      </div>
    );
  }

  if (!pa) {
    return (
      <div className="p-8 flex items-center gap-2" style={{ color: '#f87171' }}>
        <AlertCircle size={18} /> Application not found.
      </div>
    );
  }

  const badge = PA_STATUS[pa.status ?? ''] ?? PA_STATUS.draft;
  const source = pa.trade_package
    ? `Trade Package — ${pa.trade_package.name}`
    : pa.contract
      ? `Main Contract — ${pa.contract.title}`
      : '—';

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">
      {/* ─── Breadcrumb / header ─────────────────────────────────────────────── */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <Link href={`/app/projects/${projectId}/commercial`}
            className="flex items-center gap-1 text-xs mb-2 hover:opacity-80"
            style={{ color: 'var(--text-muted)' }}>
            <ArrowLeft size={13} /> Back to Commercial
          </Link>
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>
              Application #{pa.application_number}
            </h1>
            <span className="px-2.5 py-0.5 rounded-full text-xs font-semibold" style={{ backgroundColor: badge.bg, color: badge.text }}>
              {badge.label}
            </span>
            {pa.reference && <span className="text-sm font-mono" style={{ color: 'var(--text-muted)' }}>{pa.reference}</span>}
          </div>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>{source}</p>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          {/* Submit Application (draft only) */}
          {isDraft && (
            <button onClick={() => submitMutation.mutate()} disabled={submitMutation.isPending}
              className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: '#facc15', color: '#000', opacity: submitMutation.isPending ? 0.6 : 1 }}>
              {submitMutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <Send size={14} />}
              Submit Application
            </button>
          )}
          {/* Withdraw Application (submitted only) */}
          {pa.status === 'submitted' && (
            <button onClick={() => withdrawMutation.mutate()} disabled={withdrawMutation.isPending}
              className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'rgba(251,146,60,0.15)', border: '1px solid rgba(251,146,60,0.4)', color: '#fb923c', opacity: withdrawMutation.isPending ? 0.6 : 1 }}>
              {withdrawMutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <RotateCcw size={14} />}
              Withdraw to Draft
            </button>
          )}
          {/* Download PDF */}
          <button onClick={() => pdfMutation.mutate()} disabled={pdfMutation.isPending}
            className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-secondary)', opacity: pdfMutation.isPending ? 0.6 : 1 }}>
            {pdfMutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <FileText size={14} />}
            PDF
          </button>
          {/* Download Excel */}
          <button onClick={() => excelMutation.mutate()} disabled={excelMutation.isPending}
            className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-medium"
            style={{ backgroundColor: 'rgba(34,197,94,0.12)', border: '1px solid rgba(34,197,94,0.3)', color: '#4ade80', opacity: excelMutation.isPending ? 0.6 : 1 }}>
            {excelMutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <FileSpreadsheet size={14} />}
            Excel Workbook
          </button>
        </div>
      </div>

      {/* ─── Breakdown toggle (draft only) ──────────────────────────────────── */}
      {canEdit && (
        <div className="flex items-center gap-3 p-4 rounded-xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <button onClick={() => setUseBreakdown(v => !v)} className="flex items-center gap-2">
            {useBreakdown
              ? <ToggleRight size={24} style={{ color: 'var(--gold)' }} />
              : <ToggleLeft size={24} style={{ color: 'var(--text-muted)' }} />}
          </button>
          <div>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
              {useBreakdown ? 'Using detailed valuation breakdown' : 'Manual gross valuation'}
            </p>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              {useBreakdown
                ? 'Gross Valuation is calculated from Measured Works + Variations + Materials on Site.'
                : 'Gross Valuation is entered manually. Toggle on to use the detailed breakdown instead.'}
            </p>
          </div>
          <div className="ml-auto flex items-center gap-2">
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>VAT Rate</span>
            <div className="flex items-center rounded-lg overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              {[0, 5, 20].map(r => (
                <button key={r} onClick={() => setVatRate(r)}
                  className="px-3 py-1.5 text-xs font-medium transition-colors"
                  style={{ backgroundColor: vatRate === r ? 'var(--gold)' : 'var(--bg-base)', color: vatRate === r ? 'var(--accent-fg)' : 'var(--text-muted)' }}>
                  {r}%
                </button>
              ))}
              <input type="number" value={vatRate} onChange={e => setVatRate(parseFloat(e.target.value) || 0)}
                className="w-12 px-2 py-1.5 text-xs text-center outline-none"
                style={{ backgroundColor: 'var(--bg-base)', color: 'var(--text-primary)', borderLeft: '1px solid var(--border)' }}
                min={0} max={100} step={0.5}
                title="Custom VAT rate"
              />
            </div>
          </div>
        </div>
      )}

      {/* ─── Tabs ────────────────────────────────────────────────────────────── */}
      <div className="flex gap-1 p-1 rounded-lg w-fit overflow-x-auto" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className="px-4 py-2 rounded-md text-sm font-medium transition-all whitespace-nowrap"
            style={tab === t.id ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
            {t.label}
          </button>
        ))}
      </div>

      {/* ─── Summary tab ─────────────────────────────────────────────────────── */}
      {tab === 'summary' && (
        <div className="space-y-5">
        <PaymentCycleTimeline pa={pa} formatCurrency={formatCurrency} />
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
          {/* Application Details */}
          <div className="rounded-2xl p-5 space-y-0" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-muted)' }}>APPLICATION DETAILS</p>
            {[
              ['Commercial Source',    source],
              ['Application Number',   `#${pa.application_number}`],
              ['Reference',            pa.reference ?? '—'],
              ['Application Date',     pa.application_date ? formatDate(pa.application_date) : '—'],
              ['Valuation Period',     (pa.valuation_period_start || pa.valuation_period_end)
                ? `${pa.valuation_period_start ? formatDate(pa.valuation_period_start) : '—'} → ${pa.valuation_period_end ? formatDate(pa.valuation_period_end) : '—'}`
                : '—'],
              ['Payment Due Date',     pa.due_date ? formatDate(pa.due_date) : '—'],
              ['Final Date for Payment', pa.final_date_for_payment ? formatDate(pa.final_date_for_payment) : '—'],
              ['Payment Notice Deadline', pa.payment_notice_deadline ? formatDate(pa.payment_notice_deadline) : '—'],
              ['Pay Less Notice Deadline', pa.pay_less_notice_deadline ? formatDate(pa.pay_less_notice_deadline) : '—'],
            ].map(([label, value]) => (
              <FinRow key={label} label={label} value={value} />
            ))}
          </div>

          {/* Contract / Financial details */}
          <div className="space-y-5">
            {pa.contract && (
              <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-muted)' }}>CONTRACT</p>
                {[
                  ['Contract', pa.contract.title],
                  ['Reference', pa.contract.reference_number ?? '—'],
                  ['Party', pa.contract.party_name ?? '—'],
                  ['Contract Sum', pa.contract.contract_sum ? formatCurrency(fmt(pa.contract.contract_sum)) : '—'],
                  ['Retention %', pa.contract.retention_percentage ? `${pa.contract.retention_percentage}%` : '0%'],
                  ['Payment Terms', pa.contract.payment_terms_days ? `${pa.contract.payment_terms_days} days` : '—'],
                ].map(([label, value]) => <FinRow key={label} label={label} value={value} />)}
              </div>
            )}

            {/* Financial summary (live-calculated) */}
            <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
              <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-muted)' }}>VALUATION SUMMARY</p>
              {useBreakdown && (
                <>
                  <FinRow label="Measured Works Total"       value={formatCurrency(mwTotal)} />
                  <FinRow label="Linked Approved Variations" value={formatCurrency(linkedVarTotal)} />
                  <FinRow label="Manual Variations Total"    value={formatCurrency(varTotal)} />
                  <FinRow label="Materials on Site Total"    value={formatCurrency(matTotal)} />
                </>
              )}
              <FinRow label="Gross Valuation"           value={formatCurrency(grossVal)} />
              <FinRow label="Less: Retention"           value={formatCurrency(retention)} negative />
              <FinRow label="Net Valuation"             value={formatCurrency(netVal)} />
              <FinRow label="Less: Previous Certified"  value={formatCurrency(prevCert)} negative sub="Sum of previously certified applications" />
              <FinRow label="Amount Applied For"        value={formatCurrency(amountDue)} highlight large />
              {vatRate > 0 && (
                <>
                  <FinRow label={`VAT @ ${vatRate}%`}  value={formatCurrency(vatAmt)} />
                  <FinRow label="Total Due incl. VAT"   value={formatCurrency(totalDue)} highlight />
                </>
              )}
              {fmt(pa.previous_paid_value) > 0 && (
                <div className="mt-3 pt-3" style={{ borderTop: '1px solid var(--border)' }}>
                  <FinRow label="Previous Paid Value"   value={formatCurrency(fmt(pa.previous_paid_value))} />
                  <FinRow label="Previous Retention Held" value={formatCurrency(fmt(pa.previous_retention_held))} />
                </div>
              )}
            </div>

            {/* Status / certified / paid summary */}
            {['certified', 'paid'].includes(pa.status ?? '') && (
              <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                <p className="text-xs font-semibold mb-3" style={{ color: 'var(--text-muted)' }}>CERTIFICATION / PAYMENT</p>
                {fmt(pa.certified_amount) > 0 && <FinRow label="Certified Amount" value={formatCurrency(fmt(pa.certified_amount))} highlight />}
                {fmt(pa.paid_amount) > 0 && <FinRow label="Paid Amount" value={formatCurrency(fmt(pa.paid_amount))} highlight />}
              </div>
            )}
          </div>
        </div>
        </div>
      )}

      {/* ─── Measured Works tab ──────────────────────────────────────────────── */}
      {tab === 'measured-works' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Measured Works</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Contract scope valuation. Valuation = Contract Value × % Complete.
              </p>
            </div>
            <div className="px-4 py-2 rounded-xl font-bold text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--gold)' }}>
              Total: {formatCurrency(mwTotal)}
            </div>
          </div>
          <MeasuredWorksTable rows={measuredWorks} onChange={setMeasuredWorks} readOnly={!canEdit} />
        </div>
      )}

      {/* ─── Variations tab ──────────────────────────────────────────────────── */}
      {tab === 'variations' && (
        <div className="space-y-6">

          {/* Linked Approved Variations */}
          <div>
            <div className="flex items-center justify-between mb-3">
              <div>
                <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Linked Approved Variations</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  Pull approved variations from the Variations register. Values are snapshotted at inclusion — historically accurate even if a variation is later amended.
                </p>
              </div>
              <div className="px-4 py-2 rounded-xl font-bold text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--gold)' }}>
                Total: {formatCurrency(linkedVarTotal)}
              </div>
            </div>
            <LinkedVariationsPanel
              appId={appId}
              projectId={projectId}
              canEdit={canEdit}
              onSaved={(updated) => {
                queryClient.setQueryData(['payment-application', appId], updated);
              }}
            />
          </div>

          <div style={{ borderTop: '2px solid var(--border)' }} />

          {/* Manual Variation Items */}
          <div>
            <div className="flex items-center justify-between mb-3">
              <div>
                <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Manual Variation Items</h2>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                  Free-text variation line items not yet in the register, or partial-completion valuations. Valuation = Variation Value × % Complete.
                </p>
              </div>
              <div className="px-4 py-2 rounded-xl font-bold text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: '#a78bfa' }}>
                Total: {formatCurrency(varTotal)}
              </div>
            </div>
            <VariationsTable rows={variations} onChange={setVariations} readOnly={!canEdit} />
          </div>
        </div>
      )}

      {/* ─── Materials on Site tab ────────────────────────────────────────────── */}
      {tab === 'materials' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Materials on Site</h2>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                Materials purchased or delivered but not yet installed. Valuation = Material Value × % Claimed.
              </p>
            </div>
            <div className="px-4 py-2 rounded-xl font-bold text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: '#4ade80' }}>
              Total: {formatCurrency(matTotal)}
            </div>
          </div>
          <MaterialsTable rows={materials} onChange={setMaterials} readOnly={!canEdit} />
        </div>
      )}

      {/* ─── Documents tab ───────────────────────────────────────────────────── */}
      {tab === 'documents' && (
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Generated Documents</h2>
            <div className="flex gap-2">
              <button onClick={() => pdfMutation.mutate()} disabled={pdfMutation.isPending}
                className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium"
                style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-secondary)', opacity: pdfMutation.isPending ? 0.6 : 1 }}>
                <FileText size={13} /> Generate PDF
              </button>
              <button onClick={() => excelMutation.mutate()} disabled={excelMutation.isPending}
                className="flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-medium"
                style={{ backgroundColor: 'rgba(34,197,94,0.12)', border: '1px solid rgba(34,197,94,0.3)', color: '#4ade80', opacity: excelMutation.isPending ? 0.6 : 1 }}>
                <FileSpreadsheet size={13} /> Generate Excel
              </button>
            </div>
          </div>
          {docs.length === 0 ? (
            <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
              <FileText size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
              <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No documents generated yet</p>
              <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Generate a PDF or Excel workbook using the buttons above</p>
            </div>
          ) : (
            <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              <table className="w-full text-sm">
                <thead><tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                  {['Title', 'Type', 'Size', 'Generated', ''].map(h => <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>)}
                </tr></thead>
                <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                  {docs.map(doc => (
                    <tr key={doc.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-primary)' }}>{doc.title ?? doc.file_name}</td>
                      <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                        {doc.mime_type === 'application/pdf' ? '📄 PDF' : '📊 Excel'}
                      </td>
                      <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                        {doc.file_size ? `${Math.round(doc.file_size / 1024)} KB` : '—'}
                      </td>
                      <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                        {doc.created_at ? formatDate(doc.created_at) : '—'}
                      </td>
                      <td className="px-4 py-3">
                        <button onClick={() => downloadDoc(doc)}
                          className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                          style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
                          <Download size={12} /> Download
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {/* ─── Notices tab ─────────────────────────────────────────────────────── */}
      {tab === 'notices' && (
        <div className="space-y-6">

          {/* Payment Notices */}
          <div>
            <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>
              Payment Notices
              {(pa.payment_notices?.length ?? 0) > 0 && (
                <span className="ml-2 px-1.5 py-0.5 rounded text-xs" style={{ backgroundColor: 'rgba(234,179,8,0.15)', color: '#facc15' }}>
                  {pa.payment_notices!.length}
                </span>
              )}
            </h2>
            {!pa.payment_notices?.length ? (
              <div className="rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No Payment Notice issued for this application.</p>
              </div>
            ) : (
              <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                <table className="w-full text-sm">
                  <thead><tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                    {['Reference', 'Notice Date', 'Notified Sum', 'Issued By', 'Status', 'PDF'].map(h => (
                      <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                    ))}
                  </tr></thead>
                  <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                    {pa.payment_notices!.map(n => {
                      const doc = n.documents?.[0];
                      return (
                        <tr key={n.id} style={{ borderBottom: '1px solid var(--border)' }}>
                          <td className="px-4 py-3 text-xs font-mono" style={{ color: 'var(--gold)' }}>
                            {n.reference ?? `PN-${n.id}`}
                          </td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                            {n.notice_date ? formatDate(n.notice_date) : '—'}
                          </td>
                          <td className="px-4 py-3 text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                            {formatCurrency(fmt(n.notified_sum))}
                          </td>
                          <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{n.issued_by ?? '—'}</td>
                          <td className="px-4 py-3">
                            <span className="px-2 py-0.5 rounded-full text-xs capitalize" style={{ backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15' }}>
                              {n.status ?? 'issued'}
                            </span>
                          </td>
                          <td className="px-4 py-3">
                            {doc ? (
                              <button
                                onClick={() => downloadDoc(doc as DocRecord)}
                                className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                                style={{ backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15', border: '1px solid rgba(234,179,8,0.25)' }}>
                                <Download size={12} /> PDF
                              </button>
                            ) : <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          {/* Pay Less Notices */}
          <div>
            <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>
              Pay Less Notices
              {(pa.pay_less_notices?.length ?? 0) > 0 && (
                <span className="ml-2 px-1.5 py-0.5 rounded text-xs" style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' }}>
                  {pa.pay_less_notices!.length}
                </span>
              )}
            </h2>
            {!pa.pay_less_notices?.length ? (
              <div className="rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No Pay Less Notice issued for this application.</p>
              </div>
            ) : (
              <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                <table className="w-full text-sm">
                  <thead><tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                    {['Ref', 'Notice Date', 'Original Due', 'Deductions', 'Revised Payable', 'Reason', 'Status', 'PDF'].map(h => (
                      <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                    ))}
                  </tr></thead>
                  <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                    {pa.pay_less_notices!.map(n => {
                      const plnDoc = n.documents?.[0];
                      return (
                      <tr key={n.id} style={{ borderBottom: '1px solid var(--border)' }}>
                        <td className="px-4 py-3 text-xs font-mono" style={{ color: 'var(--text-muted)' }}>{n.reference ?? `PLN-${n.id}`}</td>
                        <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                          {n.notice_date ? formatDate(n.notice_date) : '—'}
                        </td>
                        <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>
                          {formatCurrency(fmt(n.original_amount_due ?? n.notified_sum))}
                        </td>
                        <td className="px-4 py-3 text-sm font-medium" style={{ color: '#f87171' }}>
                          {formatCurrency(fmt(n.total_deductions))}
                        </td>
                        <td className="px-4 py-3 text-sm font-bold" style={{ color: 'var(--text-primary)' }}>
                          {formatCurrency(fmt(n.revised_amount_payable ?? n.notified_sum))}
                        </td>
                        <td className="px-4 py-3 text-xs max-w-[160px]" style={{ color: 'var(--text-secondary)' }}>
                          <span className="line-clamp-2">{n.deduction_reason ?? '—'}</span>
                        </td>
                        <td className="px-4 py-3">
                          <span className="px-2 py-0.5 rounded-full text-xs capitalize" style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' }}>
                            {n.status ?? 'issued'}
                          </span>
                        </td>
                        <td className="px-4 py-3">
                          {plnDoc ? (
                            <button
                              onClick={() => downloadDoc(plnDoc as DocRecord)}
                              className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                              style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171', border: '1px solid rgba(239,68,68,0.25)' }}>
                              <Download size={12} /> PDF
                            </button>
                          ) : <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>}
                        </td>
                      </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>

        </div>
      )}

      {/* ─── Bottom floating save bar (breakdown tabs, draft) ─────────────────── */}
      {canEdit && ['measured-works', 'variations', 'materials'].includes(tab) && (
        <div className="fixed bottom-0 left-0 right-0 flex justify-center pb-4 z-20 pointer-events-none">
          <div className="pointer-events-auto flex items-center gap-3 px-5 py-3 rounded-2xl shadow-xl"
            style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <div className="text-sm" style={{ color: 'var(--text-secondary)' }}>
              Gross: <strong style={{ color: 'var(--gold)' }}>{formatCurrency(grossVal)}</strong>
              {useBreakdown && <span className="ml-3" style={{ color: 'var(--text-muted)' }}>
                (MW {formatCurrency(mwTotal)} + Linked {formatCurrency(linkedVarTotal)} + Manual VAR {formatCurrency(varTotal)} + MoS {formatCurrency(matTotal)})
              </span>}
              <span className="ml-3">→ Amount Due: <strong style={{ color: '#4ade80' }}>{formatCurrency(amountDue)}</strong></span>
            </div>
            <button onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}
              className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
              style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: saveMutation.isPending ? 0.6 : 1 }}>
              {saveMutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <Save size={14} />}
              Save
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
