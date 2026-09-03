'use client';

import { useQuery } from '@tanstack/react-query';
import api from '@/lib/api';
import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1D — Report Information. Entirely read-only,
 * rendered exclusively from the pack's own frozen `snapshot_json` — never
 * a live Project/Contract/Organisation re-read. A field the backend
 * couldn't resolve unambiguously (Principal Contractor, Sub-Contract
 * Order No., Scope of Works) shows "Not recorded," never a guess.
 *
 * Distributed To is the one deliberate exception rendered here from LIVE
 * data (never frozen into the snapshot — see FridayPackSnapshotService's
 * own docblock on why): before any FridayPackDelivery row exists, this
 * shows the currently configured FridayPackSettings recipients labelled
 * "Intended — not yet sent"; once delivery has started, it shows the
 * durable FridayPackDelivery rows (the actual historical recipients) and
 * never switches back.
 */

interface ReportInformationData {
  project_name: string | null;
  project_code: string | null;
  site_address: { address: string | null; city: string | null; state: string | null; postcode: string | null; country: string | null };
  principal_contractor: string | null;
  reporting_organisation_name: string | null;
  organization_role: string | null;
  sub_contract_order_no: string | null;
  scope_of_works: string | null;
  week_commencing: string;
  week_ending: string;
  prepared_by: string | null;
  prepared_at: string | null;
  report_number: string | null;
}

interface DeliveryRow {
  id: number;
  recipient_name: string | null;
  recipient_email: string;
  status: 'pending' | 'sent' | 'failed';
}

interface SettingsRecipient {
  name?: string;
  email: string;
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm font-medium" style={{ color: value ? 'var(--text-primary)' : 'var(--text-muted)' }}>
        {value || 'Not recorded'}
      </p>
    </div>
  );
}

function DistributedTo({ projectId, fridayPackId }: { projectId: string; fridayPackId: string }) {
  const { data: deliveries } = useQuery<DeliveryRow[]>({
    queryKey: ['friday-pack-deliveries', projectId, fridayPackId],
    queryFn: () => api.get(`/projects/${projectId}/friday-packs/${fridayPackId}/deliveries`).then(r => r.data),
  });

  const { data: settings } = useQuery<{ recipients?: SettingsRecipient[] | null }>({
    queryKey: ['friday-pack-settings', projectId],
    queryFn: () => api.get(`/projects/${projectId}/friday-pack-settings`).then(r => r.data),
    enabled: (deliveries?.length ?? 0) === 0,
  });

  const hasDeliveries = (deliveries?.length ?? 0) > 0;

  return (
    <div>
      <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Distributed To</p>
      {hasDeliveries ? (
        <div className="space-y-1">
          {deliveries!.map(d => (
            <p key={d.id} className="text-sm" style={{ color: 'var(--text-primary)' }}>
              {d.recipient_name || d.recipient_email}
              <span className="text-xs ml-2 capitalize" style={{ color: d.status === 'sent' ? '#4ade80' : d.status === 'failed' ? '#f87171' : 'var(--text-muted)' }}>
                {d.status}
              </span>
            </p>
          ))}
        </div>
      ) : (settings?.recipients?.length ?? 0) > 0 ? (
        <>
          <p className="text-xs mb-1" style={{ color: 'var(--text-muted)' }}>Intended recipients — not yet sent</p>
          <div className="space-y-1">
            {settings!.recipients!.map((r, i) => (
              <p key={i} className="text-sm" style={{ color: 'var(--text-primary)' }}>{r.name || r.email}</p>
            ))}
          </div>
        </>
      ) : (
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No recipients configured yet.</p>
      )}
    </div>
  );
}

export default function ReportInformationSection({ projectId, fridayPackId, info, sentAt }: {
  projectId: string; fridayPackId: string; info: ReportInformationData; sentAt: string | null;
}) {
  const siteAddressParts = [info.site_address.address, info.site_address.city, info.site_address.state, info.site_address.postcode, info.site_address.country]
    .filter(Boolean).join(', ');

  const label = info.organization_role === 'subcontractor' ? 'Sub-Contractor' : 'Reporting Organisation';

  return (
    <div className="rounded-2xl p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Report Information</h2>
      <div className="grid grid-cols-2 gap-4">
        <Field label="Project" value={[info.project_name, info.project_code].filter(Boolean).join(' — ') || null} />
        <Field label="Report No." value={info.report_number} />
        <Field label="Site Address" value={siteAddressParts || null} />
        <Field label="Principal Contractor" value={info.principal_contractor} />
        <Field label={label} value={info.reporting_organisation_name} />
        <Field label="Sub-Contract Order No." value={info.sub_contract_order_no} />
        <Field label="Scope of Works" value={info.scope_of_works} />
        <Field label="Week Commencing" value={formatDate(info.week_commencing)} />
        <Field label="Week Ending" value={formatDate(info.week_ending)} />
        <Field label="Prepared By" value={info.prepared_by} />
        <Field label="Prepared At" value={info.prepared_at ? formatDate(info.prepared_at) : null} />
        <Field label="Date Issued" value={sentAt ? formatDate(sentAt) : 'Not issued'} />
      </div>
      <DistributedTo projectId={projectId} fridayPackId={fridayPackId} />
    </div>
  );
}
