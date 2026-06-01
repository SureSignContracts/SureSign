'use client';

import { CreditCard, CheckCircle, Clock, AlertCircle } from 'lucide-react';

const PLANS = [
  { name: 'Starter',    price: '$99/mo',   companies: 1, users: 5,   projects: 10,  storage: '5 GB' },
  { name: 'Growth',     price: '$299/mo',  companies: 1, users: 20,  projects: 50,  storage: '25 GB', recommended: true },
  { name: 'Enterprise', price: 'Custom',   companies: 1, users: 'Unlimited', projects: 'Unlimited', storage: 'Custom' },
];

export default function AdminBillingPage() {
  return (
    <div className="p-6 max-w-5xl mx-auto space-y-8">
      <div>
        <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>Billing & Subscriptions</h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Subscription plans, billing status and payment management
        </p>
      </div>

      {/* Plans */}
      <section>
        <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Subscription Plans</h2>
        <div className="grid grid-cols-3 gap-4">
          {PLANS.map(plan => (
            <div
              key={plan.name}
              className="rounded-2xl p-5 flex flex-col gap-4 relative"
              style={{
                backgroundColor: 'var(--bg-surface)',
                border: plan.recommended ? '1px solid rgba(185,149,102,0.5)' : '1px solid var(--border)',
              }}
            >
              {plan.recommended && (
                <div
                  className="absolute -top-2.5 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full text-xs font-medium"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  Recommended
                </div>
              )}
              <div>
                <h3 className="font-semibold" style={{ color: 'var(--text-primary)' }}>{plan.name}</h3>
                <p className="text-2xl font-bold mt-1" style={{ color: 'var(--gold)' }}>{plan.price}</p>
              </div>
              <ul className="space-y-2">
                {[
                  `${plan.users} users`,
                  `${plan.projects} projects`,
                  `${plan.storage} storage`,
                ].map(feat => (
                  <li key={feat} className="flex items-center gap-2 text-sm" style={{ color: 'var(--text-secondary)' }}>
                    <CheckCircle size={13} style={{ color: '#4ade80' }} />
                    {feat}
                  </li>
                ))}
              </ul>
              <button
                className="w-full py-2 rounded-lg text-sm font-medium transition-opacity hover:opacity-80"
                style={plan.recommended
                  ? { backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }
                  : { backgroundColor: 'var(--bg-elevated)', color: 'var(--text-primary)' }
                }
              >
                {plan.price === 'Custom' ? 'Contact Sales' : 'Select Plan'}
              </button>
            </div>
          ))}
        </div>
      </section>

      {/* Active subscriptions */}
      <section>
        <h2 className="text-sm font-semibold mb-4" style={{ color: 'var(--text-secondary)' }}>Active Subscriptions</h2>
        <div
          className="rounded-2xl overflow-hidden"
          style={{ border: '1px solid var(--border)' }}
        >
          <div
            className="grid grid-cols-5 px-5 py-3 text-xs font-medium uppercase tracking-wider"
            style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-muted)', borderBottom: '1px solid var(--border)' }}
          >
            <span>Company</span>
            <span>Plan</span>
            <span>Status</span>
            <span>Renewal</span>
            <span>Actions</span>
          </div>
          <div className="px-5 py-10 text-center text-sm" style={{ color: 'var(--text-muted)', backgroundColor: 'var(--bg-surface)' }}>
            No active subscriptions yet — billing integration pending
          </div>
        </div>
      </section>
    </div>
  );
}
