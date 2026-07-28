<?php

namespace App\Services\Marketing;

use App\Services\EmailNotificationService;
use Carbon\CarbonInterface;

class SendMarketingContactEnquiryService
{
    public function send(array $contact, CarbonInterface $submittedAt): bool
    {
        $recipient = config('mail.marketing_contact_to', 'tech@suresigncontracts.com');

        $body = collect([
            'A new enquiry has been submitted through the SureSign marketing website.',
            '',
            'Name: '.$contact['name'],
            'Company: '.$contact['company'],
            'Email: '.$contact['email'],
            ! empty($contact['phone']) ? 'Phone: '.$contact['phone'] : 'Phone: Not provided',
            'Subject: '.$contact['subject'],
            'Submitted: '.$submittedAt->utc()->format('d M Y, H:i').' UTC',
            '',
            'Message',
            $contact['message'],
        ])->implode("\n");

        return EmailNotificationService::sendDirect(
            $recipient,
            'New Marketing Contact Enquiry',
            $body,
            [],
            $contact['email'],
            'Marketing enquiry',
        );
    }
}
