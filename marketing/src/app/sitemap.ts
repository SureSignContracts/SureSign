import type { MetadataRoute } from 'next';
import { getPricingData } from '@/lib/pricing';

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const base = 'https://suresigncontracts.app';
  const pricing = await getPricingData();
  const planPages = pricing?.plans.map((plan) => ({
    url: `${base}/pricing/${plan.slug}`,
    changeFrequency: 'weekly' as const,
    priority: 0.75,
  })) ?? [];

  return [
    { url: base, changeFrequency: 'weekly', priority: 1 },
    { url: `${base}/product`, changeFrequency: 'monthly', priority: 0.85 },
    { url: `${base}/pricing`, changeFrequency: 'weekly', priority: 0.8 },
    ...planPages,
    { url: `${base}/pricing/compare`, changeFrequency: 'weekly', priority: 0.75 },
    { url: `${base}/services`, changeFrequency: 'monthly', priority: 0.85 },
    { url: `${base}/consultancy`, changeFrequency: 'monthly', priority: 0.8 },
    { url: `${base}/adjudication`, changeFrequency: 'monthly', priority: 0.8 },
    { url: `${base}/contact`, changeFrequency: 'monthly', priority: 0.8 },
    { url: `${base}/security`, changeFrequency: 'monthly', priority: 0.7 },
    { url: `${base}/privacy`, changeFrequency: 'yearly', priority: 0.4 },
    { url: `${base}/terms`, changeFrequency: 'yearly', priority: 0.4 },
    { url: `${base}/book/demo`, changeFrequency: 'monthly', priority: 0.8 },
  ];
}
