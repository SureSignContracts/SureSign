<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\SuresignSetting;
use App\Support\Email\EmailComponents;
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
     * @param array             $meta         Optional extra context. Communications
     *                                        Platform, Batch 4 gave this previously
     *                                        "reserved" (always-empty) parameter its
     *                                        first real use: `action_url` (and optional
     *                                        `action_label`, defaulting to "View in
     *                                        SureSign") renders a real CTA button —
     *                                        every existing caller still passes `[]`
     *                                        and is completely unaffected.
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

            // Organisation URL Branding, Phase 4 — "Open {Org} Workspace"
            // only for a genuine organisation-specific customer workspace
            // destination (a /app/... path under the app's own
            // frontend_url), never for an /admin/... path, a support
            // destination, or no URL at all — and never when the caller
            // asked for a specific, non-default label (e.g. "View
            // Variation"). actionMeta() ALWAYS sets action_label (its own
            // default is the literal string 'View in SureSign' when no
            // caller-supplied label was given), so `isset()` alone can't
            // tell "caller wants the default" from "caller wants
            // something custom" — only an EXACT match against that
            // literal default string can, which is what this checks.
            if ($organization && !empty($meta['action_url']) && ($meta['action_label'] ?? 'View in SureSign') === 'View in SureSign') {
                $frontendBase = rtrim(config('suresign.frontend_url'), '/');
                if (str_starts_with($meta['action_url'], $frontendBase . '/app/')) {
                    $branding = BrandingService::forOrganization($organization->id);
                    $meta['action_label'] = 'Open ' . BrandingService::displayName($branding, $organization) . ' Workspace';

                    // Organisation URL Branding, Phase 5 (Stage 4, Part E) —
                    // the relabelling above already existed, but the URL
                    // itself still pointed at the fixed host even for a
                    // branded organisation. Swap the fixed-host prefix for
                    // the organisation's own authoritative workspace base
                    // (active custom domain > branded slug > unchanged
                    // fixed-host fallback) via the one authoritative
                    // generator — never build a hostname here manually.
                    $relativePath = substr($meta['action_url'], strlen($frontendBase));
                    $meta['action_url'] = app(OrganisationUrlGenerator::class)
                        ->authenticatedWorkspaceBaseUrl($organization) . $relativePath;
                }
            }

            $bodyHtml = self::buildEventBodyHtml($bodyText, $meta);
            $html     = self::buildHtml($settings, $subject, $bodyHtml, self::categoryFromEvent($event));

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
                'textContent' => self::buildEventPlainText($bodyText, $meta),
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
     * Communications Platform, Batch 4 — builds send()'s optional `meta`
     * CTA payload from a relative in-app path (typically the exact same
     * one already computed for the paired in-app `NotificationService`
     * call — callers should reuse that value, not recompute it) and a
     * human label. Returns `[]` (no button rendered) when there's genuinely
     * nothing to link to, so callers can pass this straight through without
     * an extra null check of their own. Centralised here, rather than each
     * of the six commercial-notification controllers building its own
     * absolute-URL-prefixing helper.
     */
    public static function actionMeta(?string $relativeActionUrl, string $actionLabel = 'View in SureSign'): array
    {
        if (!$relativeActionUrl) {
            return [];
        }

        return [
            'action_url'   => rtrim(config('suresign.frontend_url'), '/') . $relativeActionUrl,
            'action_label' => $actionLabel,
        ];
    }

    /**
     * Send a one-off transactional email to a single, explicit recipient —
     * for account-level flows (password reset, email verification) that
     * aren't tied to an organization event or the notification_settings
     * toggle list that send() gates on.
     */
    /**
     * $attachments (optional): array of ['name' => string, 'content' => raw
     * (non-base64) bytes] — base64-encoded here, at the boundary, so every
     * caller passes plain content and never has to know Brevo's wire format.
     * Existing callers passing no third argument are entirely unaffected.
     *
     * $htmlBody (optional, Communications Upgrade Batch 1): pre-built HTML
     * to use as the card's body slot instead of `nl2br(e($bodyText))` — lets
     * a caller (e.g. ConsultationCommunicationService) render real buttons/
     * details tables via App\Support\Email\EmailComponents while `$bodyText`
     * remains the true plain-text alternative sent to Brevo's own
     * `textContent` field (a genuine multipart/alternative part, not merely
     * embedded inside the HTML — this was the gap: previously only
     * `htmlContent` was ever sent). Every existing caller omits both new
     * parameters and is completely unaffected — $htmlBody null falls back
     * to today's exact nl2br(e()) behaviour, and $sendPlainTextAlternative
     * defaults to false so `textContent` is only added where a caller
     * deliberately opts in (avoids silently changing Brevo's payload shape
     * for every existing email family in this same pass).
     */
    public static function sendDirect(
        string $toEmail,
        string $subject,
        string $bodyText,
        array $attachments = [],
        ?string $replyToEmail = null,
        string $category = 'Notification',
        ?string $htmlBody = null,
        bool $sendPlainTextAlternative = false,
    ): bool
    {
        return self::sendDirectWithMessageId(
            $toEmail, $subject, $bodyText, $attachments, $replyToEmail, $category, $htmlBody, $sendPlainTextAlternative,
        )['sent'];
    }

    /**
     * Communications Upgrade Batch 1 — the same delivery as sendDirect(),
     * additionally returning Brevo's own response `messageId` so a caller
     * that persists a delivery record (ConsultationCommunicationService) can
     * store it. Kept as a separate method (rather than changing sendDirect()'s
     * return type, which every existing caller checks as a plain bool) —
     * sendDirect() above is now a thin wrapper over this one, so there is
     * only one real implementation.
     *
     * @return array{sent: bool, provider_message_id: ?string}
     */
    public static function sendDirectWithMessageId(
        string $toEmail,
        string $subject,
        string $bodyText,
        array $attachments = [],
        ?string $replyToEmail = null,
        string $category = 'Notification',
        ?string $htmlBody = null,
        bool $sendPlainTextAlternative = false,
    ): array
    {
        try {
            $settings = SuresignSetting::instance();

            if (empty($settings->brevo_api_key)) {
                Log::warning("EmailNotificationService::sendDirectWithMessageId: no Brevo API key configured — skipping '{$subject}' to {$toEmail}");
                return ['sent' => false, 'provider_message_id' => null];
            }

            $bodyHtml = $htmlBody ?? nl2br(e($bodyText));
            $html     = self::buildHtml($settings, $subject, $bodyHtml, $category, $replyToEmail !== null);

            $payload = [
                'sender'      => [
                    'name'  => $settings->email_sender_name ?: 'SureSign Contracts',
                    'email' => $settings->email_sender_email ?: ($settings->email_reply_to ?: 'noreply@suresign.io'),
                ],
                'to'          => [['email' => trim($toEmail)]],
                'replyTo'     => [
                    'email' => $replyToEmail
                        ?: ($settings->email_reply_to ?: ($settings->email_sender_email ?: 'noreply@suresign.io')),
                ],
                'subject'     => $subject,
                'htmlContent' => $html,
            ];

            if ($sendPlainTextAlternative) {
                $payload['textContent'] = $bodyText;
            }

            if (!empty($attachments)) {
                $payload['attachment'] = array_map(
                    fn (array $a) => ['name' => $a['name'], 'content' => base64_encode($a['content'])],
                    $attachments,
                );
            }

            $response = Http::withHeaders([
                'api-key'      => $settings->brevo_api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', $payload);

            if (!$response->successful()) {
                Log::warning("EmailNotificationService::sendDirectWithMessageId: Brevo returned {$response->status()} for '{$subject}' to {$toEmail}", [
                    'body' => $response->body(),
                ]);
                return ['sent' => false, 'provider_message_id' => null];
            }

            return ['sent' => true, 'provider_message_id' => $response->json('messageId')];
        } catch (\Throwable $e) {
            Log::warning("EmailNotificationService::sendDirectWithMessageId: exception sending '{$subject}' to {$toEmail}: " . $e->getMessage());
            return ['sent' => false, 'provider_message_id' => null];
        }
    }

    /**
     * Communications Platform, Batch 4 — send()'s own HTML body, now built
     * from EmailComponents (a paragraph, plus a real button when the
     * caller supplies `meta.action_url`) rather than raw `nl2br(e())`.
     * `$bodyText` here is always a short, server-composed sentence (never
     * raw user free-text), so a single paragraph is the correct shape —
     * unlike Consultancy/Appointments, this method has no multi-line
     * details table to render.
     */
    private static function buildEventBodyHtml(string $bodyText, array $meta): string
    {
        $htmlParts = [EmailComponents::paragraph($bodyText)];

        if (!empty($meta['action_url'])) {
            $label = $meta['action_label'] ?? 'View in SureSign';
            $htmlParts[] = EmailComponents::button($label, $meta['action_url'], 'secondary');
        }

        return implode("\n", $htmlParts);
    }

    /**
     * The genuine plaintext alternative sent as Brevo's own `textContent`
     * — send() had none before this batch (every caller was HTML-only).
     */
    private static function buildEventPlainText(string $bodyText, array $meta): string
    {
        $lines = [$bodyText];

        if (!empty($meta['action_url'])) {
            $label = $meta['action_label'] ?? 'View in SureSign';
            $lines[] = '';
            $lines[] = "{$label}: {$meta['action_url']}";
        }

        return implode("\n", $lines);
    }

    /**
     * A short, human-readable category label derived from the event's own
     * namespace prefix (e.g. 'payment_application.submitted' →
     * "Payment Application") — send() previously always rendered the
     * generic "Notification" category, the one piece of Consultancy's own
     * visual language (a distinct eyebrow per family) this method didn't
     * yet have. A small explicit map covers the handful of prefixes that
     * don't humanize cleanly (abbreviations); every other prefix falls
     * through to the generic rule rather than requiring a map entry per
     * event.
     */
    private static function categoryFromEvent(string $event): string
    {
        $prefix = explode('.', $event, 2)[0];

        $known = [
            'eot'              => 'Extension of Time',
            'eot_request'      => 'Extension of Time',
            'loss_and_expense' => 'Loss & Expense',
            'ai_analysis'      => 'AI Analysis',
        ];

        return $known[$prefix] ?? ucwords(str_replace('_', ' ', $prefix));
    }

    private static function buildHtml(
        SuresignSetting $settings,
        string $subject,
        string $bodyHtml,
        string $category = 'Notification',
        bool $canReply = false,
    ): string
    {
        // Communications Platform, Batch 4 — $subject was previously
        // interpolated into <title>/<h1> unescaped, relying on every
        // individual caller to pre-escape it themselves whenever it might
        // carry user-controlled text. Only one caller ever did
        // (SupportTicketController, which escapes its own already, since
        // this method didn't) — DemoRequestController did not, and its
        // subject is built directly from a public, unauthenticated
        // marketing form field ('company'), a confirmed live HTML-injection
        // path. Escaping centrally here closes it for every caller,
        // present and future, rather than relying on each call site to
        // remember to. Callers that already escaped their own subject
        // (SupportTicketController) had that redundant local `e()` removed
        // in the same change, to avoid double-escaping.
        $subject = e($subject);
        $senderName = e($settings->email_sender_name ?: 'SureSign Contracts');
        $categoryLabel = e($category);
        $replyGuidance = $canReply
            ? 'Reply to this message to contact the sender.'
            : 'Please do not reply directly to this message.';

        $headerSection = $settings->email_header_url
            ? '<img src="' . e($settings->email_header_url) . '" style="width:100%;max-width:600px;display:block;" alt="' . $senderName . '" />'
            : '<table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr>'
              . '<td style="padding:28px 40px;background:#18211d;">'
              . '<table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr>'
              . '<td valign="middle"><span style="display:inline-block;width:34px;height:34px;line-height:34px;border-radius:10px;background:#9ee5b5;font-family:Arial,sans-serif;font-size:15px;font-weight:800;color:#18211d;text-align:center;vertical-align:middle;">S</span>'
              . '<span style="display:inline-block;margin-left:12px;font-family:Arial,Helvetica,sans-serif;font-size:19px;font-weight:700;color:#ffffff;letter-spacing:-0.2px;vertical-align:middle;">' . $senderName . '</span></td>'
              . '<td align="right" valign="middle" style="font-family:Arial,sans-serif;font-size:10px;font-weight:700;color:#9ee5b5;letter-spacing:1.3px;text-transform:uppercase;">Contract clarity</td>'
              . '</tr></table>'
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
<body style="margin:0;padding:0;background:#edf1ee;font-family:Arial,Helvetica,sans-serif;-webkit-font-smoothing:antialiased;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{$subject}</div>
  <!--[if mso]><center><table width="600"><tr><td><![endif]-->
  <table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation" style="background:#edf1ee;padding:40px 0 48px;">
    <tr><td align="center" style="padding:0 16px;">

      <!-- Card -->
      <table width="600" cellpadding="0" cellspacing="0" border="0" role="presentation" style="max-width:600px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 18px 45px rgba(24,33,29,0.10);border:1px solid #dfe6e1;">

        <!-- Header -->
        <tr><td style="padding:0;">{$headerSection}</td></tr>

        <!-- Message identity -->
        <tr>
          <td style="padding:38px 44px 0;">
            <table cellpadding="0" cellspacing="0" border="0" role="presentation"><tr>
              <td style="padding:6px 10px;border-radius:8px;background:#eaf8ee;font-family:Arial,sans-serif;font-size:10px;font-weight:700;color:#28613b;letter-spacing:1.3px;text-transform:uppercase;">{$categoryLabel}</td>
            </tr></table>
            <h1 style="margin:18px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:27px;font-weight:700;color:#18211d;line-height:1.25;letter-spacing:-0.5px;">{$subject}</h1>
            <p style="margin:12px 0 0;font-family:Arial,sans-serif;font-size:12px;color:#7b8880;line-height:1.5;">A secure update from your SureSign workspace</p>
          </td>
        </tr>

        <!-- Accent rule -->
        <tr>
          <td style="padding:24px 44px 0;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr>
              <td width="48" style="border-top:3px solid #9ee5b5;font-size:0;line-height:0;">&nbsp;</td>
              <td style="border-top:1px solid #e3e9e5;font-size:0;line-height:0;">&nbsp;</td>
            </tr></table>
          </td>
        </tr>

        <!-- Body -->
        <tr>
          <td style="padding:28px 44px 40px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.72;color:#46524c;">
            {$bodyHtml}
          </td>
        </tr>

        <!-- Footer image (if set) -->
        {$footerSection}

        <!-- Footer bar -->
        <tr>
          <td style="padding:24px 40px;background:#18211d;">
            <table width="100%" cellpadding="0" cellspacing="0">
              <tr>
                <td style="font-family:Arial,sans-serif;font-size:11px;color:#aebbb4;line-height:1.6;">
                  Sent securely by <span style="color:#ffffff;font-weight:600;">{$senderName}</span>.<br>
                  {$replyGuidance}
                </td>
                <td align="right" style="font-family:Arial,sans-serif;font-size:11px;color:#829087;white-space:nowrap;">
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
