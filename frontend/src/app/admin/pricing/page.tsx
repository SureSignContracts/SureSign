'use client';

import { useState } from 'react';
import PlansTab from '@/components/pricing/PlansTab';
import ComparisonTab from '@/components/pricing/ComparisonTab';
import FaqsTab from '@/components/pricing/FaqsTab';
import SettingsTab from '@/components/pricing/SettingsTab';

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
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Pricing Management</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Everything shown on the public Pricing page — plans, the comparison table, FAQs, and CTAs — is controlled here.
        </p>
      </div>

      <div className="flex gap-1 border-b" style={{ borderColor: 'var(--border)' }}>
        {TABS.map(t => (
          <button
            key={t.key}
            onClick={() => setTab(t.key)}
            className="px-4 py-2.5 text-sm font-medium -mb-px border-b-2 transition-colors"
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
