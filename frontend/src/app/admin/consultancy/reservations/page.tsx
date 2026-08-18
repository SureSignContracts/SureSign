'use client';

import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Timer } from 'lucide-react';
import toast from '@/lib/toast';
import api from '@/lib/api';
import Button from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import EmptyState from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/getErrorMessage';

interface ReservationRow {
  id: number;
  service: string | null;
  starts_at: string;
  ends_at: string;
  status: 'active' | 'consumed' | 'expired' | 'cancelled';
  expires_at: string;
}

interface ReservationDiagnostics {
  counts: { active: number; consumed: number; expired: number; cancelled: number };
  recent: ReservationRow[];
}

const STATUS_TONE: Record<string, 'success' | 'neutral' | 'danger'> = {
  active: 'success',
  consumed: 'neutral',
  expired: 'neutral',
  cancelled: 'danger',
};

export default function ConsultancyReservationsPage() {
  const qc = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['consultancy-reservations'],
    queryFn: () => api.get('/admin/consultancy/reservations').then(r => r.data as ReservationDiagnostics),
    refetchInterval: 30000,
  });

  const cancelMutation = useMutation({
    mutationFn: (id: number) => api.post(`/admin/consultancy/reservations/${id}/cancel`).then(r => r.data),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['consultancy-reservations'] });
      toast.success('Reservation cancelled.');
    },
    onError: (e: unknown) => toast.error(getErrorMessage(e, 'Failed to cancel the reservation.')),
  });

  const counts = data?.counts;

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-5">
      <Link href="/admin/consultancy/dashboard" className="inline-flex items-center gap-1 text-sm" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Consultancy
      </Link>

      <div>
        <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <Timer size={20} /> Consultancy Reservations
        </h1>
        <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>
          Temporary slot holds — not confirmed bookings and not payments. A reservation is not yet part of the paid booking flow.
        </p>
      </div>

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        {(['active', 'consumed', 'expired', 'cancelled'] as const).map(status => (
          <div key={status} className="rounded-xl px-4 py-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <div className="text-lg font-bold tabular-nums" style={{ color: 'var(--text-primary)' }}>
              {isLoading ? '–' : (counts?.[status] ?? 0)}
            </div>
            <div className="text-xs mt-0.5 capitalize" style={{ color: 'var(--text-muted)' }}>{status}</div>
          </div>
        ))}
      </div>

      <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Recent reservations</h2>

        {isLoading ? (
          <div className="h-32 rounded-xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        ) : !data?.recent?.length ? (
          <EmptyState icon={Timer} title="No reservations yet" description="Reservations appear here once a customer selects a Consultancy slot." />
        ) : (
          <div className="space-y-2">
            {data.recent.map(r => (
              <div key={r.id} className="flex items-center justify-between px-3 py-2.5 rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)' }}>
                <div>
                  <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{r.service ?? 'Consultancy service'}</p>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    {new Date(r.starts_at).toLocaleString('en-GB')} – {new Date(r.ends_at).toLocaleString('en-GB', { hour: '2-digit', minute: '2-digit' })}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <Badge tone={STATUS_TONE[r.status] ?? 'neutral'}>{r.status}</Badge>
                  {r.status === 'active' && (
                    <Button size="sm" variant="secondary" disabled={cancelMutation.isPending} onClick={() => cancelMutation.mutate(r.id)}>
                      Cancel
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
