'use client';

import Link from 'next/link';
import { ArrowLeft, Tag } from 'lucide-react';
import { APP_VERSION, APP_VERSION_DATE, APP_VERSION_STATUS } from '@/config/app-version';

// Only officially deployed production releases belong here — see CLAUDE.md
// (Versioning & Release Notes Policy). Internal development milestones are
// tracked in git history and internal documentation, never as a public
// version entry.
const releases = [
  {
    version: APP_VERSION,
    date: APP_VERSION_DATE,
    status: APP_VERSION_STATUS,
    sections: [
      {
        title: 'New',
        items: [
          'End-to-end contract administration: contracts, subcontract trade packages, variations, RFIs, meetings, programme milestones, delay and extension of time, risk register, and adjudication support.',
          'Payment applications with automatic statutory date calculation (due date, final date for payment, payment notice and pay less notice deadlines).',
          'Optional AI-assisted contract and subcontract analysis, extracting key terms and dates for review before they’re used.',
          'Prompt Library for AI-assisted drafting workflows.',
          'Organisation branding (logo, colours, letterhead) applied automatically to every generated PDF and Excel document.',
          'Local Windows folder sync, keeping a mirrored copy of your documents outside SureSign.',
          'Guided product tours, an in-app Help Center with a searchable Knowledge Base, and Contact Support with threaded replies.',
          'Timezone-aware meeting scheduling, shown automatically in each person’s own timezone.',
          'Email reminders ahead of key payment deadlines.',
        ],
      },
      {
        title: 'Improvements',
        items: [
          'Centralised notifications across every operational module, grouped by priority and category.',
          'Company search and lookup via Companies House for faster contractor and subcontractor setup.',
          'Consistent project and document navigation across all modules.',
        ],
      },
      {
        title: 'Security',
        items: [
          'Organisation-scoped data access enforced consistently across every module.',
          'Private, access-controlled handling of support request attachments and diagnostics.',
          'Hardened file upload validation.',
        ],
      },
      {
        title: 'Performance',
        items: [
          'Background processing for document generation, so larger documents no longer block your work.',
          'Regular, automatic refresh of calendar and reminder data without manual action.',
        ],
      },
      {
        title: 'Bug Fixes',
        items: [
          'As this is SureSign’s first public release, there are no prior customer-reported issues to list here.',
        ],
      },
    ],
  },
];

export default function ReleasesPage() {
  return (
    <div className="max-w-3xl mx-auto px-6 py-8">
      <div className="mb-6">
        <Link
          href="/app/settings"
          className="inline-flex items-center gap-1.5 text-sm mb-4 hover:opacity-70 transition-opacity"
          style={{ color: 'var(--text-muted)' }}
        >
          <ArrowLeft size={14} />
          Back to Settings
        </Link>
        <h1 className="text-2xl font-semibold" style={{ color: 'var(--text-primary)' }}>
          Release Notes
        </h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          What&apos;s new in SureSign Contracts
        </p>
      </div>

      <div className="space-y-8">
        {releases.map((release, i) => (
          <div
            key={release.version}
            className="rounded-xl overflow-hidden ss-animate-in"
            style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
          >
            {/* Release header */}
            <div
              className="px-6 py-4 flex items-center justify-between"
              style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}
            >
              <div className="flex items-center gap-3">
                <div
                  className="w-8 h-8 rounded-lg flex items-center justify-center"
                  style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
                >
                  <Tag size={14} />
                </div>
                <div>
                  <div className="font-semibold text-sm" style={{ color: 'var(--text-primary)' }}>
                    SureSign Contracts {release.version}
                  </div>
                  <div className="text-xs tabular-nums" style={{ color: 'var(--text-muted)' }}>
                    {release.date}
                  </div>
                </div>
              </div>
              <span
                className="text-xs px-2.5 py-1 rounded-full font-medium"
                style={{ backgroundColor: 'var(--gold-15)', color: 'var(--gold)' }}
              >
                {release.status}
              </span>
            </div>

            {/* Sections */}
            <div className="divide-y divide-[var(--border)]">
              {release.sections.map((section) => (
                <div key={section.title} className="px-6 py-4">
                  <h3
                    className="text-xs font-semibold uppercase tracking-wider mb-3"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    {section.title}
                  </h3>
                  <ul className="space-y-1.5">
                    {section.items.map((item, i) => (
                      <li key={i} className="flex items-start gap-2.5 text-sm" style={{ color: 'var(--text-secondary)' }}>
                        <span
                          className="mt-1.5 w-1.5 h-1.5 rounded-full flex-shrink-0"
                          style={{ backgroundColor: 'var(--gold)' }}
                        />
                        {item}
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
