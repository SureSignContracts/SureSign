import type { Metadata } from 'next';
import { LegalDocument, type LegalSection } from '@/components/legal/LegalDocument';
import { MarketingNav } from '@/components/nav/MarketingNav';
import { Footer } from '@/components/shared/Footer';

export const metadata: Metadata = {
  title: 'Privacy Policy',
  description: 'How SureSign Contracts collects, uses and safeguards information.',
  alternates: { canonical: '/privacy' },
};

const sections: LegalSection[] = [
  {
    title: 'Information We Collect',
    content: `When you use SureSign Contracts, we collect the following types of information:

Account Information: Your name, email address, and password when you register for an account.

Company Information: Organisation name, registered address, contact details, and company registration information provided during onboarding or settings configuration.

Project Data: Information you enter relating to projects, contracts, trade packages, and contract administration activities.

Documents and Files: Files you upload to the platform, including contracts, correspondence, drawings, and supporting documents.

User Activity Logs: Records of actions performed within the platform, including document uploads, approvals, and configuration changes, for audit and compliance purposes.`,
  },
  {
    title: 'How We Use Your Information',
    content: `We use the information we collect to:

• Provide and operate the SureSign Contracts platform and its features.
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

Access to your organisation's data is limited to authorised users within your account and to SureSign Contracts system administrators where required for platform support.`,
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

We do not use your project or document data for any purpose other than providing the SureSign Contracts platform to you.`,
  },
  {
    title: 'Cookies and Tracking',
    content: `SureSign Contracts uses essential cookies and local browser storage to maintain your session, remember your preferences (such as theme settings), and ensure the platform functions correctly.

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
    <>
      <MarketingNav />
      <main id="main-content">
        <LegalDocument
          eyebrow="Legal"
          title="Privacy Policy"
          updated="June 2026"
          introduction="SureSign Contracts is committed to protecting your privacy. This Privacy Policy explains how we collect, use, and safeguard information when you use the SureSign Contracts platform. By using SureSign Contracts, you agree to the practices described in this policy."
          sections={sections}
        />
      </main>
      <Footer />
    </>
  );
}
