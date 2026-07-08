'use client';

import { HelpCircle } from 'lucide-react';
import { useTour } from '@/lib/tours/useTour';

// Sits beside a page's <h1>, not beside its primary action button — a
// restart-tour control competes with "New X"/"Add X" for attention when it
// shares that row, so it lives in the title's visual zone instead: the first
// thing anyone reads, but visually a ghost icon rather than a second button.
export default function PageTourButton({
  tourKey,
  label = 'Page tour',
}: {
  tourKey: string;
  label?: string;
}) {
  const { startTour } = useTour();

  return (
    <button
      type="button"
      data-tour="page-tour-button"
      onClick={() => startTour(tourKey, { force: true })}
      title={label}
      aria-label={label}
      className="inline-flex items-center justify-center w-6 h-6 rounded-full transition-all hover:bg-[var(--bg-hover)] active:scale-90 flex-shrink-0"
      style={{ color: 'var(--text-muted)' }}
    >
      <HelpCircle size={15} strokeWidth={2} />
    </button>
  );
}
