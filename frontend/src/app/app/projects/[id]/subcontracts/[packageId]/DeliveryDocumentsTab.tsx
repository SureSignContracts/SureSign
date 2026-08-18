'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { effectiveTodayYmd } from '@/lib/dateTime';
import { Plus, X, Trash2 } from 'lucide-react';
import toast from 'react-hot-toast';
import Select from '@/components/ui/Select';
import { getErrorMessage, INPUT_STYLE, CATEGORY_LABELS, StatusBadge, Field } from '@/components/deliveryDocuments/deliveryDocumentShared';

type DeliveryDoc = {
  id: number;
  title: string;
  description: string | null;
  category: string;
  status: string;
  revision: string | null;
  due_date: string | null;
  expiry_date: string | null;
  is_ai_extracted: boolean;
  document_id: number | null;
  document?: { id: number; title: string | null; file_name: string | null } | null;
};

type AvailableDocument = { id: number; title: string | null; file_name: string | null; category: string | null };

export function DeliveryDocumentsTab({ projectId, tradePackageId, canWrite }: { projectId: string; tradePackageId: number; canWrite: boolean }) {
  const qc = useQueryClient();
  const listQueryKey = ['trade-package-delivery-documents', tradePackageId];

  const { data, isLoading } = useQuery<DeliveryDoc[]>({
    queryKey: listQueryKey,
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/delivery-documents`).then(r => r.data),
  });

  const [modalDoc, setModalDoc] = useState<DeliveryDoc | 'new' | null>(null);
  const [confirmTarget, setConfirmTarget] = useState<DeliveryDoc | null>(null);

  const deleteMutation = useMutation({
    // Project-scoped route — see delivery-documents/page.tsx's identical fix.
    mutationFn: (doc: DeliveryDoc) => api.delete(`/projects/${projectId}/delivery-documents/${doc.id}`),
    onSuccess: () => {
      toast.success('Delivery document deleted');
      qc.invalidateQueries({ queryKey: listQueryKey });
      setConfirmTarget(null);
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to delete')),
  });

  const docs = data ?? [];
  // Must agree with the backend's own organisation-timezone-aware "today"
  // (TimezoneResolver), not the UTC calendar day.
  const today = effectiveTodayYmd();

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
          RAMS, method statements, ITPs, permits and other construction control documents required for this trade package.
        </p>
        {canWrite && (
          <button
            onClick={() => setModalDoc('new')}
            className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold whitespace-nowrap"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            <Plus size={13} /> Add Document
          </button>
        )}
      </div>

      <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <table className="w-full text-sm">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Title', 'Category', 'Status', 'Revision', 'Due Date', 'Expiry', 'Linked Document', 'Source', ''].map(h => (
                <th key={h} className="text-left px-3 py-2.5 text-xs font-semibold" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</td></tr>
            )}
            {!isLoading && docs.length === 0 && (
              <tr><td colSpan={9} className="text-center py-8 text-sm" style={{ color: 'var(--text-muted)' }}>No delivery documents recorded for this trade package yet.</td></tr>
            )}
            {docs.map(doc => {
              const isOverdue = !!doc.due_date && doc.due_date < today && !['approved', 'superseded'].includes(doc.status);
              const isExpired = !!doc.expiry_date && doc.expiry_date < today;
              return (
                <tr key={doc.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-3 py-2.5">
                    <button onClick={() => setModalDoc(doc)} className="text-left hover:underline" style={{ color: 'var(--text-primary)' }}>
                      {doc.title}
                    </button>
                  </td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{CATEGORY_LABELS[doc.category] ?? doc.category}</td>
                  <td className="px-3 py-2.5"><StatusBadge status={doc.status} /></td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{doc.revision ?? '—'}</td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: isOverdue ? '#f87171' : 'var(--text-secondary)' }}>
                    {doc.due_date ? formatDate(doc.due_date) : '—'}{isOverdue ? ' (overdue)' : ''}
                  </td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: isExpired ? '#f87171' : 'var(--text-secondary)' }}>
                    {doc.expiry_date ? formatDate(doc.expiry_date) : '—'}{isExpired ? ' (expired)' : ''}
                  </td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-secondary)' }}>{doc.document?.title ?? doc.document?.file_name ?? '—'}</td>
                  <td className="px-3 py-2.5 text-xs" style={{ color: 'var(--text-muted)' }}>{doc.is_ai_extracted ? 'AI' : 'Manual'}</td>
                  <td className="px-3 py-2.5 text-right">
                    {canWrite && (
                      <button onClick={() => setConfirmTarget(doc)} title="Delete">
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

      {modalDoc && (
        <DeliveryDocumentModal
          projectId={projectId}
          tradePackageId={tradePackageId}
          doc={modalDoc === 'new' ? null : modalDoc}
          invalidateKey={listQueryKey}
          onClose={() => setModalDoc(null)}
        />
      )}

      {confirmTarget && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
          <div className="w-full max-w-sm rounded-xl p-5" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
            <p className="text-sm mb-4" style={{ color: 'var(--text-primary)' }}>
              Delete &ldquo;{confirmTarget.title}&rdquo;? This cannot be undone.
            </p>
            <div className="flex justify-end gap-2">
              <button onClick={() => setConfirmTarget(null)} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
              <button
                onClick={() => deleteMutation.mutate(confirmTarget)}
                className="px-3 py-1.5 rounded-lg text-sm font-semibold text-white"
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

function DeliveryDocumentModal({ projectId, tradePackageId, doc, invalidateKey, onClose }: {
  projectId: string; tradePackageId: number; doc: DeliveryDoc | null; invalidateKey: (string | number)[]; onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!doc;

  const { data: availableDocs } = useQuery<AvailableDocument[]>({
    queryKey: ['trade-package-available-documents', tradePackageId],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${tradePackageId}/delivery-documents/available-documents`).then(r => r.data),
  });

  const [form, setForm] = useState({
    title: doc?.title ?? '',
    description: doc?.description ?? '',
    category: doc?.category ?? 'other',
    status: doc?.status ?? 'required',
    revision: doc?.revision ?? '',
    document_id: doc?.document_id ? String(doc.document_id) : '',
    due_date: doc?.due_date ?? '',
    expiry_date: doc?.expiry_date ?? '',
  });

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        ...form,
        document_id: form.document_id || null,
        due_date: form.due_date || null,
        expiry_date: form.expiry_date || null,
      };
      return isEdit
        ? api.put(`/projects/${projectId}/delivery-documents/${doc!.id}`, payload)
        : api.post(`/projects/${projectId}/trade-packages/${tradePackageId}/delivery-documents`, payload);
    },
    onSuccess: () => {
      toast.success(isEdit ? 'Delivery document updated' : 'Delivery document added');
      qc.invalidateQueries({ queryKey: invalidateKey });
      onClose();
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to save delivery document')),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.5)' }}>
      <div className="w-full max-w-lg rounded-xl p-6 max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>{isEdit ? 'Edit Delivery Document' : 'Add Delivery Document'}</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="space-y-3">
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
                <option value="expired">Expired</option>
                <option value="superseded">Superseded</option>
              </Select>
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Due Date">
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.due_date} onChange={e => setForm(f => ({ ...f, due_date: e.target.value }))} />
            </Field>
            <Field label="Expiry Date">
              <input type="date" className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
                value={form.expiry_date} onChange={e => setForm(f => ({ ...f, expiry_date: e.target.value }))} />
            </Field>
          </div>
          <Field label="Revision">
            <input className="w-full px-3 py-2 rounded-lg text-sm outline-none" style={INPUT_STYLE}
              value={form.revision} onChange={e => setForm(f => ({ ...f, revision: e.target.value }))} />
          </Field>
          <Field label="Link Existing Document">
            <Select className="w-full"
              value={form.document_id} onChange={e => setForm(f => ({ ...f, document_id: e.target.value }))}>
              <option value="">None</option>
              {(availableDocs ?? []).map(d => (
                <option key={d.id} value={d.id}>{d.title ?? d.file_name ?? `Document #${d.id}`}</option>
              ))}
            </Select>
          </Field>
        </div>
        <div className="flex justify-end gap-2 mt-5">
          <button onClick={onClose} className="px-3 py-1.5 rounded-lg text-sm" style={{ color: 'var(--text-secondary)' }}>Cancel</button>
          <button
            onClick={() => mutation.mutate()}
            disabled={!form.title || mutation.isPending}
            className="px-3 py-1.5 rounded-lg text-sm font-semibold disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Document'}
          </button>
        </div>
      </div>
    </div>
  );
}
