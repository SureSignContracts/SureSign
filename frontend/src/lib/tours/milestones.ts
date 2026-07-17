// Personal, idempotent milestone notifications for guided tours. Deliberately
// narrow: only three genuinely meaningful moments (not one notification per
// short tour, which would just be noise), never organization-wide, never
// email — see NotificationService::TOUR_MILESTONE_* on the backend.
import api from '@/lib/api';
import { TOURS } from './registry';
import { getCompletedTourKeys } from './storage';

type UserId = number | string | null | undefined;
type MilestoneKey = 'first_tour' | 'getting_started_complete' | 'all_tours_complete';

function flagKey(userId: UserId, milestone: MilestoneKey): string {
  return `suresign-tour-milestone-${milestone}-${userId ?? 'guest'}`;
}

function alreadyNotified(userId: UserId, milestone: MilestoneKey): boolean {
  try {
    return localStorage.getItem(flagKey(userId, milestone)) === '1';
  } catch {
    return false;
  }
}

function markNotified(userId: UserId, milestone: MilestoneKey) {
  try {
    localStorage.setItem(flagKey(userId, milestone), '1');
  } catch {}
}

// Called right after a tour is marked complete. Figures out whether that
// completion just crossed a milestone and, if so, asks the backend to send
// a one-off personal notification — marking locally first so a slow/failed
// request can't be fired twice from a quick double-completion; the backend
// route also dedupes by notification type per user, so this is belt-and-braces,
// not the only thing standing between a real user and duplicate notifications.
export async function checkTourMilestones(userId: UserId): Promise<void> {
  const completed = new Set(getCompletedTourKeys(userId));
  const gettingStartedKeys = TOURS.filter(t => t.group === 'Getting Started').map(t => t.key);

  const crossed: MilestoneKey[] = [];
  if (completed.size >= 1) crossed.push('first_tour');
  if (gettingStartedKeys.length > 0 && gettingStartedKeys.every(k => completed.has(k))) crossed.push('getting_started_complete');
  if (completed.size === TOURS.length) crossed.push('all_tours_complete');

  for (const milestone of crossed) {
    if (alreadyNotified(userId, milestone)) continue;
    markNotified(userId, milestone);
    try {
      await api.post('/tour-milestones', { milestone });
    } catch {
      // Non-fatal — worst case this milestone's notification never sends.
    }
  }
}
