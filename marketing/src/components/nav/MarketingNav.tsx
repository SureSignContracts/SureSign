import { getPricingData } from '@/lib/pricing';
import { MarketingNavClient } from '@/components/nav/MarketingNavClient';

export async function MarketingNav() {
  const pricing = await getPricingData();
  const pricingPlans = pricing?.plans.map(({ slug, name }) => ({ slug, name })) ?? [];

  return <MarketingNavClient pricingPlans={pricingPlans} />;
}
