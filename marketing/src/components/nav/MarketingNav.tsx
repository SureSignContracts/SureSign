'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { Container } from '@/components/shared/Container';
import { ThemeToggle } from '@/components/shared/ThemeToggle';

const LINKS = [
  { href: '/#how-it-works', label: 'How It Works', id: 'how-it-works' },
  { href: '/#connected-platform', label: 'Connected Platform', id: 'connected-platform' },
  { href: '/security', label: 'Security' },
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
      className={`group relative text-sm transition-colors duration-200 ${
        active ? 'text-text-primary' : 'text-text-secondary hover:text-text-primary'
      }`}
    >
      {label}
      <span
        className={`absolute -bottom-1 left-0 h-px bg-text-primary transition-[width] duration-300 ease-out ${
          active ? 'w-full' : 'w-0 group-hover:w-full'
        }`}
      />
    </Link>
  );
}

export function MarketingNav() {
  const [scrolled, setScrolled] = useState(false);
  const [open, setOpen] = useState(false);
  const [activeId, setActiveId] = useState<string | null>(null);

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => window.removeEventListener('scroll', onScroll);
  }, []);

  // Active-section indicator — only sections that exist on the current page
  // (e.g. the homepage) register observers; other routes simply see none.
  useEffect(() => {
    const ids = LINKS.map((l) => l.id).filter((id): id is string => Boolean(id));
    const elements = ids.map((id) => document.getElementById(id)).filter((el): el is HTMLElement => Boolean(el));
    if (elements.length === 0) return;

    const observer = new IntersectionObserver(
      (entries) => {
        const visible = entries.filter((e) => e.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio);
        if (visible[0]) setActiveId(visible[0].target.id);
      },
      { rootMargin: '-40% 0px -50% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] }
    );

    elements.forEach((el) => observer.observe(el));
    return () => observer.disconnect();
  }, []);

  return (
    <header
      className={`sticky top-0 z-50 border-b transition-colors duration-500 ${
        scrolled ? 'border-border bg-bg-base/75 backdrop-blur-md' : 'border-transparent bg-transparent'
      }`}
    >
      {/*
        Fixed height, no animated resize — animating `height` forces a layout
        reflow on every scroll-state change, which combined with the hero's
        own scroll-linked parallax was the actual source of the first-scroll
        stutter. Only compositor-safe properties (colour, blur, border)
        transition here now.
      */}
      <Container className="flex h-16 items-center justify-between">
        <Link href="/" className="text-base font-medium tracking-tight text-text-primary">
          SureSign
        </Link>

        <nav className="hidden items-center gap-9 md:flex">
          {LINKS.map((link) => (
            <NavLink key={link.href} {...link} active={Boolean(link.id) && link.id === activeId} />
          ))}
        </nav>

        <div className="hidden items-center gap-5 md:flex">
          <a
            href="https://app.suresigncontracts.app"
            className="text-sm font-medium text-text-secondary transition-colors duration-200 hover:text-text-primary"
          >
            Log In
          </a>
          <ThemeToggle />
          <Link
            href="/book-a-demo"
            className="rounded-full bg-accent px-5 py-2.5 text-sm font-medium text-accent-fg shadow-[var(--shadow-card)] transition-all duration-200 hover:shadow-[var(--shadow-pop)] active:translate-y-px"
          >
            Book a Demo
          </Link>
        </div>

        <div className="flex items-center gap-3 md:hidden">
          <ThemeToggle />
          <button
            type="button"
            onClick={() => setOpen((v) => !v)}
            className="flex h-9 w-9 items-center justify-center rounded-full border border-border transition-colors hover:border-border-light"
            aria-label="Toggle menu"
            aria-expanded={open}
          >
            <span className="sr-only">Menu</span>
            <div className="flex flex-col gap-1">
              <span className={`h-px w-4 bg-text-primary transition-transform duration-200 ${open ? 'translate-y-[2.5px] rotate-45' : ''}`} />
              <span className={`h-px w-4 bg-text-primary transition-transform duration-200 ${open ? '-translate-y-[2.5px] -rotate-45' : ''}`} />
            </div>
          </button>
        </div>
      </Container>

      <div
        className={`overflow-hidden border-t border-border bg-bg-base transition-[grid-template-rows] duration-300 ease-out md:hidden ${
          open ? 'grid grid-rows-[1fr]' : 'grid grid-rows-[0fr]'
        }`}
      >
        <div className="min-h-0">
          <Container className="flex flex-col gap-4 py-6">
            {LINKS.map((link) => (
              <NavLink key={link.href} {...link} onClick={() => setOpen(false)} />
            ))}
            <a
              href="https://app.suresigncontracts.app"
              className="text-sm text-text-secondary transition-colors duration-200 hover:text-text-primary"
              onClick={() => setOpen(false)}
            >
              Log In
            </a>
            <Link
              href="/book-a-demo"
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
