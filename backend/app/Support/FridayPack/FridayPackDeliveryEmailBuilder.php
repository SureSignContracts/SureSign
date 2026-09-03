<?php

namespace App\Support\FridayPack;

use App\Models\FridayPackDelivery;
use App\Support\Email\EmailComponents;

/**
 * Automated Friday Pack, V1F — builds the delivery email's subject/HTML/
 * plaintext. Deliberately operational-only: NO marketing CTA, no pricing,
 * no newsletter language, no tracking pixel — this is a working document
 * a recipient (who may not even hold a SureSign account) needs to
 * download, not a marketing touchpoint. Reuses EmailComponents purely for
 * its layout primitives (button/detailsTable/paragraph), the same as
 * every other transactional email in this codebase.
 */
class FridayPackDeliveryEmailBuilder
{
    public function subject(FridayPackDelivery $delivery): string
    {
        $projectName = $delivery->project->name;
        $weekEnding = $delivery->fridayPack->week_ending->format('d F Y');

        return "Friday Pack — {$projectName} — Week Ending {$weekEnding}";
    }

    public function html(FridayPackDelivery $delivery, string $downloadUrl): string
    {
        $pack = $delivery->fridayPack;
        $project = $delivery->project;
        $greetingName = $delivery->recipient_name ?: 'there';

        $html = EmailComponents::paragraph("Hi {$greetingName},");
        $html .= EmailComponents::paragraph(
            "The Friday Pack for {$project->name}, week ending "
            . $pack->week_ending->format('d F Y') . ', is ready for you to download.'
        );
        $html .= EmailComponents::detailsTable([
            'Project'      => $project->name,
            'Week ending'  => $pack->week_ending->format('d F Y'),
        ]);
        $html .= EmailComponents::button('Download Friday Pack', $downloadUrl);
        $html .= EmailComponents::paragraph(
            'This link is unique to you and expires after ' . config('suresign.friday_pack_delivery_link_ttl_days', 7)
            . ' days. No SureSign account is required to view it.'
        );

        return $html;
    }

    public function plainText(FridayPackDelivery $delivery, string $downloadUrl): string
    {
        $pack = $delivery->fridayPack;
        $project = $delivery->project;
        $greetingName = $delivery->recipient_name ?: 'there';

        return "Hi {$greetingName},\n\n"
            . "The Friday Pack for {$project->name}, week ending " . $pack->week_ending->format('d F Y') . ", is ready for you to download.\n\n"
            . "Download: {$downloadUrl}\n\n"
            . 'This link is unique to you and expires after ' . config('suresign.friday_pack_delivery_link_ttl_days', 7) . " days. No SureSign account is required to view it.\n";
    }
}
