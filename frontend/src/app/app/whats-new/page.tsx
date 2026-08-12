'use client';

import { Sparkles } from 'lucide-react';
import { useProductUpdateHistory } from '@/hooks/useProductUpdates';
import ProductUpdateContent from '@/components/product-updates/ProductUpdateContent';
import { fromUtcIso } from '@/lib/dateTime';

/**
 * "View all updates" — a manual archive of published Product Updates.
 * Deliberately independent of this user's own dismissal state: dismissal
 * controls the automatic popup only, never access to history (see spec).
 */
export default function WhatsNewHistoryPage() {
  const { data, isLoading } = useProductUpdateHistory();

  return (
    <div className="p-6 max-w-2xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold flex items-center gap-2" style={{ color: 'var(--text-primary)' }}>
          <Sparkles size={20} />
          What&apos;s New in SureSign
        </h1>
        <p className="mt-1 text-sm" style={{ color: 'var(--text-muted)' }}>
          Every product update we&apos;ve published, newest first.
        </p>
      </div>

      {isLoading ? (
        <div className="space-y-3">
          {[...Array(3)].map((_, i) => (
            <div key={i} className="h-24 rounded-2xl animate-pulse" style={{ backgroundColor: 'var(--bg-elevated)' }} />
          ))}
        </div>
      ) : !data?.length ? (
        <div className="rounded-2xl px-5 py-10 text-center" style={{ border: '1px solid var(--border)' }}>
          <Sparkles size={24} className="mx-auto mb-2" style={{ color: 'var(--text-muted)' }} />
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No updates published yet.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {data.map(u => (
            <div key={u.id} className="rounded-2xl p-5" style={{ backgroundColor: 'var(--bg-surface)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
              <ProductUpdateContent update={u} dense />
              {u.published_at && (
                <p className="text-xs mt-3" style={{ color: 'var(--text-muted)' }}>
                  Published {fromUtcIso(u.published_at).slice(0, 10)}
                </p>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
