'use client';

import { useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { useSiteSettings } from '@/hooks/useSiteSettings';
import { DollarSign, Plus, FileText, GitBranch, X, CheckCircle, Send, Download } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';

type Tab = 'payment-applications' | 'variations' | 'pay-less-notices';

type FormChangeEvent = React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>;

type ApiCollection<T> = {
  data?: T[];
};

type ContractOption = {
  id: number;
  title: string;
  reference_number?: string | null;
};

type PaymentAppForm = {
  contract_id: string;
  application_date: string;
  due_date: string;
  reference: string;
  gross_valuation: string;
  less_retention: string;
  less_previous_payments: string;
  notes: string;
};

type PaymentApplication = {
  id: number;
  application_number: number;
  reference?: string | null;
  application_date?: string | null;
  gross_valuation?: number | string | null;
  amount_due?: number | string | null;
  certified_amount?: number | string | null;
  status?: string | null;
};

type VariationRecord = {
  id: number;
};

type InputFieldProps = {
  label: string;
  name: string;
  type?: string;
  required?: boolean;
  value: string;
  onChange: (event: FormChangeEvent) => void;
};

function getErrorMessage(error: unknown, fallback: string) {
  if (
    typeof error === 'object' &&
    error !== null &&
    'response' in error &&
    typeof error.response === 'object' &&
    error.response !== null &&
    'data' in error.response &&
    typeof error.response.data === 'object' &&
    error.response.data !== null &&
    'message' in error.response.data &&
    typeof error.response.data.message === 'string'
  ) {
    return error.response.data.message;
  }

  return fallback;
}

const PA_STATUS: Record<string, { bg: string; text: string }> = {
  draft:                    { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  submitted:                { bg: 'rgba(234,179,8,0.12)',  text: '#facc15' },
  certified:                { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  pay_less_notice_issued:   { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  paid:                     { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  disputed:                 { bg: 'rgba(249,115,22,0.12)', text: '#fb923c' },
};

function InputField({ label, name, type = 'text', required = false, value, onChange }: InputFieldProps) {
  return (
    <div>
      <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
      <input
        name={name} type={type} value={value} onChange={onChange} required={required}
        className="w-full px-3 py-2 rounded-lg text-sm outline-none"
        style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
      />
    </div>
  );
}

function NewPaymentAppModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const formatCurrency = useCurrencyFormatter();
  const { data: siteSettings } = useSiteSettings();
  const currencySymbol = siteSettings?.currency_symbol ?? '£';
  const queryClient = useQueryClient();

  // First fetch contracts for this project to pick a contract_id
  const { data: contractsData } = useQuery<ApiCollection<ContractOption>>({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data),
  });
  const contracts = contractsData?.data ?? [];

  const [form, setForm] = useState<PaymentAppForm>({
    contract_id: '', application_date: new Date().toISOString().split('T')[0],
    due_date: '', reference: '', gross_valuation: '',
    less_retention: '0', less_previous_payments: '0', notes: '',
  });

  const amountDue = Math.max(
    0,
    parseFloat(form.gross_valuation || '0') -
    parseFloat(form.less_retention || '0') -
    parseFloat(form.less_previous_payments || '0')
  );

  const { mutate, isPending } = useMutation({
    mutationFn: (data: PaymentAppForm) =>
      api.post(`/contracts/${data.contract_id}/payment-applications`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      toast.success('Payment application created');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to create')),
  });

  const handleChange = (e: FormChangeEvent) => {
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-lg rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Payment Application</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Contract *</label>
            <select name="contract_id" value={form.contract_id} onChange={handleChange} required
              className="w-full px-3 py-2 rounded-lg text-sm outline-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
              <option value="">Select contract…</option>
              {contracts.map(c => (
                <option key={c.id} value={c.id}>{c.title} {c.reference_number ? `(${c.reference_number})` : ''}</option>
              ))}
            </select>
            {contracts.length === 0 && (
              <p className="text-xs mt-1" style={{ color: '#f87171' }}>No contracts found — add a contract first.</p>
            )}
          </div>
          <div className="grid grid-cols-2 gap-4">
            <InputField label="Application Date" name="application_date" type="date" value={form.application_date} onChange={handleChange} required />
            <InputField label="Due Date" name="due_date" type="date" value={form.due_date} onChange={handleChange} />
            <InputField label="Reference" name="reference" value={form.reference} onChange={handleChange} />
          </div>
          <div className="space-y-3 rounded-xl p-4" style={{ backgroundColor: 'var(--bg-elevated)' }}>
            <p className="text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>FINANCIAL SUMMARY</p>
            <InputField label={`Gross Valuation (${currencySymbol})`} name="gross_valuation" type="number" value={form.gross_valuation} onChange={handleChange} required />
            <InputField label={`Less: Retention (${currencySymbol})`} name="less_retention" type="number" value={form.less_retention} onChange={handleChange} />
            <InputField label={`Less: Previous Payments (${currencySymbol})`} name="less_previous_payments" type="number" value={form.less_previous_payments} onChange={handleChange} />
            <div className="flex justify-between items-center pt-2" style={{ borderTop: '1px solid var(--border)' }}>
              <span className="text-xs font-semibold" style={{ color: 'var(--text-secondary)' }}>Amount Due</span>
              <span className="text-base font-bold" style={{ color: 'var(--gold)' }}>{formatCurrency(amountDue)}</span>
            </div>
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea
              name="notes" value={form.notes} onChange={handleChange} rows={2}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending || contracts.length === 0} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: (isPending || contracts.length === 0) ? 0.6 : 1 }}>
              {isPending ? 'Creating…' : 'Create Application'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function CertifyModal({ pa, onClose }: { pa: PaymentApplication; onClose: () => void }) {
  const { id } = useParams<{ id: string }>();
  const queryClient = useQueryClient();
  const [certifiedAmount, setCertifiedAmount] = useState(String(pa.amount_due ?? ''));
  const [certifiedDate, setCertifiedDate] = useState(new Date().toISOString().split('T')[0]);

  const { mutate, isPending } = useMutation({
    mutationFn: () => api.post(`/payment-applications/${pa.id}/certify`, { certified_amount: certifiedAmount, certified_date: certifiedDate }).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', id] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', id] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', id] });
      toast.success('Application certified');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-sm rounded-2xl p-5 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between">
          <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Certify Application #{pa.application_number}</h2>
          <button onClick={onClose}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div>
          <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Certified Amount (£) *</label>
          <input type="number" value={certifiedAmount} onChange={e => setCertifiedAmount(e.target.value)}
            className="w-full px-3 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
        </div>
        <div>
          <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Certified Date</label>
          <input type="date" value={certifiedDate} onChange={e => setCertifiedDate(e.target.value)}
            className="w-full px-3 py-2 rounded-lg text-sm outline-none"
            style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
        </div>
        <div className="flex justify-end gap-3">
          <button onClick={onClose} className="px-3 py-1.5 rounded-lg text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
          <button onClick={() => mutate()} disabled={isPending} className="px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: '#4ade80', color: '#000', opacity: isPending ? 0.7 : 1 }}>
            {isPending ? 'Certifying…' : 'Certify'}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function ProjectCommercialPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<Tab>('payment-applications');
  const [showNewModal, setShowNewModal] = useState(false);
  const [certifyTarget, setCertifyTarget] = useState<PaymentApplication | null>(null);

  const { data: paData, isLoading: paLoading } = useQuery<ApiCollection<PaymentApplication>>({
    queryKey: ['project-payment-apps', id],
    queryFn: () => api.get(`/projects/${id}/payment-applications`).then(r => r.data),
    enabled: tab === 'payment-applications',
  });

  const { data: varData, isLoading: varLoading } = useQuery<ApiCollection<VariationRecord>>({
    queryKey: ['project-variations', id],
    queryFn: () => api.get(`/projects/${id}/variations`).then(r => r.data),
    enabled: tab === 'variations',
  });

  const submitMutation = useMutation({
    mutationFn: (paId: number) => api.post(`/payment-applications/${paId}/submit`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-payment-apps', id] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', id] });
      toast.success('Application submitted');
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to submit')),
  });

  const generatePdfMutation = useMutation({
    mutationFn: (paId: number) => api.post(`/payment-applications/${paId}/generate-pdf`).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-documents', id] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', id] });
      toast.success('PDF generated and saved to documents');
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to generate PDF')),
  });

  const paymentApps = paData?.data ?? [];
  const variations  = varData?.data ?? [];
  const totalCertified = paymentApps.reduce((sum, paymentApp) => sum + Number(paymentApp.certified_amount ?? 0), 0);
  const totalPending = paymentApps
    .filter(paymentApp => paymentApp.status === 'submitted')
    .reduce((sum, paymentApp) => sum + Number(paymentApp.amount_due ?? 0), 0);

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Commercial</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Payment applications, variations and pay less notices</p>
        </div>
        {tab === 'payment-applications' && canWrite && (
          <button
            onClick={() => setShowNewModal(true)}
            className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            <Plus size={15} />
            New Application
          </button>
        )}
      </div>

      {/* Summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total Certified</p>
          <p className="text-xl font-bold mt-1" style={{ color: '#4ade80' }}>{formatCurrency(totalCertified)}</p>
        </div>
        <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Pending Payment</p>
          <p className="text-xl font-bold mt-1" style={{ color: '#facc15' }}>{formatCurrency(totalPending)}</p>
        </div>
        <div className="rounded-xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Applications</p>
          <p className="text-xl font-bold mt-1" style={{ color: 'var(--gold)' }}>{paymentApps.length}</p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-1 p-1 rounded-lg w-fit" style={{ backgroundColor: 'var(--bg-elevated)' }}>
        {[
          { id: 'payment-applications', label: 'Payment Applications', icon: DollarSign },
          { id: 'variations',           label: 'Variations',           icon: GitBranch  },
          { id: 'pay-less-notices',     label: 'Pay Less Notices',     icon: FileText   },
        ].map(t => (
          <button
            key={t.id}
            onClick={() => setTab(t.id as Tab)}
            className="flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-all"
            style={tab === t.id
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }
            }
          >
            <t.icon size={14} />
            {t.label}
          </button>
        ))}
      </div>

      {/* Payment Applications Table */}
      {tab === 'payment-applications' && (
        <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          <table className="w-full text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['App #', 'Reference', 'Date', 'Gross', 'Amount Due', 'Certified', 'Status', 'Actions'].map(h => (
                  <th key={h} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {paLoading ? (
                [...Array(4)].map((_, i) => (
                  <tr key={i} style={{ borderBottom: '1px solid var(--border)' }}>
                    {[...Array(8)].map((_, j) => (
                      <td key={j} className="px-4 py-4">
                        <div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '70%' }} />
                      </td>
                    ))}
                  </tr>
                ))
              ) : paymentApps.length === 0 ? (
                <tr>
                  <td colSpan={8} className="px-5 py-12 text-center">
                    <DollarSign size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                    <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No payment applications yet</p>
                    <button onClick={() => setShowNewModal(true)} className="mt-3 px-3 py-1.5 rounded-lg text-xs font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
                      Create First Application
                    </button>
                  </td>
                </tr>
              ) : paymentApps.map(p => {
                const badge = PA_STATUS[p.status ?? ''] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={p.id} className="hover:bg-[var(--bg-elevated)] transition-colors" style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-4 py-3 font-mono font-semibold" style={{ color: 'var(--gold)' }}>#{p.application_number}</td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{p.reference ?? '—'}</td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{p.application_date ? formatDate(p.application_date) : '—'}</td>
                    <td className="px-4 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{formatCurrency(p.gross_valuation ?? 0)}</td>
                    <td className="px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{formatCurrency(p.amount_due ?? 0)}</td>
                    <td className="px-4 py-3 text-xs" style={{ color: p.certified_amount ? '#4ade80' : 'var(--text-muted)' }}>
                      {p.certified_amount ? formatCurrency(p.certified_amount) : '—'}
                    </td>
                    <td className="px-4 py-3">
                      <span className="px-2 py-0.5 rounded-full text-xs font-medium capitalize" style={{ backgroundColor: badge.bg, color: badge.text }}>
                        {p.status?.replace(/_/g, ' ')}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-2">
                        {p.status === 'draft' && (
                          <button
                            onClick={() => submitMutation.mutate(p.id)}
                            title="Submit"
                            className="p-1 rounded hover:opacity-80"
                            style={{ color: '#facc15' }}
                          >
                            <Send size={13} />
                          </button>
                        )}
                        {p.status === 'submitted' && (
                          <button
                            onClick={() => setCertifyTarget(p)}
                            title="Certify"
                            className="p-1 rounded hover:opacity-80"
                            style={{ color: '#4ade80' }}
                          >
                            <CheckCircle size={13} />
                          </button>
                        )}
                        <button
                          onClick={() => generatePdfMutation.mutate(p.id)}
                          title="Generate PDF"
                          className="p-1 rounded hover:opacity-80"
                          style={{ color: 'var(--text-muted)' }}
                        >
                          <Download size={13} />
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {tab === 'variations' && (
        <div className="rounded-2xl p-8 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <GitBranch size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
            {varLoading ? 'Loading…' : `${variations.length} variations — visit the Variations module for details`}
          </p>
        </div>
      )}

      {tab === 'pay-less-notices' && (
        <div className="rounded-2xl p-8 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <FileText size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No pay less notices issued</p>
        </div>
      )}

      {canWrite && showNewModal && <NewPaymentAppModal projectId={id!} onClose={() => setShowNewModal(false)} />}
      {certifyTarget && <CertifyModal pa={certifyTarget} onClose={() => setCertifyTarget(null)} />}
    </div>
  );
}
