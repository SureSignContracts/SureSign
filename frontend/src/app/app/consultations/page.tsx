'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useQuery } from '@tanstack/react-query';
import { HeartHandshake, Plus, Calendar, ChevronRight } from 'lucide-react';
import api from '@/lib/api';
import { Badge } from '@/components/ui/Badge';
import Button from '@/components/ui/Button';
import EmptyState from '@/components/ui/EmptyState';
import PaginationBar from '@/components/ui/PaginationBar';
import { formatDate } from '@/lib/utils';
import { EASE, staggerDelay } from '@/lib/motion';
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

  return (
    <Link
      href={`/app/consultations/${row.id}`}
      className={`group ss-animate-in rounded-2xl p-5 flex flex-col gap-3 transition-all duration-300 ${EASE} hover:-translate-y-0.5 shadow-[var(--shadow-card)] hover:shadow-[var(--shadow-pop)]`}
      style={{
        backgroundColor: 'var(--bg-surface)',
        border: '1px solid var(--border)',
        animationDelay: staggerDelay(index),
      }}
    >
      <div className="flex items-start justify-between gap-3">
        <div className="flex items-start gap-3 min-w-0">
          <div
            className="w-10 h-10 rounded-xl flex items-center justify-center shrink-0 ring-1"
            style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)', boxShadow: 'inset 0 0 0 1px var(--gold-8)' }}
          >
            <HeartHandshake size={17} />
          </div>
          <div className="min-w-0">
            <p className="text-sm font-semibold leading-tight truncate" style={{ color: 'var(--text-primary)' }}>
              {serviceName}
            </p>
            <p className="font-mono text-[11px] mt-1 truncate" style={{ color: 'var(--text-muted)' }}>
              {row.reference}
            </p>
          </div>
        </div>
        <Badge status={row.status} />
      </div>

      {row.consultation_enquiry?.title && (
        <p className="text-sm truncate" style={{ color: 'var(--text-secondary)' }}>
          {row.consultation_enquiry.title}
        </p>
      )}

      <div className="flex items-center justify-between gap-3 pt-2" style={{ borderTop: '1px solid var(--border)' }}>
        <div className="flex items-center gap-1.5 text-xs" style={{ color: 'var(--text-muted)' }}>
          <Calendar size={13} /> {formatDate(row.starts_at)}
        </div>
        <div
          className={`flex items-center gap-1 text-xs font-medium opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-300 ${EASE}`}
          style={{ color: 'var(--gold)' }}
        >
          View details <ChevronRight size={13} />
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
    <div className="p-6 max-w-5xl mx-auto space-y-5">
      <div className="flex items-center justify-between flex-wrap gap-3" data-tour="consultations-header">
        <div>
          <h1 className="text-2xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
            <HeartHandshake size={22} /> Consultancy
            <PageTourButton tourKey="page-consultations" label="Take a tour of this page" />
          </h1>
          <p className="text-sm mt-0.5" style={{ color: 'var(--text-muted)' }}>
            Book time with a real construction professional for guidance on your project.
          </p>
        </div>
        <Link href="/app/consultations/new" data-tour="consultations-book-button">
          <Button className="gap-2"><Plus size={15} /> Book a Consultation</Button>
        </Link>
      </div>

      <div data-tour="consultations-list">
        {isLoading ? (
          <div className="grid gap-3 sm:grid-cols-2" aria-busy="true" aria-live="polite">
            <span className="sr-only">Loading your consultations…</span>
            {[...Array(4)].map((_, i) => (
              <div key={i} className="h-32 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
            ))}
          </div>
        ) : consultations.length === 0 ? (
          <div className="rounded-2xl" style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
            <EmptyState
              icon={HeartHandshake}
              title="No consultations yet"
              description="Book a consultation to discuss a payment notice, variation, or any contract administration question with an experienced professional."
              action={
                <Link href="/app/consultations/new">
                  <Button size="sm">Book a Consultation</Button>
                </Link>
              }
            />
          </div>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            {consultations.map((c, idx) => (
              <ConsultationCard key={c.id} row={c} index={idx} />
            ))}
          </div>
        )}
      </div>

      {!isLoading && data?.total > 0 && (
        <PaginationBar
          page={data.current_page ?? page}
          lastPage={data.last_page ?? 1}
          total={data.total ?? 0}
          perPage={perPage}
          onPage={setPage}
          onPerPage={n => { setPerPage(n); setPage(1); }}
        />
      )}
    </div>
  );
}
