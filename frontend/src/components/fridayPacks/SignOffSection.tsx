'use client';

import { formatDate } from '@/lib/utils';

/**
 * Friday Pack Realignment, R1D — Sign Off. Workflow metadata only, read
 * LIVE from the FridayPack model — never frozen into snapshot_json (see
 * FridayPackSnapshotService's own docblock on why: generation always
 * predates review/approval/sending). No signature capability exists
 * anywhere in this codebase — this deliberately never uses wording like
 * "Electronic Signature"/"Digitally Signed"/"E-signature"; every line is
 * plainly workflow approval, not a legal/digital signature claim.
 */

interface SignOffPack {
  generation_source: string;
  generated_at: string | null;
  generated_by?: { name: string } | null;
  reviewed_at: string | null;
  reviewed_by?: { name: string } | null;
  approved_at: string | null;
  approved_by?: { name: string } | null;
  sent_at: string | null;
  sent_by?: { name: string } | null;
}

function preparedByLabel(pack: SignOffPack): string {
  return pack.generation_source === 'scheduled' ? 'SureSign Automation' : (pack.generated_by?.name ?? '—');
}

function Row({ label, name, at }: { label: string; name: string; at: string | null }) {
  if (!at) return null;
  return (
    <div>
      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{name} &middot; {formatDate(at)}</p>
    </div>
  );
}

export default function SignOffSection({ pack }: { pack: SignOffPack }) {
  return (
    <div className="rounded-2xl p-6" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)' }}>
      <h2 className="text-sm font-semibold mb-3" style={{ color: 'var(--text-primary)' }}>Sign Off</h2>
      <div className="flex flex-wrap gap-x-8 gap-y-3">
        <Row label="Prepared in SureSign" name={preparedByLabel(pack)} at={pack.generated_at} />
        <Row label="Reviewed in SureSign" name={pack.reviewed_by?.name ?? '—'} at={pack.reviewed_at} />
        <Row label="Approved in SureSign" name={pack.approved_by?.name ?? '—'} at={pack.approved_at} />
        <Row label="Issued/Sent by" name={pack.sent_by?.name ?? '—'} at={pack.sent_at} />
      </div>
    </div>
  );
}
