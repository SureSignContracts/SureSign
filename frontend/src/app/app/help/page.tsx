'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import {
  HelpCircle, Compass, Inbox, BookOpen, Activity, ScrollText, LifeBuoy,
} from 'lucide-react';
import { KnowledgeBaseSection } from '@/components/support/KnowledgeBaseSection';
import { SystemStatusSection } from '@/components/support/SystemStatusSection';
import { EmergencyBanner } from '@/components/support/EmergencyBanner';
import { CombinedSearch } from '@/components/support/CombinedSearch';

// The Help Center landing page's hub — some tiles are real pages, others are
// anchors into sections further down this same page (Knowledge Base, System
// Status don't have dedicated pages yet). Update an entry's href here, not
// the anchor id on its section, if that section ever moves to its own page.
const HUB_LINKS: { label: string; description: string; href: string; icon: React.ElementType }[] = [
  { label: 'Guided Tours', description: 'A walkthrough for every page.', href: '/app/help/tours', icon: Compass },
  { label: 'Contact Support', description: 'Send a question to the SureSign team.', href: '/app/help/support', icon: LifeBuoy },
  { label: 'My Support Requests', description: 'Track requests you’ve submitted.', href: '/app/help/support?tab=requests', icon: Inbox },
  { label: 'Knowledge Base', description: 'Browse the SureSign user guide.', href: '#knowledge-base', icon: BookOpen },
  { label: 'FAQ', description: 'Quick answers, by category.', href: '/app/help/faq', icon: HelpCircle },
  { label: 'System Status', description: 'Live status of platform components.', href: '#system-status', icon: Activity },
  { label: 'Release Notes', description: 'What’s new in SureSign.', href: '/app/settings/releases', icon: ScrollText },
];

function HubTile({ label, description, href, icon: Icon }: { label: string; description: string; href: string; icon: React.ElementType }) {
  const content = (
    <>
      <div className="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--gold-15)' }}>
        <Icon size={16} style={{ color: 'var(--gold)' }} />
      </div>
      <div className="min-w-0">
        <p className="text-sm font-semibold truncate" style={{ color: 'var(--text-primary)' }}>{label}</p>
        <p className="text-xs mt-0.5 truncate" style={{ color: 'var(--text-muted)' }}>{description}</p>
      </div>
    </>
  );
  const className = 'group flex items-center gap-3 rounded-2xl px-4 py-3.5 transition-colors hover:bg-[var(--bg-hover)]';
  const style = { backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' };

  return href.startsWith('#')
    ? <a href={href} className={className} style={style}>{content}</a>
    : <Link href={href} className={className} style={style}>{content}</Link>;
}

export default function HelpCenterPage() {
  const router = useRouter();
  const searchParams = useSearchParams();

  // Backward compatibility for two generations of old links, both now
  // redirected on rather than left to land on a landing page that no longer
  // has the content they were pointing at. Contact Support and My Support
  // Requests are now the same page (/app/help/support), split into tabs —
  // see the "tab" query param below.
  //   1. Contact Support (Batch 4): a `category`/`route`/`module` query
  //      param, or a bare #contact-support hash, unambiguously means this
  //      was a Contact-Support-intent link — redirect to /app/help/support
  //      (New Request tab) with the same params.
  //   2. My Support Requests (Batch 5): a `ticket` query param, or a bare
  //      #my-requests hash, means this was a My-Support-Requests-intent
  //      link — redirect to /app/help/support/{ticket} if an id is present,
  //      otherwise /app/help/support?tab=requests.
  // router.replace (not push) so the stale intermediate URL doesn't sit in
  // browser history; each branch redirects to a different route, so neither
  // can loop back into this effect.
  useEffect(() => {
    const hasSupportIntent =
      searchParams.has('category') || searchParams.has('route') || searchParams.has('module') ||
      window.location.hash === '#contact-support';
    if (hasSupportIntent) {
      router.replace(`/app/help/support?${searchParams.toString()}`);
      return;
    }

    const ticketParam = searchParams.get('ticket');
    const hasRequestsIntent = !!ticketParam || window.location.hash === '#my-requests';
    if (hasRequestsIntent) {
      router.replace(ticketParam && /^\d+$/.test(ticketParam) ? `/app/help/support/${ticketParam}` : '/app/help/support?tab=requests');
    }
  }, [searchParams, router]);

  return (
    <div className="p-6 max-w-4xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex items-start gap-3">
        <div className="w-11 h-11 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'var(--gold-15)' }}>
          <HelpCircle size={20} style={{ color: 'var(--gold)' }} />
        </div>
        <div>
          <h1 className="text-[1.75rem] font-bold" style={{ color: 'var(--text-primary)' }}>Help Center</h1>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
            Everything for getting help in SureSign, in one place.
          </p>
        </div>
      </div>

      <EmergencyBanner />

      <CombinedSearch />

      {/* Hub */}
      <div className="grid sm:grid-cols-2 gap-3">
        {HUB_LINKS.map(link => <HubTile key={link.label} {...link} />)}
      </div>

      <KnowledgeBaseSection />

      <SystemStatusSection />
    </div>
  );
}
