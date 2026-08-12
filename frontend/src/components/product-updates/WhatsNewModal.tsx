'use client';

import { useState } from 'react';
import Link from 'next/link';
import { Sparkles, ChevronLeft, ChevronRight } from 'lucide-react';
import Modal from '@/components/ui/Modal';
import ProductUpdateContent from './ProductUpdateContent';
import { useDismissProductUpdate } from '@/hooks/useProductUpdates';
import type { ProductUpdate } from '@/lib/productUpdates';

/**
 * The automatic "What's New" modal — one modal for however many pending
 * updates there are (never one popup per update; see spec's "avoid modal
 * spam"). Lightweight "1 of N" pagination when there's more than one.
 */
export default function WhatsNewModal({ updates, onDone, historyHref }: { updates: ProductUpdate[]; onDone: () => void; historyHref?: string }) {
  const [index, setIndex] = useState(0);
  const dismissMutation = useDismissProductUpdate();
  const current = updates[index];
  const hasMultiple = updates.length > 1;

  function advanceOrClose(close: () => void) {
    if (index < updates.length - 1) {
      setIndex(i => i + 1);
    } else {
      close();
    }
  }

  function handleDismiss(close: () => void) {
    dismissMutation.mutate(current.id);
    advanceOrClose(close);
  }

  if (!current) return null;

  return (
    <Modal title="What's New in SureSign" icon={Sparkles} tone="info" onClose={onDone}>
      {(close) => (
        <div className="flex flex-col gap-4">
          <div key={current.id} className="ss-fade-in-up">
            <ProductUpdateContent update={current} />
          </div>

          {historyHref && (
            <Link href={historyHref} onClick={close} className="text-xs font-medium" style={{ color: 'var(--text-muted)' }}>
              View all updates
            </Link>
          )}

          <div className="flex items-center justify-between gap-3 pt-2" style={{ borderTop: '1px solid var(--border)' }}>
            {hasMultiple ? (
              <div className="flex items-center gap-2 pt-3">
                <button
                  onClick={() => setIndex(i => Math.max(0, i - 1))}
                  disabled={index === 0}
                  aria-label="Previous update"
                  className="p-1 rounded-md disabled:opacity-30"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <ChevronLeft size={16} />
                </button>
                <span className="text-xs" style={{ color: 'var(--text-muted)' }}>
                  {index + 1} of {updates.length}
                </span>
                <button
                  onClick={() => setIndex(i => Math.min(updates.length - 1, i + 1))}
                  disabled={index === updates.length - 1}
                  aria-label="Next update"
                  className="p-1 rounded-md disabled:opacity-30"
                  style={{ color: 'var(--text-muted)' }}
                >
                  <ChevronRight size={16} />
                </button>
              </div>
            ) : (
              <div className="pt-3" />
            )}
            <div className="flex items-center gap-2 pt-3">
              <button
                onClick={() => handleDismiss(close)}
                className="px-3 py-2 rounded-lg text-xs font-medium"
                style={{ color: 'var(--text-secondary)', border: '1px solid var(--border)' }}
              >
                Don&apos;t show this update again
              </button>
              <button
                onClick={close}
                className="px-4 py-2 rounded-lg text-xs font-medium"
                style={{ backgroundColor: 'var(--gold)', color: 'var(--accent-fg)' }}
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </Modal>
  );
}
