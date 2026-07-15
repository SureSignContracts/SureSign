import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { Hero } from '@/components/hero/Hero';
import { TrustBar } from '@/components/hero/TrustBar';
import { ProblemChain } from '@/components/sections/ProblemChain';
import { HowSureSignWorks } from '@/components/sections/HowSureSignWorks';
import { ContractAnalysis } from '@/components/sections/ContractAnalysis';
import { ProjectWorkspace } from '@/components/sections/ProjectWorkspace';
import { TradePackages } from '@/components/sections/TradePackages';
import { ProductWalkthrough } from '@/components/demo/ProductWalkthrough';
import { CommercialWorkflow } from '@/components/sections/CommercialWorkflow';
import { ProgrammeAndRisk } from '@/components/sections/ProgrammeAndRisk';
import { DeliveryDocs } from '@/components/sections/DeliveryDocs';
import { Notifications } from '@/components/sections/Notifications';
import { ConnectedPlatform } from '@/components/sections/ConnectedPlatform';
import { BuiltFor } from '@/components/sections/BuiltFor';
import { Security } from '@/components/sections/Security';
import { Documentation } from '@/components/sections/Documentation';
import { BookDemoCta } from '@/components/sections/BookDemoCta';

const JSON_LD = {
  '@context': 'https://schema.org',
  '@graph': [
    {
      '@type': 'Organization',
      name: 'SureSign',
      url: 'https://suresigncontracts.app',
    },
    {
      '@type': 'SoftwareApplication',
      name: 'SureSign',
      applicationCategory: 'BusinessApplication',
      operatingSystem: 'Web',
      description:
        'Construction contract administration platform: automated contract analysis, trade packages, payment applications, statutory notices, programme, and risk — one connected workflow.',
    },
  ],
};

export default function HomePage() {
  return (
    <>
      <script
        type="application/ld+json"
        dangerouslySetInnerHTML={{ __html: JSON.stringify(JSON_LD) }}
      />
      <MarketingNav />
      <main>
        <Hero />
        <TrustBar />
        <ProblemChain />
        <HowSureSignWorks />
        <ContractAnalysis />
        <ProjectWorkspace />
        <TradePackages />
        <ProductWalkthrough />
        <CommercialWorkflow />
        <ProgrammeAndRisk />
        <DeliveryDocs />
        <Notifications />
        <ConnectedPlatform />
        <BuiltFor />
        <Security />
        <Documentation />
        <BookDemoCta />
      </main>
      <Footer />
    </>
  );
}
