import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import { ThemeToggle } from '@/components/shared/ThemeToggle';

const footerLinkClass = 'inline-flex min-h-9 items-center transition-colors duration-200 hover:text-text-primary';

export function Footer() {
  return (
    <footer className="border-t border-border">
      <Container className="grid grid-cols-2 gap-x-8 gap-y-12 py-20 lg:grid-cols-3 lg:gap-10 xl:grid-cols-[1.4fr_repeat(5,1fr)] xl:py-24">
        <div className="col-span-2 lg:col-span-3 xl:col-span-1">
          <div className="text-2xl font-medium tracking-tighter text-text-primary">SureSign</div>
          <p className="mt-4 max-w-[34ch] text-text-secondary">
            Construction contract administration, connected from contract
            analysis to final account.
          </p>
        </div>

        <nav aria-labelledby="footer-platform">
          <h2 id="footer-platform" className="text-sm font-medium uppercase tracking-wide text-text-muted">Platform</h2>
          <ul className="mt-5 space-y-0.5 text-sm text-text-secondary">
            <li><Link href="/product" className={footerLinkClass}>Product</Link></li>
            <li><Link href="/pricing" className={footerLinkClass}>Pricing</Link></li>
            <li><a href="https://docs.suresigncontracts.app" target="_blank" rel="noopener noreferrer" className={footerLinkClass}>Documentation</a></li>
            <li><Link href="/#how-it-works" className={footerLinkClass}>How It Works</Link></li>
            <li><Link href="/#connected-platform" className={footerLinkClass}>Connected Platform</Link></li>
          </ul>
        </nav>

        <nav aria-labelledby="footer-services">
          <h2 id="footer-services" className="text-sm font-medium uppercase tracking-wide text-text-muted">Services</h2>
          <ul className="mt-5 space-y-0.5 text-sm text-text-secondary">
            <li><Link href="/services" className={footerLinkClass}>Services</Link></li>
            <li><Link href="/consultancy" className={footerLinkClass}>Consultancy</Link></li>
            <li><Link href="/adjudication" className={footerLinkClass}>Adjudication</Link></li>
          </ul>
        </nav>

        <nav aria-labelledby="footer-company">
          <h2 id="footer-company" className="text-sm font-medium uppercase tracking-wide text-text-muted">Company</h2>
          <ul className="mt-5 space-y-0.5 text-sm text-text-secondary">
            <li><Link href="/book/demo?src=nav" className={footerLinkClass}>Book a Demo</Link></li>
            <li><Link href="/contact" className={footerLinkClass}>Contact</Link></li>
          </ul>
        </nav>

        <nav aria-labelledby="footer-legal">
          <h2 id="footer-legal" className="text-sm font-medium uppercase tracking-wide text-text-muted">Legal</h2>
          <ul className="mt-5 space-y-0.5 text-sm text-text-secondary">
            <li><Link href="/privacy" className={footerLinkClass}>Privacy Policy</Link></li>
            <li><Link href="/terms" className={footerLinkClass}>Terms of Use</Link></li>
          </ul>
        </nav>

        <nav aria-labelledby="footer-trust">
          <h2 id="footer-trust" className="text-sm font-medium uppercase tracking-wide text-text-muted">Trust</h2>
          <ul className="mt-5 space-y-0.5 text-sm text-text-secondary">
            <li><Link href="/security" className={footerLinkClass}>Security</Link></li>
            <li><Link href="/contact" className={footerLinkClass}>Procurement Questions</Link></li>
          </ul>
          <div className="mt-6 flex items-center gap-3">
            <ThemeToggle />
            <span className="text-xs text-text-muted">Appearance</span>
          </div>
        </nav>
      </Container>

      <Container className="border-t border-border py-7 text-xs text-text-muted">
        <span>© {new Date().getFullYear()} SureSign Contracts.</span>
      </Container>
    </footer>
  );
}
