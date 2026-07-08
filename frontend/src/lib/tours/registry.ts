import type { TourDef } from './types';

// The final step of every page tour points at the PageTourButton itself
// (which carries data-tour="page-tour-button") so users always leave a tour
// knowing exactly how to bring it back.
const RESTART_STEP = {
  target: '[data-tour="page-tour-button"]',
  title: 'Come back anytime',
  description: 'You can restart this tour whenever you like by clicking this button.',
};

// Central tour registry. Add new tours here rather than scattering step
// definitions across pages, pages only contribute `data-tour="..."`
// anchors and a button that calls `startTour(key)`.
//
// Steps whose target only exists once real data is present (see the
// data-aware notes on individual steps below) are automatically skipped by
// useTour() when that element isn't in the DOM yet, so a tour never explains
// a record type the user hasn't created anything of yet. Every tour's
// second-to-last step points forward to the next thing worth doing, so the
// guidance progresses with the user's project rather than stopping cold.
export const TOURS: TourDef[] = [
  {
    key: 'global-welcome',
    label: 'Welcome tour',
    description: 'A first-time walkthrough of the sidebar, dashboard and Help Centre.',
    steps: [
      {
        target: '[data-tour="dashboard-header"]',
        title: 'Welcome to SureSign',
        description: 'This is your dashboard, a live view of what needs attention across your projects.',
      },
      {
        target: '[data-tour="sidebar-projects"]',
        title: 'Projects',
        description: 'Browse every construction project your company runs, and create new ones from here.',
        roles: ['Super Admin', 'Admin'],
      },
      {
        target: '[data-tour="sidebar-projects"]',
        title: 'Projects',
        description: 'View the projects you have access to, along with their current status.',
        roles: ['Client'],
      },
      {
        target: '[data-tour="sidebar-commercial"]',
        title: 'Commercial',
        description: 'Manage payment applications, trade packages, notices and retention across all projects.',
        roles: ['Super Admin', 'Admin'],
      },
      {
        target: '[data-tour="sidebar-commercial"]',
        title: 'Commercial',
        description: 'Review payment applications, notices and retention for your projects.',
        roles: ['Client'],
      },
      {
        target: '[data-tour="sidebar-documents"]',
        title: 'Documents',
        description: 'Every contract and project document, organised by folder, lives here.',
      },
      {
        target: '[data-tour="sidebar-projects"]',
        title: 'Where to start',
        description: 'Open a project and go to Contracts first. That is where SureSign picks up the payment terms and key dates that drive everything else on that project.',
      },
      {
        target: '[data-tour="sidebar-help"]',
        title: 'Help Centre',
        description: 'Come back here anytime to restart this tour, take a page tour, or browse the FAQ.',
      },
    ],
  },

  // ── Dashboard ────────────────────────────────────────────────────────────
  {
    key: 'page-dashboard',
    label: 'Dashboard tour',
    description: 'Reading the dashboard and getting back to recent work quickly.',
    steps: [
      {
        target: '[data-tour="dashboard-header"]',
        title: 'Live, not cached',
        description: 'This greeting updates with the time of day as a quick confirmation you are looking at current data, not a stale report someone ran last week.',
      },
      {
        target: '[data-tour="dashboard-stats"]',
        title: 'Where to look first',
        description: 'Active projects, open RFIs, pending variations and documents processed this month. These are the numbers most likely to need your attention today, before you go looking project by project.',
      },
      {
        target: '[data-tour="dashboard-recent"]',
        title: 'Straight back to work',
        description: 'Recent Projects and Recent RFIs give you a fast route back into whatever you were last working on, without digging through menus.',
      },
      {
        target: '[data-tour="dashboard-recent"]',
        title: 'What to do next',
        description: 'Pick a project to start working in. Once it has a contract with its payment terms set, SureSign starts guiding you through the commercial workflow from there.',
      },
      RESTART_STEP,
    ],
  },

  // ── Projects (org-level list) ────────────────────────────────────────────
  {
    key: 'page-projects',
    label: 'Projects tour',
    description: 'Finding and creating construction projects.',
    steps: [
      {
        target: '[data-tour="projects-filters"]',
        title: 'Finding a project',
        description: 'Search by name, or filter to Active, On Hold, Completed or Cancelled. Useful once you are running several contracts at once and scrolling stops being practical.',
      },
      {
        target: '[data-tour="projects-grid"]',
        title: 'Your projects',
        description: 'Each card opens that project workspace: contracts, commercial, programme, RFIs and site records, all scoped to that job so nothing from one project bleeds into another.',
      },
      {
        target: '[data-tour="projects-new"]',
        title: 'Starting a new project',
        description: 'Set up a new project here first. A project is the container everything else hangs off, so contracts, trade packages and site records all need one to belong to.',
      },
      {
        target: '[data-tour="projects-grid"]',
        title: 'What to do next',
        description: 'Open a project and record its contract next. That single step is what unlocks the programme, commercial and notice workflows for that job.',
      },
      RESTART_STEP,
    ],
  },

  // ── Project Overview ─────────────────────────────────────────────────────
  {
    key: 'page-project-overview',
    label: 'Project Overview tour',
    description: 'What the project overview page shows and how to use it.',
    steps: [
      {
        target: '[data-tour="overview-stats"]',
        title: 'Key numbers',
        description: 'Jump straight to open RFIs, pending variations, payment applications or snagging. These are usually the items most likely to need action today.',
      },
      {
        target: '[data-tour="overview-health"]',
        title: 'Project health',
        description: 'A quick signal for how this project is tracking across cost, programme and risk, pulled from the records already in the system, so a problem surfaces here before it becomes a dispute.',
      },
      {
        target: '[data-tour="overview-programme"]',
        title: 'Programme',
        description: 'Upcoming milestones and overall progress, measured against the dates recorded in the contract programme.',
      },
      {
        target: '[data-tour="overview-variations"]',
        title: 'Variations',
        description: 'Pending and approved variations, and their impact on cost and programme. An approved variation not yet included in a payment application is flagged separately, so it does not get missed off the next one.',
      },
      {
        target: '[data-tour="overview-stats"]',
        title: 'What to do next',
        description: 'If this project has no contract yet, add one from the Contracts page first. Everything shown here starts filling in once a contract, and its payment terms, are on record.',
      },
      RESTART_STEP,
    ],
  },

  // ── Contracts ────────────────────────────────────────────────────────────
  // Deep, connected treatment: this page is where the payment date rules
  // that drive the whole commercial workflow are set, either directly or via
  // confirmed AI analysis, and where trade packages branch off as their own
  // subcontracts. The AI history step is data-aware (see contracts-ai-history).
  {
    key: 'page-contracts',
    label: 'Contracts tour',
    description: 'The main contract, its terms, and how they connect to everything else.',
    steps: [
      {
        target: '[data-tour="contracts-filters"]',
        title: 'Finding a contract',
        description: 'Search by name or reference, or switch between Active and Archived contracts.',
      },
      {
        target: '[data-tour="contracts-table"]',
        title: 'Where the payment rules live',
        description: 'The contract recorded here sets the payment terms for this project: when a due date falls, when the final date for payment is, and how long a pay less notice window runs. Every payment application on this project is dated from these rules.',
      },
      {
        target: '[data-tour="contracts-ai-history"]',
        title: 'AI-confirmed terms',
        description: 'Once you run AI analysis on a contract and confirm the extracted terms, those confirmed payment rules and key dates take priority over anything else on record. Nothing is used in a calculation until you have reviewed and confirmed it here.',
      },
      {
        target: '[data-tour="contracts-subcontracts"]',
        title: 'Where trade packages branch off',
        description: 'Each trade package is its own subcontract, for one trade, with its own payment terms. A groundworks package can run on entirely different dates to the main contract without either one affecting the other.',
      },
      {
        target: '[data-tour="contracts-new"]',
        title: 'Adding a contract',
        description: 'Record a new contract here. Get the payment terms right at this stage, since everything downstream, applications, notices and deadlines, is calculated from what you set here.',
      },
      {
        target: '[data-tour="contracts-table"]',
        title: 'What to do next',
        description: 'Once this contract payment terms are set, confirmed directly or via AI analysis, head to Commercial to raise the first Payment Application. The dates from here carry across automatically.',
      },
      RESTART_STEP,
    ],
  },

  // ── Trade Package workspace ──────────────────────────────────────────────
  {
    key: 'page-trade-package',
    label: 'Trade Package tour',
    description: 'The subcontract package workspace, and how its payments relate to the main contract.',
    steps: [
      {
        target: '[data-tour="tp-header"]',
        title: 'One subcontract, one workspace',
        description: 'Package reference and current status at a glance. Everything in this workspace, commercial, programme, compliance and documents, is scoped to this one subcontract trade, separate from the main contract and every other package.',
      },
      {
        target: '[data-tour="tp-actions"]',
        title: 'Reading the subcontract automatically',
        description: 'Run AI analysis on the executed subcontract to pull its payment terms into this package. Those terms then drive this package own payment dates independently of the main contract, so a different retention rate or notice period here does not need manual tracking.',
      },
      {
        target: '[data-tour="tp-tabs"]',
        title: 'Commercial here is read-only',
        description: 'The Commercial tab shows this package own applications and totals, but payment applications are actually created from the project Commercial page under Trade Packages. This tab is where you check the position, not where you raise an application.',
      },
      {
        target: '[data-tour="tp-tabs"]',
        title: 'What to do next',
        description: 'Once this package has its own payment application, its Commercial tab here starts showing real figures. Go to the project Commercial page, Trade Packages tab, to raise it.',
      },
      RESTART_STEP,
    ],
  },

  // ── Commercial ───────────────────────────────────────────────────────────
  // The flagship connected workflow: contract terms -> application ->
  // certification -> notices -> retention -> final account. The statutory
  // intelligence step only exists once at least one live application exists
  // (see commercial-statutory-intel), so a first-time user with nothing
  // submitted yet sees a shorter, still-accurate tour rather than an
  // explanation of cards that would currently all read zero.
  {
    key: 'page-commercial',
    label: 'Commercial tour',
    description: 'How a payment application moves from submission to the final account.',
    steps: [
      {
        target: '[data-tour="commercial-summary"]',
        title: 'The commercial position, in one place',
        description: 'Certified to date, paid to date, retention held and the outstanding balance. Every payment application on this project, under the main contract or a trade package, rolls up into these totals automatically as it progresses.',
      },
      {
        target: '[data-tour="commercial-tabs"]',
        title: 'Following an application through',
        description: 'An application starts in Applications, then moves through Notices and Retention as it is certified and paid. Trade Packages and Final Account sit alongside, since the same lifecycle applies to a subcontract as to the main contract.',
      },
      {
        target: '[data-tour="commercial-statutory-intel"]',
        title: 'Tracking the statutory clock',
        description: 'Once an application is submitted, these cards track it against its deadlines: whether a Payment Notice has been issued yet, whether the Pay Less Notice window is still open, and whether the final date for payment has passed unpaid.',
      },
      {
        target: '[data-tour="commercial-summary"]',
        title: 'Retention and the final account',
        description: 'Retention held is calculated automatically from each certified application, using the contract retention percentage, no separate calculation needed. Every certified application also feeds the Final Account, building a complete certified history as the project runs.',
      },
      {
        target: '[data-tour="commercial-tabs"]',
        title: 'What to do next',
        description: 'Once you certify your first application, watch the Notices tab. That is where the Payment Notice and, if needed, a Pay Less Notice get issued against their statutory deadlines.',
      },
      RESTART_STEP,
    ],
  },

  // ── Variations ───────────────────────────────────────────────────────────
  {
    key: 'page-variations',
    label: 'Variations tour',
    description: 'Tracking instructed and requested changes to the works.',
    steps: [
      {
        target: '[data-tour="variations-summary"]',
        title: 'Variation pipeline',
        description: 'Track a variation from draft through to approved or rejected, and see its total value at each stage.',
      },
      {
        target: '[data-tour="variations-filters"]',
        title: 'Search & filter',
        description: 'Find a variation by reference or description, or filter the list by its current status.',
      },
      {
        target: '[data-tour="variations-table"]',
        title: 'Variation register',
        description: 'Every instructed or requested change to the works, with its cost and programme impact. A clear register here is what protects both sides if there is ever a dispute about what was actually agreed.',
      },
      {
        target: '[data-tour="variations-new"]',
        title: 'Raising a variation',
        description: 'Log a variation when the Employer, Architect or Engineer instructs a change to the scope, design or specification of the works.',
      },
      {
        target: '[data-tour="variations-table"]',
        title: 'What to do next',
        description: 'Once a variation is approved, it is ready to be pulled into a payment application from the Commercial page, rather than being valued separately.',
      },
      RESTART_STEP,
    ],
  },

  // ── Programme ────────────────────────────────────────────────────────────
  // The Seed from AI action is a genuine, implemented automation (verified
  // against ProgrammeMilestoneController::seedFromAnalysis): it reads a
  // contract's confirmed AI analysis key_dates and creates milestones from
  // them directly. The button only renders once at least one contract
  // exists, so programme-seed is naturally data-aware already.
  {
    key: 'page-programme',
    label: 'Programme tour',
    description: 'Contract milestones and programme progress, including seeding them from AI analysis.',
    steps: [
      {
        target: '[data-tour="programme-health"]',
        title: 'Programme health',
        description: 'A quick read on how the programme is tracking: milestones achieved, at risk, or overdue.',
      },
      {
        target: '[data-tour="programme-filters"]',
        title: 'Filter & view',
        description: 'Filter milestones by status, or switch between table and timeline views of the programme.',
      },
      {
        target: '[data-tour="programme-table"]',
        title: 'Milestone schedule',
        description: 'The key dates the contract programme is measured against: practical completion, sectional completion, and any other contractual milestones.',
      },
      {
        target: '[data-tour="programme-seed"]',
        title: 'Seeding milestones from AI',
        description: 'Once a contract has a confirmed AI analysis, use Seed from AI to create milestones directly from its extracted key dates, instead of typing each one in by hand.',
      },
      {
        target: '[data-tour="programme-new"]',
        title: 'Adding a milestone',
        description: 'Add a milestone manually here for anything the seed step did not cover, or to update forecast and actual dates as work progresses.',
      },
      {
        target: '[data-tour="programme-table"]',
        title: 'What to do next',
        description: 'Keep milestone status and forecast dates current as work proceeds. That is what feeds the health score above and the project calendar automatically.',
      },
      RESTART_STEP,
    ],
  },

  // ── Delay & EOT ──────────────────────────────────────────────────────────
  {
    key: 'page-delay-eot',
    label: 'Delay & EOT tour',
    description: 'Delay events, Extension of Time requests and Loss & Expense claims.',
    steps: [
      {
        target: '[data-tour="delay-eot-tabs"]',
        title: 'Three connected records',
        description: 'A delay event is what happened. An Extension of Time request is what you are asking for as a result. A Loss & Expense claim is what it cost you. Switch tabs depending on which one you need to record or review.',
      },
      {
        target: '[data-tour="delay-events-filters"]',
        title: 'Delay events',
        description: 'Log a delay event when something outside the contractor control affects progress. Filter by status to see what is still open and unresolved.',
      },
      {
        target: '[data-tour="delay-events-new"]',
        title: 'Recording a delay',
        description: 'Raise a delay event as close to when it happens as possible. The date recorded here is what an Extension of Time claim will later rely on, so accuracy now matters more than it might seem at the time.',
      },
      {
        target: '[data-tour="delay-events-table"]',
        title: 'Delay event log',
        description: 'Every delay event on this project, with its cause, duration and current status.',
      },
      {
        target: '[data-tour="delay-eot-tabs"]',
        title: 'What to do next',
        description: 'Once a delay event is on record, switch to the Extension of Time tab to raise the claim it supports, then Loss & Expense if it also carries a cost.',
      },
      RESTART_STEP,
    ],
  },

  // ── Notices ──────────────────────────────────────────────────────────────
  {
    key: 'page-notices',
    label: 'Notices tour',
    description: 'Statutory payment and contractual notices.',
    steps: [
      {
        target: '[data-tour="notices-tabs"]',
        title: 'Notice types',
        description: 'Payment Notices, Pay Less Notices and other statutory notices are grouped here by type.',
      },
      {
        target: '[data-tour="notices-search"]',
        title: 'Finding a notice',
        description: 'Search notices by reference or description.',
      },
      {
        target: '[data-tour="notices-list"]',
        title: 'Notice log',
        description: 'Every notice issued on this project. These carry contractual deadlines, so the dates on each one are worth checking carefully rather than assuming they are right.',
      },
      {
        target: '[data-tour="notices-new"]',
        title: 'Issuing a notice',
        description: 'Issue a notice from here. SureSign calculates statutory deadlines, such as the pay less notice date, automatically from the contract payment terms, so you are not doing that arithmetic by hand under time pressure.',
      },
      {
        target: '[data-tour="notices-list"]',
        title: 'What to do next',
        description: 'Once a Payment Notice is issued, its Pay Less Notice window starts counting down automatically. Check back here if you intend to pay less than the notified sum before that window closes.',
      },
      RESTART_STEP,
    ],
  },

  // ── Risk Register ────────────────────────────────────────────────────────
  {
    key: 'page-risks',
    label: 'Risk Register tour',
    description: 'Identifying and tracking project risk.',
    steps: [
      {
        target: '[data-tour="risks-filters"]',
        title: 'Filtering by severity',
        description: 'Filter the risk register by severity: Critical, High, Medium or Low, to focus on what needs attention first.',
      },
      {
        target: '[data-tour="risks-table"]',
        title: 'Risk register',
        description: 'Risks affecting the main contract or a trade package, with their likelihood, impact and current mitigation status. Some entries here may come from confirmed AI contract analysis, others are added manually as they are identified.',
      },
      {
        target: '[data-tour="risks-new"]',
        title: 'Logging a risk',
        description: 'Record a risk as soon as it is identified. Early visibility here is often what stops a risk quietly turning into a delay event or a commercial claim later.',
      },
      {
        target: '[data-tour="risks-table"]',
        title: 'What to do next',
        description: 'If a risk here actually happens, record it properly where its consequences are tracked: Delay & EOT if it affects the programme, Variations if it changes cost.',
      },
      RESTART_STEP,
    ],
  },

  // ── RFIs ─────────────────────────────────────────────────────────────────
  // Verified against RfiController and OperationalIntelligenceService: an
  // open RFI (not responded/closed) with a response_due_date is picked up by
  // collectRfis() and surfaced on the project calendar and upcoming actions,
  // and GenerateProjectNotificationsJob runs on every create/relevant update
  // so an in-app reminder exists without a separate manual step.
  {
    key: 'page-rfis',
    label: 'RFIs tour',
    description: 'Raising and tracking Requests for Information, and how an open one stays visible elsewhere.',
    steps: [
      {
        target: '[data-tour="rfis-summary"]',
        title: 'RFI summary',
        description: 'A running count of total, open and awaiting-response RFIs. A fast check on whether missing design information is what is actually holding up progress on site.',
      },
      {
        target: '[data-tour="rfis-filters"]',
        title: 'Search & filter',
        description: 'Search by subject or number, or filter the list by status.',
      },
      {
        target: '[data-tour="rfis-new"]',
        title: 'Raise an RFI',
        description: 'Raise an RFI when clarification is needed from the Employer, Architect, Engineer or Consultant. Recording it here preserves the audit trail for the information requested and any commercial or programme impact that follows from the answer.',
      },
      {
        target: '[data-tour="rfis-table"]',
        title: 'It does not stop at this table',
        description: 'Give an RFI a response due date and it automatically appears on the project calendar and in upcoming actions, and generates an in-app reminder, until it is answered or closed. Nothing extra to set up.',
      },
      {
        target: '[data-tour="rfis-table"]',
        title: 'What to do next',
        description: 'Once you have a response, mark the RFI responded or closed promptly. A closed RFI drops off the calendar and reminders automatically, keeping both focused on what is genuinely still outstanding.',
      },
      RESTART_STEP,
    ],
  },

  // ── Meetings ─────────────────────────────────────────────────────────────
  {
    key: 'page-meetings',
    label: 'Meetings tour',
    description: 'Meeting minutes and action items.',
    steps: [
      {
        target: '[data-tour="meetings-filters"]',
        title: 'Finding minutes',
        description: 'Search meetings, or filter by type: progress, design, commercial or safety.',
      },
      {
        target: '[data-tour="meetings-table"]',
        title: 'Meeting minutes',
        description: 'Every recorded meeting for this project, with attendees and action items. Open one to review exactly what was agreed rather than relying on memory.',
      },
      {
        target: '[data-tour="meetings-new"]',
        title: 'Recording a meeting',
        description: 'Log a meeting here to keep a record of decisions and instructions. Minutes are often the first thing referred back to when there is disagreement later over what was actually agreed.',
      },
      {
        target: '[data-tour="meetings-table"]',
        title: 'What to do next',
        description: 'If an action item agreed in a meeting needs its own record, raise it properly, as an RFI, a variation, or a delay event, rather than leaving it to live only in the minutes.',
      },
      RESTART_STEP,
    ],
  },

  // ── Documents ────────────────────────────────────────────────────────────
  {
    key: 'page-documents',
    label: 'Documents tour',
    description: 'Browsing and uploading project documents.',
    steps: [
      {
        target: '[data-tour="documents-header-actions"]',
        title: 'Upload & browse',
        description: 'Switch between folder and list view, or upload a new document. Contracts, drawings, correspondence and generated packages, like certificates and notices, all live together here.',
      },
      {
        target: '[data-tour="documents-register-link"]',
        title: 'Document register',
        description: 'A chronological log of every document issued on this project. Useful evidence if a dispute ever turns on who had which document, and when they had it.',
      },
      {
        target: '[data-tour="documents-header-actions"]',
        title: 'What to do next',
        description: 'You do not need to file generated documents yourself, certificates, notices and packages land here automatically as they are created elsewhere. Use upload for everything else, like drawings and correspondence.',
      },
      RESTART_STEP,
    ],
  },

  // ── Delivery Documents ───────────────────────────────────────────────────
  {
    key: 'page-delivery-documents',
    label: 'Delivery Documents tour',
    description: 'RAMS, method statements and other delivery documentation.',
    steps: [
      {
        target: '[data-tour="delivery-documents-filters"]',
        title: 'Filtering by status',
        description: 'Filter by status: required, submitted, under review, approved, rejected, expired or superseded.',
      },
      {
        target: '[data-tour="delivery-documents-table"]',
        title: 'Delivery documentation',
        description: 'RAMS, method statements, ITPs and other documents required before or during works. Track what is still outstanding before a trade package is allowed to proceed on site.',
      },
      {
        target: '[data-tour="delivery-documents-new"]',
        title: 'Adding a document',
        description: 'Add a delivery document here and track its review status through to approval.',
      },
      {
        target: '[data-tour="delivery-documents-table"]',
        title: 'What to do next',
        description: 'Keep required documents moving through review promptly. A trade package with outstanding required documents should not really be starting work on site yet.',
      },
      RESTART_STEP,
    ],
  },

  // ── QA Reports ───────────────────────────────────────────────────────────
  {
    key: 'page-qa',
    label: 'QA Reports tour',
    description: 'Quality assurance checks against work stages.',
    steps: [
      {
        target: '[data-tour="qa-summary"]',
        title: 'QA at a glance',
        description: 'A quick count of QA reports by status, so you can see what is outstanding across the project without opening each one.',
      },
      {
        target: '[data-tour="qa-filters"]',
        title: 'Finding a report',
        description: 'Search QA reports, or filter by status.',
      },
      {
        target: '[data-tour="qa-table"]',
        title: 'QA report register',
        description: 'Quality assurance checks recorded against work stages. These support handover documentation and give you something concrete to point to in a later warranty or defects discussion.',
      },
      {
        target: '[data-tour="qa-new"]',
        title: 'Recording a QA check',
        description: 'Log a QA report against a work stage to record that it has been checked and signed off.',
      },
      {
        target: '[data-tour="qa-table"]',
        title: 'What to do next',
        description: 'Keep QA reports going stage by stage as work completes, rather than trying to reconstruct the record retrospectively near handover.',
      },
      RESTART_STEP,
    ],
  },

  // ── Snagging ─────────────────────────────────────────────────────────────
  {
    key: 'page-snagging',
    label: 'Snagging tour',
    description: 'Tracking defects through to close-out.',
    steps: [
      {
        target: '[data-tour="snagging-summary"]',
        title: 'Snag counts',
        description: 'Total, open, in progress and closed snags at a glance.',
      },
      {
        target: '[data-tour="snagging-filters"]',
        title: 'Finding a snag',
        description: 'Search snags, or filter by status to see what is still outstanding before handover.',
      },
      {
        target: '[data-tour="snagging-list"]',
        title: 'Snag list',
        description: 'Defects identified during inspection, tracked from raised through to closed. A clear snag list is what protects both parties around practical completion.',
      },
      {
        target: '[data-tour="snagging-new"]',
        title: 'Logging a snag',
        description: 'Log a snag as soon as it is spotted, with a location and description. The clearer the record, the faster it tends to get resolved.',
      },
      {
        target: '[data-tour="snagging-list"]',
        title: 'What to do next',
        description: 'Work toward closing every snag out before or at practical completion. An open snag list at handover is exactly what this page exists to prevent.',
      },
      RESTART_STEP,
    ],
  },

  // ── Site Reports ─────────────────────────────────────────────────────────
  {
    key: 'page-site-reports',
    label: 'Site Reports tour',
    description: 'Daily site diary records.',
    steps: [
      {
        target: '[data-tour="site-reports-filters"]',
        title: 'Finding a diary entry',
        description: 'Search site diary entries, or filter by status.',
      },
      {
        target: '[data-tour="site-reports-list"]',
        title: 'Site diary',
        description: 'Daily records of site activity, weather, labour and plant. A day-by-day diary is often the first thing people look for when a delay or disruption claim needs evidence.',
      },
      {
        target: '[data-tour="site-reports-new"]',
        title: 'Recording a site diary',
        description: 'Log today site diary entry. Consistent daily records make it far easier to substantiate an Extension of Time or Loss & Expense claim months later.',
      },
      {
        target: '[data-tour="site-reports-list"]',
        title: 'What to do next',
        description: 'Keep entries going day by day rather than in batches. A gap in the diary is often exactly the period a later claim needs evidence for.',
      },
      RESTART_STEP,
    ],
  },

  // ── Closeout ─────────────────────────────────────────────────────────────
  {
    key: 'page-closeout',
    label: 'Closeout tour',
    description: 'The project close-out checklist.',
    steps: [
      {
        target: '[data-tour="closeout-checklist"]',
        title: 'Closeout checklist',
        description: 'Everything needed to formally close out this project, organised by category, from outstanding documentation to final certificates.',
      },
      {
        target: '[data-tour="closeout-add-item"]',
        title: 'Adding a checklist item',
        description: 'Add an item here if something specific to this project needs tracking through to completion that is not already on the list.',
      },
      {
        target: '[data-tour="closeout-complete"]',
        title: 'Marking complete',
        description: 'Once every item is checked off, mark the project complete here. This is the formal record that closeout has actually been finished, not just mostly done, and there is nothing further to do on this page after that.',
      },
      RESTART_STEP,
    ],
  },

  // ── Calendar ─────────────────────────────────────────────────────────────
  {
    key: 'page-calendar',
    label: 'Calendar tour',
    description: 'Every contractual and statutory date for this project, in one place.',
    steps: [
      {
        target: '[data-tour="calendar-summary"]',
        title: 'Calendar at a glance',
        description: 'Total events, what is happening this month, and what is coming up next.',
      },
      {
        target: '[data-tour="calendar-view-switcher"]',
        title: 'Views',
        description: 'Switch between month, week and agenda views depending on how much detail you need.',
      },
      {
        target: '[data-tour="calendar-filters"]',
        title: 'Filtering events',
        description: 'Filter by module. RFIs, contracts and payment dates all feed their key dates into this calendar automatically as they are recorded elsewhere, you do not add them here directly.',
      },
      {
        target: '[data-tour="calendar-main"]',
        title: 'Your programme at a glance',
        description: 'Every statutory and contractual date across this project: payment due dates, notice deadlines, milestones and more, pulled together in one place so nothing gets missed because it was recorded on a different page.',
      },
      {
        target: '[data-tour="calendar-main"]',
        title: 'What to do next',
        description: 'To change what appears here, go update the record it came from, an RFI, a contract, a payment application, rather than trying to edit the calendar directly.',
      },
      RESTART_STEP,
    ],
  },
];

export function getTour(key: string): TourDef | undefined {
  return TOURS.find(t => t.key === key);
}
