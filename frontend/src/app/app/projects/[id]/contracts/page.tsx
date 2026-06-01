'use client';

import { useRef, useState } from 'react';
import { useParams } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { FileText, Plus, Search, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import PromptActionButton from '@/components/prompts/PromptActionButton';

type FormChangeEvent = React.ChangeEvent<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>;

type ApiCollection<T> = {
  data?: T[];
};

type ContractTypeOption = {
  value: string;
  label: string;
};

type ContractForm = {
  title: string;
  type: string;
  reference_number: string;
  form_of_contract: string;
  party_name: string;
  contract_sum: string;
  currency: string;
  retention_percentage: string;
  retention_cap_percentage: string;
  payment_terms_days: string;
  commencement_date: string;
  completion_date: string;
  execution_date: string;
  notes: string;
};

type ProjectContract = {
  id: number;
  reference_number?: string | null;
  title: string;
  type?: string | null;
  party_name?: string | null;
  contract_sum?: number | string | null;
  commencement_date?: string | null;
  completion_date?: string | null;
  status?: string | null;
};

type InputFieldProps = {
  label: string;
  name: string;
  type?: string;
  required?: boolean;
  value: string;
  onChange: (event: FormChangeEvent) => void;
  options?: ContractTypeOption[];
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

const CONTRACT_STATUS_LABELS: Record<string, string> = {
  draft:      'Draft',
  active:     'Active',
  expired:    'Expired',
  complete:   'Complete',
  terminated: 'Terminated',
};

function EditContractModal({ contract, projectId, onClose }: { contract: ProjectContract & Record<string, any>; projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<ContractForm>({
    title:                   contract.title ?? '',
    type:                    contract.type ?? 'main_contract',
    reference_number:        contract.reference_number ?? '',
    form_of_contract:        contract.form_of_contract ?? '',
    party_name:              contract.party_name ?? '',
    contract_sum:            String(contract.contract_sum ?? ''),
    currency:                contract.currency ?? 'GBP',
    retention_percentage:    String(contract.retention_percentage ?? '3'),
    retention_cap_percentage: String(contract.retention_cap_percentage ?? '5'),
    payment_terms_days:      String(contract.payment_terms_days ?? '30'),
    commencement_date:       contract.commencement_date ? String(contract.commencement_date).slice(0, 10) : '',
    completion_date:         contract.completion_date ? String(contract.completion_date).slice(0, 10) : '',
    execution_date:          contract.execution_date ? String(contract.execution_date).slice(0, 10) : '',
    notes:                   contract.notes ?? '',
  });

  const STATUS_OPTIONS = [
    { value: 'draft',      label: 'Draft' },
    { value: 'active',     label: 'Active' },
    { value: 'expired',    label: 'Expired' },
    { value: 'complete',   label: 'Complete' },
    { value: 'terminated', label: 'Terminated' },
  ];
  const [status, setStatus] = useState<string>(contract.status ?? 'draft');

  const { mutate, isPending } = useMutation({
    mutationFn: (data: ContractForm & { status: string }) => api.put(`/contracts/${contract.id}`, data).then(r => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      toast.success('Contract updated');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to update contract')),
  });

  const handleChange = (e: FormChangeEvent) => {
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-2xl rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Edit Contract</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate({ ...form, status }); }} className="p-5 space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="col-span-2">
              <InputField label="Title" name="title" value={form.title} onChange={handleChange} required />
            </div>
            <InputField label="Contract Type" name="type" value={form.type} onChange={handleChange} required options={CONTRACT_TYPES} />
            <div>
              <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Status</label>
              <select name="status" value={status} onChange={e => setStatus(e.target.value)}
                className="w-full px-3 py-2 rounded-lg text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}>
                {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </div>
            <InputField label="Reference Number" name="reference_number" value={form.reference_number} onChange={handleChange} />
            <InputField label="Contracting Party" name="party_name" value={form.party_name} onChange={handleChange} />
            <InputField label="Form of Contract" name="form_of_contract" value={form.form_of_contract} onChange={handleChange} />
            <InputField label="Contract Sum" name="contract_sum" type="number" value={form.contract_sum} onChange={handleChange} />
            <InputField label="Currency" name="currency" value={form.currency} onChange={handleChange} />
            <InputField label="Retention %" name="retention_percentage" type="number" value={form.retention_percentage} onChange={handleChange} />
            <InputField label="Retention Cap %" name="retention_cap_percentage" type="number" value={form.retention_cap_percentage} onChange={handleChange} />
            <InputField label="Payment Terms (days)" name="payment_terms_days" type="number" value={form.payment_terms_days} onChange={handleChange} />
            <InputField label="Execution Date" name="execution_date" type="date" value={form.execution_date} onChange={handleChange} />
            <InputField label="Commencement Date" name="commencement_date" type="date" value={form.commencement_date} onChange={handleChange} />
            <InputField label="Completion Date" name="completion_date" type="date" value={form.completion_date} onChange={handleChange} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea name="notes" value={form.notes} onChange={handleChange} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }} />
          </div>
          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Saving…' : 'Save Changes'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

const STATUS_COLORS: Record<string, { bg: string; text: string }> = {
  draft:      { bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  active:     { bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  expired:    { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
  complete:   { bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  terminated: { bg: 'rgba(239,68,68,0.12)',  text: '#f87171' },
};

const CONTRACT_TYPES = [
  { value: 'main_contract',          label: 'Main Contract' },
  { value: 'subcontract',            label: 'Subcontract' },
  { value: 'consultant_appointment', label: 'Consultant Appointment' },
  { value: 'supplier_agreement',     label: 'Supplier Agreement' },
] satisfies ContractTypeOption[];

function InputField({ label, name, type = 'text', required = false, value, onChange, options }: InputFieldProps) {
  const base = "w-full px-3 py-2 rounded-lg text-sm outline-none";
  const style = { backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

  if (options) {
    return (
      <div>
        <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
        <select name={name} value={value} onChange={onChange} required={required} className={base} style={style}>
          <option value="">Select…</option>
          {options.map(option => <option key={option.value} value={option.value}>{option.label}</option>)}
        </select>
      </div>
    );
  }
  return (
    <div>
      <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{label}{required && ' *'}</label>
      <input name={name} type={type} value={value} onChange={onChange} required={required} className={base} style={style} />
    </div>
  );
}

function NewContractModal({ projectId, onClose }: { projectId: string; onClose: () => void }) {
  const queryClient = useQueryClient();
  const fileInputRef = useRef<HTMLInputElement>(null);
  const [contractFile, setContractFile] = useState<File | null>(null);
  const [form, setForm] = useState<ContractForm>({
    title: '', type: 'main_contract', reference_number: '', form_of_contract: '',
    party_name: '', contract_sum: '', currency: 'GBP', retention_percentage: '3',
    retention_cap_percentage: '5', payment_terms_days: '30',
    commencement_date: '', completion_date: '', execution_date: '', notes: '',
  });

  const { mutate, isPending } = useMutation({
    mutationFn: (data: ContractForm) => {
      if (contractFile) {
        const fd = new FormData();
        Object.entries(data).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) fd.append(k, v); });
        fd.append('contract_file', contractFile);
        return api.post(`/projects/${projectId}/contracts`, fd, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data);
      }
      return api.post(`/projects/${projectId}/contracts`, data).then(r => r.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['project-contracts', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-activities', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-stats', projectId] });
      queryClient.invalidateQueries({ queryKey: ['project-doc-explorer', projectId] });
      toast.success('Contract added');
      onClose();
    },
    onError: (error: unknown) => toast.error(getErrorMessage(error, 'Failed to create contract')),
  });

  const handleChange = (e: FormChangeEvent) => {
    setForm(prev => ({ ...prev, [e.target.name]: e.target.value }));
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-2xl rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5" style={{ borderBottom: '1px solid var(--border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>New Contract</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(form); }} className="p-5 space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="col-span-2">
              <InputField label="Title" name="title" value={form.title} onChange={handleChange} required />
            </div>
            <InputField label="Contract Type" name="type" value={form.type} onChange={handleChange} required options={CONTRACT_TYPES} />
            <InputField label="Reference Number" name="reference_number" value={form.reference_number} onChange={handleChange} />
            <InputField label="Contracting Party" name="party_name" value={form.party_name} onChange={handleChange} />
            <InputField label="Form of Contract" name="form_of_contract" value={form.form_of_contract} onChange={handleChange} />
            <InputField label="Contract Sum" name="contract_sum" type="number" value={form.contract_sum} onChange={handleChange} />
            <InputField label="Currency" name="currency" value={form.currency} onChange={handleChange} />
            <InputField label="Retention %" name="retention_percentage" type="number" value={form.retention_percentage} onChange={handleChange} />
            <InputField label="Retention Cap %" name="retention_cap_percentage" type="number" value={form.retention_cap_percentage} onChange={handleChange} />
            <InputField label="Payment Terms (days)" name="payment_terms_days" type="number" value={form.payment_terms_days} onChange={handleChange} />
            <InputField label="Execution Date" name="execution_date" type="date" value={form.execution_date} onChange={handleChange} />
            <InputField label="Commencement Date" name="commencement_date" type="date" value={form.commencement_date} onChange={handleChange} />
            <InputField label="Completion Date" name="completion_date" type="date" value={form.completion_date} onChange={handleChange} />
          </div>
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Notes</label>
            <textarea
              name="notes" value={form.notes} onChange={handleChange} rows={3}
              className="w-full px-3 py-2 rounded-lg text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-base)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
          </div>

          {/* Contract file upload */}
          <div>
            <label className="block text-xs mb-1" style={{ color: 'var(--text-muted)' }}>
              Contract File
              <span className="ml-1 text-xs" style={{ color: 'rgba(185,149,102,0.8)' }}>(Recommended)</span>
            </label>
            <input
              ref={fileInputRef}
              type="file"
              className="hidden"
              accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png"
              onChange={e => setContractFile(e.target.files?.[0] ?? null)}
            />
            {contractFile ? (
              <div
                className="flex items-center justify-between gap-3 px-3 py-2.5 rounded-lg"
                style={{ backgroundColor: 'rgba(185,149,102,0.08)', border: '1px solid rgba(185,149,102,0.3)' }}
              >
                <span className="text-xs truncate" style={{ color: 'var(--text-primary)' }}>{contractFile.name}</span>
                <button
                  type="button"
                  onClick={() => { setContractFile(null); if (fileInputRef.current) fileInputRef.current.value = ''; }}
                  className="text-xs flex-shrink-0"
                  style={{ color: 'var(--text-muted)' }}
                >
                  Remove
                </button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg border-dashed text-sm transition-colors hover:border-[var(--gold)]"
                style={{ border: '1.5px dashed var(--border)', color: 'var(--text-muted)' }}
              >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" /><polyline points="17 8 12 3 7 8" /><line x1="12" y1="3" x2="12" y2="15" />
                </svg>
                Click to attach contract file
              </button>
            )}
            {!contractFile && (
              <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>
                Uploading the contract is recommended. It will appear in Project Documents under Contracts.
              </p>
            )}
          </div>

          <div className="flex justify-end gap-3 pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 rounded-lg text-sm" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', opacity: isPending ? 0.7 : 1 }}>
              {isPending ? 'Creating…' : 'Create Contract'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

export default function ProjectContractsPage() {
  const formatCurrency = useCurrencyFormatter();
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useProjectPermissions();
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);
  const [editContract, setEditContract] = useState<(ProjectContract & Record<string, any>) | null>(null);

  const { data, isLoading } = useQuery<ApiCollection<ProjectContract>>({
    queryKey: ['project-contracts', id],
    queryFn: () => api.get(`/projects/${id}/contracts`).then(r => r.data),
  });

  const contracts = (data?.data ?? []).filter(contract =>
    contract.title?.toLowerCase().includes(search.toLowerCase()) ||
    contract.reference_number?.toLowerCase().includes(search.toLowerCase())
  );

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Contracts</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Contract documents and sub-contract agreements</p>
        </div>
        {canWrite && (
        <button
          onClick={() => setShowModal(true)}
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={15} />
          New Contract
        </button>
        )}
      </div>

      <div className="relative max-w-sm">
        <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2" style={{ color: 'var(--text-muted)' }} />
        <input
          value={search}
          onChange={e => setSearch(e.target.value)}
          placeholder="Search contracts…"
          className="w-full pl-9 pr-4 py-2 rounded-lg text-sm outline-none"
          style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
        />
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-20 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
          ))}
        </div>
      ) : contracts.length === 0 ? (
        <div className="rounded-2xl p-12 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
          <FileText size={32} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No contracts added yet</p>
          <button onClick={() => setShowModal(true)} className="mt-4 px-4 py-2 rounded-lg text-sm font-medium" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
            Add First Contract
          </button>
        </div>
      ) : (
        <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          <table className="w-full text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['Ref #', 'Title', 'Party', 'Contract Sum', 'Commencement', 'Completion', 'Status', ''].map(h => (
                  <th key={h} className="text-left px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {contracts.map(c => {
                const badge = STATUS_COLORS[c.status] ?? { bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
                return (
                  <tr key={c.id} className="hover:bg-[var(--bg-elevated)] transition-colors cursor-pointer" style={{ borderBottom: '1px solid var(--border)' }}>
                    <td className="px-5 py-3 font-mono text-xs font-semibold" style={{ color: 'var(--gold)' }}>{c.reference_number ?? `#${c.id}`}</td>
                    <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>
                      <div>{c.title}</div>
                      <div className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                        {CONTRACT_TYPES.find(t => t.value === c.type)?.label ?? c.type?.replace(/_/g, ' ') ?? ''}
                      </div>
                    </td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{c.party_name ?? '—'}</td>
                    <td className="px-5 py-3 text-xs font-medium" style={{ color: 'var(--text-primary)' }}>{formatCurrency(c.contract_sum ?? 0)}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{c.commencement_date ? formatDate(c.commencement_date) : '—'}</td>
                    <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{c.completion_date ? formatDate(c.completion_date) : '—'}</td>
                    <td className="px-5 py-3">
                      <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: badge.bg, color: badge.text }}>
                        {CONTRACT_STATUS_LABELS[c.status ?? ''] ?? c.status ?? 'Draft'}
                      </span>
                    </td>
                    <td className="px-5 py-3">
                      {canWrite && (
                        <button onClick={() => setEditContract(c as any)}
                          className="text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-elevated)]"
                          style={{ color: 'var(--text-muted)' }}>
                          Edit
                        </button>
                      )}
                      <PromptActionButton
                        label="Prompt"
                        module="Contracts"
                        recordType="contract"
                        recordId={c.id}
                        projectId={id}
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {canWrite && showModal && <NewContractModal projectId={id!} onClose={() => setShowModal(false)} />}
      {canWrite && editContract && (
        <EditContractModal contract={editContract} projectId={id!} onClose={() => setEditContract(null)} />
      )}
    </div>
  );
}
