'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import {
  HelpCircle, Compass, Inbox, BookOpen, Activity, ScrollText, LifeBuoy,
  ChevronRight,
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

function HubTile({ label, description, href, icon: Icon, index }: { label: string; description: string; href: string; icon: React.ElementType; index: number }) {
  const featured = index === 1;
  const content = (
    <>
      <div className={`flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl transition-transform duration-300 group-hover:rotate-[-3deg] group-hover:scale-105 ${featured ? 'bg-[#9ee5b5] text-[#18211d]' : 'bg-[#e9f5ed] text-[#347b50]'}`}>
        <Icon size={17} />
      </div>
      <div className="min-w-0">
        <p className={`text-sm font-semibold ${featured ? 'text-white' : 'text-[#18211d]'}`}>{label}</p>
        <p className={`mt-1 text-xs leading-5 ${featured ? 'text-[#aebbb5]' : 'text-[#748079]'}`}>{description}</p>
      </div>
      <span className={`ml-auto self-start text-[10px] font-semibold tracking-[0.14em] ${featured ? 'text-[#9ee5b5]' : 'text-[#9aa39e]'}`}>{String(index + 1).padStart(2, '0')}</span>
      <ChevronRight size={14} className={`absolute bottom-5 right-5 transition-transform duration-300 group-hover:translate-x-1 ${featured ? 'text-[#9ee5b5]' : 'text-[#347b50]'}`} />
    </>
  );
  const className = `group relative ss-animate-in flex min-h-32 items-center gap-4 rounded-2xl px-5 py-5 pr-12 shadow-[0_10px_28px_rgba(24,33,29,0.06)] transition-all duration-300 hover:-translate-y-0.5 ${featured ? 'bg-[#18211d]' : 'bg-white'} ${index < 2 ? 'sm:col-span-3' : index === 6 ? 'sm:col-span-6' : 'sm:col-span-2'}`;
  const style = { animationDelay: `${index * 55}ms` };

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
    <div className="ss-projects-page mx-auto max-w-6xl space-y-6 p-4 sm:p-6 lg:py-9">
      {/* Header */}
      <section className="ss-animate-in overflow-hidden rounded-2xl bg-[#18211d] text-[#f4f7f5]">
        <div className="relative overflow-hidden p-7 sm:p-10 lg:p-12">
          <div className="absolute -right-20 -top-28 h-80 w-80 rounded-full border border-[#a5d6b5]/10 transition-transform duration-700 ease-out hover:scale-105" />
          <div className="relative max-w-3xl">
            <div className="mb-8 flex h-11 w-11 items-center justify-center rounded-xl border border-[#a5d6b5]/20 bg-[#a5d6b5]/10 text-[#9ee5b5]">
              <HelpCircle size={20} />
            </div>
            <h1 className="text-3xl font-semibold tracking-[-0.04em] sm:text-4xl">Find the answer. Keep the work moving.</h1>
            <p className="mt-4 max-w-xl text-sm leading-6 text-[#b9c5bf] sm:text-base">
              Search guidance, take a product tour or speak directly with the SureSign support team.
            </p>
          </div>
        </div>
      </section>

      <EmergencyBanner />

      <div className="ss-animate-in" style={{ animationDelay: '100ms' }}><CombinedSearch /></div>

      {/* Hub */}
      <section className="ss-animate-in" style={{ animationDelay: '170ms' }}>
        <div className="mb-4">
          <h2 className="text-xl font-semibold tracking-[-0.025em]" style={{ color: 'var(--text-primary)' }}>Choose how to get help</h2>
          <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>Browse guidance, check service health or contact the team.</p>
        </div>
        <div className="grid gap-3 sm:grid-cols-6">
          {HUB_LINKS.map((link, index) => <HubTile key={link.label} {...link} index={index} />)}
        </div>
      </section>

      <div className="grid items-start gap-5 lg:grid-cols-[1.25fr_0.75fr]">
        <div className="ss-animate-in" style={{ animationDelay: '240ms' }}><KnowledgeBaseSection /></div>
        <div className="ss-animate-in" style={{ animationDelay: '300ms' }}><SystemStatusSection /></div>
      </div>
    </div>
  );
}
