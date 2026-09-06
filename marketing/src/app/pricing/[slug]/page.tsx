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

// Forced dynamic (not statically prerendered via generateStaticParams) so
// this route can never end up in the inconsistent state that caused a real
// production incident: a plan page baked fully static at build time, then
// falling through to notFound() at runtime (e.g. a transient backend
// hiccup during ISR revalidation) — which needs to render the app's
// force-dynamic not-found.tsx (it calls headers(), see that file's own
// comment). Next.js cannot reconcile "this route was static" with "now it
// needs a dynamic API" and throws instead of gracefully 404ing, per
// https://nextjs.org/docs/messages/app-static-to-dynamic-error. Forcing
// dynamic here means the underlying data fetch's own `revalidate: 300`
// (see lib/pricing.ts) still caches at the fetch layer, so this costs one
// render per request, not one backend round trip per request.
export const dynamic = 'force-dynamic';

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
