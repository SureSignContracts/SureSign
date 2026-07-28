'use client';

import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import Modal from '@/components/ui/Modal';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { Copy, Loader2 } from 'lucide-react';
import { PricingPlan } from '@/types/pricing';

function firstValidationError(err: unknown): string | null {
  if (err && typeof err === 'object' && 'response' in err) {
    const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors;
    const first = errors ? Object.values(errors)[0] : undefined;
    if (Array.isArray(first) && typeof first[0] === 'string') return first[0];
  }
  return null;
}

const inputClass = 'w-full px-3 py-2 rounded-lg text-sm outline-none';
const inputStyle = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' };

/**
 * Stage 6 — "Copy Existing Plan". Only asks for the new plan's identity;
 * every commercial field and entitlement default is duplicated server-side
 * by PricingManagementService::copyPlan(). Stripe mapping is deliberately
 * never copied — the new plan always requires its own Product/Price setup.
 */
export default function CopyPlanDialog({ source, onClose, onCopied }: {
  source: PricingPlan;
  onClose: () => void;
  onCopied: (plan: PricingPlan) => void;
}) {
  const [code, setCode] = useState(`${source.code}-copy`);
  const [slug, setSlug] = useState(`${source.slug}-copy`);
  const [name, setName] = useState(`${source.name} (Copy)`);
  const [error, setError] = useState<string | null>(null);

  const mutation = useMutation({
    mutationFn: () => api.post(`/admin/pricing/plans/${source.id}/copy`, { code, slug, name }),
    onSuccess: (res) => onCopied(res.data.data as PricingPlan),
    onError: (err: unknown) => {
      setError(firstValidationError(err) ?? getErrorMessage(err, 'Could not copy this plan.'));
    },
  });

  return (
    <Modal title={`Copy "${source.name}"`} icon={Copy} tone="info" onClose={onClose} busy={mutation.isPending}>
      {(close) => (
        <div className="space-y-4" style={{ minWidth: 420 }}>
          <p className="text-xs" style={{ color: 'var(--text-secondary)' }}>
            Commercial fields, visibility of appearance, and every entitlement default will be duplicated from
            &quot;{source.name}&quot;. The new plan always starts as an unpublished draft with no Stripe Product or
            Price mapping — you&apos;ll need to configure that separately before selling it.
          </p>

          <div className="space-y-3">
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>New internal code (permanent)</label>
              <input value={code} onChange={(e) => setCode(e.target.value)} className={inputClass} style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>New slug</label>
              <input value={slug} onChange={(e) => setSlug(e.target.value)} className={inputClass} style={inputStyle} />
            </div>
            <div>
              <label className="block text-xs font-medium mb-1" style={{ color: 'var(--text-secondary)' }}>New name</label>
              <input value={name} onChange={(e) => setName(e.target.value)} className={inputClass} style={inputStyle} />
            </div>
          </div>

          {error && <p className="text-xs" style={{ color: '#f87171' }}>{error}</p>}

          <div className="flex items-center justify-end gap-3 pt-2">
            <Button variant="secondary" size="sm" onClick={close} disabled={mutation.isPending}>Cancel</Button>
            <Button variant="primary" size="sm" onClick={() => mutation.mutate()} disabled={mutation.isPending || !code || !slug || !name}>
              {mutation.isPending ? <Loader2 size={14} className="animate-spin" /> : <Copy size={14} />}
              {mutation.isPending ? 'Copying…' : 'Create copy'}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
