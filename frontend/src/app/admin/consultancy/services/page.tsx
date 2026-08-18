'use client';

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { HeartHandshake, Plus, Clock, Globe, ChevronRight } from 'lucide-react';
import toast from '@/lib/toast';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Input from '@/components/ui/Input';
import Modal from '@/components/ui/Modal';
import EmptyState from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/getErrorMessage';
import PlatformPageHero from '@/components/admin/PlatformPageHero';

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
    <div className="mx-auto max-w-7xl space-y-7 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero eyebrow="Service catalogue" title="Consultancy Services" description="Shape the consultancy offers customers can book, including duration, price and channel availability." loading={isLoading}
        metrics={[
          { label: 'Services', value: services?.length ?? 0, detail: 'catalogue entries', icon: HeartHandshake },
          { label: 'Enabled', value: services?.filter(service => service.enabled).length ?? 0, detail: 'available services', icon: Clock },
          { label: 'Public', value: services?.filter(service => service.publicly_bookable).length ?? 0, detail: 'marketing-site offers', icon: Globe },
        ]}
        action={<button onClick={openCreate} className="flex items-center gap-2 rounded-xl bg-[#9ee5b5] px-4 py-2.5 text-sm font-semibold text-[#18211d] transition-colors hover:bg-[#b3efc6] active:scale-[0.98]"><Plus size={14} />New service</button>}
      />

      {isLoading ? <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{[...Array(6)].map((_, i) => <div key={i} className="h-64 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }} />)}</div>
      : !services || services.length === 0 ? <div className="rounded-2xl" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}><EmptyState icon={HeartHandshake} title="No Consultancy Services yet" description="Create your first service to start offering consultations." /></div>
      : <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {services.map((service, index) => (
            <button key={service.id} onClick={() => openEdit(service)} className="group flex min-h-[260px] flex-col rounded-2xl p-5 text-left transition-all duration-300 hover:-translate-y-1 hover:border-[#9ee5b5]/70 hover:shadow-[0_18px_36px_rgba(24,33,29,0.10)] ss-animate-in" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: '0 3px 12px rgba(24,33,29,0.05)', animationDelay: `${Math.min(index * 55, 440)}ms` }}>
              <div className="flex items-center justify-between"><span className="font-mono text-[10px] uppercase tracking-[0.08em]" style={{ color: 'var(--text-muted)' }}>{service.code}</span><span className="inline-flex items-center gap-1.5 text-[11px] font-medium" style={{ color: service.enabled ? '#299a54' : 'var(--text-muted)' }}><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: service.enabled ? '#35b966' : 'var(--text-muted)' }} />{service.enabled ? 'Enabled' : 'Disabled'}</span></div>
              <h2 className="mt-6 text-lg font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>{service.display_name}</h2>
              <p className="mt-2 line-clamp-2 text-xs leading-relaxed" style={{ color: 'var(--text-muted)' }}>{service.public_description || service.description || 'No service description has been added.'}</p>
              <div className="mt-5 grid grid-cols-2 border-y py-3" style={{ borderColor: 'var(--border)' }}><div className="border-r" style={{ borderColor: 'var(--border)' }}><p className="text-[10px] uppercase tracking-[0.08em]" style={{ color: 'var(--text-muted)' }}>Duration</p><p className="mt-1 text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{service.appointment_type.duration_minutes} min</p></div><div className="pl-4"><p className="text-[10px] uppercase tracking-[0.08em]" style={{ color: 'var(--text-muted)' }}>Price</p><p className="mt-1 text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{formatPrice(service)}</p></div></div>
              <div className="mt-auto flex items-center justify-between pt-4"><div className="flex flex-wrap gap-x-3 text-[11px]" style={{ color: 'var(--text-secondary)' }}>{service.publicly_bookable && <span>Public</span>}{service.available_to_existing_customers && <span>Existing customers</span>}{service.is_introductory && <span style={{ color: 'var(--gold)' }}>Introductory</span>}</div><span className="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full transition-colors group-hover:bg-[#9ee5b5]"><ChevronRight size={14} className="transition-transform group-hover:translate-x-0.5" /></span></div>
            </button>
          ))}
        </div>}

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
