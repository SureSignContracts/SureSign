'use client';

import { useParams } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, HeartHandshake, Video } from 'lucide-react';
import toast from 'react-hot-toast';
import api from '@/lib/api';
import { getErrorMessage } from '@/lib/getErrorMessage';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import { formatDate } from '@/lib/utils';

interface ConsultationDetail {
  id: number;
  reference: string;
  status: string;
  starts_at: string;
  ends_at: string;
  booking_timezone: string;
  attendee_name: string;
  attendee_email: string;
  appointment_type: { name: string };
  assigned_user: { name: string } | null;
  consultation_enquiry: {
    title: string;
    description: string;
    project_stage: string | null;
    contract_form: string | null;
    preferred_outcome: string | null;
    consultancy_service: { display_name: string };
    // Always present per ConsultationPresenter's deterministic contract —
    // null until a summary is published (Phase C2, Batch 4). The visible
    // "your consultation summary" UI section is introduced in that batch,
    // not here — see suresign-consultancy-phase-c2-specification-v1.md.
    customer_summary_published: string | null;
    customer_summary_published_at: string | null;
  };
  meeting: {
    status: 'available' | 'pending' | 'temporarily_unavailable' | 'unavailable';
    join_url: string | null;
  };
}

const CANCELLABLE_STATUSES = ['requested', 'pending_confirmation', 'confirmed'];

export default function ConsultationDetailPage() {
  const params = useParams();
  const qc = useQueryClient();
  const id = params.id as string;

  const { data: consultation, isLoading } = useQuery({
    queryKey: ['consultations', id],
    queryFn: () => api.get(`/consultations/${id}`).then(r => r.data as ConsultationDetail),
  });

  const cancelMutation = useMutation({
    mutationFn: () => api.post(`/consultations/${id}/cancel`, {}).then(r => r.data),
    onSuccess: () => {
      toast.success('Consultation cancelled.');
      // React Query's default prefix matching means invalidating the
      // ['consultations'] key also invalidates ['consultations', id] here —
      // both the list and this detail view refetch with the new status.
      qc.invalidateQueries({ queryKey: ['consultations'] });
    },
    onError: (err: unknown) => toast.error(getErrorMessage(err, 'Failed to cancel.')),
  });

  if (isLoading) {
    return (
      <div className="p-6 max-w-2xl mx-auto space-y-4" aria-busy="true" aria-live="polite">
        <span className="sr-only">Loading consultation…</span>
        <div className="h-6 w-40 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        <div className="h-48 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
      </div>
    );
  }

  if (!consultation) {
    return (
      <div className="p-6 max-w-2xl mx-auto">
        <p style={{ color: 'var(--text-muted)' }}>Consultation not found.</p>
      </div>
    );
  }

  const canCancel = CANCELLABLE_STATUSES.includes(consultation.status);

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-5">
      <Link href="/app/consultations" className="inline-flex items-center gap-1 text-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 rounded" style={{ color: 'var(--text-muted)' }}>
        <ArrowLeft size={14} /> Back to Consultancy
      </Link>

      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <HeartHandshake size={20} /> {consultation.consultation_enquiry.consultancy_service.display_name}
          </h1>
          <p className="text-sm mt-1" style={{ color: 'var(--text-muted)' }}>Reference {consultation.reference}</p>
        </div>
        <Badge status={consultation.status} />
      </div>

      <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Appointment</h2>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
          <div>
            <p style={{ color: 'var(--text-muted)' }}>Date</p>
            <p style={{ color: 'var(--text-primary)' }}>{formatDate(consultation.starts_at)}</p>
          </div>
          <div>
            <p style={{ color: 'var(--text-muted)' }}>Timezone</p>
            <p style={{ color: 'var(--text-primary)' }}>{consultation.booking_timezone}</p>
          </div>
          <div>
            <p style={{ color: 'var(--text-muted)' }}>Consultant</p>
            <p style={{ color: 'var(--text-primary)' }}>{consultation.assigned_user?.name ?? 'To be assigned'}</p>
          </div>
        </div>
      </div>

      {consultation.meeting.status !== 'unavailable' && (
        <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
          <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <Video size={16} /> Meeting Status
          </h2>
          {consultation.meeting.status === 'available' && consultation.meeting.join_url ? (
            <a
              href={consultation.meeting.join_url}
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 text-sm font-medium px-4 py-2 rounded-lg focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
              style={{ backgroundColor: 'var(--accent, #2563eb)', color: '#fff' }}
            >
              <Video size={14} /> Join Google Meet
            </a>
          ) : consultation.meeting.status === 'temporarily_unavailable' ? (
            // Distinct from 'pending' below — the calendar event itself
            // isn't synced yet (queued/retrying/disconnected), a longer and
            // less certain wait than "still preparing the link" — see
            // ConsultationMeetingPresenter's own docblock. Previously shown
            // identically to 'pending', losing that distinction.
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
              We&rsquo;re still connecting this consultation to Google Meet. Your consultation is still confirmed.
              Check back shortly.
            </p>
          ) : (
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Preparing Meeting Link&hellip;</p>
          )}
        </div>
      )}

      <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Your enquiry</h2>
        <div className="space-y-2 text-sm">
          <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{consultation.consultation_enquiry.title}</p>
          <p style={{ color: 'var(--text-secondary)' }}>{consultation.consultation_enquiry.description}</p>
          {consultation.consultation_enquiry.project_stage && (
            <p style={{ color: 'var(--text-muted)' }}>Project stage: {consultation.consultation_enquiry.project_stage}</p>
          )}
          {consultation.consultation_enquiry.contract_form && (
            <p style={{ color: 'var(--text-muted)' }}>Contract form: {consultation.consultation_enquiry.contract_form}</p>
          )}
          {consultation.consultation_enquiry.preferred_outcome && (
            <p style={{ color: 'var(--text-muted)' }}>Preferred outcome: {consultation.consultation_enquiry.preferred_outcome}</p>
          )}
        </div>
      </div>

      <div className="rounded-2xl p-5 space-y-3" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Consultation Summary</h2>
        {consultation.consultation_enquiry.customer_summary_published ? (
          <div className="space-y-2 text-sm">
            <p className="whitespace-pre-wrap" style={{ color: 'var(--text-secondary)' }}>
              {consultation.consultation_enquiry.customer_summary_published}
            </p>
            {consultation.consultation_enquiry.customer_summary_published_at && (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Published {formatDate(consultation.consultation_enquiry.customer_summary_published_at)}
              </p>
            )}
          </div>
        ) : (
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
            Your consultant hasn&apos;t published a summary yet. It will appear here once your consultation is complete.
          </p>
        )}
      </div>

      {canCancel && (
        <div className="flex justify-end">
          <Button
            variant="secondary"
            disabled={cancelMutation.isPending}
            onClick={() => { if (confirm('Cancel this consultation?')) cancelMutation.mutate(); }}
          >
            {cancelMutation.isPending ? 'Cancelling…' : 'Cancel Consultation'}
          </Button>
        </div>
      )}
    </div>
  );
}
