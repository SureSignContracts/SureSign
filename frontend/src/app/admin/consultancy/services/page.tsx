'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { HeartHandshake, Plus } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Modal from '@/components/ui/Modal';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/getErrorMessage';

interface ConsultancyServiceRow {
  id: number;
  code: string;
  display_name: string;
  description: string | null;
  public_description: string | null;
  enabled: boolean;
  publicly_bookable: boolean;
  available_to_existing_customers: boolean;
  price_minor_units: number | null;
  currency: string;
  display_order: number;
  is_introductory: boolean;
  appointment_type: { duration_minutes: number };
}

const EMPTY_FORM = {
  code: '',
  display_name: '',
  description: '',
  public_description: '',
  duration_minutes: 30,
  price_pounds: '',
  currency: 'GBP',
  display_order: 0,
  is_introductory: false,
  enabled: true,
  publicly_bookable: true,
  available_to_existing_customers: true,
};

function poundsToMinorUnits(value: string): number | null {
  if (value.trim() === '') return null;
  const parsed = Number(value);
  if (Number.isNaN(parsed)) return null;
  return Math.round(parsed * 100);
}

function minorUnitsToPounds(value: number | null): string {
  return value === null ? '' : (value / 100).toFixed(2);
}

export default function ConsultancyServicesPage() {
  const qc = useQueryClient();
  // Both Super Admin and Admin manage the catalogue (Pricing Management
  // precedent — both platform-wide roles) — no client-side role gate here,
  // matching that page's convention; the API is the real boundary.

  const [editing, setEditing] = useState<ConsultancyServiceRow | null>(null);
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm] = useState(EMPTY_FORM);
  const [formErrors, setFormErrors] = useState<Record<string, string[]>>({});

  const { data: services, isLoading } = useQuery({
    queryKey: ['consultancy-services', 'all'],
    queryFn: () => api.get('/consultancy-services').then(r => r.data as ConsultancyServiceRow[]),
  });

  const saveMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      editing
        ? api.put(`/consultancy-services/${editing.id}`, payload).then(r => r.data)
        : api.post('/consultancy-services', payload).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['consultancy-services'] });
      toast.success(editing ? 'Consultancy service updated.' : 'Consultancy service created.');
      closeModal();
    },
    onError: (err: any) => {
      const errors = err?.response?.data?.errors ?? {};
      setFormErrors(errors);
      if (!Object.keys(errors).length) toast.error(getErrorMessage(err, 'Failed to save.'));
    },
  });

  function openCreate() {
    setEditing(null);
    setForm(EMPTY_FORM);
    setFormErrors({});
    setModalOpen(true);
  }

  function openEdit(s: ConsultancyServiceRow) {
    setEditing(s);
    setForm({
      code: s.code,
      display_name: s.display_name,
      description: s.description ?? '',
      public_description: s.public_description ?? '',
      duration_minutes: s.appointment_type.duration_minutes,
      price_pounds: minorUnitsToPounds(s.price_minor_units),
      currency: s.currency,
      display_order: s.display_order,
      is_introductory: s.is_introductory,
      enabled: s.enabled,
      publicly_bookable: s.publicly_bookable,
      available_to_existing_customers: s.available_to_existing_customers,
    });
    setFormErrors({});
    setModalOpen(true);
  }

  function closeModal() {
    setModalOpen(false);
    setEditing(null);
  }

  function handleSave() {
    const { price_pounds, ...rest } = form;
    const payload: Record<string, unknown> = { ...rest, price_minor_units: poundsToMinorUnits(price_pounds) };
    if (editing) delete payload.code; // immutable after creation
    saveMutation.mutate(payload);
  }

  function formatPrice(s: ConsultancyServiceRow): string {
    if (s.price_minor_units === null) return '—';
    const symbol = s.currency === 'GBP' ? '£' : `${s.currency} `;
    return `${symbol}${(s.price_minor_units / 100).toFixed(2)}`;
  }

  return (
    <div className="p-6 max-w-7xl mx-auto space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <HeartHandshake size={20} /> Consultancy Services
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
            The Consultancy Service catalogue — each service is a commercial/presentation wrapper around a scheduling type. Super Admin or Admin can manage this.
          </p>
        </div>
        <Button onClick={openCreate}><Plus size={14} /> New Service</Button>
      </div>

      <div className="rounded-2xl overflow-x-auto" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <table className="w-full min-w-[820px]">
          <thead>
            <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
              {['Service', 'Duration', 'Price', 'Visibility', 'Status', ''].map((h, i) => (
                <th key={i} className="text-left px-4 py-3 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {isLoading ? (
              [...Array(3)].map((_, i) => (
                <tr key={i} style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
                  {[...Array(6)].map((_, j) => (
                    <td key={j} className="px-4 py-3"><div className="h-4 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)', width: '60%' }} /></td>
                  ))}
                </tr>
              ))
            ) : !services || services.length === 0 ? (
              <tr><td colSpan={6}><EmptyState icon={HeartHandshake} title="No Consultancy Services yet" description="Create your first service to start offering consultations." /></td></tr>
            ) : services.map((s, idx) => (
              <tr key={s.id} style={{ borderBottom: idx < services.length - 1 ? '1px solid var(--border)' : undefined, backgroundColor: 'var(--bg-surface)' }}>
                <td className="px-4 py-3 text-sm font-medium" style={{ color: 'var(--text-primary)' }}>
                  {s.display_name}
                  {s.is_introductory && <Badge tone="accent" className="ml-2">Introductory</Badge>}
                </td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{s.appointment_type.duration_minutes} min</td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>{formatPrice(s)}</td>
                <td className="px-4 py-3 text-sm" style={{ color: 'var(--text-secondary)' }}>
                  {[s.publicly_bookable && 'Public', s.available_to_existing_customers && 'Customers'].filter(Boolean).join(' · ') || 'Internal only'}
                </td>
                <td className="px-4 py-3"><Badge tone={s.enabled ? 'success' : 'neutral'}>{s.enabled ? 'Enabled' : 'Disabled'}</Badge></td>
                <td className="px-4 py-3">
                  <button onClick={() => openEdit(s)} className="text-xs font-medium hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style={{ color: 'var(--gold)' }} aria-label={`Edit ${s.display_name}`}>
                    Edit
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {modalOpen && (
        <Modal title={editing ? 'Edit Consultancy Service' : 'New Consultancy Service'} icon={HeartHandshake} onClose={closeModal} busy={saveMutation.isPending}>
          {(close) => (
            <div className="space-y-3 max-h-[70vh] overflow-y-auto pr-1">
              <div className="space-y-1">
                <label htmlFor="cs-code" className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Code</label>
                <Input
                  id="cs-code"
                  placeholder="e.g. quick-consultation"
                  value={form.code}
                  disabled={!!editing}
                  onChange={e => setForm(f => ({ ...f, code: e.target.value }))}
                  error={formErrors.code?.[0]}
                  aria-describedby="cs-code-hint"
                />
                {!editing && <p id="cs-code-hint" className="text-xs" style={{ color: 'var(--text-muted)' }}>Cannot be changed after creation.</p>}
              </div>

              <div className="space-y-1">
                <label htmlFor="cs-name" className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Display name</label>
                <Input id="cs-name" value={form.display_name} onChange={e => setForm(f => ({ ...f, display_name: e.target.value }))} error={formErrors.display_name?.[0]} />
              </div>

              <div className="space-y-1">
                <label htmlFor="cs-description" className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Internal description (optional)</label>
                <textarea
                  id="cs-description"
                  value={form.description}
                  onChange={e => setForm(f => ({ ...f, description: e.target.value }))}
                  className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none resize-none focus:ring-2 focus:ring-[var(--gold)]/30"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  rows={2}
                />
              </div>
              <div className="space-y-1">
                <label htmlFor="cs-public-description" className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Public description (shown to customers)</label>
                <textarea
                  id="cs-public-description"
                  value={form.public_description}
                  onChange={e => setForm(f => ({ ...f, public_description: e.target.value }))}
                  className="w-full px-3.5 py-2.5 rounded-lg text-sm outline-none resize-none focus:ring-2 focus:ring-[var(--gold)]/30"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                  rows={2}
                />
              </div>

              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div className="space-y-1">
                  <label htmlFor="cs-duration" className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Duration (min)</label>
                  <Input id="cs-duration" type="number" min={5} value={form.duration_minutes} onChange={e => setForm(f => ({ ...f, duration_minutes: Number(e.target.value) }))} error={formErrors.duration_minutes?.[0]} />
                </div>
                <div className="space-y-1">
                  <label htmlFor="cs-price" className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Price ({form.currency})</label>
                  <Input id="cs-price" type="number" step="0.01" min={0} value={form.price_pounds} onChange={e => setForm(f => ({ ...f, price_pounds: e.target.value }))} error={formErrors.price_minor_units?.[0]} />
                </div>
                <div className="space-y-1">
                  <label htmlFor="cs-order" className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Display order</label>
                  <Input id="cs-order" type="number" min={0} value={form.display_order} onChange={e => setForm(f => ({ ...f, display_order: Number(e.target.value) }))} />
                </div>
              </div>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Price is display-only in this phase — no payment is collected yet.
              </p>

              <div className="space-y-2 pt-1">
                <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
                  <input type="checkbox" checked={form.enabled} onChange={e => setForm(f => ({ ...f, enabled: e.target.checked }))} />
                  Enabled
                </label>
                <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
                  <input type="checkbox" checked={form.publicly_bookable} onChange={e => setForm(f => ({ ...f, publicly_bookable: e.target.checked }))} />
                  Publicly bookable (marketing site)
                </label>
                <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
                  <input type="checkbox" checked={form.available_to_existing_customers} onChange={e => setForm(f => ({ ...f, available_to_existing_customers: e.target.checked }))} />
                  Available to existing customers (in-app)
                </label>
                <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
                  <input type="checkbox" checked={form.is_introductory} onChange={e => setForm(f => ({ ...f, is_introductory: e.target.checked }))} />
                  Introductory service (e.g. Quick Consultation)
                </label>
              </div>

              <div className="flex gap-2 pt-2">
                <Button variant="secondary" className="flex-1" onClick={close} disabled={saveMutation.isPending}>Cancel</Button>
                <Button className="flex-1" disabled={saveMutation.isPending} onClick={handleSave}>
                  {saveMutation.isPending ? 'Saving…' : 'Save'}
                </Button>
              </div>
            </div>
          )}
        </Modal>
      )}
    </div>
  );
}
