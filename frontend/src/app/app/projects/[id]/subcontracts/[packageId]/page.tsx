'use client';

import { useState } from 'react';
import { useParams, useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';
import { useCurrencyFormatter } from '@/hooks/useCurrencyFormatter';
import { useProjectPermissions } from '@/hooks/useProjectPermissions';
import GeneratePackageModal from '@/components/documents/GeneratePackageModal';
import {
  ArrowLeft, Package, Pencil, FileText, Download, Loader2, X,
  Building2, CalendarDays, Receipt, Layers, GitBranch, FileStack,
} from 'lucide-react';
import toast from 'react-hot-toast';

// ─── Types ───────────────────────────────────────────────────────────────────

type WorkspaceTab = 'overview' | 'documents' | 'commercial' | 'variations';

type TradePackage = {
  id: number;
  name: string;
  package_code?: string | null;
  package_reference?: string | null;
  contractor_name?: string | null;
  description?: string | null;
  status?: string | null;
  contract_value?: string | number | null;
  retention_percentage?: string | number | null;
  payment_terms_days?: number | null;
  payment_frequency?: string | null;
  letter_of_intent_date?: string | null;
  award_date?: string | null;
  execution_date?: string | null;
  commencement_date?: string | null;
  completion_date?: string | null;
  defects_liability_end_date?: string | null;
  contractor_contact_name?: string | null;
  contractor_email?: string | null;
  contractor_phone?: string | null;
  contractor_address?: string | null;
  contractor_company_reg_no?: string | null;
  contractor_vat_number?: string | null;
  due_date_offset_days?: number | null;
  final_date_offset_days?: number | null;
  payment_notice_offset_days?: number | null;
  pay_less_notice_offset_days?: number | null;
};

type CommercialSummary = {
  applications_count: number;
  certified_to_date: number;
  paid_to_date: number;
  retention_held: number;
  retention_released: number;
  outstanding_balance: number;
};

type AppRow = {
  id: number;
  application_number: number;
  status: string;
  application_date?: string | null;
  gross_valuation?: string | number | null;
  certified_amount?: string | number | null;
  paid_amount?: string | number | null;
  amount_due?: string | number | null;
};

type WorkspaceResponse = {
  trade_package: TradePackage;
  files_count: number;
  commercial_summary: CommercialSummary;
  applications: AppRow[];
};

// ─── Status presentation ───────────────────────────────────────────────────

const STATUS_META: Record<string, { label: string; bg: string; text: string }> = {
  tendering:        { label: 'Tendering',        bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
  tender_returned:  { label: 'Tender Returned',  bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
  under_review:     { label: 'Under Review',     bg: 'rgba(234,179,8,0.12)',  text: '#eab308' },
  awarded:          { label: 'Awarded',          bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  documents_issued: { label: 'Documents Issued', bg: 'rgba(59,130,246,0.12)', text: '#60a5fa' },
  executed:         { label: 'Executed',         bg: 'rgba(167,139,250,0.15)',text: '#a78bfa' },
  active:           { label: 'Active',           bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  completed:        { label: 'Completed',        bg: 'rgba(34,197,94,0.12)',  text: '#4ade80' },
  closed:           { label: 'Closed',           bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  archived:         { label: 'Archived',         bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
  inactive:         { label: 'Inactive',         bg: 'rgba(90,86,82,0.2)',    text: '#9a9490' },
};

function StatusBadge({ status }: { status?: string | null }) {
  const meta = STATUS_META[status ?? ''] ?? { label: status ?? '—', bg: 'var(--bg-elevated)', text: 'var(--text-muted)' };
  return (
    <span className="text-xs px-2 py-0.5 rounded-full font-medium" style={{ backgroundColor: meta.bg, color: meta.text }}>
      {meta.label}
    </span>
  );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function TradePackageWorkspacePage() {
  const params = useParams();
  const router = useRouter();
  const searchParams = useSearchParams();
  const projectId = params.id as string;
  const packageId = params.packageId as string;

  const { canWrite } = useProjectPermissions();
  const formatCurrency = useCurrencyFormatter();
  const queryClient = useQueryClient();

  const [tab, setTab] = useState<WorkspaceTab>('overview');
  const [showEdit, setShowEdit] = useState(searchParams.get('edit') === '1');
  const [showGenerate, setShowGenerate] = useState(false);

  const { data, isLoading } = useQuery<WorkspaceResponse>({
    queryKey: ['trade-package-workspace', projectId, packageId],
    queryFn: () => api.get(`/projects/${projectId}/trade-packages/${packageId}/workspace`).then(r => r.data),
    enabled: !!projectId && !!packageId,
  });

  const pkg = data?.trade_package;
  const summary = data?.commercial_summary;
  const apps = data?.applications ?? [];

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 size={22} className="animate-spin" style={{ color: 'var(--text-muted)' }} />
      </div>
    );
  }

  if (!pkg) {
    return (
      <div className="p-6 max-w-5xl mx-auto">
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Trade package not found.</p>
        <Link href={`/app/projects/${projectId}/contracts`} className="text-sm" style={{ color: 'var(--gold)' }}>
          ← Back to Contracts
        </Link>
      </div>
    );
  }

  const tabs: Array<{ key: WorkspaceTab; label: string; icon: React.ElementType }> = [
    { key: 'overview',   label: 'Overview',   icon: Layers },
    { key: 'documents',  label: 'Documents',  icon: FileStack },
    { key: 'commercial', label: 'Commercial', icon: Receipt },
    { key: 'variations', label: 'Variations', icon: GitBranch },
  ];

  return (
    <div className="p-6 max-w-5xl mx-auto space-y-6">
      {/* Header */}
      <div>
        <Link
          href={`/app/projects/${projectId}/contracts`}
          className="inline-flex items-center gap-1.5 text-xs mb-3 transition-colors hover:opacity-80"
          style={{ color: 'var(--text-muted)' }}
        >
          <ArrowLeft size={13} /> Subcontracts
        </Link>
        <div className="flex items-start justify-between gap-4 flex-wrap">
          <div className="flex items-start gap-3">
            <div className="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0" style={{ backgroundColor: 'rgba(167,139,250,0.12)' }}>
              <Package size={18} style={{ color: '#a78bfa' }} />
            </div>
            <div>
              <div className="flex items-center gap-2.5 flex-wrap">
                <h1 className="text-2xl font-bold" style={{ color: 'var(--text-primary)' }}>{pkg.name}</h1>
                <StatusBadge status={pkg.status} />
              </div>
              <p className="mt-1 text-sm font-mono" style={{ color: 'var(--gold)' }}>
                {pkg.package_reference ?? pkg.package_code ?? '—'}
              </p>
            </div>
          </div>
          {canWrite && (
            <div className="flex items-center gap-2">
              <button
                onClick={() => setShowGenerate(true)}
                className="flex items-center gap-1.5 text-sm px-3 py-2 rounded-xl transition-colors"
                style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
              >
                <FileText size={14} /> Generate Documents
              </button>
              <button
                onClick={() => setShowEdit(true)}
                className="flex items-center gap-1.5 text-sm px-3 py-2 rounded-xl transition-colors"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                <Pencil size={14} /> Edit Package
              </button>
            </div>
          )}
        </div>
      </div>

      {/* Tabs */}
      <div className="flex items-center gap-1 border-b" style={{ borderColor: 'var(--border)' }}>
        {tabs.map(t => {
          const active = tab === t.key;
          return (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className="flex items-center gap-1.5 px-3.5 py-2.5 text-sm font-medium transition-colors relative"
              style={{ color: active ? 'var(--text-primary)' : 'var(--text-muted)' }}
            >
              <t.icon size={14} /> {t.label}
              {active && <span className="absolute bottom-0 left-0 right-0 h-0.5" style={{ backgroundColor: 'var(--gold)' }} />}
            </button>
          );
        })}
      </div>

      {tab === 'overview'   && <OverviewTab pkg={pkg} formatCurrency={formatCurrency} />}
      {tab === 'documents'  && <DocumentsTab projectId={projectId} packageId={packageId} />}
      {tab === 'commercial' && <CommercialTab summary={summary} apps={apps} formatCurrency={formatCurrency} />}
      {tab === 'variations' && <VariationsPlaceholder />}

      {showEdit && (
        <EditPackageModal
          projectId={projectId}
          pkg={pkg}
          onClose={() => { setShowEdit(false); if (searchParams.get('edit')) router.replace(`/app/projects/${projectId}/subcontracts/${packageId}`); }}
        />
      )}
      {showGenerate && (
        <GeneratePackageModal
          projectId={projectId}
          tradePackage={{
            id: pkg.id, name: pkg.name, package_code: pkg.package_code,
            package_reference: pkg.package_reference, contractor_name: pkg.contractor_name,
            description: pkg.description,
          }}
          onClose={() => setShowGenerate(false)}
          onViewInPackage={() => {
            setShowGenerate(false);
            setTab('documents');
            queryClient.invalidateQueries({ queryKey: ['package-files', projectId, packageId] });
          }}
        />
      )}
    </div>
  );
}

// ─── Overview ────────────────────────────────────────────────────────────────

function InfoCard({ icon: Icon, title, children }: { icon: React.ElementType; title: string; children: React.ReactNode }) {
  return (
    <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <div className="flex items-center gap-2 mb-4">
        <Icon size={15} style={{ color: 'var(--text-muted)' }} />
        <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>{title}</h3>
      </div>
      <dl className="space-y-2.5">{children}</dl>
    </div>
  );
}

function Row({ label, value }: { label: string; value?: React.ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4">
      <dt className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</dt>
      <dd className="text-xs text-right font-medium" style={{ color: 'var(--text-secondary)' }}>{value ?? '—'}</dd>
    </div>
  );
}

function OverviewTab({ pkg, formatCurrency }: { pkg: TradePackage; formatCurrency: (n: number | string) => string }) {
  const offsetLabel = (n?: number | null) => (n != null ? `${n} day${n === 1 ? '' : 's'}` : '—');
  const date = (d?: string | null) => (d ? formatDate(d) : '—');

  return (
    <div className="grid md:grid-cols-2 gap-4">
      <InfoCard icon={Building2} title="Contractor">
        <Row label="Name" value={pkg.contractor_name} />
        <Row label="Contact" value={pkg.contractor_contact_name} />
        <Row label="Email" value={pkg.contractor_email} />
        <Row label="Phone" value={pkg.contractor_phone} />
        <Row label="Address" value={pkg.contractor_address} />
        <Row label="Company Reg No." value={pkg.contractor_company_reg_no} />
        <Row label="VAT Number" value={pkg.contractor_vat_number} />
      </InfoCard>

      <InfoCard icon={Receipt} title="Commercial Terms">
        <Row label="Contract Value" value={pkg.contract_value != null ? formatCurrency(pkg.contract_value) : '—'} />
        <Row label="Retention %" value={pkg.retention_percentage != null ? `${pkg.retention_percentage}%` : '—'} />
        <Row label="Payment Terms" value={pkg.payment_terms_days != null ? `${pkg.payment_terms_days} days` : '—'} />
        <Row label="Payment Frequency" value={pkg.payment_frequency ? pkg.payment_frequency.charAt(0).toUpperCase() + pkg.payment_frequency.slice(1) : '—'} />
      </InfoCard>

      <InfoCard icon={CalendarDays} title="Key Dates">
        <Row label="Letter of Intent" value={date(pkg.letter_of_intent_date)} />
        <Row label="Award" value={date(pkg.award_date)} />
        <Row label="Execution" value={date(pkg.execution_date)} />
        <Row label="Commencement" value={date(pkg.commencement_date)} />
        <Row label="Completion" value={date(pkg.completion_date)} />
        <Row label="Defects Liability End" value={date(pkg.defects_liability_end_date)} />
      </InfoCard>

      <InfoCard icon={Layers} title="Payment Rules (statutory dates)">
        <Row label="Due Date" value={`Application + ${offsetLabel(pkg.due_date_offset_days)}`} />
        <Row label="Final Date for Payment" value={`Due + ${offsetLabel(pkg.final_date_offset_days)}`} />
        <Row label="Payment Notice" value={`Due + ${offsetLabel(pkg.payment_notice_offset_days)}`} />
        <Row label="Pay Less Notice" value={`Final − ${offsetLabel(pkg.pay_less_notice_offset_days)}`} />
        {pkg.description && (
          <div className="pt-2 mt-2" style={{ borderTop: '1px solid var(--border)' }}>
            <dt className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Description</dt>
            <dd className="text-xs" style={{ color: 'var(--text-secondary)' }}>{pkg.description}</dd>
          </div>
        )}
      </InfoCard>
    </div>
  );
}

// ─── Documents ───────────────────────────────────────────────────────────────

const STANDARD_FOLDERS = [
  '01 Tender Enquiry', '02 Schedule of Documents', '03 Drawings', '04 Specifications',
  '05 Pricing Documents', '06 Contract Draft', '07 Correspondence', '08 Returned Tender',
  '09 Executed Contract',
];

function DocumentsTab({ projectId, packageId }: { projectId: string; packageId: string }) {
  const { data, isLoading } = useQuery({
    queryKey: ['package-files', projectId, packageId],
    queryFn: () => api.get(`/projects/${projectId}/documents/module/subcontracts/package/${packageId}`).then(r => r.data),
  });

  const files: any[] = data?.data ?? data?.files ?? [];
  const apiBase = process.env.NEXT_PUBLIC_API_URL ?? 'http://localhost:8000/api';

  return (
    <div className="space-y-4">
      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <h3 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Standard Folders</h3>
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-2">
          {STANDARD_FOLDERS.map(f => (
            <div key={f} className="flex items-center gap-2 px-3 py-2 rounded-xl text-xs" style={{ backgroundColor: 'var(--bg-elevated)', color: 'var(--text-secondary)' }}>
              <FileStack size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
              {f}
            </div>
          ))}
        </div>
      </div>

      <div className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <h3 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Files</h3>
        {isLoading ? (
          <div className="flex items-center justify-center py-8"><Loader2 size={18} className="animate-spin" style={{ color: 'var(--text-muted)' }} /></div>
        ) : files.length === 0 ? (
          <div className="text-center py-8">
            <FileText size={26} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No files uploaded for this package yet.</p>
          </div>
        ) : (
          <div className="space-y-2">
            {files.map((f: any) => (
              <div key={f.id} className="flex items-center justify-between gap-3 px-4 py-3 rounded-xl" style={{ backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)' }}>
                <div className="flex items-center gap-3 min-w-0">
                  <FileText size={15} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                  <div className="min-w-0">
                    <p className="text-sm truncate font-medium" style={{ color: 'var(--text-primary)' }}>{f.original_name}</p>
                    {f.file_size && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{(f.file_size / 1024).toFixed(0)} KB</p>}
                  </div>
                </div>
                <a
                  href={`${apiBase}/file-uploads/${f.id}/download`}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center gap-1 text-xs px-2 py-1 rounded-lg transition-colors hover:bg-[var(--bg-surface)] flex-shrink-0"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <Download size={11} /> Download
                </a>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// ─── Commercial (read-only) ──────────────────────────────────────────────────

function CommercialTab({ summary, apps, formatCurrency }: {
  summary?: CommercialSummary;
  apps: AppRow[];
  formatCurrency: (n: number | string) => string;
}) {
  const stats = [
    { label: 'Applications', value: String(summary?.applications_count ?? 0) },
    { label: 'Certified to Date', value: formatCurrency(summary?.certified_to_date ?? 0) },
    { label: 'Paid to Date', value: formatCurrency(summary?.paid_to_date ?? 0) },
    { label: 'Retention Held', value: formatCurrency(summary?.retention_held ?? 0) },
    { label: 'Outstanding Balance', value: formatCurrency(summary?.outstanding_balance ?? 0) },
  ];

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 sm:grid-cols-5 gap-3">
        {stats.map(s => (
          <div key={s.label} className="rounded-2xl p-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
            <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>{s.label}</p>
            <p className="text-lg font-bold" style={{ color: 'var(--text-primary)' }}>{s.value}</p>
          </div>
        ))}
      </div>

      <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
        <div className="px-5 py-3 flex items-center justify-between" style={{ backgroundColor: 'var(--bg-surface)', borderBottom: '1px solid var(--border)' }}>
          <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Payment Applications</h3>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>Read-only summary</span>
        </div>
        {apps.length === 0 ? (
          <div className="text-center py-8" style={{ backgroundColor: 'var(--bg-surface)' }}>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No payment applications for this package yet.</p>
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead>
              <tr style={{ backgroundColor: 'var(--bg-elevated)', borderBottom: '1px solid var(--border)' }}>
                {['#', 'Date', 'Gross', 'Certified', 'Paid', 'Status'].map(h => (
                  <th key={h} className="text-left px-5 py-2.5 text-xs font-medium" style={{ color: 'var(--text-muted)' }}>{h}</th>
                ))}
              </tr>
            </thead>
            <tbody style={{ backgroundColor: 'var(--bg-surface)' }}>
              {apps.map(a => (
                <tr key={a.id} style={{ borderBottom: '1px solid var(--border)' }}>
                  <td className="px-5 py-3 font-medium" style={{ color: 'var(--text-primary)' }}>{a.application_number}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-muted)' }}>{a.application_date ? formatDate(a.application_date) : '—'}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{a.gross_valuation != null ? formatCurrency(a.gross_valuation) : '—'}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{a.certified_amount != null ? formatCurrency(a.certified_amount) : '—'}</td>
                  <td className="px-5 py-3 text-xs" style={{ color: 'var(--text-secondary)' }}>{a.paid_amount != null ? formatCurrency(a.paid_amount) : '—'}</td>
                  <td className="px-5 py-3"><StatusBadge status={a.status} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  );
}

// ─── Variations placeholder ──────────────────────────────────────────────────

function VariationsPlaceholder() {
  return (
    <div className="rounded-2xl p-10 text-center" style={{ backgroundColor: 'var(--bg-surface)', border: '1px dashed var(--border)' }}>
      <GitBranch size={28} className="mx-auto mb-3" style={{ color: 'var(--text-muted)' }} />
      <h3 className="text-sm font-semibold mb-1" style={{ color: 'var(--text-primary)' }}>Subcontract Variations</h3>
      <p className="text-xs max-w-md mx-auto" style={{ color: 'var(--text-muted)' }}>
        Variations raised against this trade package will appear here. This workspace is ready for
        package-level variations — the feature is coming in a future update.
      </p>
    </div>
  );
}

// ─── Edit modal ──────────────────────────────────────────────────────────────

const STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: 'tendering', label: 'Tendering' },
  { value: 'tender_returned', label: 'Tender Returned' },
  { value: 'under_review', label: 'Under Review' },
  { value: 'awarded', label: 'Awarded' },
  { value: 'documents_issued', label: 'Documents Issued' },
  { value: 'executed', label: 'Executed' },
  { value: 'active', label: 'Active' },
  { value: 'completed', label: 'Completed' },
  { value: 'closed', label: 'Closed' },
  { value: 'archived', label: 'Archived' },
];

const FREQUENCY_OPTIONS = ['weekly', 'fortnightly', 'monthly', 'manual'];

const FIELD_CLS = "w-full px-3 py-2 rounded-lg text-sm";
const FIELD_STYLE = { backgroundColor: 'var(--bg-elevated)', border: '1px solid var(--border)', color: 'var(--text-primary)' } as const;
const LABEL_STYLE = { color: 'var(--text-muted)' } as const;

// Module-level so its identity is stable across renders (otherwise inputs lose focus on each keystroke).
function PkgField({ label, value, onChange, type = 'text' }: {
  label: string; value: string; onChange: (v: string) => void; type?: string;
}) {
  return (
    <div>
      <label className="block text-xs mb-1" style={LABEL_STYLE}>{label}</label>
      <input type={type} value={value} onChange={e => onChange(e.target.value)} className={FIELD_CLS} style={FIELD_STYLE} />
    </div>
  );
}

function EditPackageModal({ projectId, pkg, onClose }: { projectId: string; pkg: TradePackage; onClose: () => void }) {
  const queryClient = useQueryClient();

  const str = (v: unknown) => (v == null ? '' : String(v));
  const [form, setForm] = useState<Record<string, string>>({
    name: str(pkg.name),
    package_code: str(pkg.package_code),
    package_reference: str(pkg.package_reference),
    status: str(pkg.status) || 'active',
    description: str(pkg.description),
    // contractor
    contractor_name: str(pkg.contractor_name),
    contractor_contact_name: str(pkg.contractor_contact_name),
    contractor_email: str(pkg.contractor_email),
    contractor_phone: str(pkg.contractor_phone),
    contractor_address: str(pkg.contractor_address),
    contractor_company_reg_no: str(pkg.contractor_company_reg_no),
    contractor_vat_number: str(pkg.contractor_vat_number),
    // commercial
    contract_value: str(pkg.contract_value),
    retention_percentage: str(pkg.retention_percentage),
    payment_terms_days: str(pkg.payment_terms_days),
    payment_frequency: str(pkg.payment_frequency),
    // dates
    letter_of_intent_date: str(pkg.letter_of_intent_date)?.slice(0, 10),
    award_date: str(pkg.award_date)?.slice(0, 10),
    execution_date: str(pkg.execution_date)?.slice(0, 10),
    commencement_date: str(pkg.commencement_date)?.slice(0, 10),
    completion_date: str(pkg.completion_date)?.slice(0, 10),
    defects_liability_end_date: str(pkg.defects_liability_end_date)?.slice(0, 10),
    // offsets
    due_date_offset_days: str(pkg.due_date_offset_days),
    final_date_offset_days: str(pkg.final_date_offset_days),
    payment_notice_offset_days: str(pkg.payment_notice_offset_days),
    pay_less_notice_offset_days: str(pkg.pay_less_notice_offset_days),
  });

  const set = (k: string, v: string) => setForm(prev => ({ ...prev, [k]: v }));

  const { mutate, isPending } = useMutation({
    mutationFn: () => {
      // Send empty strings as null so clearing a field works; numbers stay numeric.
      const payload: Record<string, unknown> = {};
      Object.entries(form).forEach(([k, v]) => {
        payload[k] = v === '' ? null : v;
      });
      return api.put(`/projects/${projectId}/trade-packages/${pkg.id}`, payload).then(r => r.data);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['trade-package-workspace', projectId, String(pkg.id)] });
      queryClient.invalidateQueries({ queryKey: ['project-subcontracts', projectId] });
      toast.success('Trade package updated');
      onClose();
    },
    onError: () => toast.error('Failed to update trade package'),
  });

  const input = FIELD_CLS;
  const inputStyle = FIELD_STYLE;
  const labelCls = "block text-xs mb-1";
  const labelStyle = LABEL_STYLE;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" style={{ backgroundColor: 'rgba(0,0,0,0.6)' }}>
      <div className="w-full max-w-3xl rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
        <div className="flex items-center justify-between p-5 sticky top-0" style={{ borderBottom: '1px solid var(--border)', backgroundColor: 'var(--bg-surface)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Edit Trade Package</h2>
          <button onClick={onClose}><X size={18} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <form onSubmit={e => { e.preventDefault(); mutate(); }} className="p-5 space-y-6">
          {/* Package */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Package</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Name" value={form.name} onChange={v => set('name', v)} />
              <div>
                <label className={labelCls} style={labelStyle}>Status</label>
                <select value={form.status} onChange={e => set('status', e.target.value)} className={input} style={inputStyle}>
                  {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
              </div>
              <PkgField label="Package Code" value={form.package_code} onChange={v => set('package_code', v)} />
              <PkgField label="Package Reference" value={form.package_reference} onChange={v => set('package_reference', v)} />
              <div className="col-span-2">
                <label className={labelCls} style={labelStyle}>Description</label>
                <textarea value={form.description} onChange={e => set('description', e.target.value)} rows={2} className={input} style={inputStyle} />
              </div>
            </div>
          </section>

          {/* Contractor */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Contractor</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Contractor Name" value={form.contractor_name} onChange={v => set('contractor_name', v)} />
              <PkgField label="Contact Name" value={form.contractor_contact_name} onChange={v => set('contractor_contact_name', v)} />
              <PkgField label="Email" type="email" value={form.contractor_email} onChange={v => set('contractor_email', v)} />
              <PkgField label="Phone" value={form.contractor_phone} onChange={v => set('contractor_phone', v)} />
              <PkgField label="Address" value={form.contractor_address} onChange={v => set('contractor_address', v)} />
              <PkgField label="Company Registration No." value={form.contractor_company_reg_no} onChange={v => set('contractor_company_reg_no', v)} />
              <PkgField label="VAT Number" value={form.contractor_vat_number} onChange={v => set('contractor_vat_number', v)} />
            </div>
          </section>

          {/* Commercial terms */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Commercial Terms</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Contract Value" type="number" value={form.contract_value} onChange={v => set('contract_value', v)} />
              <PkgField label="Retention %" type="number" value={form.retention_percentage} onChange={v => set('retention_percentage', v)} />
              <PkgField label="Payment Terms (days)" type="number" value={form.payment_terms_days} onChange={v => set('payment_terms_days', v)} />
              <div>
                <label className={labelCls} style={labelStyle}>Payment Frequency</label>
                <select value={form.payment_frequency} onChange={e => set('payment_frequency', e.target.value)} className={input} style={inputStyle}>
                  <option value="">—</option>
                  {FREQUENCY_OPTIONS.map(o => <option key={o} value={o}>{o.charAt(0).toUpperCase() + o.slice(1)}</option>)}
                </select>
              </div>
            </div>
          </section>

          {/* Subcontract dates */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Subcontract Dates</h3>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Letter of Intent" type="date" value={form.letter_of_intent_date} onChange={v => set('letter_of_intent_date', v)} />
              <PkgField label="Award Date" type="date" value={form.award_date} onChange={v => set('award_date', v)} />
              <PkgField label="Execution Date" type="date" value={form.execution_date} onChange={v => set('execution_date', v)} />
              <PkgField label="Commencement Date" type="date" value={form.commencement_date} onChange={v => set('commencement_date', v)} />
              <PkgField label="Completion Date" type="date" value={form.completion_date} onChange={v => set('completion_date', v)} />
              <PkgField label="Defects Liability End" type="date" value={form.defects_liability_end_date} onChange={v => set('defects_liability_end_date', v)} />
            </div>
          </section>

          {/* Payment rules */}
          <section className="space-y-3">
            <h3 className="text-xs font-semibold uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Payment Rules (statutory date offsets)</h3>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Used to calculate due date, final date, and notice deadlines on this package&apos;s payment applications.
            </p>
            <div className="grid grid-cols-2 gap-3">
              <PkgField label="Due Date offset (days after application)" type="number" value={form.due_date_offset_days} onChange={v => set('due_date_offset_days', v)} />
              <PkgField label="Final Date offset (days after due date)" type="number" value={form.final_date_offset_days} onChange={v => set('final_date_offset_days', v)} />
              <PkgField label="Payment Notice offset (days after due date)" type="number" value={form.payment_notice_offset_days} onChange={v => set('payment_notice_offset_days', v)} />
              <PkgField label="Pay Less Notice offset (days before final date)" type="number" value={form.pay_less_notice_offset_days} onChange={v => set('pay_less_notice_offset_days', v)} />
            </div>
          </section>

          <div className="flex items-center justify-end gap-2 pt-2">
            <button type="button" onClick={onClose} className="text-sm px-4 py-2 rounded-xl" style={{ color: 'var(--text-muted)' }}>Cancel</button>
            <button type="submit" disabled={isPending} className="flex items-center gap-1.5 text-sm px-4 py-2 rounded-xl disabled:opacity-50" style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}>
              {isPending && <Loader2 size={14} className="animate-spin" />} Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
