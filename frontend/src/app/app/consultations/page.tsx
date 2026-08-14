'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { HeartHandshake, Plus, Calendar, ChevronRight, Clock, ArrowRight, CheckCircle } from 'lucide-react';
import api from '@/lib/api';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import { formatDate } from '@/lib/utils';
import { EASE } from '@/lib/motion';
import PageTourButton from '@/components/tours/PageTourButton';

interface ConsultationRow {
  id: number;
  reference: string;
  status: string;
  starts_at: string;
  appointment_type: { name: string };
  consultation_enquiry: { title: string; consultancy_service: { display_name: string } };
}

function ConsultationCard({ row, index }: { row: ConsultationRow; index: number }) {
  const serviceName = row.consultation_enquiry?.consultancy_service?.display_name ?? row.appointment_type.name;
  const startsAt = new Date(row.starts_at);
  const day = startsAt.toLocaleDateString('en-GB', { day: '2-digit' });
  const month = startsAt.toLocaleDateString('en-GB', { month: 'short' });
  const time = startsAt.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

  return (
    <Link
      href={`/app/consultations/${row.id}`}
      className={`group ss-consultancy-card-in flex min-h-56 flex-col overflow-hidden rounded-2xl transition-all duration-300 ${EASE} hover:-translate-y-1 active:translate-y-px shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)]`}
      style={{
        backgroundColor: 'var(--bg-surface)',
        border: '1px solid var(--border)',
        animationDelay: `${520 + Math.min(index * 80, 480)}ms`,
      }}
    >
      <div className="flex items-start gap-4 p-5">
        <div className="flex w-16 flex-shrink-0 flex-col items-center overflow-hidden rounded-xl border" style={{ borderColor: 'var(--border)' }}>
          <span className="w-full py-1.5 text-center text-[10px] font-semibold uppercase tracking-[0.12em]" style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}>
            {month}
          </span>
          <span className="py-2 text-2xl font-semibold tracking-[-0.04em]" style={{ color: 'var(--text-primary)' }}>{day}</span>
        </div>

        <div className="min-w-0 flex-1">
          <div className="mb-3 flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate text-base font-semibold tracking-[-0.015em]" style={{ color: 'var(--text-primary)' }}>
                {serviceName}
              </p>
              <p className="mt-1 truncate font-mono text-[11px]" style={{ color: 'var(--text-muted)' }}>
                {row.reference}
              </p>
            </div>
            <Badge status={row.status} />
          </div>

          {row.consultation_enquiry?.title && (
            <p className="line-clamp-2 text-sm leading-5" style={{ color: 'var(--text-secondary)' }}>
              {row.consultation_enquiry.title}
            </p>
          )}

          <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs" style={{ color: 'var(--text-muted)' }}>
            <span className="flex items-center gap-1.5"><Calendar size={13} /> {formatDate(row.starts_at)}</span>
            <span className="flex items-center gap-1.5"><Clock size={13} /> {time}</span>
          </div>
        </div>
      </div>

      <div className="mt-auto flex items-center justify-between gap-3 border-t px-5 py-3.5" style={{ borderColor: 'var(--border)', backgroundColor: 'var(--bg-elevated)' }}>
        <span className="text-xs font-medium" style={{ color: 'var(--text-secondary)' }}>Appointment details</span>
        <div
          className={`flex items-center gap-1 text-xs font-semibold transition-transform duration-300 ${EASE} group-hover:translate-x-1`}
          style={{ color: 'var(--gold)' }}
        >
          View <ChevronRight size={13} />
        </div>
      </div>
    </Link>
  );
}

export default function ConsultationsPage() {
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const { data, isLoading } = useQuery({
    queryKey: ['consultations', page, perPage],
    queryFn: () => api.get('/consultations', { params: { page, per_page: perPage } }).then(r => r.data),
    placeholderData: prev => prev,
  });

  const consultations: ConsultationRow[] = data?.data ?? [];

  return (
    <div className="ss-consultancy-page-in mx-auto max-w-6xl space-y-8 p-4 sm:p-6 lg:py-9">
      <section className="ss-consultancy-hero-in grid overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5] lg:grid-cols-[1.35fr_0.65fr]" data-tour="consultations-header">
        <div className="ss-consultancy-left-in relative overflow-hidden p-7 sm:p-9 lg:p-11">
          <div className="absolute -left-24 -top-28 h-72 w-72 rounded-full border border-[#a5d6b5]/10" />
          <div className="ss-consultancy-reveal relative" style={{ animationDelay: '260ms' }}>
            <div className="mb-7 flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
              <HeartHandshake size={21} />
            </div>
            <div className="flex items-start gap-2">
              <h1 className="flex-1 text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Expert guidance, when the contract gets complicated.</h1>
              <span style={{ '--text-muted': '#b9c5bf' } as React.CSSProperties}>
                <PageTourButton tourKey="page-consultations" label="Take a tour of this page" />
              </span>
            </div>
            <p className="mt-4 max-w-2xl text-sm leading-6 text-[#b9c5bf] sm:text-base">
              Talk through payment, variations, notices or delay with an experienced construction professional.
            </p>

            <Link href="/app/consultations/new" className="mt-7 inline-block" data-tour="consultations-book-button">
              <Button size="lg" className="gap-2">
                <Plus size={16} /> Book a consultation
              </Button>
            </Link>
          </div>
        </div>

        <div className="ss-consultancy-right-in flex flex-col justify-center border-t border-[#a5d6b5]/10 bg-[#202c26] p-7 sm:p-9 lg:border-l lg:border-t-0">
          <p className="ss-consultancy-reveal text-sm font-semibold text-[#f4f7f5]" style={{ animationDelay: '380ms' }}>Built around your project</p>
          <div className="mt-5 space-y-4">
            {[
              'Private, focused discussion',
              'Practical next-step guidance',
              'Consultation record kept together',
            ].map((item, index) => (
              <div key={item} className="ss-consultancy-reveal flex items-center gap-3 text-sm text-[#b9c5bf]" style={{ animationDelay: `${460 + (index * 75)}ms` }}>
                <CheckCircle size={16} className="flex-shrink-0 text-[#9ee5b5]" />
                {item}
              </div>
            ))}
          </div>
        </div>
      </section>

      <section data-tour="consultations-list">
        <div className="ss-consultancy-list-in mb-4 flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Your consultations</h2>
            <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
              {isLoading ? 'Loading your appointments…' : consultations.length === 0 ? 'Your booked sessions will appear here.' : `${data?.total ?? consultations.length} appointment${(data?.total ?? consultations.length) === 1 ? '' : 's'} in your record.`}
            </p>
          </div>
          {consultations.length > 0 && (
            <Link href="/app/consultations/new" className="group inline-flex items-center gap-2 text-sm font-semibold" style={{ color: 'var(--gold)' }}>
              Book a consultation <ArrowRight size={14} className="transition-transform duration-200 group-hover:translate-x-1" />
            </Link>
          )}
        </div>

        {isLoading ? (
          <div className="grid gap-4 sm:grid-cols-2" aria-busy="true" aria-live="polite">
            <span className="sr-only">Loading your consultations…</span>
            {[...Array(4)].map((_, i) => (
              <div key={i} className="ss-consultancy-card-in" style={{ animationDelay: `${520 + Math.min(i * 70, 350)}ms` }}>
                <div className="h-56 animate-pulse rounded-2xl" style={{ backgroundColor: 'var(--bg-elevated)' }} />
              </div>
            ))}
          </div>
        ) : consultations.length === 0 ? (
          <div className="ss-consultancy-card-in rounded-2xl" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: '520ms' }}>
            <EmptyState
              icon={HeartHandshake}
              title="No consultations yet"
              description="Book a consultation to discuss a payment notice, variation, or any contract administration question with an experienced professional."
              action={
                <Link href="/app/consultations/new">
                  <Button size="sm">Book a consultation</Button>
                </Link>
              }
            />
          </div>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2">
            {consultations.map((c, idx) => (
              <ConsultationCard key={c.id} row={c} index={idx} />
            ))}
          </div>
        )}
      </section>

      {!isLoading && data?.total > 0 && (
        <div className="ss-consultancy-list-in" style={{ animationDelay: '660ms' }}>
          <PaginationBar
            page={data.current_page ?? page}
            lastPage={data.last_page ?? 1}
            total={data.total ?? 0}
            perPage={perPage}
            onPage={setPage}
            onPerPage={n => { setPerPage(n); setPage(1); }}
          />
        </div>
      )}
    </div>
  );
}
