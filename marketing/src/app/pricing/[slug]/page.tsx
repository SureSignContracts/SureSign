import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';
import { PricingPlanExperience } from '@/components/sections/pricing/PricingPlanExperience';
import { PricingFaq } from '@/components/sections/pricing/PricingFaq';
import { getPricingData } from '@/lib/pricing';

interface PricingPlanPageProps {
  params: Promise<{ slug: string }>;
}

export const dynamicParams = true;

export async function generateStaticParams() {
  const data = await getPricingData();
  return data?.plans.map((plan) => ({ slug: plan.slug })) ?? [];
}

export async function generateMetadata({ params }: PricingPlanPageProps): Promise<Metadata> {
  const { slug } = await params;
  const data = await getPricingData();
  const plan = data?.plans.find((candidate) => candidate.slug === slug);

  if (!plan) {
    return {
      title: 'Pricing Plan',
      robots: { index: false, follow: false },
    };
  }

  const description = plan.description || plan.summary || `Explore the ${plan.name} plan for SureSign construction contract administration.`;
  const canonical = `/pricing/${plan.slug}`;

  return {
    title: `${plan.name} Plan`,
    description,
    alternates: { canonical },
    openGraph: {
      title: `${plan.name} Plan | SureSign`,
      description,
      url: canonical,
      siteName: 'SureSign',
      locale: 'en_GB',
      type: 'website',
    },
    twitter: {
      card: 'summary_large_image',
      title: `${plan.name} Plan | SureSign`,
      description,
    },
  };
}

export default async function PricingPlanPage({ params }: PricingPlanPageProps) {
  const { slug } = await params;
  const data = await getPricingData();
  const plan = data?.plans.find((candidate) => candidate.slug === slug);

  if (!data || !plan) notFound();

  return (
    <>
      <MarketingNav />
      <main id="main-content">
        <PricingPlanExperience plan={plan} sections={data.feature_sections} settings={data.settings} />
        <PricingFaq faqs={data.faqs} />
      </main>
      <Footer />
    </>
  );
}
