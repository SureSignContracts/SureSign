'use client';

import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';

const sections = [
  {
    title: 'Information We Collect',
    content: `When you use SureSign, we collect the following types of information:

Account Information: Your name, email address, and password when you register for an account.

Company Information: Organisation name, registered address, contact details, and company registration information provided during onboarding or settings configuration.

Project Data: Information you enter relating to projects, contracts, trade packages, and contract administration activities.

Documents and Files: Files you upload to the platform, including contracts, correspondence, drawings, and supporting documents.

User Activity Logs: Records of actions performed within the platform, including document uploads, approvals, and configuration changes, for audit and compliance purposes.`,
  },
  {
    title: 'How We Use Your Information',
    content: `We use the information we collect to:

• Provide and operate the SureSign platform and its features.
• Enable project and contract administration workflows.
• Maintain audit trails and activity logs as required for construction contract administration.
• Send platform notifications and system communications relevant to your account.
• Respond to support requests and technical queries.
• Improve platform performance, reliability, and features.

We do not sell your personal information to third parties.`,
  },
  {
    title: 'Data Storage and Security',
    content: `Your data is stored securely. We implement industry-standard security measures including:

• Encrypted data transmission using TLS/HTTPS.
• Secure credential storage using hashed passwords.
• Role-based access controls to restrict data access within your organisation.
• Regular security monitoring and updates.

Access to your organisation's data is limited to authorised users within your account and to SureSign system administrators where required for platform support.`,
  },
  {
    title: 'Data Retention',
    content: `We retain your data for as long as your account is active. If you close your account, we will retain your data for a reasonable period to allow for account recovery or legal compliance obligations, after which it will be securely deleted.

Documents and project records may be subject to longer retention requirements under applicable construction industry regulations or contractual obligations. You are responsible for managing your own compliance obligations in this regard.`,
  },
  {
    title: 'Your Rights',
    content: `Depending on your jurisdiction, you may have the following rights regarding your personal data:

• The right to access the personal information we hold about you.
• The right to request correction of inaccurate information.
• The right to request deletion of your personal information, subject to legal and contractual obligations.
• The right to object to or restrict certain processing of your data.
• The right to data portability.

To exercise any of these rights, please contact us using the details provided in the Contact section below.`,
  },
  {
    title: 'Sharing of Information',
    content: `We do not share your personal data with third parties except in the following circumstances:

• Where required by law or regulatory authority.
• With service providers who support the operation of the platform (such as cloud hosting providers), under appropriate data processing agreements.
• With your explicit consent.

We do not use your project or document data for any purpose other than providing the SureSign platform to you.`,
  },
  {
    title: 'Cookies and Tracking',
    content: `SureSign uses essential cookies and local browser storage to maintain your session, remember your preferences (such as theme settings), and ensure the platform functions correctly.

We do not use tracking cookies, advertising cookies, or third-party analytics that profile your behaviour across other websites.`,
  },
  {
    title: 'Contact Information',
    content: `If you have any questions about this Privacy Policy or how we handle your data, please contact us:

SureSign Contracts
Email: support@suresigncontracts.com

We aim to respond to all privacy-related enquiries within 10 working days.`,
  },
];

export default function PrivacyPage() {
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
          Privacy Policy
        </h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Last updated: June 2026
        </p>
      </div>

      <div
        className="rounded-xl overflow-hidden mb-6 px-6 py-4"
        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}
      >
        <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
          SureSign is committed to protecting your privacy. This Privacy Policy explains how we collect, use, and safeguard information when you use the SureSign platform. By using SureSign, you agree to the practices described in this policy.
        </p>
      </div>

      <div className="space-y-6">
        {sections.map((section, i) => (
          <div
            key={i}
            className="rounded-xl overflow-hidden"
            style={{ border: '1px solid var(--border)' }}
          >
            <div
              className="px-6 py-3"
              style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}
            >
              <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
                {section.title}
              </h2>
            </div>
            <div className="px-6 py-4">
              <p
                className="text-sm whitespace-pre-line leading-relaxed"
                style={{ color: 'var(--text-secondary)' }}
              >
                {section.content}
              </p>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
