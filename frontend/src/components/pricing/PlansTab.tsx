'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import Toggle from '@/components/ui/Toggle';
import {
  ArrowUp, ArrowDown, ChevronDown, ChevronRight, Copy, Plus, Save, Trash2, Archive, Rocket, Pencil, Layers,
} from 'lucide-react';
import {
  ACCENT_COLORS, BACKGROUND_STYLES, BADGE_COLORS, PLAN_ICONS, PricingPlan,
} from '@/types/pricing';
import EntitlementsEditorModal from './EntitlementsEditor';
import CopyPlanDialog from './CopyPlanDialog';

const inputClass = 'w-full px-3 py-2 rounded-lg text-sm outline-none';
const inputStyle = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

function emptyForm(): Partial<PricingPlan> {
  return {
    code: '', slug: '', name: '', currency: 'GBP', price_prefix: '', price_suffix: '',
    description: '', summary: '', cta_text: '', cta_url: '', cta_new_tab: false,
    is_visible: true, is_popular: false, badge_text: '', badge_color: '', accent_color: '',
    background_style: '', icon: '', custom_label: '',
  };
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>{label}</label>
      {children}
    </div>
  );
}

function Select({ value, onChange, options, allowBlank = true }: { value: string; onChange: (v: string) => void; options: readonly string[]; allowBlank?: boolean }) {
  return (
    <select value={value} onChange={e => onChange(e.target.value)} className={inputClass} style={inputStyle}>
      {allowBlank && <option value="">—</option>}
      {options.map(o => <option key={o} value={o}>{o}</option>)}
    </select>
  );
}

/** Stage 2/8 — an expandable section so the editor stays comfortable as more capability is added. */
function Section({ title, subtitle, defaultOpen = true, children }: { title: string; subtitle?: string; defaultOpen?: boolean; children: React.ReactNode }) {
  const [open, setOpen] = useState(defaultOpen);

  return (
    <div className="rounded-lg" style={{ border: '1px solid var(--border)' }}>
      <button
        type="button"
        onClick={() => setOpen(o => !o)}
        className="w-full flex items-center justify-between px-4 py-2.5"
      >
        <div className="text-left">
          <span className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</span>
          {subtitle && <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{subtitle}</p>}
        </div>
        {open ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
      </button>
      {open && <div className="px-4 pb-4 space-y-3">{children}</div>}
    </div>
  );
}

function PlanForm({ initial, isNew, onCancel, onSaved, onManageEntitlements }: {
  initial: Partial<PricingPlan>;
  isNew: boolean;
  onCancel: () => void;
  onSaved: () => void;
  onManageEntitlements?: () => void;
}) {
  const [form, setForm] = useState<Partial<PricingPlan>>(initial);
  const set = <K extends keyof PricingPlan>(k: K) => (v: PricingPlan[K]) => setForm(p => ({ ...p, [k]: v }));

  const mutation = useMutation({
    mutationFn: () => isNew
      ? api.post('/admin/pricing/plans', form)
      : api.put(`/admin/pricing/plans/${initial.id}`, form),
    onSuccess: onSaved,
  });

  return (
    <Card>
      <CardBody className="space-y-3">
        <Section title="General" subtitle="Identity — the internal code is permanent once created.">
          <div className="grid grid-cols-2 gap-3">
            {isNew && (
              <Field label="Internal code (permanent, e.g. &quot;starter&quot;)">
                <input value={form.code || ''} onChange={e => set('code')(e.target.value)} className={inputClass} style={inputStyle} />
              </Field>
            )}
            <Field label="Slug">
              <input value={form.slug || ''} onChange={e => set('slug')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Name">
              <input value={form.name || ''} onChange={e => set('name')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Custom label">
              <input value={form.custom_label || ''} onChange={e => set('custom_label')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
          </div>
        </Section>

        <Section title="Commercial" subtitle="Pricing and marketing copy shown on the public Pricing page.">
          <div className="grid grid-cols-3 gap-3">
            <Field label="Monthly price">
              <input type="number" step="0.01" value={form.monthly_price ?? ''} onChange={e => set('monthly_price')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Annual price (total per year)">
              <input type="number" step="0.01" value={form.annual_price ?? ''} onChange={e => set('annual_price')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Currency (ISO 4217)">
              <input value={form.currency || 'GBP'} onChange={e => set('currency')(e.target.value.toUpperCase())} maxLength={3} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Price prefix">
              <input value={form.price_prefix || ''} onChange={e => set('price_prefix')(e.target.value)} placeholder="From" className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Price suffix">
              <input value={form.price_suffix || ''} onChange={e => set('price_suffix')(e.target.value)} placeholder="/month + VAT" className={inputClass} style={inputStyle} />
            </Field>
          </div>

          <Field label="Summary (short marketing line)">
            <input value={form.summary || ''} onChange={e => set('summary')(e.target.value)} className={inputClass} style={inputStyle} />
          </Field>
          <Field label="Description">
            <textarea value={form.description || ''} onChange={e => set('description')(e.target.value)} rows={3} className={inputClass} style={inputStyle} />
          </Field>

          <div className="grid grid-cols-3 gap-3">
            <Field label="CTA text">
              <input value={form.cta_text || ''} onChange={e => set('cta_text')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="CTA URL (relative or https://)">
              <input value={form.cta_url || ''} onChange={e => set('cta_url')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Open in new tab">
              <div className="pt-2"><Toggle checked={!!form.cta_new_tab} onChange={set('cta_new_tab')} /></div>
            </Field>
          </div>
        </Section>

        {!isNew && (
          <Section title="Stripe" subtitle="Product/Price mapping — read-only here; managed independently of this page." defaultOpen={false}>
            {form.provider_prices && form.provider_prices.length > 0 ? (
              <div className="space-y-1.5">
                {form.provider_prices.map(pp => (
                  <div key={pp.id} className="text-xs flex items-center gap-2 flex-wrap" style={{ color: 'var(--text-secondary)' }}>
                    <span className="px-1.5 py-0.5 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)' }}>{pp.billing_interval}</span>
                    <span>{pp.currency} {(pp.unit_amount / 100).toFixed(2)}</span>
                    <span style={{ color: 'var(--text-muted)' }}>{pp.provider_price_id}</span>
                    {!pp.is_active && <span style={{ color: 'var(--text-muted)' }}>(inactive)</span>}
                    {!pp.livemode && <span style={{ color: 'var(--text-muted)' }}>(test mode)</span>}
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No Stripe Product/Price mapping configured for this plan yet.</p>
            )}
          </Section>
        )}

        {!isNew && onManageEntitlements && (
          <Section title="Entitlements" subtitle="Default feature flags and usage limits granted by this plan.">
            <Button variant="secondary" size="sm" onClick={onManageEntitlements}>
              <Layers size={14} /> Manage entitlements
            </Button>
          </Section>
        )}

        <Section title="Visibility" subtitle="Whether this plan can be seen publicly and whether it's the highlighted plan." defaultOpen={false}>
          <div className="flex items-center gap-6">
            <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-primary)' }}>
              <Toggle checked={!!form.is_visible} onChange={set('is_visible')} /> Visible
            </label>
            <label className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-primary)' }}>
              <Toggle checked={!!form.is_popular} onChange={set('is_popular')} /> Popular plan
            </label>
          </div>
        </Section>

        <Section title="Metadata" subtitle="Badge, colour, and icon shown on the public Pricing page." defaultOpen={false}>
          <div className="grid grid-cols-4 gap-3">
            <Field label="Badge text">
              <input value={form.badge_text || ''} onChange={e => set('badge_text')(e.target.value)} className={inputClass} style={inputStyle} />
            </Field>
            <Field label="Badge colour">
              <Select value={form.badge_color || ''} onChange={set('badge_color')} options={BADGE_COLORS} />
            </Field>
            <Field label="Accent colour">
              <Select value={form.accent_color || ''} onChange={set('accent_color')} options={ACCENT_COLORS} />
            </Field>
            <Field label="Background style">
              <Select value={form.background_style || ''} onChange={set('background_style')} options={BACKGROUND_STYLES} />
            </Field>
            <Field label="Icon">
              <Select value={form.icon || ''} onChange={set('icon')} options={PLAN_ICONS} />
            </Field>
          </div>
        </Section>

        <div className="flex justify-end gap-2 pt-1">
          <Button variant="secondary" onClick={onCancel}>Cancel</Button>
          <Button onClick={() => mutation.mutate()} disabled={mutation.isPending}>
            <Save size={14} /> {mutation.isPending ? 'Saving…' : 'Save Plan'}
          </Button>
        </div>
      </CardBody>
    </Card>
  );
}

export default function PlansTab() {
  const qc = useQueryClient();
  const [editingId, setEditingId] = useState<number | 'new' | null>(null);
  const [entitlementsPlan, setEntitlementsPlan] = useState<PricingPlan | null>(null);
  const [copySource, setCopySource] = useState<PricingPlan | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['admin-pricing-plans'],
    queryFn: () => api.get('/admin/pricing/plans').then(r => r.data.data as PricingPlan[]),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['admin-pricing-plans'] });

  const publishMutation = useMutation({ mutationFn: (id: number) => api.post(`/admin/pricing/plans/${id}/publish`), onSuccess: invalidate });
  const archiveMutation = useMutation({ mutationFn: (id: number) => api.post(`/admin/pricing/plans/${id}/archive`), onSuccess: invalidate });
  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/pricing/plans/${id}`),
    onSuccess: (res) => {
      invalidate();
      if (res.data?.message?.includes('archived')) {
        alert(res.data.message);
      }
    },
  });
  const reorderMutation = useMutation({ mutationFn: (order: number[]) => api.put('/admin/pricing/plans/reorder', { order }), onSuccess: invalidate });

  const plans = (data || []).slice().sort((a, b) => a.order - b.order);

  function move(index: number, dir: -1 | 1) {
    const target = index + dir;
    if (target < 0 || target >= plans.length) return;
    const order = plans.map(p => p.id);
    [order[index], order[target]] = [order[target], order[index]];
    reorderMutation.mutate(order);
  }

  if (isLoading) return <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading plans…</p>;

  return (
    <div className="space-y-4">
      <div className="flex justify-end">
        <Button onClick={() => setEditingId('new')}><Plus size={14} /> New Blank Plan</Button>
      </div>

      {editingId === 'new' && (
        <PlanForm initial={emptyForm()} isNew onCancel={() => setEditingId(null)} onSaved={() => { setEditingId(null); invalidate(); }} />
      )}

      <div className="grid grid-cols-1 gap-4">
        {plans.map((plan, i) => (
          <div key={plan.id}>
            {editingId === plan.id ? (
              <PlanForm
                initial={plan}
                isNew={false}
                onCancel={() => setEditingId(null)}
                onSaved={() => { setEditingId(null); invalidate(); }}
                onManageEntitlements={() => setEntitlementsPlan(plan)}
              />
            ) : (
              <Card>
                <CardBody className="flex items-start justify-between gap-4">
                  <div className="flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <h3 className="font-semibold" style={{ color: 'var(--text-primary)' }}>{plan.name}</h3>
                      <span className="text-xs px-2 py-0.5 rounded-full" style={{
                        backgroundColor: plan.status === 'active' ? 'rgba(74,222,128,0.15)' : plan.status === 'draft' ? 'rgba(250,204,21,0.15)' : 'rgba(148,148,148,0.15)',
                        color: plan.status === 'active' ? '#4ade80' : plan.status === 'draft' ? '#eab308' : 'var(--text-muted)',
                      }}>
                        {plan.status}
                      </span>
                      {plan.is_popular && <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>Popular</span>}
                      {!plan.is_visible && <span className="text-xs px-2 py-0.5 rounded-full" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>Hidden</span>}
                    </div>
                    <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>code: {plan.code} · {plan.currency} {plan.monthly_price ?? '—'}/mo</p>
                    <p className="text-sm mt-2" style={{ color: 'var(--text-secondary)' }}>{plan.summary}</p>
                  </div>
                  <div className="flex flex-col items-end gap-2">
                    <div className="flex gap-1">
                      <button onClick={() => move(i, -1)} disabled={i === 0} className="p-1.5 rounded-md disabled:opacity-30" style={{ backgroundColor: 'var(--bg-elevated)' }}><ArrowUp size={14} /></button>
                      <button onClick={() => move(i, 1)} disabled={i === plans.length - 1} className="p-1.5 rounded-md disabled:opacity-30" style={{ backgroundColor: 'var(--bg-elevated)' }}><ArrowDown size={14} /></button>
                    </div>
                    <div className="flex gap-1.5">
                      <Button size="sm" variant="secondary" onClick={() => setEditingId(plan.id)}><Pencil size={12} /> Edit</Button>
                      <Button size="sm" variant="secondary" onClick={() => setEntitlementsPlan(plan)}><Layers size={12} /> Entitlements</Button>
                      <Button size="sm" variant="secondary" onClick={() => setCopySource(plan)}><Copy size={12} /> Copy</Button>
                      {plan.status !== 'active' && (
                        <Button size="sm" variant="secondary" onClick={() => publishMutation.mutate(plan.id)}><Rocket size={12} /> Publish</Button>
                      )}
                      {plan.status !== 'archived' && (
                        <Button size="sm" variant="secondary" onClick={() => archiveMutation.mutate(plan.id)}><Archive size={12} /> Archive</Button>
                      )}
                      <Button size="sm" variant="danger" onClick={() => { if (confirm(`Delete "${plan.name}"?`)) deleteMutation.mutate(plan.id); }}><Trash2 size={12} /></Button>
                    </div>
                  </div>
                </CardBody>
              </Card>
            )}
          </div>
        ))}
      </div>

      {entitlementsPlan && (
        <EntitlementsEditorModal plan={entitlementsPlan} onClose={() => setEntitlementsPlan(null)} />
      )}

      {copySource && (
        <CopyPlanDialog
          source={copySource}
          onClose={() => setCopySource(null)}
          onCopied={() => { setCopySource(null); invalidate(); }}
        />
      )}
    </div>
  );
}
