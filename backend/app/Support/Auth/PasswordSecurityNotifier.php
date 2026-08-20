<?php

namespace App\Support\Auth;

use App\Jobs\SendPasswordSecurityNotificationJob;
use App\Models\User;
use App\Services\TimezoneResolver;
use Illuminate\Support\Facades\Log;

/**
 * Unified Password Security Hardening — the one place a password-mutation
 * success notification is dispatched from, called only AFTER the
 * authoritative password write has already committed. Never called for a
 * failed/rejected mutation.
 *
 * Every method wraps its dispatch in try/catch and never throws — a
 * queue-connection failure at dispatch time (this app's `database` queue
 * driver — a DB insert) must not turn an already-successful password
 * change into a 500 response or an ambiguous "did it work?" state for the
 * caller. This is the "safe notifier wrapper" approach: the credential
 * change is authoritative regardless of whether this succeeds.
 *
 * Resolves the recipient's effective timezone
 * (`TimezoneResolver::effectiveTimezone()`) and formats the display
 * timestamp HERE, at the point closest to the actual event — mirroring
 * the established pattern of pre-formatting a display value before
 * handing it to `AccountEmailService`/the queued job, rather than making
 * the job or the mail service re-derive it later.
 */
class PasswordSecurityNotifier
{
    public static function notifyChanged(User $user): void
    {
        self::dispatch($user, 'changed');
    }

    public static function notifyReset(User $user): void
    {
        self::dispatch($user, 'reset');
    }

    public static function notifyAdminChanged(User $user): void
    {
        self::dispatch($user, 'admin_changed');
    }

    private static function dispatch(User $user, string $type): void
    {
        try {
            $timezone = TimezoneResolver::effectiveTimezone($user, $user->organization);
            $occurredAtDisplay = now()->setTimezone($timezone)->format('j M Y, g:i A T');

            SendPasswordSecurityNotificationJob::dispatch(
                $user->email,
                $user->first_name ?? $user->name,
                $type,
                $occurredAtDisplay,
            )->afterCommit();
        } catch (\Throwable $e) {
            Log::error("PasswordSecurityNotifier: failed to dispatch '{$type}' notification for user {$user->id}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
