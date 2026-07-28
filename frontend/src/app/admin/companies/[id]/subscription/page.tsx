'use client';

import { useParams } from 'next/navigation';
import { useQuery } from '@tanstack/react-query';
import Link from 'next/link';
import api from '@/lib/api';
import { ArrowLeft } from 'lucide-react';
import OrganizationSubscriptionSection from '@/components/billing/intelligence/OrganizationSubscriptionSection';

/**
 * G4A/G4B.2 — Organisation Subscription Administration, split out of the
 * company detail page onto its own route so that page isn't overloaded
 * with this section's own heavier data fetch and Super-Admin-only actions
 * (assign manual/complimentary, terminate). Same query key as the parent
 * company page (['admin-company', id]) so navigating here from that page's
 * link is instant (cache already warm); a direct visit fetches its own copy.
 */
export default function AdminCompanySubscriptionPage() {
  const { id } = useParams<{ id: string }>();

  const { data: org, isLoading: orgLoading } = useQuery({
    queryKey: ['admin-company', id],
    queryFn: () => api.get(`/organizations/${id}`).then(r => r.data?.data ?? r.data),
    enabled: !!id,
  });

  if (orgLoading) {
    return (
      <div className="p-6 max-w-6xl mx-auto space-y-4">
        <div className="h-6 w-40 rounded animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
        <div className="h-32 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-surface)' }} />
      </div>
    );
  }

  if (!org) {
    return (
      <div className="p-8 text-center py-24">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Company not found.</p>
      </div>
    );
  }

  return (
    <div className="p-6 max-w-6xl mx-auto space-y-6">
      <Link
        href={`/admin/companies/${id}`}
        className="inline-flex items-center gap-1.5 text-xs transition-colors hover:text-[var(--text-primary)]"
        style={{ color: 'var(--text-muted)' }}
      >
        <ArrowLeft size={13} /> {org.name}
      </Link>

      <div>
        <h1 className="text-xl font-bold" style={{ color: 'var(--text-primary)' }}>Subscription & Billing</h1>
        <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{org.name}</p>
      </div>

      <OrganizationSubscriptionSection organizationId={org.id} />
    </div>
  );
}
