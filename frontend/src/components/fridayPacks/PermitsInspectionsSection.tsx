'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1E.1 — Permits (sourced from the EXISTING
 * App\Models\DeliveryDocument register, `category = 'permit'`, same
 * factual/no-conclusion contract as RamsSection) + R1E.2E — Statutory
 * Inspections (sourced from the new App\Models\StatutoryInspection
 * register). These are two genuinely separate source domains sharing one
 * report section, exactly as the frozen `permits_inspections` snapshot
 * key does — this component never conflates a permit/compliance
 * document with an inspection EVENT. Outcome/status/next-due are shown
 * exactly as recorded; this component never states or implies a
 * legal-compliance conclusion.
 */

interface PermitItem {
  title: string;
  revision: string | null;
  status: string;
  current: boolean;
  submitted_this_week: boolean;
  approved_this_week: boolean;
  expiry_date: string | null;
}

interface InspectionPlant {
  name: string;
  identifier: string | null;
}

interface InspectionItem {
  inspection_date: string;
  inspection_type: string;
  subject_description: string;
  plant: InspectionPlant | null;
  reference: string | null;
  outcome: string;
  status: string;
  next_due_date: string | null;
}

const STATUS_LABELS: Record<string, string> = {
  required: 'Required', pending: 'Pending', submitted: 'Submitted',
  under_review: 'Under Review', approved: 'Approved', rejected: 'Rejected',
  expired: 'Expired', superseded: 'Superseded',
};

const OUTCOME_LABELS: Record<string, string> = { satisfactory: 'Satisfactory', issues_found: 'Issues Found' };

export default function PermitsInspectionsSection({ permits, inspections }: { permits: PermitItem[]; inspections: InspectionItem[] }) {
  return (
    <div className="rounded-2xl p-6 space-y-4" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Permits / Statutory Inspections</h2>

      <div>
        <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Permits</p>
        {permits.length === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            No permit records captured for this reporting period.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{ color: 'var(--text-muted)' }}>
                  <th className="text-left py-1.5 pr-3 font-medium">Title</th>
                  <th className="text-left py-1.5 px-2 font-medium">Status</th>
                  <th className="text-left py-1.5 px-2 font-medium">Activity this week</th>
                  <th className="text-left py-1.5 pl-2 font-medium">Expiry</th>
                </tr>
              </thead>
              <tbody>
                {permits.map((item, i) => (
                  <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                    <td className="py-1.5 pr-3" style={{ color: 'var(--text-primary)' }}>{item.title}</td>
                    <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{STATUS_LABELS[item.status] ?? item.status}</td>
                    <td className="py-1.5 px-2" style={{ color: 'var(--text-muted)' }}>
                      {[item.submitted_this_week && 'Submitted this week', item.approved_this_week && 'Approved this week'].filter(Boolean).join(' · ') || '—'}
                    </td>
                    <td className="py-1.5 pl-2" style={{ color: 'var(--text-secondary)' }}>{item.expiry_date ? formatDate(item.expiry_date) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div>
        <p className="text-xs font-medium mb-2" style={{ color: 'var(--text-secondary)' }}>Statutory Inspections</p>
        {inspections.length === 0 ? (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
            No Statutory Inspection records captured for this reporting period.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr style={{ color: 'var(--text-muted)' }}>
                  <th className="text-left py-1.5 pr-3 font-medium">Date</th>
                  <th className="text-left py-1.5 px-2 font-medium">Inspection</th>
                  <th className="text-left py-1.5 px-2 font-medium">Subject / Plant</th>
                  <th className="text-left py-1.5 px-2 font-medium">Reference</th>
                  <th className="text-left py-1.5 px-2 font-medium">Outcome</th>
                  <th className="text-left py-1.5 px-2 font-medium">Status</th>
                  <th className="text-left py-1.5 pl-2 font-medium">Next Due</th>
                </tr>
              </thead>
              <tbody>
                {inspections.map((item, i) => (
                  <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                    <td className="py-1.5 pr-3" style={{ color: 'var(--text-muted)' }}>{formatDate(item.inspection_date)}</td>
                    <td className="py-1.5 px-2" style={{ color: 'var(--text-primary)' }}>{item.inspection_type}</td>
                    <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>
                      {item.subject_description}
                      {item.plant && (
                        <span style={{ color: 'var(--text-muted)' }}> ({item.plant.name}{item.plant.identifier ? ` · ${item.plant.identifier}` : ''})</span>
                      )}
                    </td>
                    <td className="py-1.5 px-2" style={{ color: 'var(--text-muted)' }}>{item.reference || '—'}</td>
                    <td className="py-1.5 px-2" style={{ color: 'var(--text-secondary)' }}>{OUTCOME_LABELS[item.outcome] ?? item.outcome}</td>
                    <td className="py-1.5 px-2 capitalize" style={{ color: 'var(--text-secondary)' }}>{item.status}</td>
                    <td className="py-1.5 pl-2" style={{ color: 'var(--text-secondary)' }}>{item.next_due_date ? formatDate(item.next_due_date) : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
