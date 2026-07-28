'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useEffect, useRef, useState, type KeyboardEvent } from 'react';
import { ChevronDown } from 'lucide-react';
import { Container } from '@/components/shared/Container';

export interface PricingNavPlan {
  slug: string;
  name: string;
}

const LINKS = [
  { href: '/product', label: 'Product' },
  { href: '/adjudication', label: 'Adjudication' },
  { href: '/contact', label: 'Contact' },
  { href: 'https://docs.suresigncontracts.app', label: 'Documentation', external: true },
];

function NavLink({
  href,
  label,
  external,
  active,
  onClick,
}: {
  href: string;
  label: string;
  external?: boolean;
  active?: boolean;
  onClick?: () => void;
}) {
  return (
    <Link
      href={href}
      target={external ? '_blank' : undefined}
      rel={external ? 'noopener' : undefined}
      onClick={onClick}
      className={`group relative rounded-lg px-3 py-2 text-sm transition-[background-color,color] duration-200 ${
        active ? 'bg-bg-surface font-medium text-text-primary' : 'text-text-secondary hover:bg-bg-surface hover:text-text-primary'
      }`}
    >
      {label}
    </Link>
  );
}

function PricingDropdown({ plans, mobile = false, onNavigate }: { plans: PricingNavPlan[]; mobile?: boolean; onNavigate?: () => void }) {
  const pathname = usePathname();
  const rootRef = useRef<HTMLDivElement>(null);
  const firstLinkRef = useRef<HTMLAnchorElement>(null);
  const [open, setOpen] = useState(false);
  const active = pathname === '/pricing' || pathname.startsWith('/pricing/');
  const entries = [
    { href: '/pricing', label: 'Overview' },
    ...plans.map((plan) => ({ href: `/pricing/${plan.slug}`, label: plan.name })),
    { href: '/pricing/compare', label: 'Compare Plans' },
  ];

  useEffect(() => {
    if (!open) return;

    const closeOutside = (event: PointerEvent) => {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const closeOnEscape = (event: globalThis.KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('pointerdown', closeOutside);
    document.addEventListener('keydown', closeOnEscape);
    return () => {
      document.removeEventListener('pointerdown', closeOutside);
      document.removeEventListener('keydown', closeOnEscape);
    };
  }, [open]);

  function handleButtonKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setOpen(true);
      requestAnimationFrame(() => firstLinkRef.current?.focus());
    }
  }

  function handleMenuKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const links = Array.from(event.currentTarget.querySelectorAll<HTMLAnchorElement>('a'));
    const current = links.indexOf(document.activeElement as HTMLAnchorElement);
    const next = event.key === 'Home'
      ? 0
      : event.key === 'End'
        ? links.length - 1
        : event.key === 'ArrowDown'
          ? (current + 1) % links.length
          : (current - 1 + links.length) % links.length;
    links[next]?.focus();
  }

  return (
    <div ref={rootRef} className={mobile ? 'w-full' : 'relative'}>
      <button
        type="button"
        aria-expanded={open}
        aria-haspopup="menu"
        onClick={() => setOpen((value) => !value)}
        onKeyDown={handleButtonKeyDown}
        className={`flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm transition-[background-color,color] duration-200 ${
          active || open
            ? 'bg-bg-surface font-medium text-text-primary'
            : 'text-text-secondary hover:bg-bg-surface hover:text-text-primary'
        }`}
      >
        Pricing
        <ChevronDown
          size={14}
          strokeWidth={1.7}
          aria-hidden="true"
          className={`transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
        />
      </button>

      <div
        role="menu"
        aria-label="Pricing pages"
        onKeyDown={handleMenuKeyDown}
        className={
          mobile
            ? `grid overflow-hidden transition-[grid-template-rows,opacity] duration-200 ${open ? 'mt-3 grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0'}`
            : `absolute left-1/2 top-[calc(100%+0.75rem)] w-44 -translate-x-1/2 overflow-hidden rounded-2xl border border-border bg-bg-base/95 p-1.5 shadow-[var(--shadow-pop)] backdrop-blur-xl transition-[opacity,transform,visibility] duration-200 ease-out ${open ? 'visible translate-y-0 opacity-100' : 'invisible -translate-y-1.5 opacity-0'}`
        }
      >
        <div className={mobile ? 'min-h-0 pl-4' : ''}>
          {!mobile && (
            <div className="px-2.5 pb-2 pt-1">
              <p className="text-[11px] font-medium text-text-muted">Plans and pricing</p>
            </div>
          )}
          {entries.map((entry, index) => {
            const startsPlanGroup = index === 1 && plans.length > 0;
            const startsCompare = index === plans.length + 1;
            return (
              <div key={entry.href} className={startsPlanGroup || startsCompare ? 'mt-1 border-t border-border pt-1' : ''}>
                <Link
                  ref={index === 0 ? firstLinkRef : undefined}
                  href={entry.href}
                  role="menuitem"
                  tabIndex={open ? 0 : -1}
                  onClick={() => {
                    setOpen(false);
                    onNavigate?.();
                  }}
                  className={`block rounded-xl px-2.5 py-2.5 text-sm transition-colors ${
                    pathname === entry.href
                      ? 'bg-bg-surface font-medium text-text-primary'
                      : 'text-text-secondary hover:bg-bg-surface hover:text-text-primary'
                  }`}
                >
                  {entry.label}
                </Link>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
}

export function MarketingNavClient({ pricingPlans }: { pricingPlans: PricingNavPlan[] }) {
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const [scrolled, setScrolled] = useState(false);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  return (
    <header
      className={`sticky top-0 z-50 transition-colors duration-500 ${
        scrolled || open
          ? 'border-b border-border bg-bg-base/88 backdrop-blur-xl'
          : 'border-b border-transparent bg-bg-base'
      }`}
    >
      <Container className="relative flex h-16 items-center justify-between">
        <Link href="/" className="relative flex h-10 w-12 items-center justify-center" aria-label="SureSign home">
          <span className="brand-logo h-8 w-12" aria-hidden="true" />
        </Link>

        <nav
          className="absolute left-1/2 hidden -translate-x-1/2 items-center gap-1 xl:flex"
          aria-label="Primary navigation"
        >
          <PricingDropdown plans={pricingPlans} />
          {LINKS.map((link) => (
            <NavLink
              key={link.href}
              {...link}
              active={!link.external && pathname === link.href}
            />
          ))}
        </nav>

        <div className="hidden items-center gap-5 xl:flex">
          <a
            href="https://app.suresigncontracts.app"
            className="text-sm font-medium text-text-secondary transition-colors duration-200 hover:text-text-primary"
          >
            Log In
          </a>
          <Link
            href="/book/demo?src=nav"
            className="whitespace-nowrap rounded-full bg-accent px-5 py-2.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
          >
            Book a Demo
          </Link>
        </div>

        <div className="flex items-center gap-3 xl:hidden">
          <button
            type="button"
            onClick={() => setOpen((value) => !value)}
            className="flex h-11 w-11 items-center justify-center rounded-full border border-border transition-colors hover:border-border-light"
            aria-label="Toggle menu"
            aria-expanded={open}
            aria-controls="mobile-navigation"
          >
            <span className="sr-only">Menu</span>
            <span className="flex flex-col gap-1" aria-hidden="true">
              <span className={`h-px w-4 bg-text-primary transition-transform duration-200 ${open ? 'translate-y-[2.5px] rotate-45' : ''}`} />
              <span className={`h-px w-4 bg-text-primary transition-transform duration-200 ${open ? '-translate-y-[2.5px] -rotate-45' : ''}`} />
            </span>
          </button>
        </div>
      </Container>

      <div
        id="mobile-navigation"
        className={`overflow-hidden border-t border-border bg-bg-base transition-[grid-template-rows] duration-300 ease-out xl:hidden ${
          open ? 'grid grid-rows-[1fr]' : 'grid grid-rows-[0fr]'
        }`}
      >
        <div className="min-h-0">
          <Container className="flex flex-col gap-4 py-6">
            <PricingDropdown plans={pricingPlans} mobile onNavigate={() => setOpen(false)} />
            {LINKS.map((link) => (
              <NavLink
                key={link.href}
                {...link}
                active={!link.external && pathname === link.href}
                onClick={() => setOpen(false)}
              />
            ))}
            <a
              href="https://app.suresigncontracts.app"
              className="text-sm text-text-secondary transition-colors duration-200 hover:text-text-primary"
              onClick={() => setOpen(false)}
            >
              Log In
            </a>
            <Link
              href="/book/demo?src=nav"
              className="rounded-full bg-accent px-5 py-2.5 text-center text-sm font-medium text-accent-fg"
              onClick={() => setOpen(false)}
            >
              Book a Demo
            </Link>
          </Container>
        </div>
      </div>
    </header>
  );
}
