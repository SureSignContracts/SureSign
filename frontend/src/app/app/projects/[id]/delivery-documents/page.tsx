'use client';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { Plus, X, Trash2, FileStack } from 'lucide-react';
import toast from 'react-hot-toast';
import { getErrorMessage, INPUT_STYLE, CATEGORY_LABELS, StatusBadge, Field } from '@/components/deliveryDocuments/deliveryDocumentShared';
import PageTourButton from '@/components/tours/PageTourButton';
import Button from '@/components/ui/Button';

type DeliveryDoc = {
  id: number;
  title: string;
  category: string;
  status: string;
  revision: string | null;
  due_date: string | null;
  expiry_date: string | null;
  is_ai_extracted: boolean;
  contract_id: number | null;
  trade_package_id: number | null;
  source_name: string | null;
  action_url: string | null;
};

type FilterStatus = 'all' | 'required' | 'pending' | 'submitted' | 'under_review' | 'approved' | 'rejected' | 'expired' | 'superseded';

export default function DeliveryDocumentsPage() {
  const { id: projectId } = useParams<{ id: string }>();
  const router = useRouter();
  const qc = useQueryClient();
  const [filter, setFilter] = useState<FilterStatus>('all');
  const [showCreate, setShowCreate] = useState(false);
  const [confirmTarget, setConfirmTarget] = useState<DeliveryDoc | null>(null);

  const listQueryKey = ['project-delivery-documents', projectId];

  const { data, isLoading } = useQuery<{ data: DeliveryDoc[] }>({
    queryKey: listQueryKey,
    queryFn: () => api.get(`/projects/${projectId}/delivery-documents`).then(r => r.data),
  });

  const deleteMutation = useMutation({
    mutationFn: (doc: DeliveryDoc) => api.delete(`/delivery-documents/${doc.id}`),
    onSuccess: () => {
      toast.success('Delivery document deleted');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setConfirmTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete')),
  });

  const docs = data?.data ?? [];
  const filtered = filter === 'all' ? docs : docs.filter(d => d.status === filter);
  // Must agree with the backend's own organisation-timezone-aware "today"
  // (TimezoneResolver), not the UTC calendar day.
  const today = effectiveTodayYmd();

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6 pb-12">
      <div className="ss-animate-in flex items-start justify-between gap-4 flex-wrap">
        <div>
          <div className="flex items-center gap-1.5">
            <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>
              Delivery documents
            </h1>
            <PageTourButton tourKey="page-delivery-documents" label="Take a tour of this page" />
          </div>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            RAMS, method statements, ITPs, permits and other construction control documents, across the main contract and every trade package.
          </p>
        </div>
        <button
          data-tour="delivery-documents-new"
          onClick={() => setShowCreate(true)}
          className="flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-all active:scale-[0.98] hover:opacity-90"
          style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
        >
          <Plus size={14} /> Add document
        </button>
      </div>

      <div className="ss-animate-in flex gap-1 p-1 rounded-full w-fit flex-wrap" data-tour="delivery-documents-filters" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', animationDelay: '60ms' }}>
        {(['all', 'required', 'pending', 'submitted', 'under_review', 'approved', 'rejected', 'expired', 'superseded'] as const).map(s => (
          <button
            key={s}
            onClick={() => setFilter(s)}
            className="px-3 py-1.5 rounded-full text-xs font-medium capitalize transition-all active:scale-[0.97]"
            style={filter === s
              ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
              : { color: 'var(--text-secondary)' }}
          >
            {s === 'all' ? `All (${docs.length})` : s.replace('_', ' ')}
          </button>
        ))}
      </div>

      <div className="ss-animate-in rounded-xl overflow-hidden" data-tour="delivery-documents-table" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '120ms' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Title', 'Source', 'Category', 'Status', 'Due Date', 'Expiry', 'Origin', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={8} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            )}
            {!isLoading && filtered.length === 0 && (
              <tr className="ss-animate-in">
                <td colSpan={8} className="px-3 py-12 text-center">
                  <FileStack size={28} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
                  <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
                    No delivery documents{filter !== 'all' ? ` with status "${filter}"` : ' recorded for this project yet'}.
                  </p>
                  {filter === 'all' && (
                    <Button onClick={() => setShowCreate(true)} variant="secondary" size="sm" className="mt-3">
                      Add first document
                    </Button>
                  )}
                </td>
              </tr>
            )}
            {filtered.map((doc, index) => {
              const isOverdue = !!doc.due_date && doc.due_date < today && !['approved', 'superseded'].includes(doc.status);
              const isExpired = !!doc.expiry_date && doc.expiry_date < today;
              return (
                <tr key={doc.id} className="ss-animate-in hover:bg-[var(--bg-hover)] transition-colors" style={{ borderBottom: '1px solid var(--border)', animationDelay: `${Math.min(index * 45, 360)}ms` }}>
                  <td className="px-3 py-2.5">
                    <button
                      onClick={() => doc.action_url && router.push(doc.action_url)}
                      className="text-left hover:underline"
                      style={{ color: 'var(--text-primary)' }}
                    >
                      {doc.title}
                    </button>
                  </td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{doc.source_name ?? '—'}</td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{CATEGORY_LABELS[doc.category] ?? doc.category}</td>
                  <td className="px-3 py-2.5"><StatusBadge status={doc.status} /></td>
                  <td className="px-3 py-2.5 text-xs tabular-nums" style={{ color: isOverdue ? '#f87171' : 'var(--text-secondary)' }}>
                    {doc.due_date ? formatDate(doc.due_date) : '—'}{isOverdue ? ' (overdue)' : ''}
                  </td>
                  <td className="px-3 py-2.5 text-xs tabular-nums" style={{ color: isExpired ? '#f87171' : 'var(--text-secondary)' }}>
                    {doc.expiry_date ? formatDate(doc.expiry_date) : '—'}{isExpired ? ' (expired)' : ''}
                  </td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>{doc.is_ai_extracted ? 'AI' : 'Manual'}</td>
                  <td className="px-3 py-2.5 text-right">
                    {!doc.is_ai_extracted && (
                      <button onClick={() => setConfirmTarget(doc)} title="Delete" className="p-1 rounded-lg transition-colors hover:bg-[var(--bg-hover)]">
                        <Trash2 size={14} style={{ color: 'var(--text-muted)' }} />
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {showCreate && (
        <CreateDeliveryDocumentModal projectId={projectId} invalidateKey={listQueryKey} onClose={() => setShowCreate(false)} />
      )}

      {confirmTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="ss-animate-in w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete &ldquo;{confirmTarget.title}&rdquo;? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmTarget(null)} className="px-3 py-1.5 rounded-lg text-sm transition-all active:scale-[0.98]" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => deleteMutation.mutate(confirmTarget)}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white transition-all active:scale-[0.98]"
                style={{ backgroundColor: '#a11a1a' }}
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function CreateDeliveryDocumentModal({ projectId, invalidateKey, onClose }: {
  projectId: string; invalidateKey: (string | number)[]; onClose: () => void;
}) {
  const qc = useQueryClient();

  const { data: contractsData } = useQuery({
    queryKey: ['project-contracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/contracts`).then(r => r.data?.data ?? r.data ?? []),
  });
  const { data: subcontractsData } = useQuery({
    queryKey: ['project-subcontracts', projectId],
    queryFn: () => api.get(`/projects/${projectId}/documents/module/subcontracts`).then(r => r.data),
  });

  const contracts: Array<{ id: number; title: string }> = Array.isArray(contractsData) ? contractsData : [];
  const tradePackages: Array<{ id: number; name: string }> = subcontractsData?.trade_packages ?? [];

  const [parentType, setParentType] = useState<'contract' | 'trade_package'>('contract');
  const [parentId, setParentId] = useState<number | ''>('');
  const [form, setForm] = useState({
    title: '', description: '', category: 'other', status: 'required',
    revision: '', due_date: '', expiry_date: '',
  });

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        due_date: form.due_date || null,
        expiry_date: form.expiry_date || null,
        contract_id: parentType === 'contract' ? parentId || null : null,
        trade_package_id: parentType === 'trade_package' ? parentId || null : null,
      };
      return api.post(`/projects/${projectId}/delivery-documents`, payload);
    },
    onSuccess: () => {
      toast.success('Delivery document added');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save delivery document')),
  });

  const parentOptions = parentType === 'contract' ? contracts : tradePackages;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 backdrop-blur-sm" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="ss-animate-in w-full max-w-lg rounded-xl p-6 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>Add delivery document</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <Field label="Applies to" required>
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={parentType} onChange={e => { setParentType(e.target.value as 'contract' | 'trade_package'); setParentId(''); }}>
                <option value="contract">Contract</option>
                <option value="trade_package">Trade Package</option>
              </select>
            </Field>
            <Field label={parentType === 'contract' ? 'Contract' : 'Trade package'} required>
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={parentId} onChange={e => setParentId(e.target.value ? Number(e.target.value) : '')}>
                <option value="">Select…</option>
                {parentOptions.map((o: { id: number; title?: string; name?: string }) => (
                  <option key={o.id} value={o.id}>{o.title ?? o.name}</option>
                ))}
              </select>
            </Field>
          </div>
          <Field label="Title" required>
            <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
              value={form.title} onChange={e => setForm(f => ({ ...f, title: e.target.value }))} />
          </Field>
          <Field label="Description">
            <textarea className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE} rows={2}
              value={form.description} onChange={e => setForm(f => ({ ...f, description: e.target.value }))} />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Category">
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))}>
                {Object.entries(CATEGORY_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            </Field>
            <Field label="Status">
              <select className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.status} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}>
                <option value="required">Required</option>
                <option value="pending">Pending</option>
                <option value="submitted">Submitted</option>
                <option value="under_review">Under Review</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </select>
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Due date">
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.due_date} onChange={e => setForm(f => ({ ...f, due_date: e.target.value }))} />
            </Field>
            <Field label="Expiry date">
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.expiry_date} onChange={e => setForm(f => ({ ...f, expiry_date: e.target.value }))} />
            </Field>
          </div>
          <Field label="Revision">
            <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
              value={form.revision} onChange={e => setForm(f => ({ ...f, revision: e.target.value }))} />
          </Field>
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <button onClick={onClose} className="px-3 py-1.5 rounded-lg text-sm transition-all active:scale-[0.98]" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => mutation.mutate()}
            disabled={!form.title || !parentId || mutation.isPending}
            className="px-3 py-1.5 rounded-lg text-sm font-semibold transition-all active:scale-[0.98] disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Saving…' : 'Add document'}
          </button>
        </div>
      </div>
    </div>
  );
}
