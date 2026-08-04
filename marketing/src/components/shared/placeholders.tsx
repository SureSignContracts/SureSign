/**
 * Schematic stand-ins for real product screens, built from CSS only (no
 * fabricated screenshots, no stock photography). Structured to match the
 * actual screens they represent per project-context.md so the shapes are
 * truthful even before real captures replace them — swap each of these for
 * an <Image> of the real exported screenshot inside the same MockupFrame.
 *
 * Colours are hardcoded to light values throughout, not the --bg-base/
 * --text-* theme tokens — these represent real product screenshots, which
 * don't invert when the marketing site's own theme toggle flips to dark
 * (a real screenshot dropped in later won't either).
 */

function Row({ cols }: { cols: string[] }) {
  return (
    <div className="grid grid-cols-5 gap-3 border-b border-[#e4e4e4] px-5 py-3 text-sm last:border-b-0">
      {cols.map((c, i) => (
        <span key={i} className={i === 0 ? 'font-medium text-[#0a0a0a]' : 'text-[#525252]'}>
          {c}
        </span>
      ))}
    </div>
  );
}

export function PaymentAppTable() {
  return (
    <div className="bg-white">
      <div className="grid grid-cols-5 gap-3 border-b border-[#e4e4e4] bg-[#f4f4f4] px-5 py-2.5 text-xs font-medium uppercase tracking-wide text-[#8a8a8a]">
        <span>Application</span>
        <span>Due Date</span>
        <span>Notice Deadline</span>
        <span>Status</span>
        <span>Amount</span>
      </div>
      <Row cols={['PA-014', '12 Aug 2026', '19 Aug 2026', 'Certified', '£184,220']} />
      <Row cols={['PA-013', '15 Jul 2026', '22 Jul 2026', 'Paid', '£201,050']} />
      <Row cols={['PA-012', '17 Jun 2026', '24 Jun 2026', 'Paid', '£176,900']} />
    </div>
  );
}

export function AiAnalysisReview() {
  const rows = [
    ['Parties', 'Confirmed'],
    ['Payment terms', 'Confirmed'],
    ['Key dates', 'Confirmed'],
    ['Retention rules', 'Review'],
    ['Programme milestones', 'Confirmed'],
  ];
  return (
    <div className="bg-white p-5">
      <div className="mb-4 font-mono text-xs text-[#8a8a8a]">contract_ai_analysis · status: completed</div>
      <div className="space-y-2">
        {rows.map(([label, status]) => (
          <div key={label} className="flex items-center justify-between rounded-lg border border-[#e4e4e4] px-4 py-2.5 text-sm">
            <span className="text-[#0a0a0a]">{label}</span>
            <span className={status === 'Confirmed' ? 'text-[#525252]' : 'font-medium text-[#0a0a0a]'}>
              {status}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

export function ContractUploadPreview() {
  return (
    <div className="bg-white p-5">
      <div className="mb-4 font-mono text-xs text-[#8a8a8a]">contract_intake · ready for analysis</div>
      <div className="rounded-xl border border-[#d4d4d4] bg-[#fafafa] p-5">
        <div className="flex items-start justify-between gap-4">
          <div className="min-w-0">
            <p className="truncate text-sm font-medium text-[#0a0a0a]">JCT Design and Build Contract.pdf</p>
            <p className="mt-1 text-xs text-[#737373]">Executed contract · 148 pages · 12.4 MB</p>
          </div>
          <span className="shrink-0 rounded-md border border-[#d4d4d4] bg-white px-2 py-1 text-xs font-medium text-[#404040]">
            Uploaded
          </span>
        </div>
        <div className="mt-5 grid gap-2 border-t border-[#e4e4e4] pt-4 sm:grid-cols-2">
          <div>
            <p className="text-xs text-[#8a8a8a]">Project</p>
            <p className="mt-1 text-sm text-[#262626]">Riverside Apartments</p>
          </div>
          <div>
            <p className="text-xs text-[#8a8a8a]">Document type</p>
            <p className="mt-1 text-sm text-[#262626]">Main contract</p>
          </div>
        </div>
      </div>
    </div>
  );
}

export function ContractExtractionPreview() {
  const rows = [
    ['Parties', 'Extracted'],
    ['Payment terms', 'Extracted'],
    ['Key dates', 'Extracting'],
    ['Retention rules', 'Queued'],
    ['Programme milestones', 'Queued'],
  ];

  return (
    <div className="bg-white p-5">
      <div className="flex items-center justify-between gap-4">
        <div className="font-mono text-xs text-[#8a8a8a]">contract_ai_analysis · status: processing</div>
        <span className="font-mono text-xs text-[#525252]">52%</span>
      </div>
      <div className="mt-3 h-1 overflow-hidden rounded-full bg-[#e5e5e5]">
        <div className="h-full w-[52%] rounded-full bg-[#171717]" />
      </div>
      <div className="mt-4 space-y-2">
        {rows.map(([label, status]) => (
          <div key={label} className="flex items-center justify-between rounded-lg border border-[#e4e4e4] px-4 py-2.5 text-sm">
            <span className="text-[#0a0a0a]">{label}</span>
            <span className={status === 'Extracting' ? 'font-medium text-[#0a0a0a]' : 'text-[#737373]'}>{status}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

export function TradePackageTree() {
  const packages = ['CF: Concrete Frame', 'BW: Brickwork', 'WD: Windows & Doors'];
  return (
    <div className="bg-white p-5">
      <div className="space-y-2">
        {packages.map((pkg) => (
          <div key={pkg} className="flex items-center justify-between rounded-lg border border-[#e4e4e4] px-4 py-2.5 text-sm">
            <span className="text-[#0a0a0a]">{pkg}</span>
            <span className="text-[#8a8a8a]">9 folders</span>
          </div>
        ))}
      </div>
    </div>
  );
}

const SIDEBAR_ITEMS = [
  'Dashboard', 'Projects', 'Contracts', 'Trade Packages', 'Commercial',
  'Programme', 'Documents', 'Risks', 'Notifications', 'Reports', 'Settings',
];

function StatTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-[#e4e4e4] bg-white px-4 py-3">
      <div className="text-xs text-[#8a8a8a]">{label}</div>
      <div className="mt-1 text-base font-medium text-[#0a0a0a]">{value}</div>
    </div>
  );
}

/** Wide project dashboard — the "everything in one place" first screen. */
export function DashboardPreview() {
  const bars = [40, 55, 100, 65, 35];
  const deadlines = [
    ['Payment Notice', '15 Mar 2026'],
    ['Pay Less Notice', '20 Mar 2026'],
    ['Final Date for Payment', '27 Mar 2026'],
  ];
  const activity = [
    ['Payment Application #03 submitted', '2h ago'],
    ['Executed Contract uploaded', '1d ago'],
    ['Site Meeting #12 added', '2d ago'],
  ];

  return (
    <div className="flex bg-white text-sm">
      <div className="hidden w-40 shrink-0 border-r border-[#e4e4e4] bg-[#f4f4f4] p-4 sm:block">
        <div className="mb-4 text-xs font-medium tracking-tight text-[#0a0a0a]">SureSign</div>
        <div className="space-y-0.5">
          {SIDEBAR_ITEMS.map((item, i) => (
            <div
              key={item}
              className={`rounded-md px-2.5 py-1.5 text-xs ${
                i === 0 ? 'bg-white font-medium text-[#0a0a0a]' : 'text-[#8a8a8a]'
              }`}
            >
              {item}
            </div>
          ))}
        </div>
      </div>

      <div className="flex-1 p-5">
        <div className="flex items-center justify-between">
          <div className="font-medium text-[#0a0a0a]">Project</div>
          <div className="hidden items-center gap-3 text-xs text-[#8a8a8a] md:flex">
            <span>Search…</span>
          </div>
        </div>

        <div className="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
          <StatTile label="Contract Sum" value="£3,500,000" />
          <StatTile label="Paid to Date" value="£1,567,000" />
          <StatTile label="Balance" value="£1,933,000" />
          <StatTile label="Applications" value="03" />
        </div>

        <div className="mt-4 grid gap-2.5 md:grid-cols-[1.3fr_1fr]">
          <div className="rounded-lg border border-[#e4e4e4] bg-white p-4">
            <div className="text-xs font-medium text-[#0a0a0a]">Payment Applications</div>
            <div className="mt-4 flex h-20 items-end gap-2.5">
              {bars.map((h, i) => (
                <div key={i} className="flex-1 rounded-t bg-[#d4d4d4]" style={{ height: `${h}%` }} />
              ))}
            </div>
          </div>

          <div className="rounded-lg border border-[#e4e4e4] bg-white p-4">
            <div className="text-xs font-medium text-[#0a0a0a]">Upcoming Deadlines</div>
            <div className="mt-3 space-y-2.5">
              {deadlines.map(([label, date]) => (
                <div key={label} className="flex items-center justify-between text-xs">
                  <span className="text-[#525252]">{label}</span>
                  <span className="text-[#8a8a8a]">{date}</span>
                </div>
              ))}
            </div>
          </div>
        </div>

        <div className="mt-2.5 rounded-lg border border-[#e4e4e4] bg-white p-4">
          <div className="text-xs font-medium text-[#0a0a0a]">Recent Activity</div>
          <div className="mt-3 space-y-2.5">
            {activity.map(([label, when]) => (
              <div key={label} className="flex items-center justify-between text-xs">
                <span className="text-[#525252]">{label}</span>
                <span className="text-[#8a8a8a]">{when}</span>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

export function ContractSummary() {
  const rows = [
    ['Contract', 'JCT Standard Building Contract'],
    ['Contract Sum', '£3,500,000'],
    ['Completion Date', '26 January 2027'],
  ];
  return (
    <div className="bg-white p-6">
      <div className="font-mono text-xs text-[#8a8a8a]">contracts / SP-COL-001</div>
      <div className="mt-4 space-y-3.5">
        {rows.map(([label, value]) => (
          <div key={label} className="flex items-center justify-between border-b border-[#e4e4e4] pb-3.5 text-sm last:border-b-0 last:pb-0">
            <span className="text-[#8a8a8a]">{label}</span>
            <span className="font-medium text-[#0a0a0a]">{value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

export function DocumentsExplorer() {
  const documents = ['Executed Contract', 'Payment Notice', 'Programme', 'Risk Register', 'Meeting Minutes'];
  return (
    <div className="divide-y divide-[#e4e4e4] bg-white">
      {documents.map((doc) => (
        <div key={doc} className="flex items-center justify-between px-5 py-3 text-sm">
          <span className="text-[#0a0a0a]">{doc}</span>
          <span className="text-[#8a8a8a]">PDF</span>
        </div>
      ))}
    </div>
  );
}

export function NotificationsFeed() {
  const notes = [
    { title: 'Payment deadline approaching', detail: 'PA-014 due in 3 days', unread: true },
    { title: 'Trade package generated', detail: 'Groundworks (GW)', unread: true },
    { title: 'Contract AI analysis completed', detail: 'Awaiting confirmation', unread: false },
  ];
  return (
    <div className="divide-y divide-[#e4e4e4] bg-white">
      {notes.map((note) => (
        <div key={note.title} className="flex items-start gap-3 px-5 py-4 text-sm">
          <span className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${note.unread ? 'bg-[#0a0a0a]' : 'bg-[#d4d4d4]'}`} />
          <div>
            <div className="font-medium text-[#0a0a0a]">{note.title}</div>
            <div className="mt-0.5 text-[#8a8a8a]">{note.detail}</div>
          </div>
        </div>
      ))}
    </div>
  );
}

export function ProgrammeTimeline() {
  const milestones = [
    { label: 'Groundworks complete', date: 'Mar' },
    { label: 'Frame erected', date: 'Jun' },
    { label: 'Watertight', date: 'Sep' },
    { label: 'Practical completion', date: 'Dec' },
  ];
  return (
    <div className="bg-white p-5">
      <div className="flex items-end justify-between gap-2">
        {milestones.map((m) => (
          <div key={m.label} className="flex flex-1 flex-col items-center gap-2">
            <div className="h-1.5 w-full rounded-full bg-[#e4e4e4]" />
            <span className="h-2 w-2 rounded-full border-2 border-[#0a0a0a] bg-white" />
            <span className="text-center text-[11px] text-[#8a8a8a]">{m.date}</span>
          </div>
        ))}
      </div>
      <div className="mt-3 text-xs text-[#525252]">{milestones[0].label} → {milestones[milestones.length - 1].label}</div>
    </div>
  );
}

export function StatutoryChainScreen() {
  const stages = ['Payment Application', 'Due Date', 'Payment Notice', 'Pay Less Notice', 'Final Date for Payment', 'Paid'];
  return (
    <div className="bg-white p-6">
      <div className="flex flex-col">
        {stages.map((stage, i) => (
          <div key={stage} className="flex gap-3">
            <div className="flex flex-col items-center">
              <span className="mt-[5px] h-2.5 w-2.5 shrink-0 rounded-full bg-[#0a0a0a]" />
              {i < stages.length - 1 && <span className="w-px flex-1 bg-[#d4d4d4]" style={{ minHeight: '1.5rem' }} />}
            </div>
            <span className={`text-sm text-[#0a0a0a] ${i < stages.length - 1 ? 'pb-6' : ''}`}>{stage}</span>
          </div>
        ))}
      </div>
    </div>
  );
}
