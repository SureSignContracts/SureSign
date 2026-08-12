<?php

namespace App\Services\Support;

use App\Models\SuresignSetting;
use App\Services\EmailNotificationService;
use Carbon\CarbonInterface;

/**
 * The "Contact your administrator" page's enquiry email — mirrors
 * SendMarketingContactEnquiryService's shape (EmailNotificationService::
 * sendDirect(), sender's own address set as reply-to so a reply from
 * support goes straight back to them, never a second email-sending path).
 * Sends to the same SuresignSetting::support_email the page itself
 * displays — never a separate hardcoded address the page's own copy could
 * silently disagree with.
 */
class SendAccountAccessEnquiryService
{
    public function send(array $enquiry, CarbonInterface $submittedAt): bool
    {
        $recipient = SuresignSetting::instance()->support_email ?: 'tech@suresigncontracts.com';

        $body = collect([
            'Someone was unable to sign in or access SureSign and used the',
            '"Contact your administrator" page to ask for help.',
            '',
            'Name: '.($enquiry['name'] ?: 'Not provided'),
            'Email: '.$enquiry['email'],
            'Submitted: '.$submittedAt->utc()->format('d M Y, H:i').' UTC',
            '',
            'Message',
            $enquiry['message'],
        ])->implode("\n");

        return EmailNotificationService::sendDirect(
            $recipient,
            'SureSign account access enquiry',
            $body,
            [],
            $enquiry['email'],
            'Account access enquiry',
        );
    }
}
