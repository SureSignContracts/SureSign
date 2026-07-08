'use client';

import Link from 'next/link';
import { ArrowLeft, Tag } from 'lucide-react';
import { APP_VERSION, APP_VERSION_DATE, APP_VERSION_STATUS } from '@/config/app-version';

const releases = [
  {
    version: APP_VERSION,
    date: APP_VERSION_DATE,
    status: APP_VERSION_STATUS,
    sections: [
      {
        title: 'Project & Trade Package Management',
        items: [
          'Trade Package folder generation with configurable subfolders per package type.',
          'Trade Package Package Generation — bulk creation of document packages across multiple trade packages.',
          'Automated document numbering sequences per Project and Trade Package.',
          'Document Register with full CRUD, filtering by status and category, and export to PDF/DOCX.',
          'Project storage overview with per-folder file counts and sizes.',
        ],
      },
      {
        title: 'Document Management',
        items: [
          'Document template system with support for contract, letter, and report template types.',
          'DOCX-to-PDF conversion service for automated document generation.',
          'Soft-delete support for file uploads with restore capability.',
          'Local document mirror sync with configurable Windows folder paths.',
          'File upload metadata improvements including category, version, and project association.',
        ],
      },
      {
        title: 'Notifications',
        items: [
          'In-platform notification system with read/unread state management.',
          'Notification bell with live unread count badge.',
          'Notification preferences and dismissal support.',
          'Admin tools for sending system-wide notifications.',
        ],
      },
      {
        title: 'Companies House Integration',
        items: [
          'Company search and lookup via Companies House.',
          'Automatic population of company details from public registry data.',
          'Registered address import for contractor and subcontractor records.',
        ],
      },
      {
        title: 'Organisation & User Management',
        items: [
          'Organisation branding configuration including logo, cover image, accent colour, and email footer.',
          'Improved user role management with policy-based access control.',
          'Admin panel enhancements including feature flags and platform-wide settings.',
          'Support for multiple administrator roles with scoped permissions.',
        ],
      },
      {
        title: 'Settings & Configuration',
        items: [
          'Platform settings management for administrators.',
          'Feature flag controls for AI assistant, document generation, and self-registration.',
          'Local document mirror configuration with path testing.',
          'Suresign-specific settings panel for platform customisation.',
        ],
      },
      {
        title: 'Infrastructure & Stability',
        items: [
          'Docker configuration improvements for consistent local and production environments.',
          'Additional database migrations for new models and relationships.',
          'Queue-based processing for long-running document operations.',
          'Improved error handling across API controllers.',
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
