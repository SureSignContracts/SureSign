'use client';

import { useMemo, useState } from 'react';
import { usePathname } from 'next/navigation';
import { usePendingProductUpdates } from '@/hooks/useProductUpdates';
import { getSessionClosedIds, markSessionClosed } from '@/lib/whatsNewSession';
import WhatsNewModal from './WhatsNewModal';

/**
 * Mounted once in each authenticated app shell (AppLayout, AdminLayout) —
 * both only render this from inside their already-gated "normal shell"
 * branch, so mounting here alone already guarantees this never shows
 * before auth, a forced password change, or (in AppLayout's case)
 * onboarding. `enabled` additionally excludes the onboarding wizard route
 * itself, matching GlobalTourLauncher's identical precaution.
 */
export default function WhatsNewLauncher({ enabled = true, historyHref }: { enabled?: boolean; historyHref?: string }) {
  const pathname = usePathname();
  const onOnboardingPage = pathname === '/app/onboarding';

  const { data: pending } = usePendingProductUpdates(enabled && !onOnboardingPage);

  // Read once at mount (lazy initializer, not an effect) — anything
  // closed in an earlier route within this same tab session.
  const [sessionClosed, setSessionClosed] = useState<Set<number>>(() => new Set(getSessionClosedIds()));

  // Exclude anything the user has already clicked plain "Close" on during
  // this tab session — see lib/whatsNewSession.ts for why this is separate
  // from the server-side dismissal the "Don't show again" button records.
  const visible = useMemo(
    () => (pending ?? []).filter(u => !sessionClosed.has(u.id)),
    [pending, sessionClosed],
  );

  if (visible.length === 0) return null;

  return (
    <WhatsNewModal
      updates={visible}
      historyHref={historyHref}
      onDone={() => {
        const ids = visible.map(u => u.id);
        markSessionClosed(ids);
        setSessionClosed(prev => new Set([...prev, ...ids]));
      }}
    />
  );
}
