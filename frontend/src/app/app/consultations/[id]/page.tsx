'use client';

import { useParams } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  ArrowLeft, HeartHandshake, Video, Calendar, Clock, UserRound,
  Globe2, FileText, Target, Layers3, CheckCircle,
} from 'lucide-react';
import toast from '@/lib/toast';
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
      <div className="mx-auto max-w-6xl space-y-5 p-4 sm:p-6 lg:py-9" aria-busy="true" aria-live="polite">
        <span className="sr-only">Loading consultation…</span>
        <div className="h-5 w-40 animate-pulse rounded" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        <div className="h-72 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        <div className="grid gap-5 lg:grid-cols-[1.15fr_0.85fr]">
          <div className="h-64 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          <div className="h-64 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-elevated)' }} />
        </div>
      </div>
    );
  }

  if (!consultation) {
    return (
      <div className="mx-auto max-w-6xl p-6">
        <p style={{ color: 'var(--text-muted)' }}>Consultation not found.</p>
      </div>
    );
  }

  const canCancel = CANCELLABLE_STATUSES.includes(consultation.status);
  const startsAt = new Date(consultation.starts_at);
  const endsAt = new Date(consultation.ends_at);
  const day = startsAt.toLocaleDateString('en-GB', { day: '2-digit' });
  const month = startsAt.toLocaleDateString('en-GB', { month: 'short' });
  const weekday = startsAt.toLocaleDateString('en-GB', { weekday: 'long' });
  const startTime = startsAt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
  const endTime = endsAt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

  return (
    <div className="ss-project-setup mx-auto max-w-6xl space-y-6 p-4 sm:p-6 lg:py-9">
      <Link href="/app/consultations" className="ss-animate-in inline-flex items-center gap-1.5 rounded-lg px-1 py-1 text-sm font-medium transition-all duration-200 hover:-translate-x-0.5 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2" style={{ color: 'var(--text-secondary)' }}>
        <ArrowLeft size={14} /> Back to consultancy
      </Link>

      <section className="ss-animate-in grid overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5] lg:grid-cols-[1.25fr_0.75fr]" style={{ animationDelay: '50ms' }}>
        <div className="relative overflow-hidden p-7 sm:p-9 lg:p-11">
          <div className="absolute -left-24 -top-28 h-72 w-72 rounded-full border border-[#a5d6b5]/10" />
          <div className="relative">
            <div className="mb-8 flex items-center justify-between gap-4">
              <div className="flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
                <HeartHandshake size={21} />
              </div>
              <Badge status={consultation.status} className="border border-white/10 bg-white/10 text-[#d7e2dc]" />
            </div>
            <p className="font-mono text-xs text-[#91a099]">{consultation.reference}</p>
            <h1 className="mt-3 max-w-2xl text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">
              {consultation.consultation_enquiry.consultancy_service.display_name}
            </h1>
            <p className="mt-4 max-w-xl text-sm leading-6 text-[#b9c5bf]">
              Everything for your consultation, from the original enquiry to the consultant&rsquo;s written summary.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-5 border-t border-[#a5d6b5]/10 bg-[#202c26] p-7 sm:p-9 lg:border-l lg:border-t-0">
          <div className="flex w-20 flex-shrink-0 flex-col items-center overflow-hidden rounded-xl border border-[#a5d6b5]/20 bg-[#18211d]">
            <span className="w-full bg-[#a5d6b5]/10 py-2 text-center text-[11px] font-semibold uppercase tracking-[0.12em] text-[#9ee5b5]">{month}</span>
            <span className="py-3 text-3xl font-semibold tracking-[-0.04em]">{day}</span>
          </div>
          <div>
            <p className="text-base font-semibold">{weekday}</p>
            <p className="mt-1 flex items-center gap-1.5 text-sm text-[#b9c5bf]"><Clock size={14} /> {startTime} - {endTime}</p>
            <p className="mt-1 text-xs text-[#91a099]">{consultation.booking_timezone}</p>
          </div>
        </div>
      </section>

      <div className="grid items-start gap-5 lg:grid-cols-[1.15fr_0.85fr]">
        <div className="space-y-5">
          {consultation.meeting.status !== 'unavailable' && (
            <section className="ss-animate-in overflow-hidden rounded-2xl border" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--gold-30)', boxShadow: 'var(--shadow-card)', animationDelay: '120ms' }}>
              <div className="flex flex-col justify-between gap-5 p-6 sm:flex-row sm:items-center">
                <div>
                  <div className="mb-2 flex items-center gap-2 text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}><Video size={15} /></span>
                    Online meeting
                  </div>
          {consultation.meeting.status === 'available' && consultation.meeting.join_url ? (
                    <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>Your secure meeting room is ready.</p>
                  ) : consultation.meeting.status === 'temporarily_unavailable' ? (
                    <p className="max-w-md text-sm leading-6" style={{ color: 'var(--text-secondary)' }}>
                      We&rsquo;re still connecting this consultation to Google Meet. Your appointment remains confirmed, so check back shortly.
                    </p>
                  ) : (
                    <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>Your meeting link is being prepared.</p>
                  )}
                </div>
                {consultation.meeting.status === 'available' && consultation.meeting.join_url && (
            <a
              href={consultation.meeting.join_url}
              target="_blank"
              rel="noopener noreferrer"
                    className="inline-flex flex-shrink-0 items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold transition-all duration-200 hover:-translate-y-0.5 hover:opacity-90 active:scale-[0.98] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2"
                    style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
            >
                    <Video size={15} /> Join meeting
            </a>
                )}
              </div>
            </section>
          )}

          <section className="ss-animate-in rounded-2xl border p-6" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '180ms' }}>
            <div className="mb-5 flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}><FileText size={16} /></span>
              <div>
                <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Your enquiry</h2>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>The context shared with your consultant</p>
              </div>
            </div>
            <h3 className="text-lg font-semibold tracking-[-0.015em]" style={{ color: 'var(--text-primary)' }}>{consultation.consultation_enquiry.title}</h3>
            <p className="mt-2 whitespace-pre-wrap text-sm leading-6" style={{ color: 'var(--text-secondary)' }}>{consultation.consultation_enquiry.description}</p>

            {(consultation.consultation_enquiry.project_stage || consultation.consultation_enquiry.contract_form || consultation.consultation_enquiry.preferred_outcome) && (
              <div className="mt-6 grid gap-3 border-t pt-5 sm:grid-cols-2" style={{ borderColor: 'var(--border)' }}>
                {consultation.consultation_enquiry.project_stage && (
                  <DetailItem icon={<Layers3 size={14} />} label="Project stage" value={consultation.consultation_enquiry.project_stage} />
                )}
                {consultation.consultation_enquiry.contract_form && (
                  <DetailItem icon={<FileText size={14} />} label="Contract form" value={consultation.consultation_enquiry.contract_form} />
                )}
                {consultation.consultation_enquiry.preferred_outcome && (
                  <DetailItem icon={<Target size={14} />} label="Preferred outcome" value={consultation.consultation_enquiry.preferred_outcome} className="sm:col-span-2" />
                )}
              </div>
            )}
          </section>
        </div>

        <div className="space-y-5">
          <section className="ss-animate-in rounded-2xl border p-6" style={{ backgroundColor: 'var(--bg-surface)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '150ms' }}>
            <h2 className="mb-5 text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Appointment details</h2>
            <div className="space-y-4">
              <DetailItem icon={<Calendar size={14} />} label="Date" value={formatDate(consultation.starts_at)} />
              <DetailItem icon={<Clock size={14} />} label="Time" value={`${startTime} - ${endTime}`} />
              <DetailItem icon={<Globe2 size={14} />} label="Timezone" value={consultation.booking_timezone} />
              <DetailItem icon={<UserRound size={14} />} label="Consultant" value={consultation.assigned_user?.name ?? 'To be assigned'} />
            </div>
          </section>

          <section className="ss-animate-in rounded-2xl border p-6" style={{ backgroundColor: consultation.consultation_enquiry.customer_summary_published ? 'var(--bg-surface)' : 'var(--bg-elevated)', borderColor: 'var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '220ms' }}>
            <div className="mb-4 flex items-center gap-2">
              <CheckCircle size={17} style={{ color: consultation.consultation_enquiry.customer_summary_published ? 'var(--gold)' : 'var(--text-muted)' }} />
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Consultation summary</h2>
            </div>
        {consultation.consultation_enquiry.customer_summary_published ? (
              <div className="space-y-3 text-sm">
                <p className="whitespace-pre-wrap leading-6" style={{ color: 'var(--text-secondary)' }}>
              {consultation.consultation_enquiry.customer_summary_published}
            </p>
            {consultation.consultation_enquiry.customer_summary_published_at && (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Published {formatDate(consultation.consultation_enquiry.customer_summary_published_at)}
              </p>
            )}
          </div>
        ) : (
              <div>
                <p className="text-sm font-medium" style={{ color: 'var(--text-secondary)' }}>Summary pending</p>
                <p className="mt-1 text-sm leading-6" style={{ color: 'var(--text-muted)' }}>
                  Your consultant&rsquo;s notes and recommended next steps will appear here after the session.
                </p>
              </div>
        )}
          </section>
        </div>
      </div>

      {canCancel && (
        <div className="ss-animate-in flex items-center justify-between gap-4 border-t pt-5" style={{ borderColor: 'var(--border)', animationDelay: '260ms' }}>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Plans changed? You can cancel this appointment here.</p>
          <Button
            variant="secondary"
            disabled={cancelMutation.isPending}
            onClick={() => { if (confirm('Cancel this consultation?')) cancelMutation.mutate(); }}
          >
            {cancelMutation.isPending ? 'Cancelling…' : 'Cancel consultation'}
          </Button>
        </div>
      )}
    </div>
  );
}

function DetailItem({ icon, label, value, className = '' }: { icon: React.ReactNode; label: string; value: string; className?: string }) {
  return (
    <div className={`flex items-start gap-3 ${className}`}>
      <span className="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)' }}>{icon}</span>
      <div className="min-w-0">
        <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
        <p className="mt-0.5 break-words text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{value}</p>
      </div>
    </div>
  );
}
