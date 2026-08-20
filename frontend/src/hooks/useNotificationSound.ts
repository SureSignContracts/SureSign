'use client';

import { useCallback, useRef } from 'react';
import { useSiteSettings } from '@/hooks/useSiteSettings';
import { useAuthStore } from '@/store/authStore';
import toast from '@/lib/toast';

// ~3s — comfortably above the gap between two nearly-simultaneous same-tab
// calls, without being long enough to plausibly suppress two genuinely
// separate notification arrivals (60s polling interval, see
// useNewNotificationWatcher).
const COOLDOWN_MS = 3000;

// Restrained by design — see CLAUDE.md's "Notification Sound System"
// section. Not exposed as a user-configurable mixer in V1.
const PLAYBACK_VOLUME = 0.35;

function lastPlayedStorageKey(userId: number | undefined): string {
  return `suresign-notification-sound-last-played-${userId ?? 'guest'}`;
}

/**
 * Notification Sound System — playback only. Reads the platform-wide
 * configured asset (`useSiteSettings().notification_sound_url`, uploaded by
 * Super Admin/Admin via the SureSign Branding settings hub — no new
 * request, that hook is already fetched at the authenticated shell level)
 * and the per-user ON/OFF preference (`useAuthStore().user.notification_sound_enabled`).
 *
 * Deliberately does NOT decide what counts as a "new" notification — that
 * stays with `useNewNotificationWatcher`, which is expected to call
 * `playNotificationSound()` from its `onNew` callback.
 *
 * Autoplay rejection (`HTMLAudioElement.play()` rejecting because no user
 * gesture has occurred yet, or any other browser restriction) is treated as
 * a normal, silent no-op — never a console error, never a toast. The
 * in-app notification itself is never affected either way.
 */
export function useNotificationSound() {
  const { data: siteSettings } = useSiteSettings();
  const soundUrl = siteSettings?.notification_sound_url ?? null;
  const soundEnabled = useAuthStore(s => s.user?.notification_sound_enabled ?? true);
  const userId = useAuthStore(s => s.user?.id);
  const audioRef = useRef<HTMLAudioElement | null>(null);

  const resolveAudio = useCallback((): HTMLAudioElement | null => {
    if (!soundUrl) return null; // nothing configured yet — nothing to play
    if (!audioRef.current || audioRef.current.src !== soundUrl) {
      const audio = new Audio(soundUrl);
      audio.volume = PLAYBACK_VOLUME;
      audioRef.current = audio;
    }
    return audioRef.current;
  }, [soundUrl]);

  /**
   * The real "a new notification arrived" path — respects the user's own
   * preference, a short cooldown, and same-origin cross-tab suppression (a
   * localStorage last-played timestamp, so several open SureSign tabs
   * receiving the same poll result produce one audible cue, not several).
   */
  const playNotificationSound = useCallback(() => {
    if (!soundEnabled) return;
    const audio = resolveAudio();
    if (!audio) return;

    const key = lastPlayedStorageKey(userId);
    const now = Date.now();
    try {
      const last = Number(window.localStorage.getItem(key) ?? '0');
      if (now - last < COOLDOWN_MS) return; // this tab or another already played recently
      window.localStorage.setItem(key, String(now));
    } catch {
      // localStorage unavailable (private browsing, quota, disabled) —
      // fall back to always playing rather than silently never playing;
      // this is playback coordination only, never a functional dependency.
    }

    audio.currentTime = 0;
    audio.play().catch(() => {});
  }, [soundEnabled, resolveAudio, userId]);

  /**
   * Explicit user-initiated preview (Settings "Test sound", the Branding
   * tab's inline "Play", the Super Admin Preview tab). Bypasses the ON/OFF
   * preference — the user is deliberately checking what it sounds like —
   * and the cooldown/cross-tab suppression (a single, isolated, intentional
   * play), but still requires a real configured asset.
   *
   * Unlike `playNotificationSound()`, this DOES surface feedback via toast
   * — this is a deliberate diagnostic action, not a background one, so
   * silence here would just be confusing. A resolved `play()` promise means
   * the browser genuinely started playback (this is also how a per-tab/
   * per-site "mute" — which silences audio without rejecting `play()` —
   * shows up: the success toast fires, but nothing is audible, which is
   * itself the useful signal that the tab/site needs unmuting rather than
   * anything being broken).
   */
  const playTestSound = useCallback(() => {
    const audio = resolveAudio();
    if (!audio) {
      toast.error('No notification sound has been configured yet.');
      return;
    }
    audio.currentTime = 0;
    audio.play()
      .then(() => toast.success('Playing notification sound.'))
      .catch(() => toast.error("Couldn't play the sound. If this browser tab or site is muted, unmute it and try again."));
  }, [resolveAudio]);

  return { playNotificationSound, playTestSound, hasSoundConfigured: !!soundUrl };
}
