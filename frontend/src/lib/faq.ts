import {
  Rocket, FolderKanban, FileText, Package, Sparkles, DollarSign,
  MessageSquare, Users2, Clock, FolderOpen, FileStack, CheckSquare,
  Bell, Settings,
} from 'lucide-react';

export interface FaqItem {
  q: string;
  a: string;
}

export interface FaqCategory {
  key: string;
  label: string;
  icon: React.ElementType;
  items: FaqItem[];
}

// Shared by the FAQ page (/app/help/faq) and the Help Center landing page's
// combined search — a single source so the two never drift out of sync.
export const FAQ_CATEGORIES: FaqCategory[] = [
  {
    key: 'getting-started',
    label: 'Getting Started',
    icon: Rocket,
    items: [
      { q: 'Where do I start?', a: 'Your dashboard gives a live overview of everything that needs attention across your projects: open RFIs, pending variations and payment applications. From there, use the sidebar to move between Projects, Commercial, Documents and the other modules.' },
      { q: 'How do I restart a page tour?', a: 'Open Guided Tours from the Help menu and use the "Restart" button next to any tour, or click the question-mark icon in the header of a supported page to take that page’s tour again.' },
      { q: 'What’s the difference between Admin and Client views?', a: 'Admins and Super Admins manage all projects, contracts and organisation settings. Clients see the projects they’ve been given access to, along with documents, RFIs, notices and their responses.' },
    ],
  },
  {
    key: 'projects',
    label: 'Projects',
    icon: FolderKanban,
    items: [
      { q: 'How do I create a new project?', a: 'From the Projects page, click "New project" and fill in the project details. Admins and Super Admins can create projects; Clients see the projects they’ve been added to.' },
      { q: 'What does the project health score mean?', a: 'The Project Overview page shows a health score combining cost, programme and risk signals for that project: healthy, needs attention, or critical.' },
    ],
  },
  {
    key: 'contracts',
    label: 'Contracts',
    icon: FileText,
    items: [
      { q: 'Where do I find a project’s main contract?', a: 'Open a project, then go to Contracts in the project sidebar. You can view contract terms, key dates and any linked AI analysis from there.' },
    ],
  },
  {
    key: 'trade-packages',
    label: 'Trade Packages',
    icon: Package,
    items: [
      { q: 'What is a Trade Package?', a: 'A Trade Package represents a subcontract agreement for a specific trade (e.g. groundworks, M&E) within a project, with its own commercial, programme, compliance and document workspace.' },
      { q: 'How do I upload a subcontract?', a: 'Open the Trade Package workspace, go to the Documents tab, and upload the executed subcontract. You can then run AI Analysis on it to extract key terms.' },
    ],
  },
  {
    key: 'ai-analysis',
    label: 'AI Analysis',
    icon: Sparkles,
    items: [
      { q: 'What does AI Analysis do?', a: 'AI Analysis reads an uploaded contract or subcontract and extracts key terms, such as payment rules, important dates, parties and programme milestones, for an admin to review and confirm before it’s used for payment date calculations. It must be enabled by an admin in Settings.' },
      { q: 'Is AI Analysis on by default?', a: 'No, it’s opt-in per organisation and is configured by an admin in Settings before it can be used.' },
    ],
  },
  {
    key: 'commercial',
    label: 'Commercial',
    icon: DollarSign,
    items: [
      { q: 'How do Payment Notices and Pay Less Notices work?', a: 'After a payment application is certified, a Payment Notice confirms the sum due. If the paying party intends to pay less than that sum, a Pay Less Notice is issued before the statutory final date for payment. Both are generated from the Commercial page.' },
      { q: 'How do I track overdue actions?', a: 'The Commercial and Project Overview pages surface pending certifications, outstanding balances and notices that are due, so overdue items are visible at a glance.' },
    ],
  },
  {
    key: 'rfis',
    label: 'RFIs',
    icon: MessageSquare,
    items: [
      { q: 'How do I create an RFI?', a: 'Open a project, go to RFIs, and click "New RFI". Fill in the subject and details, then the RFI is tracked through open, pending response, responded and closed statuses.' },
    ],
  },
  {
    key: 'meetings',
    label: 'Meetings',
    icon: Users2,
    items: [
      { q: 'Where do meeting minutes live?', a: 'Open a project and go to Meetings to record and review meeting minutes for that project.' },
    ],
  },
  {
    key: 'delay-eot',
    label: 'Delay & EOT',
    icon: Clock,
    items: [
      { q: 'What’s tracked under Delay & EOT?', a: 'Delay events, Extension of Time (EOT) requests, and Loss & Expense claims. Each can be linked to a contract or a trade package.' },
    ],
  },
  {
    key: 'documents',
    label: 'Documents',
    icon: FolderOpen,
    items: [
      { q: 'Where do generated documents appear?', a: 'Generated and uploaded documents appear in the project’s Documents page, organised by folder, and are logged in the Document Register for a full audit trail.' },
    ],
  },
  {
    key: 'delivery-documents',
    label: 'Delivery Documentation',
    icon: FileStack,
    items: [
      { q: 'What are Delivery Documents?', a: 'Delivery Documents cover handover-related paperwork for a project, such as certificates and manuals, tracked separately from general project documents.' },
    ],
  },
  {
    key: 'qa-snagging-site',
    label: 'QA / Snagging / Site Reports',
    icon: CheckSquare,
    items: [
      { q: 'What’s the difference between QA Reports, Snagging and Site Reports?', a: 'QA Reports record quality assurance checks against work stages. Snagging tracks defects that need resolving before handover. Site Reports capture day-to-day site activity and conditions.' },
    ],
  },
  {
    key: 'notifications',
    label: 'Notifications',
    icon: Bell,
    items: [
      { q: 'How do I get notified about deadlines?', a: 'SureSign sends reminders for statutory payment dates and other deadlines via email and in-app notifications, based on your organisation’s notification settings.' },
    ],
  },
  {
    key: 'account-settings',
    label: 'Account & Settings',
    icon: Settings,
    items: [
      { q: 'Where do I update my organisation’s branding?', a: 'Go to Settings to update your company logo, letterhead and colours. These appear on all generated PDFs and Excel documents.' },
      { q: 'How do I restart the welcome tour?', a: 'Open Guided Tours from the Help menu and use the "Restart" button next to "Welcome tour". It will run again the next time it can find its target elements, usually on your dashboard.' },
    ],
  },
];

export function searchFaq(query: string): FaqCategory[] {
  const q = query.trim().toLowerCase();
  if (!q) return FAQ_CATEGORIES;
  return FAQ_CATEGORIES
    .map(cat => ({
      ...cat,
      items: cat.items.filter(i => i.q.toLowerCase().includes(q) || i.a.toLowerCase().includes(q)),
    }))
    .filter(cat => cat.items.length > 0);
}
