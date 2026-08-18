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

    private function appendSupport(array &$htmlParts, array &$textLines): void
    {
        $supportEmail = SuresignSetting::instance()->support_email;
        $htmlParts[] = EmailComponents::supportBlock($supportEmail);
        $textLines[] = '';
        $textLines[] = 'Questions about your account? Contact us: https://suresigncontracts.app/contact';
    }
}
