'use client';

import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import toast from '@/lib/toast';
import { X, Wrench, Sparkles, CheckCircle2, Pencil } from 'lucide-react';
import api from '@/lib/api';
import Select from '@/components/ui/Select';
import EmptyState from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { toUtcIso, fromUtcIso, formatDateTime } from '@/lib/dateTime';
import { useAuthStore } from '@/store/authStore';
import type {
  FeatureAvailabilityAdminMap,
  FeatureAvailabilityStatus,
  UpdateFeatureAvailabilityPayload,
} from '@/types/featureAvailability';

const REASON_MIN_LENGTH = 10;

const STATUS_META: Record<FeatureAvailabilityStatus, { label: string; color: string; icon: React.ElementType }> = {
  active: { label: 'Active', color: '#4ade80', icon: CheckCircle2 },
  maintenance: { label: 'Maintenance', color: '#eab308', icon: Wrench },
  coming_soon: { label: 'Coming Soon', color: '#60a5fa', icon: Sparkles },
};

function StatusPill({ status }: { status: FeatureAvailabilityStatus }) {
  const meta = STATUS_META[status];
  const Icon = meta.icon;
  return (
    <span
      className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
      style={{ backgroundColor: meta.color + '1f', color: meta.color }}
    >
      <Icon size={11} /> {meta.label}
    </span>
  );
}

/**
 * Confirmation copy — every transition must clearly state its customer
 * impact before Super Admin commits it (Phase B Step 14).
 */
function confirmationMessage(label: string, from: FeatureAvailabilityStatus, to: FeatureAvailabilityStatus): string {
  if (to === 'active') {
    return `${label} will become available to customer users.`;
  }
  if (to === 'maintenance') {
    return `You are about to make ${label} unavailable to customer users.`;
  }
  return `You are about to mark ${label} as Coming Soon for customer users.`;
}

function EditDialog({
  featureKey,
  label,
  currentStatus,
  currentMessage,
  currentAvailableAt,
  maintenanceSupported,
  comingSoonSupported,
  onClose,
  onSubmit,
  submitting,
}: {
  featureKey: string;
  label: string;
  currentStatus: FeatureAvailabilityStatus;
  currentMessage: string | null;
  currentAvailableAt: string | null;
  maintenanceSupported: boolean;
  comingSoonSupported: boolean;
  onClose: () => void;
  onSubmit: (payload: UpdateFeatureAvailabilityPayload) => void;
  submitting: boolean;
}) {
  const [status, setStatus] = useState<FeatureAvailabilityStatus>(currentStatus);
  const [message, setMessage] = useState(currentMessage ?? '');
  const [availableAt, setAvailableAt] = useState(fromUtcIso(currentAvailableAt));
  const [reason, setReason] = useState('');
  const [confirmed, setConfirmed] = useState(false);

  const reasonValid = reason.trim().length >= REASON_MIN_LENGTH;
  const canSubmit = reasonValid && confirmed && !submitting;

  // Never rely on backend validation alone — an unsupported option for this
  // specific registry entry is never even selectable here (Phase B Step 13).
  const options: { value: FeatureAvailabilityStatus; label: string }[] = [
    { value: 'active', label: 'Active' },
    ...(maintenanceSupported ? [{ value: 'maintenance' as const, label: 'Maintenance' }] : []),
    ...(comingSoonSupported ? [{ value: 'coming_soon' as const, label: 'Coming Soon' }] : []),
  ];

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center backdrop-blur-sm p-4" style={{ backgroundColor: 'rgba(0,0,0,0.7)' }} onClick={onClose}>
      <div
        className="w-full max-w-md rounded-2xl p-6 ss-animate-in max-h-[90vh] overflow-y-auto"
        style={{ backgroundColor: 'var(--bg-panel)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-pop)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="flex items-start justify-between mb-5">
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>{label}</h2>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>Currently {STATUS_META[currentStatus].label}</p>
          </div>
          <button onClick={onClose} aria-label="Close"><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>

        <div className="space-y-4">
          <div>
            <label htmlFor={`fa-status-${featureKey}`} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>Status</label>
            <Select id={`fa-status-${featureKey}`} value={status} onChange={e => setStatus(e.target.value as FeatureAvailabilityStatus)} className="w-full">
              {options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </Select>
          </div>

          {status !== 'active' && (
            <>
              <div>
                <label htmlFor={`fa-message-${featureKey}`} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                  Customer message <span style={{ color: 'var(--text-muted)' }}>(optional)</span>
                </label>
                <textarea
                  id={`fa-message-${featureKey}`}
                  value={message}
                  onChange={e => setMessage(e.target.value)}
                  rows={2}
                  maxLength={2000}
                  placeholder="Shown to customers in addition to the default copy…"
                  className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                />
              </div>

              <div>
                <label htmlFor={`fa-available-at-${featureKey}`} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
                  Expected availability <span style={{ color: 'var(--text-muted)' }}>(optional, informational only)</span>
                </label>
                <input
                  id={`fa-available-at-${featureKey}`}
                  type="datetime-local"
                  value={availableAt}
                  onChange={e => setAvailableAt(e.target.value)}
                  className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none"
                  style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
                />
              </div>
            </>
          )}

          <div
            className="rounded-xl p-3.5 text-xs"
            style={{ backgroundColor: 'rgba(249,115,22,0.08)', border: '1px solid rgba(249,115,22,0.2)', color: 'var(--text-secondary)' }}
          >
            <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{confirmationMessage(label, currentStatus, status)}</p>
          </div>

          <div>
            <label htmlFor={`fa-reason-${featureKey}`} className="block text-xs font-medium mb-1.5" style={{ color: 'var(--text-secondary)' }}>
              Reason <span style={{ color: '#ef4444' }}>*</span>
            </label>
            <textarea
              id={`fa-reason-${featureKey}`}
              value={reason}
              onChange={e => setReason(e.target.value)}
              rows={3}
              placeholder="Explain the reason for this change…"
              className="w-full px-3.5 py-2.5 rounded-xl text-sm outline-none resize-none"
              style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' }}
            />
            {!reasonValid && reason.length > 0 && (
              <p className="text-xs mt-1" style={{ color: '#f87171' }}>Reason must be at least {REASON_MIN_LENGTH} characters.</p>
            )}
          </div>

          <label className="flex items-start gap-2 text-xs" style={{ color: 'var(--text-secondary)' }}>
            <input type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} className="mt-0.5" />
            I confirm this Feature Availability change.
          </label>
        </div>

        <div className="flex gap-3 mt-6">
          <button onClick={onClose} className="flex-1 py-2.5 rounded-xl text-sm font-medium" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
            Cancel
          </button>
          <button
            onClick={() => onSubmit({
              status,
              message: status === 'active' ? null : (message.trim() || null),
              available_at: status === 'active' || !availableAt ? null : toUtcIso(availableAt),
              reason: reason.trim(),
              confirmed: true,
            })}
            disabled={!canSubmit}
            className="flex-1 py-2.5 rounded-xl text-sm font-medium disabled:opacity-50"
            style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
          >
            {submitting ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * Super Admin ONLY — matches the Phase A management API's own
 * `role:Super Admin` authorization for both GET and PUT exactly (Admin is
 * deliberately excluded even from read access here, unlike most other
 * Super-Admin-OR-Admin settings surfaces in this codebase). This component
 * assumes its caller has already gated rendering on the Super Admin role —
 * see admin/suresign/page.tsx's tab visibility — but the real enforcement
 * is always the backend's own role middleware, never this check alone.
 */
export default function FeatureAvailabilityManager() {
  const qc = useQueryClient();
  const timeZone = useAuthStore.getState().user?.effective_timezone;
  const [editingKey, setEditingKey] = useState<string | null>(null);

  const { data, isLoading } = useQuery<FeatureAvailabilityAdminMap>({
    queryKey: ['admin-feature-availability'],
    queryFn: () => api.get('/admin/feature-availability').then(r => r.data?.features ?? {}),
    staleTime: 60 * 1000,
  });

  const mutation = useMutation({
    mutationFn: ({ featureKey, payload }: { featureKey: string; payload: UpdateFeatureAvailabilityPayload }) =>
      api.put(`/admin/feature-availability/${featureKey}`, payload),
    onSuccess: () => {
      // Both the management view and the customer-facing status must
      // reflect the change immediately, within this session, with no hard
      // refresh (Phase B Step 15).
      qc.invalidateQueries({ queryKey: ['admin-feature-availability'] });
      qc.invalidateQueries({ queryKey: ['feature-availability'] });
    },
  });

  const grouped = useMemo(() => {
    const entries = Object.entries(data ?? {});
    const byCategory = new Map<string, typeof entries>();
    for (const entry of entries) {
      const category = entry[1].category;
      if (!byCategory.has(category)) byCategory.set(category, []);
      byCategory.get(category)!.push(entry);
    }
    return Array.from(byCategory.entries());
  }, [data]);

  const editingEntry = editingKey ? data?.[editingKey] : undefined;

  if (isLoading) {
    return <div className="text-sm" style={{ color: 'var(--text-muted)' }}>Loading…</div>;
  }

  if (grouped.length === 0) {
    return <EmptyState title="No registered features" description="Nothing is currently registered in the Feature Availability catalogue." />;
  }

  return (
    <div className="space-y-6">
      {grouped.map(([category, entries]) => (
        <div key={category} className="space-y-2">
          <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>{category} Modules</h3>
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            {entries.map(([key, entry], i) => (
              <div
                key={key}
                className="flex items-center justify-between gap-3 px-4 py-3"
                style={{ backgroundColor: 'var(--bg-surface)', borderTop: i > 0 ? '1px solid var(--border)' : undefined }}
              >
                <div className="min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <p className="text-sm font-medium truncate" style={{ color: 'var(--text-primary)' }}>{entry.label}</p>
                    <StatusPill status={entry.status} />
                  </div>
                  {entry.description && (
                    <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{entry.description}</p>
                  )}
                  {entry.status !== 'active' && entry.message && (
                    <p className="text-xs mt-0.5" style={{ color: 'var(--text-secondary)' }}>&ldquo;{entry.message}&rdquo;</p>
                  )}
                  {entry.status === 'maintenance' && entry.available_at && (
                    <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
                      Expected back {formatDateTime(entry.available_at, { timeZone })}
                    </p>
                  )}
                </div>
                <button
                  onClick={() => setEditingKey(key)}
                  className="flex-shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium"
                  style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)', border: '1px solid var(--border)' }}
                >
                  <Pencil size={12} /> Edit
                </button>
              </div>
            ))}
          </div>
        </div>
      ))}

      {editingKey && editingEntry && (
        <EditDialog
          featureKey={editingKey}
          label={editingEntry.label}
          currentStatus={editingEntry.status}
          currentMessage={editingEntry.message}
          currentAvailableAt={editingEntry.available_at}
          maintenanceSupported={editingEntry.maintenance_supported}
          comingSoonSupported={editingEntry.coming_soon_supported}
          onClose={() => setEditingKey(null)}
          submitting={mutation.isPending}
          onSubmit={(payload) => {
            mutation.mutate({ featureKey: editingKey, payload }, {
              onSuccess: () => {
                toast.success(`${editingEntry.label} updated.`);
                setEditingKey(null);
              },
              onError: (e: unknown) => toast.error(getErrorMessage(e, 'Could not update this feature.')),
            });
          }}
        />
      )}
    </div>
  );
}
