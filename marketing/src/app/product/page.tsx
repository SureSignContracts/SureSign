import type { Metadata } from 'next';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Container } from '@/components/shared/Container';
import { ContractAnalysis } from '@/components/sections/ContractAnalysis';
import { ProjectWorkspace } from '@/components/sections/ProjectWorkspace';
import { TradePackages } from '@/components/sections/TradePackages';
import { CommercialWorkflow } from '@/components/sections/CommercialWorkflow';
import { ProgrammeAndRisk } from '@/components/sections/ProgrammeAndRisk';
import { Drawings } from '@/components/sections/Drawings';
import { DeliveryDocs } from '@/components/sections/DeliveryDocs';
import { Notifications } from '@/components/sections/Notifications';
import { BookDemoCta } from '@/components/sections/BookDemoCta';

export const metadata: Metadata = {
  title: 'Product Workflows',
  description:
    'Explore SureSign contract intelligence, project workspaces, trade packages, commercial administration, programme, risk, drawings, documents and notifications.',
  alternates: { canonical: '/product' },
  openGraph: {
    title: 'SureSign Product Workflows',
    description:
      'See how confirmed contract information connects construction commercial workflows and the complete project record.',
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
        <section className="bg-atmosphere border-b border-border">
          <Container className="py-20 md:py-28">
            <p className="text-sm font-medium text-text-muted">Product workflows</p>
            <h1 className="mt-4 max-w-[14ch] text-5xl font-medium leading-[0.98] tracking-tighter text-text-primary text-balance md:text-7xl">
              One contract record, used throughout the project.
            </h1>
            <p className="mt-6 max-w-[54ch] text-lg leading-8 text-text-secondary">
              Explore the detailed workflows behind the shorter commercial story on
              the homepage. Each area reads from or contributes to the same confirmed
              project record.
            </p>
          </Container>
        </section>
        <ContractAnalysis />
        <ProjectWorkspace />
        <TradePackages />
        <CommercialWorkflow />
        <ProgrammeAndRisk />
        <Drawings />
        <DeliveryDocs />
        <Notifications />
        <BookDemoCta />
      </main>
      <Footer />
    </>
  );
}
