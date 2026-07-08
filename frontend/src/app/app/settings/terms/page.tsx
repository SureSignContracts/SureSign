'use client';

import Link from 'next/link';
import { ArrowLeft } from 'lucide-react';

const sections = [
  {
    title: 'Acceptance of Terms',
    content: `By accessing or using the SureSign Contracts platform, you agree to be bound by these Terms of Use. If you do not agree to these terms, you must not use the platform.

These terms apply to all users of SureSign Contracts, including organisation administrators, project managers, and any other individuals accessing the platform under a licensed account.`,
  },
  {
    title: 'Platform Usage',
    content: `SureSign Contracts is a contract administration platform designed for use in the construction industry. You are granted a limited, non-exclusive, non-transferable licence to access and use the platform for your internal business purposes during your subscription period.

You may not resell, sublicense, or otherwise make the platform available to third parties outside of your organisation without prior written agreement from SureSign Contracts.`,
  },
  {
    title: 'User Responsibilities',
    content: `You are responsible for:

• Ensuring all information entered into the platform is accurate and complete.
• Managing user accounts within your organisation, including granting and revoking access appropriately.
• Complying with all applicable laws and regulations in connection with your use of the platform.
• Ensuring that your use of the platform does not violate any contractual obligations you hold with third parties.

You must not use SureSign Contracts for any unlawful purpose or in any way that could damage, disable, or impair the platform or its availability to other users.`,
  },
  {
    title: 'Account Security',
    content: `You are responsible for maintaining the confidentiality of your account credentials. You must notify us immediately if you become aware of any unauthorised access to your account.

SureSign Contracts is not liable for any loss or damage arising from unauthorised use of your account where you have failed to take reasonable steps to protect your login credentials.`,
  },
  {
    title: 'Intellectual Property',
    content: `All intellectual property rights in the SureSign Contracts platform, including its software, design, and documentation, are owned by or licensed to SureSign Contracts.

You retain ownership of the data and documents you upload to the platform. By uploading content, you grant SureSign Contracts a limited licence to store and process that content solely for the purpose of providing the platform to you.

You must not copy, reproduce, or distribute any part of the platform without our prior written consent.`,
  },
  {
    title: 'Service Availability',
    content: `We aim to maintain a high level of platform availability. However, we do not guarantee uninterrupted or error-free operation of the platform.

We may perform scheduled or emergency maintenance that temporarily affects availability. Where possible, we will provide advance notice of planned maintenance.

SureSign Contracts is not liable for any losses arising from platform downtime, provided we have taken reasonable steps to minimise disruption.`,
  },
  {
    title: 'Limitation of Liability',
    content: `To the extent permitted by law, SureSign Contracts shall not be liable for any indirect, incidental, or consequential losses arising from your use of the platform, including but not limited to loss of data, loss of contracts, or loss of business opportunity.

Our total liability to you in connection with the platform shall not exceed the fees paid by you in the twelve months preceding the claim.

Nothing in these terms excludes or limits our liability for death or personal injury caused by negligence, or for fraud or fraudulent misrepresentation.`,
  },
  {
    title: 'Acceptable Use',
    content: `You must not use SureSign Contracts to:

• Upload or distribute malicious software, viruses, or harmful content.
• Attempt to gain unauthorised access to any part of the platform or its infrastructure.
• Harvest or collect data from the platform without authorisation.
• Engage in any activity that disrupts or interferes with the platform or its users.
• Misrepresent your identity or organisation when using the platform.

We reserve the right to suspend or terminate accounts that violate these acceptable use requirements.`,
  },
  {
    title: 'Termination',
    content: `We may suspend or terminate your access to the platform if you materially breach these Terms of Use, fail to pay applicable fees, or if we are required to do so by law.

You may terminate your account at any time by contacting us. Upon termination, your access to the platform will cease. We will retain your data for a reasonable period following termination in accordance with our Privacy Policy.`,
  },
  {
    title: 'Changes to These Terms',
    content: `We may update these Terms of Use from time to time. We will notify you of material changes by email or via a notification within the platform. Continued use of the platform after changes take effect constitutes your acceptance of the updated terms.`,
  },
  {
    title: 'Contact Information',
    content: `If you have any questions about these Terms of Use, please contact us:

SureSign Contracts
Email: support@suresigncontracts.com

We aim to respond to all enquiries within 5 working days.`,
  },
];

export default function TermsPage() {
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
          Terms of Use
        </h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Last updated: June 2026
        </p>
      </div>

      <div
        className="rounded-xl overflow-hidden mb-6 px-6 py-4"
        style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
      >
        <p className="text-sm" style={{ color: 'var(--text-secondary)' }}>
          These Terms of Use govern your access to and use of the SureSign Contracts platform. Please read them carefully before using the service.
        </p>
      </div>

      <div className="space-y-6">
        {sections.map((section, i) => (
          <div
            key={i}
            className="rounded-xl overflow-hidden ss-animate-in"
            style={{ border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', animationDelay: `${Math.min(i * 45, 360)}ms` }}
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
