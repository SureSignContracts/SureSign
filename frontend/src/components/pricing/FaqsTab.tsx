'use client';

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import Toggle from '@/components/ui/Toggle';
import { ArrowUp, ArrowDown, Plus, Save, Trash2 } from 'lucide-react';
import { PricingFaq } from '@/types/pricing';

const inputClass = 'w-full px-3 py-2 rounded-lg text-sm outline-none';
const inputStyle = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

export default function FaqsTab() {
  const qc = useQueryClient();
  const [drafts, setDrafts] = useState<Record<number, { question: string; answer: string }>>({});
  const [newFaq, setNewFaq] = useState({ question: '', answer: '' });

  const { data: faqs, isLoading } = useQuery({
    queryKey: ['admin-pricing-faqs'],
    queryFn: () => api.get('/admin/pricing/faqs').then(r => r.data.data as PricingFaq[]),
  });

  const invalidate = () => qc.invalidateQueries({ queryKey: ['admin-pricing-faqs'] });

  const createMutation = useMutation({
    mutationFn: (payload: { question: string; answer: string }) => api.post('/admin/pricing/faqs', payload),
    onSuccess: () => { setNewFaq({ question: '', answer: '' }); invalidate(); },
  });
  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<PricingFaq> }) => api.put(`/admin/pricing/faqs/${id}`, payload),
    onSuccess: invalidate,
  });
  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/admin/pricing/faqs/${id}`),
    onSuccess: invalidate,
  });
  const reorderMutation = useMutation({
    mutationFn: (order: number[]) => api.put('/admin/pricing/faqs/reorder', { order }),
    onSuccess: invalidate,
  });

  const ordered = (faqs || []).slice().sort((a, b) => a.order - b.order);

  function move(index: number, dir: -1 | 1) {
    const target = index + dir;
    if (target < 0 || target >= ordered.length) return;
    const order = ordered.map(f => f.id);
    [order[index], order[target]] = [order[target], order[index]];
    reorderMutation.mutate(order);
  }

  function draftFor(faq: PricingFaq) {
    return drafts[faq.id] ?? { question: faq.question, answer: faq.answer };
  }

  if (isLoading) return <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading FAQs…</p>;

  return (
    <div className="space-y-4">
      <Card>
        <CardBody className="space-y-3">
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-secondary)' }}>Add FAQ</h3>
          <input
            value={newFaq.question}
            onChange={e => setNewFaq(p => ({ ...p, question: e.target.value }))}
            placeholder="Question"
            className={inputClass}
            style={inputStyle}
          />
          <textarea
            value={newFaq.answer}
            onChange={e => setNewFaq(p => ({ ...p, answer: e.target.value }))}
            placeholder="Answer"
            rows={2}
            className={inputClass}
            style={inputStyle}
          />
          <div className="flex justify-end">
            <Button
              size="sm"
              disabled={!newFaq.question.trim() || !newFaq.answer.trim() || createMutation.isPending}
              onClick={() => createMutation.mutate(newFaq)}
            >
              <Plus size={13} /> Add FAQ
            </Button>
          </div>
        </CardBody>
      </Card>

      {ordered.map((faq, i) => {
        const draft = draftFor(faq);
        return (
          <Card key={faq.id}>
            <CardBody className="space-y-3">
              <div className="flex items-center justify-between">
                <div className="flex gap-1">
                  <button onClick={() => move(i, -1)} disabled={i === 0} className="p-1 disabled:opacity-30"><ArrowUp size={13} /></button>
                  <button onClick={() => move(i, 1)} disabled={i === ordered.length - 1} className="p-1 disabled:opacity-30"><ArrowDown size={13} /></button>
                </div>
                <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
                  Enabled
                  <Toggle checked={faq.is_enabled} onChange={v => updateMutation.mutate({ id: faq.id, payload: { is_enabled: v } })} />
                </label>
              </div>
              <input
                value={draft.question}
                onChange={e => setDrafts(p => ({ ...p, [faq.id]: { ...draft, question: e.target.value } }))}
                className={inputClass}
                style={inputStyle}
              />
              <textarea
                value={draft.answer}
                onChange={e => setDrafts(p => ({ ...p, [faq.id]: { ...draft, answer: e.target.value } }))}
                rows={2}
                className={inputClass}
                style={inputStyle}
              />
              <div className="flex justify-end gap-2">
                <Button size="sm" variant="danger" onClick={() => { if (confirm('Delete this FAQ?')) deleteMutation.mutate(faq.id); }}>
                  <Trash2 size={12} />
                </Button>
                <Button size="sm" variant="secondary" onClick={() => updateMutation.mutate({ id: faq.id, payload: draft })}>
                  <Save size={12} /> Save
                </Button>
              </div>
            </CardBody>
          </Card>
        );
      })}
    </div>
  );
}
