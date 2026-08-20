<?php

namespace App\Services;

use App\Models\SuresignSetting;
use App\Support\Email\EmailComponents;

/**
 * Communications Platform, Batch 4 — password reset and email
 * verification, migrated off their previous synchronous, plain-text
 * dispatch (see App\Jobs\SendPasswordResetEmailJob /
 * SendEmailVerificationJob for the queued/afterCommit contract those
 * jobs now provide) onto the same EmailComponents visual language every
 * other family in this batch was brought onto.
 *
 * Deliberately its own small service rather than folded into
 * AppointmentEmailService/ConsultationCommunicationService — those own
 * genuinely different domains (scheduling, Consultancy). "Account" is the
 * correct, narrow home for the two transactional flows that exist
 * platform-wide regardless of any module: resetting a password and
 * verifying an email address.
 */
class AccountEmailService
{
    private const LINK_EXPIRY_MINUTES = 60;

    public function sendPasswordReset(string $email, ?string $name, string $resetUrl): bool
    {
        $greeting = $name ? "Hi {$name}," : 'Hi there,';

        $htmlParts = [
            EmailComponents::paragraph($greeting),
            EmailComponents::paragraph('We received a request to reset your SureSign password.'),
            EmailComponents::button('Reset Password', $resetUrl, 'primary'),
            EmailComponents::quietNote(
                'This link expires in ' . self::LINK_EXPIRY_MINUTES . ' minutes. '
                . "If you didn't request this, you can safely ignore this email — your password will not be changed."
            ),
        ];
        $textLines = [
            $greeting,
            '',
            'We received a request to reset your SureSign password.',
            '',
            "Reset it here: {$resetUrl}",
            '',
            'This link expires in ' . self::LINK_EXPIRY_MINUTES . " minutes. If you didn't request this, you can safely ignore this email — your password will not be changed.",
        ];

        $this->appendSupport($htmlParts, $textLines);

        return EmailNotificationService::sendDirect(
            $email,
            'Reset your SureSign password',
            implode("\n", $textLines),
            [],
            null,
            'Account',
            implode("\n", $htmlParts),
            true,
        );
    }

    public function sendEmailVerification(string $email, ?string $name, string $verifyUrl): bool
    {
        $greeting = $name ? "Hi {$name}," : 'Hi there,';

        $htmlParts = [
            EmailComponents::paragraph($greeting),
            EmailComponents::paragraph('Please confirm your email address to finish setting up your SureSign account.'),
            EmailComponents::button('Verify Email Address', $verifyUrl, 'primary'),
            EmailComponents::quietNote('This link expires in ' . self::LINK_EXPIRY_MINUTES . ' minutes.'),
        ];
        $textLines = [
            $greeting,
            '',
            'Please confirm your email address to finish setting up your SureSign account.',
            '',
            "Verify it here: {$verifyUrl}",
            '',
            'This link expires in ' . self::LINK_EXPIRY_MINUTES . ' minutes.',
        ];

        $this->appendSupport($htmlParts, $textLines);

        return EmailNotificationService::sendDirect(
            $email,
            'Verify your SureSign email address',
            implode("\n", $textLines),
            [],
            null,
            'Account',
            implode("\n", $htmlParts),
            true,
        );
    }

    /**
     * Unified Password Security Hardening — sent after a successful
     * AUTHENTICATED, self-chosen password change (Settings → Change
     * Password, and the admin-forced must-change flow — both are the
     * account holder themselves choosing their own replacement password,
     * so they share this one notification). This is a SECURITY
     * NOTIFICATION, not an approval gate — the password has already
     * changed by the time this is ever sent; nothing here can undo or
     * confirm it. Never contains the current, new, or any password value.
     */
    public function sendPasswordChanged(string $email, ?string $name, string $occurredAtDisplay): bool
    {
        return $this->sendSecurityNotification(
            $email,
            $name,
            'Your SureSign password was changed',
            "Your SureSign password was changed on {$occurredAtDisplay}.",
        );
    }

    /**
     * Sent after a successful Forgot Password → Reset Password completion
     * — deliberately a DIFFERENT method/subject from `sendPasswordReset()`
     * above, which sends the pre-reset reset LINK. Conflating the two
     * would misdescribe which event actually happened to the recipient.
     */
    public function sendPasswordResetSecurityNotification(string $email, ?string $name, string $occurredAtDisplay): bool
    {
        return $this->sendSecurityNotification(
            $email,
            $name,
            'Your SureSign password was reset',
            "Your SureSign password was reset on {$occurredAtDisplay}.",
        );
    }

    /**
     * Sent when a Super Admin/Admin explicitly sets another user's
     * password (`UserController::setPassword()`). Deliberately does not
     * name the administrator — this notifies the affected account holder
     * of what happened to THEIR credential, not who performed the action;
     * that detail already lives in `ActivityLog`, a separate, appropriately
     * access-controlled surface.
     */
    public function sendPasswordChangedByAdmin(string $email, ?string $name, string $occurredAtDisplay): bool
    {
        return $this->sendSecurityNotification(
            $email,
            $name,
            'Your SureSign password was changed by an administrator',
            "Your SureSign password was changed by an administrator on {$occurredAtDisplay}.",
        );
    }

    private function sendSecurityNotification(string $email, ?string $name, string $subject, string $eventLine): bool
    {
        $greeting = $name ? "Hi {$name}," : 'Hi there,';
        $forgotPasswordUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/') . '/forgot-password';

        $htmlParts = [
            EmailComponents::paragraph($greeting),
            EmailComponents::paragraph($eventLine),
            EmailComponents::paragraph('If you made this change, no action is required.'),
            EmailComponents::statusCallout(
                "If you didn't make this change, secure your account immediately by resetting your password.",
                'info',
            ),
            EmailComponents::button('Reset Your Password', $forgotPasswordUrl, 'secondary'),
        ];
        $textLines = [
            $greeting,
            '',
            $eventLine,
            '',
            'If you made this change, no action is required.',
            '',
            "If you didn't make this change, secure your account immediately: {$forgotPasswordUrl}",
        ];

        $this->appendSupport($htmlParts, $textLines);

        return EmailNotificationService::sendDirect(
            $email,
            $subject,
            implode("\n", $textLines),
            [],
            null,
            'Account',
            implode("\n", $htmlParts),
            true,
        );
    }

    private function appendSupport(array &$htmlParts, array &$textLines): void
    {
        $supportEmail = SuresignSetting::instance()->support_email;
        $htmlParts[] = EmailComponents::supportBlock($supportEmail);
        $textLines[] = '';
        $textLines[] = 'Questions about your account? Contact us: https://suresigncontracts.app/contact';
    }
}
