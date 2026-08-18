'use client';
import FeatureAvailabilityGate from '@/components/feature-availability/FeatureAvailabilityGate';

import { useState } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { Plus, X, Trash2, FileStack, CheckCircle2, ShieldAlert } from 'lucide-react';
import toast from '@/lib/toast';
import { getErrorMessage, INPUT_STYLE, CATEGORY_LABELS, StatusBadge, Field } from '@/components/deliveryDocuments/deliveryDocumentShared';
import PageTourButton from '@/components/tours/PageTourButton';
import Button from '@/components/ui/Button';
import Select from '@/components/ui/Select';
import { ProjectModuleHeader, ProjectModuleMetric } from '@/components/projects/ProjectModuleHeader';

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

function DeliveryDocumentsPage() {
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
    // Route is project-scoped — matches the registered
    // DELETE /projects/{project}/delivery-documents/{deliveryDocument}
    // (a bare /delivery-documents/{id} was never a registered route and
    // would 404; found during Feature Availability Phase C's route audit).
    mutationFn: (doc: DeliveryDoc) => api.delete(`/projects/${projectId}/delivery-documents/${doc.id}`),
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
  const approvedCount = docs.filter(doc => doc.status === 'approved').length;
  const reviewCount = docs.filter(doc => doc.status === 'submitted' || doc.status === 'under_review').length;
  const overdueCount = docs.filter(doc => !!doc.due_date && doc.due_date < today && !['approved', 'superseded'].includes(doc.status)).length;

  return (
    <div className="ss-projects-page mx-auto max-w-7xl space-y-6 p-4 pb-12 sm:p-6 lg:p-8">
      <ProjectModuleHeader
        category="Delivery control"
        title="Delivery documents"
        description="Control RAMS, method statements, ITPs, permits and package deliverables from one register."
        icon={FileStack}
        tour={<PageTourButton tourKey="page-delivery-documents" label="Take a tour of this page" />}
        action={(
          <button
            data-tour="delivery-documents-new"
            onClick={() => setShowCreate(true)}
            className="flex h-11 items-center gap-2 whitespace-nowrap rounded-xl bg-[#9ee5b5] px-5 text-sm font-semibold text-[#18211d] transition-all duration-200 hover:-translate-y-0.5 hover:bg-[#b4edc6] active:translate-y-0"
          >
            <Plus size={16} /> Add document
          </button>
        )}
        metricColumns={4}
      >
        <ProjectModuleMetric label="Total documents" value={docs.length} index={0} />
        <ProjectModuleMetric label="Approved" value={approvedCount} tone="#4ade80" index={1} />
        <ProjectModuleMetric label="In review" value={reviewCount} tone="#facc15" index={2} />
        <ProjectModuleMetric label="Overdue" value={overdueCount} tone={overdueCount > 0 ? '#f87171' : '#9ee5b5'} index={3} />
      </ProjectModuleHeader>

      <div className="ss-animate-in flex flex-col gap-3 rounded-2xl p-2 sm:flex-row sm:items-center sm:justify-between" data-tour="delivery-documents-filters" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '80ms' }}>
        <div className="flex gap-1 overflow-x-auto rounded-xl p-1" style={{ backgroundColor: 'var(--bg-elevated)' }}>
          {(['all', 'required', 'pending', 'submitted', 'under_review', 'approved', 'rejected', 'expired', 'superseded'] as const).map(s => (
            <button
              key={s}
              onClick={() => setFilter(s)}
              className="whitespace-nowrap rounded-lg px-3 py-2 text-xs font-semibold capitalize transition-all duration-200 hover:-translate-y-px active:translate-y-0"
              style={filter === s
                ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)', boxShadow: '0 5px 14px rgba(0,0,0,0.08)' }
                : { color: 'var(--text-secondary)' }}
            >
              {s === 'all' ? `All (${docs.length})` : s.replace('_', ' ')}
            </button>
          ))}
        </div>
        <p className="hidden items-center gap-2 pr-3 text-xs sm:flex" style={{ color: 'var(--text-muted)' }}>
          {overdueCount > 0 ? <><ShieldAlert size={13} style={{ color: '#f87171' }} /> {overdueCount} item{overdueCount === 1 ? '' : 's'} need attention</> : <><CheckCircle2 size={13} style={{ color: '#4ade80' }} /> Register is up to date</>}
        </p>
      </div>

      <div className="ss-animate-in overflow-hidden rounded-2xl" data-tour="delivery-documents-table" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '150ms' }}>
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
                <td colSpan={8} className="px-5 py-16 text-center">
                  <span className="mx-auto grid h-12 w-12 place-items-center rounded-2xl" style={{ backgroundColor: 'rgba(158,229,181,0.16)', color: '#48b66a' }}><FileStack size={22} /></span>
                  <p className="mt-4 text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                    No delivery documents{filter !== 'all' ? ` with status "${filter}"` : ' recorded for this project yet'}.
                  </p>
                  <p className="mx-auto mt-1 max-w-md text-xs leading-5" style={{ color: 'var(--text-muted)' }}>Build the register around RAMS, permits, ITPs and package deliverables so responsibilities and due dates stay visible.</p>
                  {filter === 'all' && (
                    <Button onClick={() => setShowCreate(true)} size="sm" className="mt-4">
                      <Plus size={14} /> Add first document
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
              <Select className="w-full"
                value={parentType} onChange={e => { setParentType(e.target.value as 'contract' | 'trade_package'); setParentId(''); }}>
                <option value="contract">Contract</option>
                <option value="trade_package">Trade Package</option>
              </Select>
            </Field>
            <Field label={parentType === 'contract' ? 'Contract' : 'Trade package'} required>
              <Select className="w-full"
                value={parentId} onChange={e => setParentId(e.target.value ? Number(e.target.value) : '')}>
                <option value="">Select…</option>
                {parentOptions.map((o: { id: number; title?: string; name?: string }) => (
                  <option key={o.id} value={o.id}>{o.title ?? o.name}</option>
                ))}
              </Select>
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
              <Select className="w-full"
                value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))}>
                {Object.entries(CATEGORY_LABELS).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </Select>
            </Field>
            <Field label="Status">
              <Select className="w-full"
                value={form.status} onChange={e => setForm(f => ({ ...f, status: e.target.value }))}>
                <option value="required">Required</option>
                <option value="pending">Pending</option>
                <option value="submitted">Submitted</option>
                <option value="under_review">Under Review</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
              </Select>
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

export default function GatedDeliveryDocumentsPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id as string;
  return (
    <FeatureAvailabilityGate featureKey="project.delivery_documents" title="Delivery Documents" backHref={`/app/projects/${id}/overview`} backLabel="Back to Project Overview">
      <DeliveryDocumentsPage />
    </FeatureAvailabilityGate>
  );
}
