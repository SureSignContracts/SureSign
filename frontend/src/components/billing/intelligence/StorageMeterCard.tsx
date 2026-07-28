import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { HardDrive } from 'lucide-react';
import UsageMeter from './UsageMeter';
import { UsageMetric } from '@/types/subscriptionIntelligence';

/**
 * Stage 4 — the authoritative figure is `SUM(file_size)` across every
 * table that records a file's size at write time (documents, document
 * versions, uploads, adjudication documents) — never a live filesystem
 * scan. See `App\Services\Intelligence\UsageMetricsService::storageGbUsed()`.
 */
export default function StorageMeterCard({ storage }: { storage: UsageMetric }) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          <HardDrive size={16} aria-hidden style={{ color: 'var(--text-secondary)' }} />
          <CardTitle>Storage</CardTitle>
        </div>
      </CardHeader>
      <CardBody>
        <UsageMeter metric={storage} />
        <p className="text-[11px] mt-3" style={{ color: 'var(--text-muted)' }}>
          Includes documents, generated files, and uploaded attachments across your organisation.
        </p>
      </CardBody>
    </Card>
  );
}
