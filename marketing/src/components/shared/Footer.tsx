import Link from 'next/link';
import { Container } from '@/components/shared/Container';
import { ThemeToggle } from '@/components/shared/ThemeToggle';

export function Footer() {
  return (
    <footer className="border-t border-border">
      <Container className="grid gap-12 py-20 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr] lg:gap-10 lg:py-24">
        <div>
          <div className="text-2xl font-medium tracking-tighter text-text-primary">SureSign</div>
          <p className="mt-4 max-w-[34ch] text-text-secondary">
            Construction contract administration, connected from contract
            analysis to final account.
          </p>
        </div>

        <div>
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Platform</div>
          <ul className="mt-5 space-y-3.5 text-sm text-text-secondary">
            <li><Link href="/#how-it-works" className="transition-colors duration-200 hover:text-text-primary">How It Works</Link></li>
            <li><Link href="/product" className="transition-colors duration-200 hover:text-text-primary">Product Workflows</Link></li>
            <li><Link href="/#connected-platform" className="transition-colors duration-200 hover:text-text-primary">Connected Platform</Link></li>
            <li><Link href="/adjudication" className="transition-colors duration-200 hover:text-text-primary">Adjudication</Link></li>
            <li><a href="https://docs.suresigncontracts.app" target="_blank" rel="noopener" className="transition-colors duration-200 hover:text-text-primary">Documentation</a></li>
          </ul>
        </div>

        <div>
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Company</div>
          <ul className="mt-5 space-y-3.5 text-sm text-text-secondary">
            <li><Link href="/book/demo?src=nav" className="transition-colors duration-200 hover:text-text-primary">Book a Demo</Link></li>
            <li><Link href="/contact" className="transition-colors duration-200 hover:text-text-primary">Contact</Link></li>
          </ul>
        </div>

        <div>
          <div className="text-sm font-medium uppercase tracking-wide text-text-muted">Trust</div>
          <ul className="mt-5 space-y-3.5 text-sm text-text-secondary">
            <li><Link href="/security" className="transition-colors duration-200 hover:text-text-primary">Security</Link></li>
            <li><Link href="/contact" className="transition-colors duration-200 hover:text-text-primary">Procurement Questions</Link></li>
          </ul>
          <div className="mt-6 flex items-center gap-3">
            <ThemeToggle />
            <span className="text-xs text-text-muted">Appearance</span>
          </div>
        </div>
      </Container>

      <Container className="flex flex-col gap-3 border-t border-border py-7 text-xs text-text-muted md:flex-row md:items-center md:justify-between">
        <span>© {new Date().getFullYear()} SureSign Contracts.</span>
        <div className="flex items-center gap-5">
          <Link
            href="/privacy"
            className="transition-colors duration-200 hover:text-text-primary"
          >
            Privacy
          </Link>
          <Link
            href="/terms"
            className="transition-colors duration-200 hover:text-text-primary"
          >
            Terms of Use
          </Link>
        </div>
      </Container>
    </footer>
  );
}
