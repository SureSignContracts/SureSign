'use client';

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import toast from '@/lib/toast';
import { getErrorMessage } from '@/lib/getErrorMessage';
import Button from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import Toggle from '@/components/ui/Toggle';
import { ArrowUp, ArrowDown, Plus, Save, Trash2 } from 'lucide-react';
import { PricingIncludedItem, PricingSettings } from '@/types/pricing';

const inputClass = 'w-full px-3 py-2.5 rounded-lg text-sm outline-none';
const inputStyle = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>{label}</label>
      {children}
      {hint && <p className="text-xs mt-1.5" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  );
}

export default function SettingsTab() {
  const qc = useQueryClient();
  const [form, setForm] = useState<Partial<PricingSettings> | null>(null);
  const [newItemText, setNewItemText] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['admin-pricing-settings'],
    queryFn: () => api.get('/admin/pricing/settings').then(r => r.data.data as PricingSettings),
  });

  const { data: items } = useQuery({
    queryKey: ['admin-pricing-included-items'],
    queryFn: () => api.get('/admin/pricing/included-items').then(r => r.data.data as PricingIncludedItem[]),
  });

  useEffect(() => {
    if (data && !form) setForm(data);
  }, [data]); // eslint-disable-line react-hooks/exhaustive-deps

  const saveMutation = useMutation({
    mutationFn: (payload: Partial<PricingSettings>) => api.put('/admin/pricing/settings', payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin-pricing-settings'] });
      toast.success('Pricing settings saved.');
    },
    onError: (e) => toast.error(getErrorMessage(e, 'Failed to save pricing settings.')),
  });

  const invalidateItems = () => qc.invalidateQueries({ queryKey: ['admin-pricing-included-items'] });
  const createItem = useMutation({
    mutationFn: (text: string) => api.post('/admin/pricing/included-items', { text, icon: 'check-circle' }),
    onSuccess: () => { setNewItemText(''); invalidateItems(); },
  });
  const updateItem = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<PricingIncludedItem> }) => api.put(`/admin/pricing/included-items/${id}`, payload),
    onSuccess: invalidateItems,
  });
  const deleteItem = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/pricing/included-items/${id}`),
    onSuccess: invalidateItems,
  });
  const reorderItems = useMutation({
    mutationFn: (order: number[]) => api.put('/admin/pricing/included-items/reorder', { order }),
    onSuccess: invalidateItems,
  });

  const orderedItems = (items || []).slice().sort((a, b) => a.order - b.order);

  function moveItem(index: number, dir: -1 | 1) {
    const target = index + dir;
    if (target < 0 || target >= orderedItems.length) return;
    const order = orderedItems.map(i => i.id);
    [order[index], order[target]] = [order[target], order[index]];
    reorderItems.mutate(order);
  }

  if (isLoading || !form) return <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading settings…</p>;

  const set = (k: keyof PricingSettings) => (v: any) => setForm(p => ({ ...p, [k]: v }));

  return (
    <div className="space-y-8">
      <section className="space-y-4">
        <div className="flex items-center justify-between">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Publish Status</h3>
          <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-primary)' }}>
            {form.published ? 'Live on marketing site' : 'Not published'}
            <Toggle checked={!!form.published} onChange={set('published')} />
          </label>
        </div>
      </section>

      <section className="space-y-4">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Hero &amp; Section</h3>
        <Card>
          <CardBody className="space-y-4">
            <Field label="Hero title">
              <input value={form.hero_title || ''} onChange={e => set('hero_title')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Hero subtitle">
              <textarea value={form.hero_subtitle || ''} onChange={e => set('hero_subtitle')(e.target.value)} rows={2} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Section title (above the pricing cards)">
              <input value={form.section_title || ''} onChange={e => set('section_title')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
          </CardBody>
        </Card>
      </section>

      <section className="space-y-4">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Billing Toggle</h3>
        <Card>
          <CardBody className="space-y-4">
            <div className="flex items-center justify-between">
              <span className="text-sm" style={{ color: 'var(--text-primary)' }}>Monthly billing enabled</span>
              <Toggle checked={!!form.monthly_billing_enabled} onChange={set('monthly_billing_enabled')} />
            </div>
            <div className="flex items-center justify-between">
              <span className="text-sm" style={{ color: 'var(--text-primary)' }}>Annual billing enabled</span>
              <Toggle checked={!!form.annual_billing_enabled} onChange={set('annual_billing_enabled')} />
            </div>
            <Field label="Discount label (e.g. &quot;Save 15% billed annually&quot;)">
              <input value={form.discount_label || ''} onChange={e => set('discount_label')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
          </CardBody>
        </Card>
      </section>

      <section className="space-y-4">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Everything Included</h3>
        <Card>
          <CardBody className="space-y-4">
            <Field label="Title">
              <input value={form.everything_included_title || ''} onChange={e => set('everything_included_title')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Subtitle">
              <textarea value={form.everything_included_subtitle || ''} onChange={e => set('everything_included_subtitle')(e.target.value)} rows={2} className={inputClass} style={inputStyle} />
            </Field>
          </CardBody>
        </Card>

        <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
          {orderedItems.map((item, i) => (
            <div key={item.id} className="flex items-center gap-3 px-4 py-3" style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
              <div className="flex gap-1">
                <button onClick={() => moveItem(i, -1)} disabled={i === 0} className="disabled:opacity-30"><ArrowUp size={13} /></button>
                <button onClick={() => moveItem(i, 1)} disabled={i === orderedItems.length - 1} className="disabled:opacity-30"><ArrowDown size={13} /></button>
              </div>
              <input
                value={item.text}
                onChange={e => updateItem.mutate({ id: item.id, payload: { text: e.target.value } })}
                className="flex-1 px-2 py-1 rounded-md text-sm outline-none"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' }}
              />
              <Toggle checked={item.is_visible} onChange={v => updateItem.mutate({ id: item.id, payload: { is_visible: v } })} />
              <button onClick={() => deleteItem.mutate(item.id)} style={{ color: '#ef4444' }}><Trash2 size={13} /></button>
            </div>
          ))}
          <div className="flex gap-2 px-4 py-3" style={{ backgroundColor: 'var(--bg-surface)' }}>
            <input
              value={newItemText}
              onChange={e => setNewItemText(e.target.value)}
              placeholder="New included item"
              className="flex-1 px-2.5 py-1.5 rounded-md text-sm outline-none"
              style={inputStyle}
            />
            <Button size="sm" variant="secondary" onClick={() => newItemText.trim() && createItem.mutate(newItemText.trim())}>
              <Plus size={12} /> Add
            </Button>
          </div>
        </div>
      </section>

      <section className="space-y-4">
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Final CTA</h3>
        <Card>
          <CardBody className="space-y-4">
            <Field label="Title">
              <input value={form.final_cta_title || ''} onChange={e => set('final_cta_title')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Subtitle">
              <textarea value={form.final_cta_subtitle || ''} onChange={e => set('final_cta_subtitle')(e.target.value)} rows={2} className={inputClass} style={inputStyle} />
            </Field>
            <div className="grid grid-cols-2 gap-4">
              <Field label="Primary CTA text">
                <input value={form.primary_cta_text || ''} onChange={e => set('primary_cta_text')(e.target.value)} className={inputClass} style={inputStyle} />
              </Field>
              <Field label="Primary CTA URL">
                <input value={form.primary_cta_url || ''} onChange={e => set('primary_cta_url')(e.target.value)} className={inputClass} style={inputStyle} />
              </Field>
              <Field label="Secondary CTA text">
                <input value={form.secondary_cta_text || ''} onChange={e => set('secondary_cta_text')(e.target.value)} className={inputClass} style={inputStyle} />
              </Field>
              <Field label="Secondary CTA URL">
                <input value={form.secondary_cta_url || ''} onChange={e => set('secondary_cta_url')(e.target.value)} className={inputClass} style={inputStyle} />
              </Field>
            </div>
          </CardBody>
        </Card>
      </section>

      <div className="flex justify-end">
        <Button size="lg" onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
          <Save size={15} /> {saveMutation.isPending ? 'Saving…' : 'Save Settings'}
        </Button>
      </div>
    </div>
  );
}
