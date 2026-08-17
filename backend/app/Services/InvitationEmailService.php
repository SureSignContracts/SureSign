<?php

namespace App\Services;

use App\Models\SuresignSetting;
use App\Support\Email\EmailComponents;

/**
 * Invitation & First-Time Account Setup phase — the dedicated invitation
 * email, deliberately separate from AccountEmailService::sendEmailVerification()
 * (self-registration verification). The two must never share copy, subject,
 * or CTA: an admin-invited recipient did not sign themselves up, and must
 * never be told to "verify" an account they never created.
 *
 * Built on the same EmailComponents visual language as every other family
 * in the Communications Platform — reuses the existing branded layout via
 * EmailNotificationService::sendDirect(), 'Invitation' category (renders as
 * the eyebrow label the phase spec asks for).
 */
class InvitationEmailService
{
    /**
     * @param string|null $name Genuine stored recipient name only — never
     *   derived from the email address. Null produces the generic "Hi,"
     *   greeting (see UserController::invite()).
     * @param string|null $organizationName Only passed when authoritative
     *   (the invited user is actually being assigned to a known
     *   Organisation) — omitted entirely from the copy otherwise, never
     *   guessed.
     * @param bool $includeBetaNotice Per-invite admin choice (see
     *   UserController::invite()) — never a global config flag, since not
     *   every invited recipient should necessarily be told the same thing.
     */
    public function send(string $email, ?string $name, string $acceptUrl, ?string $organizationName, int $expiryDays, bool $includeBetaNotice = false): bool
    {
        $greeting = $name ? "Hi {$name}," : 'Hi,';
        $joinLine = $organizationName
            ? "You've been invited to join {$organizationName} on SureSign."
            : "You've been invited to join SureSign.";
        $expiryLine = 'This invitation expires in ' . $expiryDays . ' ' . ($expiryDays === 1 ? 'day' : 'days') . '.';
        $betaLine = 'SureSign is currently in beta. You may notice ongoing improvements as we continue refining the platform.';

        $htmlParts = [
            EmailComponents::paragraph($greeting),
            EmailComponents::paragraph($joinLine),
        ];
        $textLines = [
            $greeting,
            '',
            $joinLine,
        ];

        if ($includeBetaNotice) {
            $htmlParts[] = EmailComponents::paragraph($betaLine);
            $textLines[] = '';
            $textLines[] = $betaLine;
        }

        $htmlParts[] = EmailComponents::paragraph(
            'SureSign provides a central workspace for managing construction contracts, project records, '
            . 'documents, commercial workflows, drawings, notices, and project administration.'
        );
        $htmlParts[] = EmailComponents::paragraph('Accept your invitation to finish setting up your account and create your password.');
        $htmlParts[] = EmailComponents::button('Accept Invitation & Set Up Account', $acceptUrl, 'primary');
        $htmlParts[] = EmailComponents::quietNote(
            $expiryLine . " If you weren't expecting this invitation, you can safely ignore this email."
        );

        $textLines[] = '';
        $textLines[] = 'SureSign provides a central workspace for managing construction contracts, project records, documents, '
            . 'commercial workflows, drawings, notices, and project administration.';
        $textLines[] = '';
        $textLines[] = 'Accept your invitation to finish setting up your account and create your password:';
        $textLines[] = $acceptUrl;
        $textLines[] = '';
        $textLines[] = $expiryLine . " If you weren't expecting this invitation, you can safely ignore this email.";

        $supportEmail = SuresignSetting::instance()->support_email;
        $htmlParts[] = EmailComponents::supportBlock($supportEmail);
        $textLines[] = '';
        $textLines[] = $supportEmail ? "Questions? Contact us at {$supportEmail}." : 'Questions? Please get in touch with us.';

        return EmailNotificationService::sendDirect(
            $email,
            "You've been invited to SureSign",
            implode("\n", $textLines),
            [],
            null,
            'Invitation',
            implode("\n", $htmlParts),
            true,
        );
    }
}
