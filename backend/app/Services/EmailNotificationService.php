<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SuresignSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailNotificationService
{
    /**
     * Send a notification email if the event is enabled in notification_settings.
     *
     * Recipients are resolved from the event's organization (its own contact
     * email plus every user with the 'Client' role) and the platform's admin
     * oversight address — never from email_sender_email/email_reply_to, which
     * configure the outgoing "From"/"Reply-To" headers, not who receives it.
     *
     * @param string            $event        e.g. 'payment_application.submitted'
     * @param string            $subject      Email subject line
     * @param string            $bodyText     Plain-text body (converted to HTML)
     * @param array             $meta         Optional extra context (reserved)
     * @param Organization|null $organization The organization this event belongs to
     */
    public static function send(string $event, string $subject, string $bodyText, array $meta = [], ?Organization $organization = null): void
    {
        try {
            $settings = SuresignSetting::instance();

            // Check if this event is enabled
            $enabledEvents = $settings->notification_settings ?? [];
            if (!in_array($event, $enabledEvents, true)) {
                return;
            }

            if (empty($settings->brevo_api_key)) {
                Log::warning("EmailNotificationService: no Brevo API key configured — skipping event '{$event}'");
                return;
            }

            $recipients = [];

            // Platform admin oversight address — sees every notification.
            if (!empty($settings->admin_email)) {
                $recipients[] = $settings->admin_email;
            }

            if ($organization) {
                // The organization's own contact email.
                if (!empty($organization->email)) {
                    $recipients[] = $organization->email;
                }

                // Every user on that organization with the 'Client' role (the client owner(s)).
                $clientEmails = $organization->users()
                    ->whereHas('roles', fn ($q) => $q->where('name', 'Client'))
                    ->pluck('email')
                    ->filter()
                    ->all();
                array_push($recipients, ...$clientEmails);
            }

            // Stray whitespace from a pasted address is enough for a mail server to
            // silently reject the message even though Brevo's API accepts the call.
            $recipients = array_values(array_unique(array_filter(array_map('trim', $recipients))));

            if (empty($recipients)) {
                Log::warning("EmailNotificationService: no recipient email resolved — skipping event '{$event}'");
                return;
            }

            $bodyHtml = nl2br(e($bodyText));
            $html     = self::buildHtml($settings, $subject, $bodyHtml);

            $toList = array_map(fn($email) => ['email' => $email], $recipients);

            $response = Http::withHeaders([
                'api-key'      => $settings->brevo_api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender'      => [
                    'name'  => $settings->email_sender_name ?: 'SureSign Contracts',
                    'email' => $settings->email_sender_email ?: ($settings->email_reply_to ?: 'noreply@suresign.io'),
                ],
                'to'          => $toList,
                'replyTo'     => ['email' => $settings->email_reply_to ?: ($settings->email_sender_email ?: 'noreply@suresign.io')],
                'subject'     => $subject,
                'htmlContent' => $html,
            ]);

            if (!$response->successful()) {
                Log::warning("EmailNotificationService: Brevo returned {$response->status()} for event '{$event}'", [
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("EmailNotificationService: exception sending event '{$event}': " . $e->getMessage());
        }
    }

    /**
     * Send a one-off transactional email to a single, explicit recipient —
     * for account-level flows (password reset, email verification) that
     * aren't tied to an organization event or the notification_settings
     * toggle list that send() gates on.
     */
    public static function sendDirect(string $toEmail, string $subject, string $bodyText): bool
    {
        try {
            $settings = SuresignSetting::instance();

            if (empty($settings->brevo_api_key)) {
                Log::warning("EmailNotificationService::sendDirect: no Brevo API key configured — skipping '{$subject}' to {$toEmail}");
                return false;
            }

            $bodyHtml = nl2br(e($bodyText));
            $html     = self::buildHtml($settings, $subject, $bodyHtml);

            $response = Http::withHeaders([
                'api-key'      => $settings->brevo_api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender'      => [
                    'name'  => $settings->email_sender_name ?: 'SureSign Contracts',
                    'email' => $settings->email_sender_email ?: ($settings->email_reply_to ?: 'noreply@suresign.io'),
                ],
                'to'          => [['email' => trim($toEmail)]],
                'replyTo'     => ['email' => $settings->email_reply_to ?: ($settings->email_sender_email ?: 'noreply@suresign.io')],
                'subject'     => $subject,
                'htmlContent' => $html,
            ]);

            if (!$response->successful()) {
                Log::warning("EmailNotificationService::sendDirect: Brevo returned {$response->status()} for '{$subject}' to {$toEmail}", [
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning("EmailNotificationService::sendDirect: exception sending '{$subject}' to {$toEmail}: " . $e->getMessage());
            return false;
        }
    }

    private static function buildHtml(SuresignSetting $settings, string $subject, string $bodyHtml): string
    {
        $senderName = e($settings->email_sender_name ?: 'SureSign Contracts');

        $headerSection = $settings->email_header_url
            ? '<img src="' . e($settings->email_header_url) . '" style="width:100%;max-width:600px;display:block;" alt="' . $senderName . '" />'
            : '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
              . '<td style="padding:28px 40px 24px;background:#0d0d0d;">'
              . '<span style="font-family:Georgia,serif;font-size:22px;font-weight:700;color:#c9a84c;letter-spacing:0.5px;">' . $senderName . '</span>'
              . '<span style="display:block;font-family:Arial,sans-serif;font-size:10px;font-weight:600;color:#888888;letter-spacing:2px;text-transform:uppercase;margin-top:3px;">Construction Contract Administration</span>'
              . '</td></tr></table>';

        $footerSection = $settings->email_footer_url
            ? '<img src="' . e($settings->email_footer_url) . '" style="width:100%;max-width:600px;display:block;" alt="Footer" />'
            : '';

        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:0;background:#f0ede8;font-family:Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased;">
  <!--[if mso]><center><table width="600"><tr><td><![endif]-->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0ede8;padding:32px 0 40px;">
    <tr><td align="center" style="padding:0 16px;">

      <!-- Card -->
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:4px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

        <!-- Header -->
        <tr><td style="padding:0;">{$headerSection}</td></tr>

        <!-- Gold rule -->
        <tr><td style="background:#c9a84c;height:3px;font-size:0;line-height:0;">&nbsp;</td></tr>

        <!-- Subject bar -->
        <tr>
          <td style="padding:24px 40px 0;">
            <p style="margin:0;font-family:Arial,sans-serif;font-size:11px;font-weight:700;color:#c9a84c;letter-spacing:2px;text-transform:uppercase;">Notification</p>
            <h1 style="margin:6px 0 0;font-family:Georgia,serif;font-size:20px;font-weight:400;color:#1a1a1a;line-height:1.3;">{$subject}</h1>
          </td>
        </tr>

        <!-- Divider -->
        <tr>
          <td style="padding:20px 40px 0;">
            <table width="100%" cellpadding="0" cellspacing="0"><tr><td style="border-top:1px solid #e8e4de;font-size:0;">&nbsp;</td></tr></table>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:24px 40px 32px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#3a3a3a;">
            {$bodyHtml}
          </td>
        </tr>

        <!-- Footer image (if set) -->
        {$footerSection}

        <!-- Footer bar -->
        <tr>
          <td style="padding:20px 40px;background:#1a1a1a;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="font-family:Arial,sans-serif;font-size:11px;color:#666666;line-height:1.5;">
                  This notification was sent by <span style="color:#c9a84c;">{$senderName}</span>.<br>
                  Please do not reply directly to this message.
                </td>
                <td align="right" style="font-family:Arial,sans-serif;font-size:11px;color:#444444;white-space:nowrap;">
                  &copy; {$year} {$senderName}
                </td>
              </tr>
            </table>
          </td>
        </tr>

      </table>
      <!-- / Card -->

    </td></tr>
  </table>
  <!--[if mso]></td></tr></table></center><![endif]-->
</body>
</html>
HTML;
    }
}
