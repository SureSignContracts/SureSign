'use client';

import { useState } from 'react';
import PlansTab from '@/components/pricing/PlansTab';
import ComparisonTab from '@/components/pricing/ComparisonTab';
import FaqsTab from '@/components/pricing/FaqsTab';
import SettingsTab from '@/components/pricing/SettingsTab';
import PlatformPageHero from '@/components/admin/PlatformPageHero';
import { BadgePoundSterling, Layers, ListChecks } from 'lucide-react';

const TABS = [
  { key: 'plans', label: 'Plans' },
  { key: 'comparison', label: 'Features & Comparison' },
  { key: 'faqs', label: 'FAQs' },
  { key: 'settings', label: 'Global Settings & CTAs' },
] as const;

type TabKey = typeof TABS[number]['key'];

export default function AdminPricingPage() {
  const [tab, setTab] = useState<TabKey>('plans');

  return (
    <div className="mx-auto max-w-7xl space-y-6 p-4 pb-12 sm:p-6 lg:p-8">
      <PlatformPageHero
        eyebrow="Commercial catalogue"
        title="Pricing management"
        description="Shape the plans, entitlements and buying journey presented on the public pricing page."
        metrics={[
          { label: 'Catalogue', value: 'Plans', detail: 'pricing and positioning', icon: BadgePoundSterling },
          { label: 'Entitlements', value: 'Controlled', detail: 'features and limits', icon: Layers },
          { label: 'Public journey', value: '4 sections', detail: 'plans, comparison, FAQs and CTAs', icon: ListChecks },
        ]}
      />

      <div className="flex gap-1 overflow-x-auto border-b" style={{ borderColor: 'var(--border)' }}>
        {TABS.map(t => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className="-mb-px whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors"
            style={{
              borderColor: tab === t.key ? 'var(--gold)' : 'transparent',
              color: tab === t.key ? 'var(--text-primary)' : 'var(--text-muted)',
            }}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'plans' && <PlansTab />}
      {tab === 'comparison' && <ComparisonTab />}
      {tab === 'faqs' && <FaqsTab />}
      {tab === 'settings' && <SettingsTab />}
    </div>
  );
}
