'use client';

import { useState, useMemo, useRef, useEffect } from 'react';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { useSiteSettings } from '@/hooks/useSiteSettings';
import {
  DollarSign, Plus, FileText, X, CheckCircle, Send, Download,
  LayoutDashboard, Package, Bell, Percent, MoreHorizontal,
  Trash2, XCircle, AlertTriangle, CreditCard, Receipt,
  FileCheck, Banknote, Eye, RotateCcw,
} from 'lucide-react';
import { FinalAccountTab } from './FinalAccountTab';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PageTourButton from '@/components/tours/PageTourButton';

// ─── Types ───────────────────────────────────────────────────────────────────

type CommercialTab = 'overview' | 'applications' | 'trade-packages' | 'notices' | 'retention' | 'final-account';

type FormChangeEvent = React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>;

type ApiCollection<T> = { data?: T[] };

type ContractOption = {
  id: number;
  title: string;
  reference_number?: string | null;
  contract_sum?: number | null;
  retention_percentage?: number | null;
  retention_cap_percentage?: number | null;
  payment_terms_days?: number | null;
  party_name?: string | null;
  status?: string | null;
};

type TradePackageOption = {
  id: number;
  name: string;
  package_reference?: string | null;
  contractor_name?: string | null;
  status?: string | null;
};

type PaymentNoticeDocument = {
  id: number;
  title?: string | null;
  file_name?: string | null;
  file_size?: number | null;
  created_at?: string | null;
};

type PaymentNoticeRecord = {
  id: number;
  reference?: string | null;
  notice_date?: string | null;
  notified_sum?: number | string | null;
  basis_of_assessment?: string | null;
  issued_by?: string | null;
  status?: string | null;
  is_late?: boolean;
  payment_application_id?: number | null;
  payment_application?: {
    id: number;
    application_number: number;
    amount_due?: number | string | null;
    certified_amount?: number | string | null;
    status?: string | null;
    contract?: { id: number; title: string; reference_number?: string | null } | null;
    trade_package?: { id: number; name: string; package_reference?: string | null } | null;
  } | null;
  documents?: PaymentNoticeDocument[];
};

type PaymentApplication = {
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
  previous_gross_valuation?: number | string | null;
  previous_applications_count?: number | null;
  certified_amount?: number | string | null;
  certified_date?: string | null;
  certificate_reference?: string | null;
  certificate_notes?: string | null;
  paid_amount?: number | string | null;
  payment_date?: string | null;
  payment_reference?: string | null;
  status?: string | null;
  submitted_at?: string | null;
  certified_at?: string | null;
  paid_at?: string | null;
  cancelled_at?: string | null;
  withdrawal_count?: number | null;
  withdrawn_at?: string | null;
  withdrawal_reason?: string | null;
  contract_id?: number | null;
  trade_package_id?: number | null;
  contract?: { id: number; title: string; reference_number?: string | null; contract_sum?: number | null; party_name?: string | null } | null;
  trade_package?: { id: number; name: string; package_reference?: string | null } | null;
  notes?: string | null;
  payment_notices?: PaymentNoticeRecord[];
  pay_less_notices?: PayLessNoticeRecord[];
};

type PayLessNoticeRecord = {
  id: number;
  notice_date?: string | null;
  amount?: number | string | null;
  notified_sum?: number | string | null;
  total_deductions?: number | string | null;
  revised_amount_payable?: number | string | null;
  original_amount_due?: number | string | null;
  reason?: string | null;
  deduction_reason?: string | null;
  reference?: string | null;
  basis_of_difference?: string | null;
  issued_by?: string | null;
  status?: string | null;
  is_late?: boolean;
  payment_application_id?: number | null;
  payment_notice_id?: number | null;
  payment_application?: {
    id: number;
    application_number: number;
    amount_due?: number | string | null;
    certified_amount?: number | string | null;
    contract?: { id: number; title: string; reference_number?: string | null } | null;
    trade_package?: { id: number; name: string; package_reference?: string | null } | null;
  } | null;
  payment_notice?: { id: number; reference?: string | null; notified_sum?: number | string | null } | null;
  documents?: PaymentNoticeDocument[];
};

type RetentionRelease = {
  id: number;
  contract_id?: number | null;
  trade_package_id?: number | null;
  release_amount?: number | string | null;
  release_date?: string | null;
  release_reason?: string | null;
  moiety?: 'half_1' | 'half_2' | 'other' | null;
  notes?: string | null;
  contract?: { id: number; title: string } | null;
  trade_package?: { id: number; name: string } | null;
};

// ─── Constants ───────────────────────────────────────────────────────────────

const PA_STATUS: Record<string, { bg: string; text: string; label: string }> = {
  draft:                   { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490', label: 'Draft' },
  submitted:               { bg: 'rgba(234,179,8,0.12)',  text: '#facc15', label: 'Submitted' },
  pn_issued:               { bg: 'rgba(234,179,8,0.15)',  text: '#facc15', label: 'PN Issued' },
  pay_less_notice_issued:  { bg: 'rgba(239,68,68,0.12)',  text: '#f87171', label: 'PLN Issued' },
  certified:               { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80', label: 'Certified' },
  paid:                    { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa', label: 'Paid' },
  cancelled:               { bg: 'rgba(239,68,68,0.08)',  text: '#f87171', label: 'Cancelled' },
  disputed:                { bg: 'rgba(249,115,22,0.12)', text: '#fb923c', label: 'Disputed' },
};

const RETENTION_RELEASE_REASONS = [
  'Practical Completion',
  'Making Good Defects',
  'Final Account',
  'Manual Adjustment',
  'Other',
];

// ─── Lifecycle derivation ─────────────────────────────────────────────────────

function deriveEffectiveStatus(p: PaymentApplication): string {
  if (p.status === 'cancelled' || p.status === 'paid') return p.status ?? '';
  if ((p.pay_less_notices?.length ?? 0) > 0) return 'pay_less_notice_issued';
  if ((p.payment_notices?.length ?? 0) > 0) return 'pn_issued';
  return p.status ?? 'draft';
}

type HealthStatus = 'healthy' | 'action_required' | 'overdue' | 'paid' | 'cancelled';

function deriveHealthStatus(p: PaymentApplication, today: string): HealthStatus {
  if (p.status === 'cancelled') return 'cancelled';
  if (p.status === 'paid') return 'paid';
  if (p.status === 'draft') return 'healthy';

  const hasPN  = (p.payment_notices?.length ?? 0) > 0;
  const hasPLN = (p.pay_less_notices?.length ?? 0) > 0;

  if (p.final_date_for_payment && p.final_date_for_payment < today) return 'overdue';
  if (!hasPN && p.payment_notice_deadline && p.payment_notice_deadline < today) return 'overdue';
  if (hasPN && !hasPLN && p.pay_less_notice_deadline && p.pay_less_notice_deadline < today) return 'overdue';

  if (['submitted', 'certified'].includes(p.status ?? '')) return 'action_required';
  return 'healthy';
}

const HEALTH_DOT: Record<HealthStatus, string> = {
  healthy:         '#4ade80',
  action_required: '#fb923c',
  overdue:         '#f87171',
  paid:            '#60a5fa',
  cancelled:       '#6b7280',
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function getErrorMessage(error: unknown, fallback: string) {
  if (typeof error === 'object' && error !== null && 'response' in error) {
    const resp = (error as Record<string, unknown>).response as Record<string, unknown>;
    if (resp && 'data' in resp) {
      const d = resp.data as Record<string, unknown>;
      if (d && 'message' in d && typeof d.message === 'string') return d.message;
    }
  }
  return fallback;
}

function fmt(v: number | string | null | undefined): number {
  return typeof v === 'string' ? parseFloat(v) || 0 : Number(v) || 0;
}

function blobDownload(document: { id: number; file_name?: string }) {
  api.get(`/documents/${document.id}/download`, { responseType: 'blob' }).then(res => {
    const url = URL.createObjectURL(res.data);
    const a = window.document.createElement('a');
    a.href = url;
    a.download = document.file_name ?? 'document.pdf';
    a.click();
    URL.revokeObjectURL(url);
  });
}

// ─── Shared UI ───────────────────────────────────────────────────────────────

function InputField({ label, name, type = 'text', required = false, value, onChange, step, readOnly, hint }: {
  label: string; name: string; type?: string; required?: boolean;
  value: string; onChange?: (e: FormChangeEvent) => void;
  step?: string; readOnly?: boolean; hint?: string;
}) {
  return (
    <div>
      <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
      <input name={name} type={type} value={value} onChange={onChange} required={required} step={step}
        readOnly={readOnly}
        className="w-full px-3 py-2 rounded-lg text-sm outline-none"
        style={{
          backgroundColor: readOnly ? 'var(--bg-elevated)' : 'var(--bg-base)',
          border: '1px solid var(--border)',
          color: readOnly ? 'var(--text-muted)' : 'var(--text-primary)',
          cursor: readOnly ? 'default' : undefined,
        }}
      />
      {hint && <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  );
}

function SelectField({ label, name, required = false, value, onChange, children }: {
  label: string; name: string; required?: boolean; value: string;
  onChange: (e: FormChangeEvent) => void; children: React.ReactNode;
}) {
  return (
    <div>
      <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
      <select name={name} value={value} onChange={onChange} required={required}
        className="w-full px-3 py-2 rounded-lg text-sm outline-none"
        style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
        {children}
      </select>
    </div>
  );
}

function TextareaField({ label, name, required = false, value, onChange, rows = 2 }: {
  label: string; name: string; required?: boolean; value: string;
  onChange: (e: FormChangeEvent) => void; rows?: number;
}) {
  return (
    <div>
      <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
      <textarea name={name} value={value} onChange={onChange} required={required} rows={rows}
        className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
        style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
    </div>
  );
}

function SumCard({ label, value, color, sub, index = 0 }: { label: string; value: string; color: string; sub?: string; index?: number }) {
  return (
    <div className="ss-animate-in rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${index * 60}ms` }}>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-lg font-bold mt-1 leading-tight tabular-nums" style={{ color }}>{value}</p>
      {sub && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </div>
  );
}

function ModalWrap({ children, wide }: { children: React.ReactNode; wide?: boolean }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className={`w-full ${wide ? 'max-w-2xl' : 'max-w-lg'} rounded-2xl max-h-[92vh] overflow-y-auto ss-animate-in`}
        style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        {children}
      </div>
    </div>
  );
}

function ModalHeader({ title, sub, onClose }: { title: string; sub?: string; onClose: () => void }) {
  return (
    <div className="flex items-start justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
      <div>
        <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h2>
        {sub && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
      </div>
      <button onClick={onClose} className="mt-0.5"><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
    </div>
  );
}

function FinancialRow({ label, value, highlight, negative }: { label: string; value: string; highlight?: boolean; negative?: boolean }) {
  return (
    <div className="flex justify-between items-center py-2" style={{ borderBottom: '1px solid var(--border)' }}>
      <span className="text-xs" style={{ color: highlight ? 'var(--text-primary)' : 'var(--text-muted)' }}>{label}</span>
      <span className="text-sm font-semibold tabular-nums" style={{ color: negative ? '#f87171' : highlight ? 'var(--gold)' : 'var(--text-secondary)' }}>
        {negative && value !== '£0.00' ? `(${value})` : value}
      </span>
    </div>
  );
}

// ─── Status-based Row Actions Dropdown ───────────────────────────────────────

type ActionItem =
  | { kind: 'action'; label: string; icon: React.ElementType; color?: string; onClick: () => void }
  | { kind: 'divider' };

function RowActions({ items }: { items: ActionItem[] }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen(v => !v)}
        className="p-1.5 rounded-lg hover:opacity-80 transition-opacity"
        style={{ backgroundColor: open ? 'var(--bg-elevated)' : undefined }}
      >
        <MoreHorizontal size={15} style={{ color: 'var(--text-muted)' }} />
      </button>
      {open && (
        <div className="absolute right-0 top-full mt-1 z-30 min-w-[210px] rounded-xl shadow-xl overflow-hidden py-1"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          {items.map((item, i) => {
            if (item.kind === 'divider') {
              return <div key={i} className="my-1" style={{ borderTop: '1px solid var(--border)' }} />;
            }
            const Icon = item.icon;
            return (
              <button key={i} onClick={() => { item.onClick(); setOpen(false); }}
                className="flex items-center gap-2.5 w-full px-3 py-2 text-sm text-left hover:bg-[var(--bg-hover)] transition-colors"
                style={{ color: item.color ?? 'var(--text-secondary)' }}>
                <Icon size={13} />
                {item.label}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}

// ─── New Payment App Modal ────────────────────────────────────────────────────

function NewPaymentAppModal({ projectId, onClose, initialTradePackageId, initialContractId }: {
  projectId: string; onClose: () => void;
  initialTradePackageId?: number; initialContractId?: number;
}) {
  const formatCurrency = useCurrencyFormatter();
  const { data: siteSettings } = useSiteSettings();
  const currencySymbol = siteSettings?.currency_symbol ?? '£';
  const queryClient = useQueryClient();
  const router = useRouter();

  const [step, setStep] = useState<1 | 2>(initialTradePackageId || initialContractId ? 2 : 1);
  const [sourceType, setSourceType] = useState<'contract' | 'trade_package'>(initialTradePackageId ? 'trade_package' : 'contract');

  const { data: contractsData } = useQuery<ApiCollection<ContractOption>>({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data),
  });
  const contracts = (contractsData?.data ?? []).filter(c => c.status !== 'terminated');

  const { data: subData } = useQuery<{ trade_packages?: TradePackageOption[] }>({
    queryKey: ['project-subcontracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/documents/module/subcontracts`).then(r => r.data),
  });
  const tradePackages = subData?.trade_packages ?? [];

  const [contractId, setContractId] = useState(initialContractId ? String(initialContractId) : '');
  const [tradePackageId, setTradePackageId] = useState(initialTradePackageId ? String(initialTradePackageId) : '');
  const [form, setForm] = useState({
    application_date: new Date().toISOString().split('T')[0],
    valuation_period_start: '',
    valuation_period_end: '',
    due_date: '',
    final_date_for_payment: '',
    payment_notice_deadline: '',
    pay_less_notice_deadline: '',
    reference: '',
    gross_valuation: '',
    less_retention: '0',
    notes: '',
  });

  // Previous values fetched when source is chosen
  const [prevValues, setPrevValues] = useState<{
    previous_certified_value?: number;
    previous_paid_value?: number;
    previous_retention_held?: number;
    less_previous_payments?: number;
    previous_applications_count?: number;
  } | null>(null);

  const selectedContract = contracts.find(c => String(c.id) === contractId);

  // Server-calculated defaults (next number, statutory dates, carried-forward values).
  const [defaults, setDefaults] = useState<{
    application_number?: number;
    is_first_application?: boolean;
    suggested_reference?: string;
    valuation_period_start?: string | null;
    valuation_period_end?: string | null;
    dates?: Record<string, string | null>;
  } | null>(null);

  // Auto-fetch defaults whenever the source or application date changes, then pre-fill
  // the form. This surfaces the automation (dates, period, carry-forward) before saving.
  useEffect(() => {
    const sourceId = sourceType === 'contract' ? contractId : tradePackageId;
    if (!sourceId) { setPrevValues(null); setDefaults(null); return; }

    const params = new URLSearchParams({
      source: sourceType,
      [sourceType === 'contract' ? 'contract_id' : 'trade_package_id']: String(sourceId),
      application_date: form.application_date,
    });

    api.get(`/projects/${projectId}/payment-application-defaults?${params.toString()}`).then(r => {
      const d = r.data?.data;
      if (!d) return;
      setDefaults(d);
      setPrevValues({
        previous_certified_value:    d.previous?.previous_certified_value ?? 0,
        previous_paid_value:         d.previous?.previous_paid_value ?? 0,
        previous_retention_held:     d.previous?.previous_retention_held ?? 0,
        less_previous_payments:      d.previous?.less_previous_payments ?? 0,
        previous_applications_count: d.previous?.previous_applications_count ?? 0,
      });
      setForm(prev => ({
        ...prev,
        valuation_period_start:   d.valuation_period_start ?? prev.valuation_period_start,
        valuation_period_end:     d.valuation_period_end ?? prev.valuation_period_end,
        due_date:                 d.dates?.due_date ?? prev.due_date,
        final_date_for_payment:   d.dates?.final_date_for_payment ?? prev.final_date_for_payment,
        payment_notice_deadline:  d.dates?.payment_notice_deadline ?? prev.payment_notice_deadline,
        pay_less_notice_deadline: d.dates?.pay_less_notice_deadline ?? prev.pay_less_notice_deadline,
        reference:                prev.reference || d.suggested_reference || '',
      }));
    }).catch(() => { setPrevValues(null); setDefaults(null); });
  }, [contractId, tradePackageId, sourceType, projectId, form.application_date]);

  const handleContractChange = (newId: string) => {
    setContractId(newId);
    const c = contracts.find(ct => String(ct.id) === newId);
    if (c?.retention_percentage && form.gross_valuation) {
      setForm(prev => ({ ...prev, less_retention: String(Math.round(parseFloat(prev.gross_valuation) * (c.retention_percentage! / 100) * 100) / 100) }));
    }
  };

  const handleChange = (e: FormChangeEvent) => {
    if (e.target.name === 'gross_valuation') {
      const gross = parseFloat(e.target.value || '0');
      const retPct = selectedContract?.retention_percentage ?? 0;
      setForm(prev => ({ ...prev, gross_valuation: e.target.value, less_retention: retPct ? String(Math.round(gross * (retPct / 100) * 100) / 100) : prev.less_retention }));
      return;
    }
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  };

  const previousCertified = prevValues?.previous_certified_value ?? 0;
  const gross     = fmt(form.gross_valuation);
  const retention = fmt(form.less_retention);
  const amountDue = Math.max(0, gross - retention - previousCertified);

  const { mutate, isPending } = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        less_previous_payments: previousCertified,
      };
      if (sourceType === 'contract') return api.post(`/contracts/${contractId}/payment-applications`, payload).then(r => r.data);
      return api.post(`/projects/${projectId}/trade-packages/${tradePackageId}/payment-applications`, payload).then(r => r.data);
    },
    onSuccess: (data: { id: number }) => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('Payment application created');
      onClose();
      router.push(`/app/projects/${projectId}/commercial/applications/${data.id}`);
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to create')),
  });

  const canSubmit = step === 2 && (sourceType === 'contract' ? !!contractId : !!tradePackageId) && !!form.gross_valuation;

  return (
    <ModalWrap wide>
      <ModalHeader title="New Payment Application" sub={step === 1 ? 'Step 1: Choose source' : `Step 2: ${sourceType === 'contract' ? 'Main Contract' : 'Trade Package'}`} onClose={onClose} />
      <div className="p-5 space-y-4">
        {step === 1 && (
          <>
            <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>Which contract is this application for?</p>
            <div className="grid grid-cols-2 gap-3">
              {([{ type: 'contract' as const, label: 'Main Contract', icon: FileText }, { type: 'trade_package' as const, label: 'Trade Package', icon: Package }]).map(opt => (
                <button key={opt.type} onClick={() => { setSourceType(opt.type); setStep(2); }}
                  className="flex flex-col items-start gap-2 p-4 rounded-xl text-left transition-all duration-150 hover:-translate-y-0.5 hover:shadow-md"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '2px solid rgba(0,0,0,0.08)' }}>
                  <opt.icon size={20} style={{ color: 'var(--gold)' }} />
                  <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{opt.label}</span>
                </button>
              ))}
            </div>
          </>
        )}
        {step === 2 && (
          <form onSubmit={e => { e.preventDefault(); mutate(); }} className="space-y-4">
            {sourceType === 'contract' ? (
              <div>
                <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Contract *</label>
                <select value={contractId} onChange={e => handleContractChange(e.target.value)} required
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
                  <option value="">Select contract…</option>
                  {contracts.map(c => <option key={c.id} value={c.id}>{c.title}</option>)}
                </select>
                {selectedContract?.retention_percentage && (
                  <p className="text-xs mt-1" style={{ color: '#facc15' }}>Retention {selectedContract.retention_percentage}% (auto-calculated)</p>
                )}
              </div>
            ) : (
              <div>
                <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Trade Package *</label>
                <select value={tradePackageId} onChange={e => setTradePackageId(e.target.value)} required
                  className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
                  <option value="">Select package…</option>
                  {tradePackages.map(tp => <option key={tp.id} value={tp.id}>{tp.name}{tp.contractor_name ? ` (${tp.contractor_name})` : ''}</option>)}
                </select>
              </div>
            )}

            {/* Application number — auto-detected (first vs subsequent) */}
            {defaults?.application_number != null && (
              <div className="flex items-center gap-2 rounded-lg px-3 py-2 text-xs" style={{ backgroundColor: 'var(--gold-15)', border: '1px solid var(--gold-30)', color: 'var(--gold)' }}>
                <span className="font-semibold">Application #{defaults.application_number}</span>
                <span style={{ color: 'var(--text-muted)' }}>
                  {defaults.is_first_application
                    ? '(first application for this source)'
                    : '(subsequent application; previous certified value is carried forward automatically)'}
                </span>
              </div>
            )}

            {/* Previous values banner */}
            {prevValues && (prevValues.previous_applications_count ?? 0) > 0 && (
              <div className="rounded-xl p-3 space-y-2" style={{ backgroundColor: 'rgba(96,165,250,0.08)', border: '1px solid rgba(96,165,250,0.2)' }}>
                <p className="text-xs font-semibold" style={{ color: '#60a5fa' }}>PREVIOUS APPLICATION DATA (auto-calculated)</p>
                <div className="grid grid-cols-3 gap-2">
                  {[
                    { label: 'Prev. Certified', value: formatCurrency(prevValues.previous_certified_value ?? 0) },
                    { label: 'Prev. Paid', value: formatCurrency(prevValues.previous_paid_value ?? 0) },
                    { label: 'Retention Held', value: formatCurrency(prevValues.previous_retention_held ?? 0) },
                  ].map(item => (
                    <div key={item.label}>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{item.label}</p>
                      <p className="text-sm font-semibold" style={{ color: '#60a5fa' }}>{item.value}</p>
                    </div>
                  ))}
                </div>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  Previous Certified Value ({formatCurrency(previousCertified)}) will be deducted automatically as &quot;Less: Previous Certified&quot;.
                </p>
              </div>
            )}

            <div className="grid grid-cols-2 gap-3">
              <InputField label="Application Date" name="application_date" type="date" value={form.application_date} onChange={handleChange} required />
              <InputField label="Reference" name="reference" value={form.reference} onChange={handleChange} />
            </div>
            {sourceType === 'contract' && defaults?.dates && Object.values(defaults.dates).some(Boolean) && (
              <p className="text-xs" style={{ color: '#facc15' }}>
                Dates below auto-calculated from the contract/AI analysis, adjust if needed.
              </p>
            )}
            <div className="grid grid-cols-2 gap-3">
              <InputField label="Valuation Period Start" name="valuation_period_start" type="date" value={form.valuation_period_start} onChange={handleChange} />
              <InputField label="Valuation Period End" name="valuation_period_end" type="date" value={form.valuation_period_end} onChange={handleChange} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <InputField label="Payment Due Date" name="due_date" type="date" value={form.due_date} onChange={handleChange} />
              <InputField label="Final Date for Payment" name="final_date_for_payment" type="date" value={form.final_date_for_payment} onChange={handleChange} />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <InputField label="Payment Notice Deadline" name="payment_notice_deadline" type="date" value={form.payment_notice_deadline} onChange={handleChange} />
              <InputField label="Pay Less Notice Deadline" name="pay_less_notice_deadline" type="date" value={form.pay_less_notice_deadline} onChange={handleChange} />
            </div>

            <div className="rounded-xl p-4 space-y-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
              <p className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>FINANCIAL SUMMARY</p>
              <InputField label={`Current Gross Valuation (${currencySymbol})`} name="gross_valuation" type="number" step="0.01" value={form.gross_valuation} onChange={handleChange} required />
              <InputField label={`Less: Retention (${currencySymbol})`} name="less_retention" type="number" step="0.01" value={form.less_retention} onChange={handleChange} />
              <div className="flex justify-between items-center py-1.5" style={{ borderTop: '1px solid var(--border)' }}>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Less: Previous Certified Value</span>
                <span className="text-sm font-semibold" style={{ color: '#f87171' }}>({formatCurrency(previousCertified)})</span>
              </div>
              <div className="flex justify-between items-center pt-2" style={{ borderTop: '1px solid var(--border)' }}>
                <span className="text-xs font-semibold" style={{ color: 'var(--text-secondary)' }}>Amount Applied For</span>
                <span className="text-base font-bold" style={{ color: 'var(--gold)' }}>{formatCurrency(amountDue)}</span>
              </div>
            </div>
            <TextareaField label="Notes" name="notes" value={form.notes} onChange={handleChange} />
            <div className="flex justify-between gap-3 pt-2">
              <button type="button" onClick={() => setStep(1)} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>← Back</button>
              <div className="flex gap-3">
                <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
                <button type="submit" disabled={isPending || !canSubmit} className="px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: (!canSubmit || isPending) ? 0.6 : 1 }}>
                  {isPending ? 'Creating…' : 'Create Application'}
                </button>
              </div>
            </div>
          </form>
        )}
      </div>
    </ModalWrap>
  );
}

// ─── Certify Modal ────────────────────────────────────────────────────────────

function CertifyModal({ pa, projectId, onClose }: { pa: PaymentApplication; projectId: string; onClose: () => void }) {
  const formatCurrency = useCurrencyFormatter();
  const { data: siteSettings } = useSiteSettings();
  const currencySymbol = siteSettings?.currency_symbol ?? '£';
  const queryClient = useQueryClient();
  const [certifiedAmount, setCertifiedAmount] = useState(String(fmt(pa.amount_due) > 0 ? fmt(pa.amount_due) : ''));
  const [certifiedDate, setCertifiedDate] = useState(new Date().toISOString().split('T')[0]);
  const [certRef, setCertRef] = useState('');
  const [certNotes, setCertNotes] = useState('');

  const diff = fmt(certifiedAmount) - fmt(pa.amount_due);

  const { mutate, isPending } = useMutation({
    mutationFn: () => api.post(`/payment-applications/${pa.id}/certify`, {
      certified_amount: certifiedAmount,
      certified_date: certifiedDate,
      certificate_reference: certRef || undefined,
      certificate_notes: certNotes || undefined,
    }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('Application certified, certificate PDF saved to documents');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Certification failed')),
  });

  const source = pa.trade_package ? `Trade Package: ${pa.trade_package.name}` : pa.contract ? `Main Contract: ${pa.contract.title}` : 'Unknown';

  return (
    <ModalWrap wide>
      <ModalHeader title={`Certify Application #${pa.application_number}`} sub="Review the application and enter certified amount" onClose={onClose} />
      <div className="p-5 space-y-5">
        <div className="rounded-xl p-4 space-y-1" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <p className="text-xs font-semibold mb-2" style={{ color: 'var(--text-muted)' }}>APPLICATION SUMMARY</p>
          <FinancialRow label="Application Number" value={`#${pa.application_number}`} />
          <FinancialRow label="Commercial Source" value={source} />
          {pa.contract?.contract_sum && <FinancialRow label="Contract Value" value={formatCurrency(pa.contract.contract_sum)} />}
          <FinancialRow label="Gross Valuation" value={formatCurrency(fmt(pa.gross_valuation))} />
          <FinancialRow label="Less: Retention" value={formatCurrency(fmt(pa.less_retention))} negative />
          <FinancialRow label="Less: Previous Certified Value" value={formatCurrency(fmt(pa.less_previous_payments))} negative />
          <FinancialRow label="Amount Applied For" value={formatCurrency(fmt(pa.amount_due))} highlight />
          {fmt(pa.previous_paid_value) > 0 && (
            <div className="pt-1 mt-1" style={{ borderTop: '1px solid var(--border)' }}>
              <FinancialRow label="Previous Certified Value" value={formatCurrency(fmt(pa.previous_certified_value))} />
              <FinancialRow label="Previous Paid Value" value={formatCurrency(fmt(pa.previous_paid_value))} />
            </div>
          )}
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Certified Amount ({currencySymbol}) *</label>
            <input type="number" step="0.01" value={certifiedAmount} onChange={e => setCertifiedAmount(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none font-bold"
              style={{ backgroundColor: 'var(--bg-base)', border: '2px solid var(--gold)', color: 'var(--text-primary)' }} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Certification Date *</label>
            <input type="date" value={certifiedDate} onChange={e => setCertifiedDate(e.target.value)}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
        </div>
        <InputField label="Certificate Reference (optional)" name="cert_ref" value={certRef} onChange={e => setCertRef(e.target.value)} />
        <TextareaField label="Notes / Reason for Difference (optional)" name="cert_notes" value={certNotes} onChange={e => setCertNotes(e.target.value)} />

        {certifiedAmount && (
          <div className="rounded-xl p-3 flex justify-between items-center" style={{ backgroundColor: Math.abs(diff) < 0.01 ? 'var(--bg-elevated)' : diff < 0 ? 'rgba(239,68,68,0.08)' : 'rgba(34,197,94,0.08)', border: `1px solid ${Math.abs(diff) < 0.01 ? 'var(--border)' : diff < 0 ? 'rgba(239,68,68,0.3)' : 'rgba(34,197,94,0.3)'}` }}>
            <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Difference from applied amount</span>
            <span className="text-sm font-bold" style={{ color: Math.abs(diff) < 0.01 ? 'var(--text-muted)' : diff < 0 ? '#f87171' : '#4ade80' }}>
              {diff >= 0 ? '+' : ''}{formatCurrency(diff)}
            </span>
          </div>
        )}

        <div className="flex justify-end gap-3 pt-2">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutate()} disabled={isPending || !certifiedAmount} className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: '#4ade80', color: '#000', opacity: (isPending || !certifiedAmount) ? 0.6 : 1 }}>
            <CheckCircle size={14} />
            {isPending ? 'Certifying…' : 'Certify Application'}
          </button>
        </div>
      </div>
    </ModalWrap>
  );
}

// ─── Mark Paid Modal ──────────────────────────────────────────────────────────

function MarkPaidModal({ pa, projectId, onClose }: { pa: PaymentApplication; projectId: string; onClose: () => void }) {
  const formatCurrency = useCurrencyFormatter();
  const { data: siteSettings } = useSiteSettings();
  const currencySymbol = siteSettings?.currency_symbol ?? '£';
  const queryClient = useQueryClient();
  const [form, setForm] = useState({
    paid_amount: String(fmt(pa.certified_amount) > 0 ? fmt(pa.certified_amount) : fmt(pa.amount_due)),
    payment_date: new Date().toISOString().split('T')[0],
    payment_reference: '',
    notes: '',
  });

  const { mutate, isPending } = useMutation({
    mutationFn: () => api.post(`/payment-applications/${pa.id}/mark-paid`, form).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('Marked as paid');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed')),
  });

  const handleChange = (e: FormChangeEvent) => setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));

  return (
    <ModalWrap>
      <ModalHeader title={`Mark Application #${pa.application_number} as Paid`} sub={`Certified: ${formatCurrency(fmt(pa.certified_amount))}`} onClose={onClose} />
      <div className="p-5 space-y-4">
        <div className="grid grid-cols-2 gap-4">
          <InputField label={`Paid Amount (${currencySymbol}) *`} name="paid_amount" type="number" step="0.01" value={form.paid_amount} onChange={handleChange} required />
          <InputField label="Payment Date *" name="payment_date" type="date" value={form.payment_date} onChange={handleChange} required />
        </div>
        <InputField label="Payment Reference" name="payment_reference" value={form.payment_reference} onChange={handleChange} />
        <TextareaField label="Notes" name="notes" value={form.notes} onChange={handleChange} />
        <div className="flex justify-end gap-3 pt-2">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutate()} disabled={isPending || !form.paid_amount} className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: '#60a5fa', color: '#000', opacity: (isPending || !form.paid_amount) ? 0.6 : 1 }}>
            <CreditCard size={14} />
            {isPending ? 'Saving…' : 'Mark as Paid'}
          </button>
        </div>
      </div>
    </ModalWrap>
  );
}

// ─── Payment Notice Modal ─────────────────────────────────────────────────────

function PaymentNoticeModal({ pa, projectId, onClose }: { pa: PaymentApplication; projectId: string; onClose: () => void }) {
  const formatCurrency = useCurrencyFormatter();
  const { data: siteSettings } = useSiteSettings();
  const currencySymbol = siteSettings?.currency_symbol ?? '£';
  const queryClient = useQueryClient();
  const notifiedDefault = fmt(pa.certified_amount) > 0 ? fmt(pa.certified_amount) : fmt(pa.amount_due);

  const [form, setForm] = useState({
    notice_date: new Date().toISOString().split('T')[0],
    reference: '',
    notified_sum: String(notifiedDefault),
    basis_of_assessment: '',
    issued_by: '',
  });

  const handleChange = (e: FormChangeEvent) => setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));

  const { mutate, isPending } = useMutation({
    mutationFn: () => api.post(`/payment-applications/${pa.id}/payment-notice`, form).then(r => r.data),
    onSuccess: (data: { notice: PaymentNoticeRecord; document?: PaymentNoticeDocument | null }) => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-payment-notices', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      if (data.document?.id) {
        blobDownload(data.document as { id: number; file_name?: string });
        toast.success('Payment Notice issued, downloading PDF');
      } else {
        toast.success('Payment Notice issued, PDF saved to documents');
      }
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to create')),
  });

  const diff = fmt(pa.amount_due) - fmt(form.notified_sum);
  const source = pa.trade_package ? `Trade Package: ${pa.trade_package.name}` : pa.contract ? `Main Contract: ${pa.contract.title}` : 'Unknown';

  return (
    <ModalWrap wide>
      <ModalHeader title={`Payment Notice: Application #${pa.application_number}`} sub="Issue a formal Payment Notice" onClose={onClose} />
      <div className="p-5 space-y-5">
        <div className="rounded-xl p-4 space-y-1" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <p className="text-xs font-semibold mb-2" style={{ color: 'var(--text-muted)' }}>APPLICATION DETAILS</p>
          <FinancialRow label="Application Number" value={`#${pa.application_number}`} />
          <FinancialRow label="Commercial Source" value={source} />
          <FinancialRow label="Amount Applied For" value={formatCurrency(fmt(pa.amount_due))} />
          {fmt(pa.certified_amount) > 0 && <FinancialRow label="Certified Amount" value={formatCurrency(fmt(pa.certified_amount))} />}
          {pa.due_date && <FinancialRow label="Payment Due Date" value={formatDate(pa.due_date)} />}
          {pa.final_date_for_payment && <FinancialRow label="Final Date for Payment" value={formatDate(pa.final_date_for_payment)} />}
          {pa.payment_notice_deadline && <FinancialRow label="Payment Notice Deadline" value={formatDate(pa.payment_notice_deadline)} />}
        </div>

        {pa.payment_notice_deadline && new Date(form.notice_date) > new Date(pa.payment_notice_deadline) && (
          <div className="rounded-xl p-3 text-xs font-medium" style={{ backgroundColor: 'rgba(251,146,60,0.1)', border: '1px solid rgba(251,146,60,0.3)', color: '#fb923c' }}>
            This notice date is after the Payment Notice Deadline ({formatDate(pa.payment_notice_deadline)}). The notice will still be issued, but will be recorded as late.
          </div>
        )}

        <div className="grid grid-cols-2 gap-4">
          <InputField label="Notice Date *" name="notice_date" type="date" value={form.notice_date} onChange={handleChange} required />
          <InputField label="Payment Notice Reference" name="reference" value={form.reference} onChange={handleChange} />
        </div>
        <div>
          <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notified Sum ({currencySymbol}) *</label>
          <input type="number" name="notified_sum" step="0.01" value={form.notified_sum}
            onChange={handleChange}
            className="w-full px-3 py-2 rounded-lg text-sm outline-none font-bold"
            style={{ backgroundColor: 'var(--bg-base)', border: '2px solid var(--gold)', color: 'var(--text-primary)' }} />
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Pre-filled from certified amount (or amount applied for). Override if needed.</p>
        </div>
        <TextareaField label="Basis of Assessment / Notes" name="basis_of_assessment" value={form.basis_of_assessment} onChange={handleChange} rows={3} />
        <InputField label="Issued By" name="issued_by" value={form.issued_by} onChange={handleChange} hint="Name of the person / organisation issuing this notice" />

        {form.notified_sum && (
          <div className="rounded-xl p-3 space-y-1" style={{ backgroundColor: Math.abs(diff) < 0.01 ? 'var(--bg-elevated)' : 'rgba(234,179,8,0.08)', border: `1px solid ${Math.abs(diff) < 0.01 ? 'var(--border)' : 'rgba(234,179,8,0.3)'}` }}>
            <FinancialRow label="Amount Applied For" value={formatCurrency(fmt(pa.amount_due))} />
            <FinancialRow label="Notified Sum" value={formatCurrency(fmt(form.notified_sum))} highlight />
            {Math.abs(diff) > 0.01 && (
              <div className="flex justify-between items-center pt-1">
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Difference</span>
                <span className="text-sm font-bold" style={{ color: diff > 0 ? '#f87171' : '#4ade80' }}>
                  {diff > 0 ? `(${formatCurrency(diff)})` : `+${formatCurrency(Math.abs(diff))}`}
                </span>
              </div>
            )}
          </div>
        )}

        <div className="flex justify-end gap-3 pt-2">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutate()} disabled={isPending || !form.notified_sum}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium active:scale-[0.98]"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: (isPending || !form.notified_sum) ? 0.6 : 1 }}>
            <FileCheck size={14} />
            {isPending ? 'Issuing…' : 'Issue Payment Notice'}
          </button>
        </div>
      </div>
    </ModalWrap>
  );
}

// ─── Pay Less Notice Modal ────────────────────────────────────────────────────

function PayLessNoticeModal({ pa, projectId, onClose }: { pa: PaymentApplication; projectId: string; onClose: () => void }) {
  const formatCurrency = useCurrencyFormatter();
  const queryClient = useQueryClient();

  const latestPN = pa.payment_notices?.[0];
  const originalAmountDue = latestPN
    ? fmt(latestPN.notified_sum)
    : (fmt(pa.certified_amount) > 0 ? fmt(pa.certified_amount) : fmt(pa.amount_due));

  const [form, setForm] = useState({
    notice_date: new Date().toISOString().split('T')[0],
    reference: '',
    original_amount_due: String(originalAmountDue),
    total_deductions: '',
    deduction_reason: '',
    detailed_deduction_notes: '',
    issued_by: '',
    payment_notice_id: latestPN ? String(latestPN.id) : '',
  });

  const handleChange = (e: FormChangeEvent) => setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));

  const revisedPayable = Math.max(0, fmt(form.original_amount_due) - fmt(form.total_deductions));

  const { mutate, isPending } = useMutation({
    mutationFn: () => api.post(`/payment-applications/${pa.id}/pay-less-notice`, form).then(r => r.data),
    onSuccess: (data: { notice: PayLessNoticeRecord; document?: { id: number; file_name?: string } }) => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-pay-less-notices', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Pay Less Notice issued, PDF saved to documents');
      if (data.document) blobDownload(data.document);
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to create')),
  });

  const source = pa.trade_package ? `Trade Package: ${pa.trade_package.name}` : pa.contract ? `Main Contract: ${pa.contract.title}` : 'Unknown';

  return (
    <ModalWrap wide>
      <ModalHeader title={`Pay Less Notice: Application #${pa.application_number}`} sub="Issue a formal Pay Less Notice" onClose={onClose} />
      <div className="p-5 space-y-5">
        <div className="rounded-xl p-4 space-y-1" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          <p className="text-xs font-semibold mb-2" style={{ color: 'var(--text-muted)' }}>APPLICATION DETAILS</p>
          <FinancialRow label="Application Number" value={`#${pa.application_number}`} />
          <FinancialRow label="Commercial Source" value={source} />
          <FinancialRow label="Amount Applied For" value={formatCurrency(fmt(pa.amount_due))} />
          {fmt(pa.certified_amount) > 0 && <FinancialRow label="Certified Amount" value={formatCurrency(fmt(pa.certified_amount))} />}
          {latestPN && <FinancialRow label={`Payment Notice (${latestPN.reference ?? 'PN'})`} value={formatCurrency(fmt(latestPN.notified_sum))} />}
          {pa.due_date && <FinancialRow label="Payment Due Date" value={formatDate(pa.due_date)} />}
          {pa.pay_less_notice_deadline && <FinancialRow label="PLN Deadline" value={formatDate(pa.pay_less_notice_deadline)} />}
        </div>

        {!latestPN && (
          <div className="rounded-xl p-3 flex gap-2.5" style={{ backgroundColor: 'rgba(249,115,22,0.07)', border: '1px solid rgba(249,115,22,0.25)' }}>
            <AlertTriangle size={14} className="flex-shrink-0 mt-0.5" style={{ color: '#fb923c' }} />
            <div>
              <p className="text-xs font-medium" style={{ color: '#fb923c' }}>No Payment Notice has been issued for this application.</p>
              <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>This Pay Less Notice will be based on the application amount / applicable notified sum fallback. Please review the statutory position before issuing.</p>
            </div>
          </div>
        )}
        {pa.pay_less_notice_deadline && new Date(form.notice_date) > new Date(pa.pay_less_notice_deadline) && (
          <div className="rounded-xl p-3 text-xs font-medium" style={{ backgroundColor: 'rgba(251,146,60,0.1)', border: '1px solid rgba(251,146,60,0.3)', color: '#fb923c' }}>
            This notice date is after the Pay Less Notice Deadline ({formatDate(pa.pay_less_notice_deadline)}). The notice will still be issued, but will be recorded as late.
          </div>
        )}

        <div className="grid grid-cols-2 gap-4">
          <InputField label="Notice Date *" name="notice_date" type="date" value={form.notice_date} onChange={handleChange} required />
          <InputField label="Reference (optional)" name="reference" value={form.reference} onChange={handleChange} />
        </div>
        <InputField label="Notified Sum *" name="original_amount_due" type="number" step="0.01" value={form.original_amount_due} onChange={handleChange} required hint={latestPN ? 'Pre-filled from the latest Payment Notice. Override only if required.' : 'No Payment Notice issued. Using application amount as fallback.'} />
        <InputField label="Total Deductions *" name="total_deductions" type="number" step="0.01" value={form.total_deductions} onChange={handleChange} required />
        <TextareaField label="Basis of Calculation *" name="deduction_reason" value={form.deduction_reason} onChange={handleChange} required rows={3} />
        <TextareaField label="Detailed Deduction Notes (optional)" name="detailed_deduction_notes" value={form.detailed_deduction_notes} onChange={handleChange} />
        <InputField label="Issued By" name="issued_by" value={form.issued_by} onChange={handleChange} />

        {form.total_deductions && (
          <div className="rounded-xl p-3 space-y-1" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.3)' }}>
            <FinancialRow label="Notified Sum" value={formatCurrency(fmt(form.original_amount_due))} />
            <FinancialRow label="Total Deductions" value={formatCurrency(fmt(form.total_deductions))} negative />
            <FinancialRow label="Revised Amount Payable" value={formatCurrency(revisedPayable)} highlight />
          </div>
        )}

        <div className="flex justify-end gap-3 pt-2">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutate()} disabled={isPending || !form.total_deductions || !form.deduction_reason}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
            style={{ backgroundColor: '#f87171', color: '#000', opacity: (isPending || !form.total_deductions || !form.deduction_reason) ? 0.6 : 1 }}>
            <AlertTriangle size={14} />
            {isPending ? 'Issuing…' : 'Issue Pay Less Notice'}
          </button>
        </div>
      </div>
    </ModalWrap>
  );
}

// ─── Delete Confirm Modal ─────────────────────────────────────────────────────

function DeleteConfirmModal({ pa, projectId, onClose }: { pa: PaymentApplication; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();

  const { mutate, isPending } = useMutation({
    mutationFn: () => api.delete(`/payment-applications/${pa.id}`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Application deleted');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Delete failed')),
  });

  return (
    <ModalWrap>
      <ModalHeader title="Delete Payment Application" onClose={onClose} />
      <div className="p-5 space-y-4">
        <div className="rounded-xl p-4 flex gap-3" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.3)' }}>
          <AlertTriangle size={18} className="shrink-0 mt-0.5" style={{ color: '#f87171' }} />
          <div>
            <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Delete Application #{pa.application_number}?</p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>This action cannot be undone. Only draft applications can be deleted.</p>
          </div>
        </div>
        <div className="flex justify-end gap-3">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutate()} disabled={isPending} className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: '#f87171', color: '#fff', opacity: isPending ? 0.6 : 1 }}>
            <Trash2 size={13} />
            {isPending ? 'Deleting…' : 'Delete Application'}
          </button>
        </div>
      </div>
    </ModalWrap>
  );
}

// ─── Retention Release Modal ──────────────────────────────────────────────────

const MOIETY_REASON_DEFAULT: Record<string, string> = {
  half_1: 'Practical Completion',
  half_2: 'Making Good Defects',
  other:  'Manual Adjustment',
};

function ReleaseRetentionModal({
  projectId, contractId, tradePackageId, maxRelease,
  totalRetentionHeld, half1Released, half2Released, initialMoiety, onClose,
}: {
  projectId: string;
  contractId?: number;
  tradePackageId?: number;
  maxRelease: number;
  totalRetentionHeld: number;
  half1Released: number;
  half2Released: number;
  initialMoiety?: 'half_1' | 'half_2' | 'other';
  onClose: () => void;
}) {
  const formatCurrency = useCurrencyFormatter();
  const queryClient = useQueryClient();

  const half1Target    = totalRetentionHeld / 2;
  const half2Target    = totalRetentionHeld / 2;
  const half1Outstanding = Math.max(0, half1Target - half1Released);
  const half2Outstanding = Math.max(0, half2Target - half2Released);

  const suggestedAmount = (moiety: string): string => {
    if (moiety === 'half_1') return half1Outstanding > 0 ? half1Outstanding.toFixed(2) : '';
    if (moiety === 'half_2') return half2Outstanding > 0 ? half2Outstanding.toFixed(2) : '';
    return '';
  };

  const startMoiety = initialMoiety ?? 'other';
  const [form, setForm] = useState({
    moiety:         startMoiety,
    release_amount: suggestedAmount(startMoiety),
    release_date:   new Date().toISOString().split('T')[0],
    release_reason: MOIETY_REASON_DEFAULT[startMoiety] ?? RETENTION_RELEASE_REASONS[0],
    notes:          '',
  });

  const handleChange = (e: FormChangeEvent) => {
    const { name, value } = e.target;
    setForm(prev => {
      const next = { ...prev, [name]: value };
      if (name === 'moiety') {
        next.release_amount = suggestedAmount(value);
        next.release_reason = MOIETY_REASON_DEFAULT[value] ?? prev.release_reason;
      }
      return next;
    });
  };

  const overMax = fmt(form.release_amount) > maxRelease;
  const moietyAlreadyReleased =
    (form.moiety === 'half_1' && half1Target > 0 && half1Released >= half1Target) ||
    (form.moiety === 'half_2' && half2Target > 0 && half2Released >= half2Target);

  const { mutate, isPending } = useMutation({
    mutationFn: () => api.post(`/projects/${projectId}/retention-releases`, {
      ...form,
      contract_id:      contractId ?? undefined,
      trade_package_id: tradePackageId ?? undefined,
    }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-retention-releases', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Retention released');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed')),
  });

  const canSubmit = !isPending && !!form.release_amount && !overMax;

  return (
    <ModalWrap>
      <ModalHeader title="Release Retention" sub={`Current retention balance: ${formatCurrency(maxRelease)}`} onClose={onClose} />
      <div className="p-5 space-y-4">

        {/* Moiety selector */}
        <div>
          <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-muted)' }}>RELEASE MOIETY</p>
          <div className="grid grid-cols-3 gap-2">
            {(['half_1', 'half_2', 'other'] as const).map(m => (
              <button key={m} onClick={() => setForm(prev => {
                const next = { ...prev, moiety: m, release_amount: suggestedAmount(m), release_reason: MOIETY_REASON_DEFAULT[m] ?? prev.release_reason };
                return next;
              })}
                className="py-2 px-3 rounded-lg text-xs text-left transition-all"
                style={{
                  backgroundColor: form.moiety === m ? 'rgba(74,222,128,0.12)' : 'var(--bg-elevated)',
                  border: `1px solid ${form.moiety === m ? 'rgba(74,222,128,0.4)' : 'var(--border)'}`,
                  color: form.moiety === m ? '#4ade80' : 'var(--text-secondary)',
                }}>
                <span className="font-semibold block">{m === 'half_1' ? 'Half 1' : m === 'half_2' ? 'Half 2' : 'Other'}</span>
                <span style={{ color: 'var(--text-muted)', fontSize: '10px' }}>
                  {m === 'half_1' ? 'Practical Completion' : m === 'half_2' ? 'Making Good Defects' : 'Manual Adjustment'}
                </span>
              </button>
            ))}
          </div>
        </div>

        {/* Moiety already released warning */}
        {moietyAlreadyReleased && (
          <div className="rounded-xl p-3 flex gap-2" style={{ backgroundColor: 'rgba(234,179,8,0.08)', border: '1px solid rgba(234,179,8,0.3)' }}>
            <AlertTriangle size={14} style={{ color: '#facc15', flexShrink: 0, marginTop: 2 }} />
            <p className="text-xs" style={{ color: '#facc15' }}>
              This moiety has already been fully released ({formatCurrency(form.moiety === 'half_1' ? half1Released : half2Released)} of {formatCurrency(half1Target)} target).
              You can still record an additional adjustment if needed.
            </p>
          </div>
        )}

        {/* Amount / date */}
        <div className="grid grid-cols-2 gap-4">
          <InputField label="Release Amount *" name="release_amount" type="number" step="0.01" value={form.release_amount} onChange={handleChange} required hint={overMax ? `Max: ${formatCurrency(maxRelease)}` : (form.moiety !== 'other' && form.release_amount ? `Suggested: ${formatCurrency(fmt(form.release_amount))}` : undefined)} />
          <InputField label="Release Date *" name="release_date" type="date" value={form.release_date} onChange={handleChange} required />
        </div>

        {overMax && (
          <div className="rounded-xl p-3 flex gap-2" style={{ backgroundColor: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.3)' }}>
            <AlertTriangle size={14} style={{ color: '#f87171', flexShrink: 0, marginTop: 2 }} />
            <p className="text-xs" style={{ color: '#f87171' }}>Release amount exceeds current retention balance ({formatCurrency(maxRelease)}).</p>
          </div>
        )}

        <SelectField label="Release Reason *" name="release_reason" value={form.release_reason} onChange={handleChange} required>
          {RETENTION_RELEASE_REASONS.map(r => <option key={r} value={r}>{r}</option>)}
        </SelectField>
        <TextareaField label="Notes (optional)" name="notes" value={form.notes} onChange={handleChange} />
        <div className="flex justify-end gap-3 pt-2">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutate()} disabled={!canSubmit}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium"
            style={{ backgroundColor: '#4ade80', color: '#000', opacity: canSubmit ? 1 : 0.6 }}>
            <Banknote size={14} />
            {isPending ? 'Releasing…' : 'Release Retention'}
          </button>
        </div>
      </div>
    </ModalWrap>
  );
}

// ─── Payment Details Modal ────────────────────────────────────────────────────

function PaymentDetailsModal({ pa, onClose }: { pa: PaymentApplication; onClose: () => void }) {
  const formatCurrency = useCurrencyFormatter();
  const badge = PA_STATUS[pa.status ?? ''] ?? PA_STATUS.draft;
  return (
    <ModalWrap>
      <ModalHeader title={`Payment Details: Application #${pa.application_number}`} onClose={onClose} />
      <div className="p-5 space-y-1">
        <FinancialRow label="Status" value={badge.label} />
        {fmt(pa.paid_amount) > 0 && <FinancialRow label="Paid Amount" value={formatCurrency(fmt(pa.paid_amount))} highlight />}
        {pa.payment_date && <FinancialRow label="Payment Date" value={formatDate(pa.payment_date)} />}
        {pa.payment_reference && <FinancialRow label="Payment Reference" value={pa.payment_reference} />}
        {fmt(pa.certified_amount) > 0 && <FinancialRow label="Certified Amount" value={formatCurrency(fmt(pa.certified_amount))} />}
        {pa.certified_date && <FinancialRow label="Certified Date" value={formatDate(pa.certified_date)} />}
        <div className="flex justify-end pt-3">
          <button onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Close</button>
        </div>
      </div>
    </ModalWrap>
  );
}

// ─── Applications Table ───────────────────────────────────────────────────────

function ApplicationsTable({
  paymentApps, isLoading, canWrite, projectId, showSource,
  onCertify, onMarkPaid, onPaymentNotice, onPayLessNotice, onDelete,
  genPdf, genCert,
}: {
  paymentApps: PaymentApplication[];
  isLoading: boolean;
  canWrite: boolean;
  projectId: string;
  showSource?: boolean;
  onCertify: (pa: PaymentApplication) => void;
  onMarkPaid: (pa: PaymentApplication) => void;
  onPaymentNotice: (pa: PaymentApplication) => void;
  onPayLessNotice: (pa: PaymentApplication) => void;
  onDelete: (pa: PaymentApplication) => void;
  genPdf: (pa: PaymentApplication) => void;
  genCert: (pa: PaymentApplication) => void;
}) {
  const formatCurrency = useCurrencyFormatter();
  const queryClient = useQueryClient();
  const today = useMemo(() => new Date().toISOString().split('T')[0], []);
  const [paymentDetailsTarget, setPaymentDetailsTarget] = useState<PaymentApplication | null>(null);

  const submitMutation = useMutation({
    mutationFn: (paId: number) => api.post(`/payment-applications/${paId}/submit`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Application submitted');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Submit failed')),
  });

  const cancelMutation = useMutation({
    mutationFn: (paId: number) => api.post(`/payment-applications/${paId}/cancel`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Application cancelled');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Cancel failed')),
  });

  const withdrawMutation = useMutation({
    mutationFn: (paId: number) => api.post(`/payment-applications/${paId}/withdraw`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      toast.success('Application withdrawn to draft');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Withdraw failed')),
  });

  const cols = showSource
    ? ['App #', 'Source', 'Ref / Date', 'Gross', 'Amount Applied For', 'Certified', 'Status', '']
    : ['App #', 'Ref / Date', 'Gross', 'Amount Applied For', 'Certified', 'Status', ''];

  if (isLoading) {
    return (
      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm"><tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
          {[...Array(4)].map((_, i) => (
            <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
              {cols.map((_, j) => <td key={j} className="px-4 py-4"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} /></td>)}
            </tr>
          ))}
        </tbody></table>
      </div>
    );
  }

  if (paymentApps.length === 0) {
    return (
      <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <DollarSign size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No payment applications yet</p>
      </div>
    );
  }

  const healthLegend: Array<[HealthStatus, string]> = [
    ['healthy', 'Healthy'],
    ['action_required', 'Action Required'],
    ['overdue', 'Overdue'],
    ['paid', 'Paid'],
    ['cancelled', 'Cancelled'],
  ];

  return (
    <>
      {/* Phase 9 — health dot legend */}
      <div className="flex items-center gap-4 mb-2 px-1 flex-wrap">
        <span className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>Health:</span>
        {healthLegend.map(([key, label]) => (
          <span key={key} className="flex items-center gap-1 text-xs" style={{ color: 'var(--text-muted)' }}>
            <span style={{ width: 7, height: 7, borderRadius: '50%', backgroundColor: HEALTH_DOT[key], display: 'inline-block', flexShrink: 0 }} />
            {label}
          </span>
        ))}
      </div>
      <div className="rounded-2xl" style={{ border: '1px solid var(--border)', overflow: 'visible' }}>
      <table className="w-full text-sm" style={{ borderCollapse: 'separate', borderSpacing: 0 }}>
        <thead>
          <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
            {cols.map((h, i) => <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)', borderRadius: i === 0 ? '12px 0 0 0' : i === cols.length - 1 ? '0 12px 0 0' : undefined }}>{h}</th>)}
          </tr>
        </thead>
        <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
          {paymentApps.map(p => {
            const hasPayLessNotice = (p.pay_less_notices?.length ?? 0) > 0;
            const hasPaymentNotice = (p.payment_notices?.length ?? 0) > 0;
            const effectiveStatus = deriveEffectiveStatus(p);
            const health = deriveHealthStatus(p, today);
            const badge = PA_STATUS[effectiveStatus] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)', label: p.status ?? '—' };
            const isCancelled = p.status === 'cancelled';

            const actions: ActionItem[] = [];

            // "Open" is always available
            actions.push({ kind: 'action', label: 'Open Detail / Valuation', icon: Eye, onClick: () => window.location.href = `/app/projects/${projectId}/commercial/applications/${p.id}` });
            actions.push({ kind: 'divider' });

            if (p.status === 'draft') {
              // Draft: View, Submit, Download Draft, Delete Draft
              actions.push({ kind: 'action', label: 'Submit Application', icon: Send, color: '#facc15', onClick: () => submitMutation.mutate(p.id) });
              actions.push({ kind: 'divider' });
              actions.push({ kind: 'action', label: 'Download Draft PDF', icon: Download, onClick: () => genPdf(p) });
              if (canWrite) {
                actions.push({ kind: 'divider' });
                actions.push({ kind: 'action', label: 'Delete Draft', icon: Trash2, color: '#f87171', onClick: () => onDelete(p) });
              }
            } else if (p.status === 'submitted') {
              if (canWrite) actions.push({ kind: 'action', label: 'Certify Application', icon: CheckCircle, color: '#4ade80', onClick: () => onCertify(p) });
              actions.push({ kind: 'divider' });
              actions.push({ kind: 'action', label: 'Download Application PDF', icon: Download, onClick: () => genPdf(p) });
              if (canWrite) {
                actions.push({ kind: 'divider' });
                if (hasPaymentNotice) {
                  const pnDoc = p.payment_notices?.[0]?.documents?.[0];
                  actions.push({
                    kind: 'action',
                    label: 'Download Payment Notice PDF',
                    icon: FileCheck,
                    color: '#facc15',
                    onClick: () => pnDoc
                      ? blobDownload(pnDoc as { id: number; file_name?: string })
                      : toast('Payment Notice exists, but no generated PDF is available yet.'),
                  });
                } else {
                  actions.push({ kind: 'action', label: 'Create Payment Notice', icon: FileCheck, color: '#facc15', onClick: () => onPaymentNotice(p) });
                }
                actions.push({ kind: 'action', label: 'Create Pay Less Notice', icon: AlertTriangle, color: '#fb923c', onClick: () => onPayLessNotice(p) });
                actions.push({ kind: 'divider' });
                actions.push({ kind: 'action', label: 'Withdraw Application', icon: RotateCcw, color: '#fb923c', onClick: () => withdrawMutation.mutate(p.id) });
                actions.push({ kind: 'action', label: 'Cancel Application', icon: XCircle, color: '#f87171', onClick: () => cancelMutation.mutate(p.id) });
              }
            } else if (p.status === 'certified') {
              if (canWrite) actions.push({ kind: 'action', label: 'Mark as Paid', icon: CreditCard, color: '#60a5fa', onClick: () => onMarkPaid(p) });
              actions.push({ kind: 'divider' });
              actions.push({ kind: 'action', label: 'Download Application PDF', icon: Download, onClick: () => genPdf(p) });
              actions.push({ kind: 'action', label: 'Download Certificate', icon: Receipt, onClick: () => genCert(p) });
              if (canWrite) {
                actions.push({ kind: 'divider' });
                if (hasPaymentNotice) {
                  const pnDoc = p.payment_notices?.[0]?.documents?.[0];
                  actions.push({
                    kind: 'action',
                    label: 'Download Payment Notice PDF',
                    icon: FileCheck,
                    color: '#facc15',
                    onClick: () => pnDoc
                      ? blobDownload(pnDoc as { id: number; file_name?: string })
                      : toast('Payment Notice exists, but no generated PDF is available yet.'),
                  });
                } else {
                  actions.push({ kind: 'action', label: 'Create Payment Notice', icon: FileCheck, color: '#facc15', onClick: () => onPaymentNotice(p) });
                }
                actions.push({ kind: 'action', label: 'Create Pay Less Notice', icon: AlertTriangle, color: '#fb923c', onClick: () => onPayLessNotice(p) });
                // No Cancel for certified — payment has been certified; use PN/PLN workflow instead
              }
            } else if (isCancelled) {
              // Cancelled: only allow permanent deletion
              if (canWrite) {
                actions.push({ kind: 'divider' });
                actions.push({ kind: 'action', label: 'Delete Application', icon: Trash2, color: '#f87171', onClick: () => onDelete(p) });
              }
            } else if (p.status === 'paid') {
              actions.push({ kind: 'action', label: 'View Payment Details', icon: Eye, onClick: () => setPaymentDetailsTarget(p) });
              actions.push({ kind: 'divider' });
              actions.push({ kind: 'action', label: 'Download Application PDF', icon: Download, onClick: () => genPdf(p) });
              actions.push({ kind: 'action', label: 'Download Certificate', icon: Receipt, onClick: () => genCert(p) });
            }

            return (
              <tr key={p.id} className={`transition-colors ${isCancelled ? 'opacity-60 hover:opacity-100' : 'hover:bg-[var(--bg-hover)]'}`} style={{ borderBottom: '1px solid var(--border)' }}>
                <td className="px-4 py-3 font-mono font-semibold" style={{ color: isCancelled ? 'var(--text-muted)' : 'var(--gold)' }}>#{p.application_number}</td>
                {showSource && (
                  <td className="px-4 py-3">
                    <div className="flex flex-col gap-0.5">
                      <span className="text-xs px-1.5 py-0.5 rounded w-fit font-medium" style={{ backgroundColor: p.trade_package_id ? 'rgba(167,139,250,0.15)' : 'rgba(96,165,250,0.15)', color: p.trade_package_id ? '#a78bfa' : '#60a5fa' }}>
                        {p.trade_package_id ? 'Trade Pkg' : 'Main'}
                      </span>
                      <span className="text-xs" style={{ color: 'var(--text-muted)' }}>{p.trade_package?.name ?? p.contract?.title ?? '—'}</span>
                    </div>
                  </td>
                )}
                <td className="px-4 py-3">
                  <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>{p.reference ?? '—'}</p>
                  <p className="text-xs mt-0.5 tabular-nums" style={{ color: 'var(--text-muted)' }}>{p.application_date ? formatDate(p.application_date) : '—'}</p>
                </td>
                <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(fmt(p.gross_valuation))}</td>
                <td className="px-4 py-3 text-xs font-medium tabular-nums" style={{ color: 'var(--text-primary)' }}>{formatCurrency(fmt(p.amount_due))}</td>
                <td className="px-4 py-3 text-xs tabular-nums" style={{ color: fmt(p.certified_amount) > 0 ? '#4ade80' : 'var(--text-muted)' }}>
                  {fmt(p.certified_amount) > 0 ? formatCurrency(fmt(p.certified_amount)) : '—'}
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    <span
                      title={health.replace('_', ' ')}
                      style={{ width: 7, height: 7, borderRadius: '50%', backgroundColor: HEALTH_DOT[health], flexShrink: 0, display: 'inline-block' }}
                    />
                    <span className="px-2 py-0.5 rounded-full text-xs font-medium" style={{ backgroundColor: badge.bg, color: badge.text }}>{badge.label}</span>
                    {hasPaymentNotice && (() => {
                      const pnDoc = p.payment_notices?.[0]?.documents?.[0];
                      return pnDoc ? (
                        <button
                          onClick={e => { e.stopPropagation(); blobDownload(pnDoc as { id: number; file_name?: string }); }}
                          title="Download Payment Notice PDF"
                          className="px-1.5 py-0.5 rounded text-xs font-medium hover:opacity-80 transition-opacity"
                          style={{ backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15', border: '1px solid rgba(234,179,8,0.25)', cursor: 'pointer' }}>
                          PN
                        </button>
                      ) : (
                        <span className="px-1.5 py-0.5 rounded text-xs font-medium" style={{ backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15' }}>PN</span>
                      );
                    })()}
                    {hasPayLessNotice && (() => {
                      const plnDoc = p.pay_less_notices?.[0]?.documents?.[0];
                      return plnDoc ? (
                        <button
                          onClick={e => { e.stopPropagation(); blobDownload(plnDoc as { id: number; file_name?: string }); }}
                          title="Download Pay Less Notice PDF"
                          className="px-1.5 py-0.5 rounded text-xs font-medium hover:opacity-80 transition-opacity"
                          style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171', border: '1px solid rgba(239,68,68,0.25)', cursor: 'pointer' }}>
                          PLN
                        </button>
                      ) : (
                        <span className="px-1.5 py-0.5 rounded text-xs font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' }}>PLN</span>
                      );
                    })()}
                  </div>
                </td>
                <td className="px-4 py-3">
                  {actions.length > 0 && <RowActions items={actions} />}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      </div>
      {paymentDetailsTarget && <PaymentDetailsModal pa={paymentDetailsTarget} onClose={() => setPaymentDetailsTarget(null)} />}
    </>
  );
}

// ─── Overview Tab ─────────────────────────────────────────────────────────────

function OverviewTab({ paymentApps, contracts, tradePackages, formatCurrency, canWrite, onNewApp }: {
  paymentApps: PaymentApplication[];
  contracts: ContractOption[];
  tradePackages: TradePackageOption[];
  formatCurrency: (v: number | string) => string;
  canWrite: boolean;
  onNewApp: (opts?: { contractId?: number; tradePackageId?: number }) => void;
}) {
  const today = new Date().toISOString().split('T')[0];
  const sevenDays = new Date(); sevenDays.setDate(sevenDays.getDate() + 7);
  const nextWeek = sevenDays.toISOString().split('T')[0];

  const active = paymentApps.filter(p => p.status !== 'cancelled');

  const awaitingPN      = active.filter(p => ['submitted', 'certified'].includes(p.status ?? '') && (p.payment_notices?.length ?? 0) === 0);
  const pnOverdue       = awaitingPN.filter(p => p.payment_notice_deadline && p.payment_notice_deadline < today);
  const awaitingPLN     = active.filter(p => (p.payment_notices?.length ?? 0) > 0 && (p.pay_less_notices?.length ?? 0) === 0 && (!p.pay_less_notice_deadline || p.pay_less_notice_deadline >= today));
  const plnPassed       = active.filter(p => (p.payment_notices?.length ?? 0) > 0 && (p.pay_less_notices?.length ?? 0) === 0 && p.pay_less_notice_deadline && p.pay_less_notice_deadline < today);
  const finalThisWeek   = active.filter(p => p.final_date_for_payment && p.final_date_for_payment >= today && p.final_date_for_payment <= nextWeek && p.status !== 'paid');
  const paymentOverdue  = active.filter(p => p.final_date_for_payment && p.final_date_for_payment < today && p.status !== 'paid');

  const intelCards = [
    { label: 'Awaiting Payment Notice', count: awaitingPN.length,    color: '#fb923c', hint: 'Submitted, no PN issued yet' },
    { label: 'PN Deadline Overdue',     count: pnOverdue.length,     color: '#f87171', hint: 'PN deadline passed with no notice' },
    { label: 'Awaiting Pay Less Notice',count: awaitingPLN.length,   color: '#fb923c', hint: 'PN issued, PLN window still open' },
    { label: 'PLN Deadline Passed',     count: plnPassed.length,     color: '#f87171', hint: 'PLN deadline passed, full notified sum may be payable' },
    { label: 'Final Date This Week',    count: finalThisWeek.length,  color: '#facc15', hint: 'Payment due within 7 days' },
    { label: 'Payment Overdue',         count: paymentOverdue.length, color: '#f87171', hint: 'Final date passed, not yet marked paid' },
  ];

  const mainCertified = paymentApps.filter(p => p.contract_id && !p.trade_package_id).reduce((s, p) => s + fmt(p.certified_amount), 0);
  const tradeCertified = paymentApps.filter(p => !!p.trade_package_id).reduce((s, p) => s + fmt(p.certified_amount), 0);

  return (
    <div className="space-y-6">

      {/* ── Statutory Intelligence ────────────────────────────────────────── */}
      {active.length > 0 && (
        <div data-tour="commercial-statutory-intel">
          <p className="text-xs font-semibold mb-3 uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Statutory Intelligence</p>
          <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3">
            {intelCards.map((card, i) => (
              <div key={card.label} className="rounded-xl p-4 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: `1px solid ${card.count > 0 ? card.color + '40' : 'var(--border)'}`, boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}>
                <div className="flex items-start justify-between gap-2">
                  <p className="text-xs font-medium leading-tight" style={{ color: card.count > 0 ? card.color : 'var(--text-muted)' }}>{card.label}</p>
                  <span className="text-lg font-bold leading-none flex-shrink-0 tabular-nums" style={{ color: card.count > 0 ? card.color : 'var(--text-muted)' }}>{card.count}</span>
                </div>
                <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>{card.hint}</p>
              </div>
            ))}
          </div>
        </div>
      )}

      <div>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Main Contracts</h3>
        </div>
        {contracts.length === 0 ? (
          <div className="rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No contracts found. Add a contract first.</p>
          </div>
        ) : (
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            <table className="w-full text-sm">
              <thead><tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Contract', 'Contract Sum', 'Apps', 'Certified', 'Retention %', 'Status', ''].map(h => <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>)}
              </tr></thead>
              <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                {contracts.map(c => {
                  const apps = paymentApps.filter(p => p.contract_id === c.id);
                  const certified = apps.reduce((s, a) => s + fmt(a.certified_amount), 0);
                  return (
                    <tr key={c.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-4 py-3"><p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{c.title}</p>{c.party_name && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{c.party_name}</p>}</td>
                      <td className="px-4 py-3 text-sm tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.contract_sum ? formatCurrency(c.contract_sum) : '—'}</td>
                      <td className="px-4 py-3 font-mono text-sm" style={{ color: 'var(--gold)' }}>{apps.length}</td>
                      <td className="px-4 py-3 text-sm tabular-nums" style={{ color: '#4ade80' }}>{formatCurrency(certified)}</td>
                      <td className="px-4 py-3 text-sm tabular-nums" style={{ color: 'var(--text-secondary)' }}>{c.retention_percentage ? `${c.retention_percentage}%` : '—'}</td>
                      <td className="px-4 py-3"><span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>{c.status ?? 'draft'}</span></td>
                      <td className="px-4 py-3">
                        {canWrite && (
                          <button onClick={() => onNewApp({ contractId: c.id })} className="inline-flex items-center gap-2 rounded-full px-2 py-1 text-xs font-semibold" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
                            <span className="flex h-4 w-4 items-center justify-center rounded-full text-[11px]" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>+</span>
                            <span>App</span>
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <p className="mt-2 text-xs text-right" style={{ color: 'var(--text-muted)' }}>Main contracts certified: <span className="tabular-nums" style={{ color: '#4ade80' }}>{formatCurrency(mainCertified)}</span></p>
      </div>

      <div>
        <div className="flex items-center justify-between mb-3">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Trade Packages</h3>
        </div>
        {tradePackages.length === 0 ? (
          <div className="rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No trade packages yet</p>
          </div>
        ) : (
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            <table className="w-full text-sm">
              <thead><tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Package', 'Contractor', 'Apps', 'Certified', 'Status', ''].map(h => <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>)}
              </tr></thead>
              <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                {tradePackages.map(tp => {
                  const apps = paymentApps.filter(p => p.trade_package_id === tp.id);
                  const certified = apps.reduce((s, a) => s + fmt(a.certified_amount), 0);
                  return (
                    <tr key={tp.id} style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-4 py-3"><p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{tp.name}</p>{tp.package_reference && <p className="text-[11px] mt-0.5 font-mono" style={{ color: 'var(--text-muted)' }}>{tp.package_reference}</p>}</td>
                      <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{tp.contractor_name ?? '—'}</td>
                      <td className="px-4 py-3 font-mono text-sm" style={{ color: '#a78bfa' }}>{apps.length}</td>
                      <td className="px-4 py-3 text-sm tabular-nums" style={{ color: apps.length > 0 ? '#4ade80' : 'var(--text-muted)' }}>{apps.length > 0 ? formatCurrency(certified) : '—'}</td>
                      <td className="px-4 py-3"><span className="text-xs px-2 py-0.5 rounded-full capitalize" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>{tp.status ?? 'active'}</span></td>
                      <td className="px-4 py-3">
                        {canWrite && (
                          <button onClick={() => onNewApp({ tradePackageId: tp.id })} className="inline-flex items-center gap-2 rounded-full px-2 py-1 text-xs font-semibold" style={{ backgroundColor: 'rgba(167,139,250,0.12)', color: '#8b5cf6' }}>
                            <span className="flex h-4 w-4 items-center justify-center rounded-full text-[11px]" style={{ backgroundColor: 'rgba(167,139,250,0.2)', color: '#8b5cf6' }}>+</span>
                            <span>App</span>
                          </button>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
        <p className="mt-2 text-xs text-right" style={{ color: 'var(--text-muted)' }}>Trade packages certified: <span className="tabular-nums" style={{ color: '#4ade80' }}>{formatCurrency(tradeCertified)}</span></p>
      </div>
    </div>
  );
}

// ─── Trade Packages Tab ───────────────────────────────────────────────────────

function TradePackagesTab({ tradePackages, paymentApps, formatCurrency, canWrite, onNewApp }: {
  tradePackages: TradePackageOption[];
  paymentApps: PaymentApplication[];
  formatCurrency: (v: number | string) => string;
  canWrite: boolean;
  onNewApp: (tradePackageId: number) => void;
}) {
  if (tradePackages.length === 0) {
    return (
      <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <Package size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>No trade packages yet</p>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Generate trade package folders from the Subcontracts module</p>
      </div>
    );
  }
  return (
    <div className="space-y-4">
      {tradePackages.map((tp, i) => {
        const apps = paymentApps.filter(p => p.trade_package_id === tp.id);
        const certified = apps.reduce((s, a) => s + fmt(a.certified_amount), 0);
        const pending = apps.filter(a => a.status === 'submitted').reduce((s, a) => s + fmt(a.amount_due), 0);
        return (
          <div key={tp.id} className="rounded-2xl ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}>
            <div className="flex items-start justify-between p-4" style={{ borderBottom: apps.length > 0 ? '1px solid var(--border)' : undefined }}>
              <div>
                <div className="flex items-center gap-2">
                  <Package size={15} style={{ color: '#a78bfa' }} />
                  <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{tp.name}</span>
                  {tp.package_reference && <span className="text-[11px] font-mono px-1.5 py-0.5 rounded" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>{tp.package_reference}</span>}
                </div>
                {tp.contractor_name && <p className="text-xs mt-1 ml-5" style={{ color: 'var(--text-muted)' }}>{tp.contractor_name}</p>}
              </div>
              <div className="flex items-center gap-4">
                <div className="text-right"><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Certified</p><p className="text-sm font-semibold tabular-nums" style={{ color: apps.length > 0 ? '#4ade80' : 'var(--text-muted)' }}>{apps.length > 0 ? formatCurrency(certified) : '—'}</p></div>
                <div className="text-right"><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Pending</p><p className="text-sm font-semibold tabular-nums" style={{ color: pending > 0 ? '#facc15' : 'var(--text-muted)' }}>{pending > 0 ? formatCurrency(pending) : '—'}</p></div>
                <div className="text-right"><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Apps</p><p className="text-sm font-semibold font-mono" style={{ color: '#a78bfa' }}>{apps.length}</p></div>
                {canWrite && <button onClick={() => onNewApp(tp.id)} className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'rgba(167,139,250,0.15)', color: '#a78bfa' }}><Plus size={12} /> Application</button>}
              </div>
            </div>
            {apps.length > 0 && (
              <div className="px-4 pb-4 pt-3">
                <p className="text-xs font-semibold mb-2" style={{ color: 'var(--text-muted)' }}>RECENT APPLICATIONS</p>
                <div className="space-y-1">
                  {apps.slice(0, 3).map(a => {
                    const badge = PA_STATUS[a.status ?? ''] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)', label: a.status ?? '—' };
                    return (
                      <div key={a.id} className="flex items-center justify-between text-xs py-1.5 px-2 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                        <span style={{ color: 'var(--gold)' }}>#{a.application_number}</span>
                        <span className="tabular-nums" style={{ color: 'var(--text-muted)' }}>{a.application_date ? formatDate(a.application_date) : '—'}</span>
                        <span className="tabular-nums" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(fmt(a.amount_due))}</span>
                        <span className="px-1.5 py-0.5 rounded-full capitalize" style={{ backgroundColor: badge.bg, color: badge.text }}>{badge.label}</span>
                      </div>
                    );
                  })}
                  {apps.length > 3 && <p className="text-xs text-center pt-1" style={{ color: 'var(--text-muted)' }}>+{apps.length - 3} more, see All Applications tab</p>}
                </div>
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

// ─── Notices Tab ──────────────────────────────────────────────────────────────

function NoticesTab({ paymentNotices, payLessNotices, isLoading, formatCurrency }: {
  paymentNotices: PaymentNoticeRecord[];
  payLessNotices: PayLessNoticeRecord[];
  isLoading: boolean;
  formatCurrency: (v: number | string) => string;
}) {
  if (isLoading) {
    return (
      <div className="rounded-xl p-8 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</p>
      </div>
    );
  }

  const hasAny = paymentNotices.length > 0 || payLessNotices.length > 0;
  if (!hasAny) {
    return (
      <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <Bell size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>No notices issued</p>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Payment Notices and Pay Less Notices appear here once issued from a submitted or certified application.</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">

      {/* ── Payment Notices ─────────────────────────────────────────────────── */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
            Payment Notices
            {paymentNotices.length > 0 && (
              <span className="ml-2 px-1.5 py-0.5 rounded text-xs font-medium" style={{ backgroundColor: 'rgba(234,179,8,0.15)', color: '#facc15' }}>
                {paymentNotices.length}
              </span>
            )}
          </h2>
        </div>

        {paymentNotices.length === 0 ? (
          <div className="rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No Payment Notices issued yet.</p>
          </div>
        ) : (
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            <table className="w-full text-sm">
              <thead>
                <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                  {['Reference', 'Application', 'Contract / Package', 'Notice Date', 'Notified Sum', 'Issued By', 'PDF'].map(h => (
                    <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                {paymentNotices.map(n => {
                  const doc = n.documents?.[0];
                  const source = n.payment_application?.trade_package?.name
                    ?? n.payment_application?.contract?.title
                    ?? '—';
                  return (
                    <tr key={n.id} className="hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                      <td className="px-4 py-3 text-[11px] font-mono" style={{ color: 'var(--gold)' }}>
                        {n.reference ?? `PN-${n.payment_application?.application_number ?? n.id}`}
                      </td>
                      <td className="px-4 py-3 text-[11px] font-semibold font-mono" style={{ color: 'var(--text-secondary)' }}>
                        {n.payment_application ? `#${n.payment_application.application_number}` : '—'}
                      </td>
                      <td className="px-4 py-3 text-xs max-w-[180px]" style={{ color: 'var(--text-muted)' }}>
                        <span className="line-clamp-1">{source}</span>
                      </td>
                      <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                        {n.notice_date ? formatDate(n.notice_date) : '—'}
                      </td>
                      <td className="px-4 py-3 text-sm font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>
                        {formatCurrency(fmt(n.notified_sum))}
                      </td>
                      <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                        {n.issued_by ?? '—'}
                      </td>
                      <td className="px-4 py-3">
                        {doc ? (
                          <button
                            onClick={() => blobDownload(doc as { id: number; file_name?: string })}
                            className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium"
                            style={{ backgroundColor: 'rgba(234,179,8,0.12)', color: '#facc15', border: '1px solid rgba(234,179,8,0.25)' }}>
                            <Download size={11} /> PDF
                          </button>
                        ) : (
                          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* ── Pay Less Notices ────────────────────────────────────────────────── */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
            Pay Less Notices
            {payLessNotices.length > 0 && (
              <span className="ml-2 px-1.5 py-0.5 rounded text-xs font-medium" style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' }}>
                {payLessNotices.length}
              </span>
            )}
          </h2>
        </div>

        {payLessNotices.length === 0 ? (
          <div className="rounded-xl p-6 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No Pay Less Notices issued yet.</p>
          </div>
        ) : (
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            <table className="w-full text-sm">
              <thead>
                <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                  {['Ref', 'Application', 'PN Ref', 'Notice Date', 'Notified Sum', 'Deductions', 'Revised Amount Payable', 'Status', 'PDF'].map(h => (
                    <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
                {payLessNotices.map(n => {
                  const plnDoc = n.documents?.[0];
                  return (
                  <tr key={n.id} className="hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3 text-[11px] font-mono" style={{ color: 'var(--text-muted)' }}>
                      {n.reference ?? `PLN-${n.id}`}
                    </td>
                    <td className="px-4 py-3 font-mono font-semibold text-[11px]" style={{ color: 'var(--gold)' }}>
                      {n.payment_application ? `#${n.payment_application.application_number}` : '—'}
                    </td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                      {n.payment_notice?.reference ?? '—'}
                    </td>
                    <td className="px-4 py-3 text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>{n.notice_date ? formatDate(n.notice_date) : '—'}</td>
                    <td className="px-4 py-3 text-sm tabular-nums" style={{ color: 'var(--text-secondary)' }}>
                      {formatCurrency(fmt(n.original_amount_due ?? n.notified_sum))}
                    </td>
                    <td className="px-4 py-3 text-sm font-medium tabular-nums" style={{ color: '#f87171' }}>
                      {formatCurrency(fmt(n.total_deductions ?? n.amount))}
                    </td>
                    <td className="px-4 py-3 text-sm font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>
                      {formatCurrency(fmt(n.revised_amount_payable ?? n.notified_sum))}
                    </td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-0.5 rounded-full text-xs font-medium capitalize" style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171' }}>
                        {n.status ?? 'issued'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      {plnDoc ? (
                        <button
                          onClick={() => blobDownload(plnDoc as { id: number; file_name?: string })}
                          className="flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium"
                          style={{ backgroundColor: 'rgba(239,68,68,0.12)', color: '#f87171', border: '1px solid rgba(239,68,68,0.25)' }}>
                          <Download size={11} /> PDF
                        </button>
                      ) : (
                        <span className="text-xs" style={{ color: 'var(--text-muted)' }}>—</span>
                      )}
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
  );
}

// ─── Retention Tab ────────────────────────────────────────────────────────────

type ReleaseOpts = {
  contractId?: number;
  maxRelease: number;
  totalRetentionHeld: number;
  half1Released: number;
  half2Released: number;
  initialMoiety?: 'half_1' | 'half_2' | 'other';
};

function MoietyCard({ label, sub, target, released, canWrite, onRelease }: {
  label: string;
  sub: string;
  target: number;
  released: number;
  canWrite: boolean;
  onRelease: () => void;
}) {
  const formatCurrency = useCurrencyFormatter();
  const outstanding = Math.max(0, target - released);
  const fullyReleased = target > 0 && released >= target;
  return (
    <div className="rounded-xl p-3 space-y-2" style={{
      backgroundColor: 'var(--bg-elevated)',
      border: `1px solid ${fullyReleased ? 'rgba(74,222,128,0.2)' : outstanding > 0 ? 'rgba(251,146,60,0.2)' : 'var(--border)'}`,
    }}>
      <div className="flex items-start justify-between">
        <div>
          <p className="text-xs font-semibold" style={{ color: 'var(--text-primary)' }}>{label}</p>
          <p style={{ color: 'var(--text-muted)', fontSize: '10px' }}>{sub}</p>
        </div>
        {fullyReleased ? (
          <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(74,222,128,0.1)', color: '#4ade80' }}>Released</span>
        ) : outstanding > 0 ? (
          <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'rgba(251,146,60,0.1)', color: '#fb923c' }}>Outstanding</span>
        ) : null}
      </div>
      <div className="space-y-1">
        <div className="flex justify-between text-xs">
          <span style={{ color: 'var(--text-muted)' }}>Target</span>
          <span className="tabular-nums" style={{ color: 'var(--text-secondary)' }}>{target > 0 ? formatCurrency(target) : '—'}</span>
        </div>
        <div className="flex justify-between text-xs">
          <span style={{ color: 'var(--text-muted)' }}>Released</span>
          <span className="tabular-nums" style={{ color: released > 0 ? '#4ade80' : 'var(--text-muted)' }}>{formatCurrency(released)}</span>
        </div>
        <div className="flex justify-between text-xs font-medium">
          <span style={{ color: 'var(--text-muted)' }}>Outstanding</span>
          <span className="tabular-nums" style={{ color: outstanding > 0 ? '#fb923c' : '#4ade80' }}>{formatCurrency(outstanding)}</span>
        </div>
      </div>
      {canWrite && outstanding > 0 && (
        <button onClick={onRelease}
          className="w-full py-1.5 rounded-lg text-xs font-medium mt-1 flex items-center justify-center gap-1"
          style={{ backgroundColor: 'rgba(74,222,128,0.12)', color: '#4ade80' }}>
          <Banknote size={11} /> Release {label}
        </button>
      )}
    </div>
  );
}

function RetentionTab({ contracts, paymentApps, retentionReleases, formatCurrency, canWrite, projectId }: {
  contracts: ContractOption[];
  paymentApps: PaymentApplication[];
  retentionReleases: RetentionRelease[];
  formatCurrency: (v: number | string) => string;
  canWrite: boolean;
  projectId: string;
}) {
  const [releaseOpts, setReleaseOpts] = useState<ReleaseOpts | null>(null);

  const retentionRows = contracts.filter(c => (c.retention_percentage ?? 0) > 0).map(c => {
    const apps = paymentApps.filter(p => p.contract_id === c.id);
    const certifiedApps = apps.filter(a => ['certified', 'paid'].includes(a.status ?? ''));
    const totalRetentionHeld = certifiedApps.reduce((s, a) => s + fmt(a.less_retention), 0);
    const contractReleases = retentionReleases.filter(r => r.contract_id === c.id);
    const totalReleased  = contractReleases.reduce((s, r) => s + fmt(r.release_amount), 0);
    const half1Released  = contractReleases.filter(r => r.moiety === 'half_1').reduce((s, r) => s + fmt(r.release_amount), 0);
    const half2Released  = contractReleases.filter(r => r.moiety === 'half_2').reduce((s, r) => s + fmt(r.release_amount), 0);
    const half1Target    = totalRetentionHeld / 2;
    const half2Target    = totalRetentionHeld / 2;
    const balance        = Math.max(0, totalRetentionHeld - totalReleased);
    const retPct         = c.retention_percentage ?? 0;
    const capPct         = c.retention_cap_percentage ?? retPct;
    const maxRetention   = (c.contract_sum ?? 0) * (capPct / 100);
    const pctUsed        = maxRetention > 0 ? Math.min(100, (totalRetentionHeld / maxRetention) * 100) : 0;
    return {
      contract: c, totalRetentionHeld, totalReleased,
      half1Released, half2Released, half1Target, half2Target,
      balance, retPct, capPct, maxRetention, pctUsed, contractReleases,
    };
  });

  if (retentionRows.length === 0) {
    return (
      <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <Percent size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
        <p className="text-sm font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>No retention has been held yet</p>
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Retention will be calculated from certified payment applications.</p>
      </div>
    );
  }

  const openRelease = (row: typeof retentionRows[0], moiety?: 'half_1' | 'half_2' | 'other') =>
    setReleaseOpts({
      contractId:         row.contract.id,
      maxRelease:         row.balance,
      totalRetentionHeld: row.totalRetentionHeld,
      half1Released:      row.half1Released,
      half2Released:      row.half2Released,
      initialMoiety:      moiety,
    });

  return (
    <div className="space-y-4">
      {retentionRows.map((row, i) => (
        <div key={row.contract.id} className="rounded-2xl p-5 space-y-4 ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}>

          {/* Header */}
          <div className="flex items-start justify-between">
            <div>
              <p className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{row.contract.title}</p>
              {row.contract.party_name && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{row.contract.party_name}</p>}
            </div>
            <div className="flex items-center gap-3">
              <div className="flex gap-3 text-right">
                <div><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Rate</p><p className="text-base font-bold tabular-nums" style={{ color: '#facc15' }}>{row.retPct}%</p></div>
                {row.contract.retention_cap_percentage && <div><p className="text-xs" style={{ color: 'var(--text-muted)' }}>Cap</p><p className="text-base font-bold tabular-nums" style={{ color: 'var(--text-secondary)' }}>{row.capPct}%</p></div>}
              </div>
              {canWrite && row.balance > 0 && (
                <button onClick={() => openRelease(row)}
                  className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                  style={{ backgroundColor: 'rgba(74,222,128,0.15)', color: '#4ade80' }}>
                  <Banknote size={12} /> Release Retention
                </button>
              )}
            </div>
          </div>

          {/* Summary cards */}
          <div className="grid grid-cols-4 gap-3">
            {[
              { label: 'Retention Held',     value: formatCurrency(row.totalRetentionHeld), color: '#facc15' },
              { label: 'Retention Released',  value: formatCurrency(row.totalReleased),      color: '#4ade80' },
              { label: 'Current Balance',     value: formatCurrency(row.balance),            color: row.balance > 0 ? '#fb923c' : 'var(--text-muted)' },
              { label: 'Max Retention',       value: row.maxRetention > 0 ? formatCurrency(row.maxRetention) : '—', color: 'var(--text-secondary)' },
            ].map(card => (
              <div key={card.label} className="rounded-xl p-3" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{card.label}</p>
                <p className="text-base font-bold mt-1 tabular-nums" style={{ color: card.color }}>{card.value}</p>
              </div>
            ))}
          </div>

          {/* Moiety split — only shown when retention is actually being held */}
          {row.totalRetentionHeld > 0 && (
            <div>
              <p className="text-xs font-semibold mb-2" style={{ color: 'var(--text-muted)' }}>MOIETY SPLIT</p>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <MoietyCard
                  label="Half 1" sub="Practical Completion"
                  target={row.half1Target} released={row.half1Released}
                  canWrite={canWrite && row.balance > 0}
                  onRelease={() => openRelease(row, 'half_1')}
                />
                <MoietyCard
                  label="Half 2" sub="Making Good Defects"
                  target={row.half2Target} released={row.half2Released}
                  canWrite={canWrite && row.balance > 0}
                  onRelease={() => openRelease(row, 'half_2')}
                />
              </div>
            </div>
          )}

          {/* Utilisation bar */}
          {row.maxRetention > 0 && (
            <div>
              <div className="flex justify-between text-xs mb-1.5" style={{ color: 'var(--text-muted)' }}>
                <span>Retention utilisation</span><span className="tabular-nums">{row.pctUsed.toFixed(1)}%</span>
              </div>
              <div className="h-2 rounded-full overflow-hidden" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <div className="h-full rounded-full transition-all" style={{ width: `${row.pctUsed}%`, backgroundColor: row.pctUsed > 80 ? '#f87171' : '#facc15' }} />
              </div>
            </div>
          )}

          {/* Release history */}
          {row.contractReleases.length > 0 && (
            <div>
              <p className="text-xs font-semibold mb-2" style={{ color: 'var(--text-muted)' }}>RELEASE HISTORY</p>
              <div className="space-y-1">
                {row.contractReleases.map(r => (
                  <div key={r.id} className="flex items-center gap-2 text-xs py-1.5 px-3 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                    {/* Moiety badge */}
                    {r.moiety && r.moiety !== 'other' && (
                      <span className="px-1.5 py-0.5 rounded text-xs font-bold shrink-0" style={{
                        backgroundColor: r.moiety === 'half_1' ? 'rgba(234,179,8,0.15)' : 'rgba(167,139,250,0.15)',
                        color:           r.moiety === 'half_1' ? '#facc15' : '#a78bfa',
                      }}>
                        {r.moiety === 'half_1' ? 'H1' : 'H2'}
                      </span>
                    )}
                    <span style={{ color: '#4ade80' }} className="shrink-0 tabular-nums">{formatCurrency(fmt(r.release_amount))}</span>
                    <span style={{ color: 'var(--text-secondary)', flex: 1 }}>{r.release_reason}</span>
                    <span style={{ color: 'var(--text-muted)' }} className="shrink-0 tabular-nums">{r.release_date ? formatDate(r.release_date) : '—'}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      ))}

      {releaseOpts && (
        <ReleaseRetentionModal
          projectId={projectId}
          contractId={releaseOpts.contractId}
          maxRelease={releaseOpts.maxRelease}
          totalRetentionHeld={releaseOpts.totalRetentionHeld}
          half1Released={releaseOpts.half1Released}
          half2Released={releaseOpts.half2Released}
          initialMoiety={releaseOpts.initialMoiety}
          onClose={() => setReleaseOpts(null)}
        />
      )}
    </div>
  );
}

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function ProjectCommercialPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id: projectId } = useParams<{ id: string }>();
  const id = projectId!;
  const { canWrite } = useProjectPermissions();
  const searchParams = useSearchParams();

  const VALID_TABS: CommercialTab[] = ['overview', 'applications', 'trade-packages', 'notices', 'retention', 'final-account'];
  const tabParam = searchParams.get('tab') as CommercialTab | null;
  const initialTab: CommercialTab = tabParam && VALID_TABS.includes(tabParam) ? tabParam : 'overview';

  const [tab, setTab] = useState<CommercialTab>(initialTab);
  const [newAppOpts, setNewAppOpts] = useState<{ open: boolean; contractId?: number; tradePackageId?: number }>({ open: false });
  const [certifyTarget, setCertifyTarget] = useState<PaymentApplication | null>(null);
  const [paidTarget, setPaidTarget] = useState<PaymentApplication | null>(null);
  const [pnTarget, setPnTarget] = useState<PaymentApplication | null>(null);
  const [plnTarget, setPlnTarget] = useState<PaymentApplication | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<PaymentApplication | null>(null);

  const { data: paData, isLoading: paLoading } = useQuery<ApiCollection<PaymentApplication>>({
    queryKey: ['project-payment-apps', id],
    queryFn: () => api.get(`/projects/${id}/payment-applications`).then(r => r.data),
  });
  const { data: contractsData } = useQuery<ApiCollection<ContractOption>>({
    queryKey: ['project-contracts', id],
    queryFn: () => api.get(`/projects/${id}/contracts`).then(r => r.data),
  });
  const { data: subData } = useQuery<{ trade_packages?: TradePackageOption[] }>({
    queryKey: ['project-subcontracts', id],
    queryFn: () => api.get(`/projects/${id}/documents/module/subcontracts`).then(r => r.data),
  });
  const { data: paymentNoticesData, isLoading: paymentNoticesLoading } = useQuery<{ data?: PaymentNoticeRecord[] }>({
    queryKey: ['project-payment-notices', id],
    queryFn: () => api.get(`/projects/${id}/payment-notices`).then(r => r.data),
    enabled: tab === 'notices',
  });
  const { data: noticesData, isLoading: noticesLoading } = useQuery<ApiCollection<PayLessNoticeRecord>>({
    queryKey: ['project-pay-less-notices', id],
    queryFn: () => api.get(`/projects/${id}/pay-less-notices`).then(r => r.data),
    enabled: tab === 'notices',
  });
  const { data: releasesData } = useQuery<ApiCollection<RetentionRelease>>({
    queryKey: ['project-retention-releases', id],
    queryFn: () => api.get(`/projects/${id}/retention-releases`).then(r => r.data),
    enabled: tab === 'retention',
  });

  const paymentApps = paData?.data ?? [];
  const contracts = contractsData?.data ?? [];
  const tradePackages = subData?.trade_packages ?? [];
  const paymentNotices = paymentNoticesData?.data ?? [];
  const notices = noticesData?.data ?? [];
  const retentionReleases = releasesData?.data ?? [];

  const totals = useMemo(() => {
    const active = paymentApps.filter(p => p.status !== 'cancelled');
    const submitted  = active.filter(p => p.status === 'submitted');
    const certified  = active.filter(p => ['certified', 'paid'].includes(p.status ?? ''));
    const paid       = active.filter(p => p.status === 'paid');
    const pnCount    = active.filter(p => (p.payment_notices?.length ?? 0) > 0).length;
    return {
      submitted:     submitted.length,
      certifiedAmt:  certified.reduce((s, p) => s + fmt(p.certified_amount), 0),
      paidAmt:       paid.reduce((s, p) => s + fmt(p.paid_amount), 0),
      retentionHeld: certified.reduce((s, p) => s + fmt(p.less_retention), 0),
      pendingCert:   submitted.reduce((s, p) => s + fmt(p.amount_due), 0),
      outstanding:   certified.filter(p => p.status === 'certified').reduce((s, p) => s + fmt(p.certified_amount) - fmt(p.paid_amount), 0),
      paymentNoticesIssued: pnCount,
      payLessNoticesIssued: notices.length,
    };
  }, [paymentApps, notices]);

  const genPdfMutation = useMutation({
    mutationFn: (pa: PaymentApplication) => api.post(`/payment-applications/${pa.id}/generate-pdf`).then(r => r.data),
    onSuccess: (doc: { id: number; file_name?: string }) => { blobDownload(doc); toast.success('Application PDF generated'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed')),
  });
  const genCertMutation = useMutation({
    mutationFn: (pa: PaymentApplication) => api.post(`/payment-applications/${pa.id}/generate-certificate`).then(r => r.data),
    onSuccess: (doc: { id: number; file_name?: string }) => { blobDownload(doc); toast.success('Certificate PDF downloaded'); },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed')),
  });

  const openNewApp = (opts?: { contractId?: number; tradePackageId?: number }) => setNewAppOpts({ open: true, ...opts });

  const TABS = [
    { id: 'overview' as CommercialTab,       label: 'Overview',       icon: LayoutDashboard },
    { id: 'applications' as CommercialTab,   label: 'Applications',   icon: DollarSign },
    { id: 'trade-packages' as CommercialTab, label: 'Trade Packages', icon: Package },
    { id: 'notices' as CommercialTab,        label: 'Notices',        icon: Bell },
    { id: 'retention' as CommercialTab,      label: 'Retention',      icon: Percent },
    { id: 'final-account' as CommercialTab, label: 'Final Account',  icon: FileCheck },
  ];

  const CARDS = [
    { label: 'Certified To Date',        value: formatCurrency(totals.certifiedAmt),        color: '#4ade80' },
    { label: 'Paid To Date',             value: formatCurrency(totals.paidAmt),             color: '#60a5fa' },
    { label: 'Submitted',                value: String(totals.submitted),                   color: '#facc15', sub: 'awaiting certification' },
    { label: 'Retention Held',           value: formatCurrency(totals.retentionHeld),       color: '#facc15', sub: 'from certified apps' },
    { label: 'Outstanding Balance',      value: formatCurrency(totals.outstanding),         color: '#a78bfa', sub: 'certified but unpaid' },
    { label: 'Pending Certification',    value: formatCurrency(totals.pendingCert),         color: '#fb923c' },
  ];

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div>
        <div className="flex items-center gap-1.5">
          <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Commercial</h1>
          <PageTourButton tourKey="page-commercial" label="Take a tour of this page" />
        </div>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Payment applications, trade packages, notices and retention</p>
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3" data-tour="commercial-summary">
        {CARDS.map((c, i) => <SumCard key={c.label} label={c.label} value={c.value} color={c.color} sub={c.sub} index={i} />)}
      </div>

      <div className="flex gap-1 p-1 rounded-full w-fit overflow-x-auto" data-tour="commercial-tabs" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        {TABS.map(t => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className="flex items-center gap-2 px-4 py-2 rounded-full text-sm font-medium transition-all active:scale-[0.97] whitespace-nowrap"
            style={tab === t.id ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' } : { color: 'var(--text-secondary)' }}>
            <t.icon size={14} />{t.label}
          </button>
        ))}
      </div>

      {tab === 'overview' && <OverviewTab paymentApps={paymentApps} contracts={contracts} tradePackages={tradePackages} formatCurrency={formatCurrency} canWrite={canWrite} onNewApp={openNewApp} />}

      {tab === 'applications' && (
        <ApplicationsTable
          paymentApps={paymentApps} isLoading={paLoading} canWrite={canWrite} projectId={id!} showSource
          onCertify={setCertifyTarget} onMarkPaid={setPaidTarget}
          onPaymentNotice={setPnTarget}
          onPayLessNotice={setPlnTarget} onDelete={setDeleteTarget}
          genPdf={pa => genPdfMutation.mutate(pa)} genCert={pa => genCertMutation.mutate(pa)}
        />
      )}

      {tab === 'trade-packages' && (
        <TradePackagesTab tradePackages={tradePackages} paymentApps={paymentApps} formatCurrency={formatCurrency} canWrite={canWrite} onNewApp={tpId => openNewApp({ tradePackageId: tpId })} />
      )}

      {tab === 'notices' && (
        <NoticesTab
          paymentNotices={paymentNotices}
          payLessNotices={notices}
          isLoading={noticesLoading || paymentNoticesLoading}
          formatCurrency={formatCurrency}
        />
      )}

      {tab === 'retention' && (
        <RetentionTab
          contracts={contracts}
          paymentApps={paymentApps}
          retentionReleases={retentionReleases}
          formatCurrency={formatCurrency}
          canWrite={canWrite}
          projectId={id!}
        />
      )}

      {tab === 'final-account' && (
        <FinalAccountTab
          contracts={contracts}
          tradePackages={tradePackages}
          projectId={id!}
        />
      )}

      {canWrite && newAppOpts.open && <NewPaymentAppModal projectId={id!} onClose={() => setNewAppOpts({ open: false })} initialContractId={newAppOpts.contractId} initialTradePackageId={newAppOpts.tradePackageId} />}
      {certifyTarget  && <CertifyModal pa={certifyTarget}  projectId={id!} onClose={() => setCertifyTarget(null)} />}
      {paidTarget     && <MarkPaidModal pa={paidTarget}    projectId={id!} onClose={() => setPaidTarget(null)} />}
      {pnTarget       && <PaymentNoticeModal pa={pnTarget}  projectId={id!} onClose={() => setPnTarget(null)} />}
      {plnTarget      && <PayLessNoticeModal pa={plnTarget} projectId={id!} onClose={() => setPlnTarget(null)} />}
      {deleteTarget   && <DeleteConfirmModal pa={deleteTarget} projectId={id!} onClose={() => setDeleteTarget(null)} />}
    </div>
  );
}
