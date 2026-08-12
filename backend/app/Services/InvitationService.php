<?php

namespace App\Services;

use App\Exceptions\InvitationAlreadyAcceptedException;
use App\Jobs\SendInvitationEmailJob;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Invitation & First-Time Account Setup phase — the dedicated invitation
 * send + acceptance service, completely separate from
 * EmailVerificationService (self-registration). See InvitationLinkService
 * for the signed-link mechanism and InvitationEmailService for the email
 * copy itself.
 */
class InvitationService
{
    public function __construct(
        private readonly InvitationLinkService $linkService = new InvitationLinkService(),
    ) {
    }

    /**
     * Sends (or re-sends) the invitation email for an already-created,
     * not-yet-accepted User. Never called for a User that has already
     * completed setup.
     */
    public function send(User $user): void
    {
        $acceptUrl = $this->linkService->acceptUrl($user);
        $expiryDays = (int) config('suresign.invitation.link_expiry_days');

        SendInvitationEmailJob::dispatch(
            $user->email,
            $user->first_name,
            $acceptUrl,
            $user->organization?->name,
            $expiryDays,
        )->afterCommit();
    }

    /**
     * Atomic first-time account setup. Validates the invitation hasn't
     * already been accepted, then — in one transaction — sets the
     * recipient's chosen password, clears the internal compatibility
     * secret's must-change flag, marks the email verified (the signed link
     * itself is what proves control of the mailbox), and records the audit
     * event. Any failure rolls back the whole operation, so a half-accepted
     * account (password changed but not verified, or vice versa) can never
     * be left behind.
     *
     * @throws InvitationAlreadyAcceptedException
     */
    public function accept(User $user, string $password): User
    {
        if ($user->email_verified_at !== null) {
            throw new InvitationAlreadyAcceptedException();
        }

        return DB::transaction(function () use ($user, $password) {
            // Re-check for a concurrent acceptance inside the transaction —
            // lockForUpdate() closes the window between the check above and
            // this write (e.g. two tabs submitting the setup form at once).
            $locked = User::lockForUpdate()->findOrFail($user->id);

            if ($locked->email_verified_at !== null) {
                throw new InvitationAlreadyAcceptedException();
            }

            $locked->password = Hash::make($password);
            $locked->must_change_password = false;
            $locked->email_verified_at = now();
            $locked->save();

            // Setting up a brand-new account has no prior session to
            // revoke — unlike UserController::setPassword()/forcePasswordReset(),
            // which explicitly revoke existing tokens for that reason.
            ActivityLog::record(
                'user.invitation_accepted',
                "{$locked->email} accepted their SureSign invitation and completed account setup",
                $locked,
                $locked,
            );

            return $locked;
        });
    }
}
