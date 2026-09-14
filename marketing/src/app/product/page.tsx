import type { Metadata } from 'next';
import Link from 'next/link';
import { ArrowDownRight, ArrowUpRight, FileCheck2, Layers3, ClipboardCheck } from 'lucide-react';
import { ProductMotion } from '@/components/product/ProductMotion';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { ContractAnalysis } from '@/components/sections/ContractAnalysis';
import { ProjectWorkspace } from '@/components/sections/ProjectWorkspace';
import { TradePackages } from '@/components/sections/TradePackages';
import { CommercialWorkflow } from '@/components/sections/CommercialWorkflow';
import { ProgrammeAndRisk } from '@/components/sections/ProgrammeAndRisk';
import { Drawings } from '@/components/sections/Drawings';
import { SiteRecords } from '@/components/sections/SiteRecords';
import { HealthAndSafety } from '@/components/sections/HealthAndSafety';
import { FridayPacks } from '@/components/sections/FridayPacks';
import { DeliveryDocs } from '@/components/sections/DeliveryDocs';
import { Notifications } from '@/components/sections/Notifications';
import { BookDemoCta } from '@/components/sections/BookDemoCta';

export const metadata: Metadata = {
  title: 'Product Workflows',
  description:
    'Explore SureSign contract intelligence, project workspaces, trade packages, commercial administration, programme, risk, site reports, Health & Safety records, weekly Friday Packs, drawings, documents and notifications.',
  alternates: { canonical: '/product' },
  openGraph: {
    title: 'SureSign Product Workflows',
    description:
      'See how confirmed contract information connects construction commercial workflows, site records, weekly reporting and the complete project record.',
    url: '/product',
    siteName: 'SureSign',
    locale: 'en_GB',
    type: 'website',
  },
  twitter: {
    card: 'summary_large_image',
    title: 'SureSign Product Workflows',
    description:
      'Confirmed contract information connected to construction commercial workflows and one project record.',
  },
};

export default function ProductPage() {
  return (
    <>
      <MarketingNav />
      <main id="main-content">
        <ProductMotion>
          <section className="bg-atmosphere border-b border-border">
            <Container className="py-16 md:py-20">
              <p data-product-intro className="text-sm font-medium text-text-muted">Product workflows</p>
              <h1 data-product-intro className="mt-5 max-w-[19ch] text-5xl font-medium leading-[1.06] tracking-tighter text-text-primary text-balance md:text-7xl">
                One contract.<br />Every part of the project.
              </h1>
              <p data-product-intro className="mt-6 max-w-[48ch] text-lg leading-8 text-text-secondary">
                Connect confirmed contract information to commercial workflows, site records and the complete project story.
              </p>
              <div data-product-intro className="mt-8 flex flex-wrap items-center gap-4">
                <Link href="/book/demo?src=product" className="group inline-flex min-h-12 items-center gap-5 rounded-full bg-accent px-6 text-sm font-medium text-accent-fg transition-transform duration-200 hover:-translate-y-1">
                  Book a Demo <ArrowUpRight aria-hidden className="size-4 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                </Link>
                <a href="#contract-intelligence" className="group inline-flex min-h-12 items-center gap-3 px-3 text-sm font-medium text-text-primary">
                  Explore the workflows <ArrowDownRight aria-hidden className="size-4 transition-transform duration-200 group-hover:translate-y-1" />
                </a>
              </div>
              <nav aria-label="Product workflow stages" className="relative mt-14 grid gap-3 md:mt-16 md:grid-cols-3 md:gap-6">
                <div data-product-line aria-hidden className="absolute inset-x-0 top-0 hidden h-px bg-border-light md:block" />
                {[
                  { title: 'Start with certainty', detail: 'Review and confirm the contract', href: '#contract-intelligence', icon: FileCheck2 },
                  { title: 'Keep work connected', detail: 'Packages, payments and programme', href: '#commercial-workflows', icon: Layers3 },
                  { title: 'Build the project record', detail: 'Site evidence and weekly reporting', href: '#site-records', icon: ClipboardCheck },
                ].map(({ title, detail, href, icon: Icon }) => (
                  <a data-product-step key={href} href={href} className="group flex items-start gap-4 rounded-xl border border-border bg-bg-base p-5 transition-[background-color,border-color] duration-200 hover:border-border-light hover:bg-bg-surface md:mt-5">
                    <Icon aria-hidden className="mt-1 size-5 shrink-0 text-text-secondary" />
                    <span className="flex-1"><span className="block text-base font-medium text-text-primary">{title}</span><span className="mt-1 block text-sm text-text-secondary">{detail}</span></span>
                    <ArrowDownRight aria-hidden className="mt-1 size-4 shrink-0 text-text-muted transition-transform duration-200 group-hover:translate-x-1 group-hover:translate-y-1" />
                  </a>
                ))}
              </nav>
            </Container>
          </section>
          <div id="contract-intelligence" data-product-chapter className="scroll-mt-24">
            <ContractAnalysis />
          </div>
          <div id="project-workspace" data-product-chapter className="scroll-mt-24">
            <ProjectWorkspace />
          </div>
          <div id="trade-packages" data-product-chapter className="scroll-mt-24">
            <TradePackages />
          </div>
          <div id="commercial-workflows" data-product-chapter className="scroll-mt-24">
            <CommercialWorkflow />
          </div>
          <div id="programme-risk" data-product-chapter className="scroll-mt-24">
            <ProgrammeAndRisk />
          </div>
          <div id="drawings" data-product-chapter className="scroll-mt-24">
            <Drawings />
          </div>
          <div id="site-records" data-product-chapter className="scroll-mt-24">
            <SiteRecords />
          </div>
          <div id="health-safety" data-product-chapter className="scroll-mt-24">
            <HealthAndSafety />
          </div>
          <div id="friday-packs" data-product-chapter className="scroll-mt-24">
            <FridayPacks />
          </div>
          <div id="delivery-documents" data-product-chapter className="scroll-mt-24">
            <DeliveryDocs />
          </div>
          <div id="notifications" data-product-chapter className="scroll-mt-24">
            <Notifications />
          </div>
          <BookDemoCta />
        </ProductMotion>
      </main>
      <Footer />
    </>
  );
}
