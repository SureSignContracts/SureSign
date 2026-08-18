<?php

namespace App\Support\Email;

/**
 * Consultancy Communications & Global Email Experience Upgrade, Batch 1 —
 * reusable, table-based HTML fragment builders consumed as the `$bodyHtml`
 * passed into the existing `App\Services\EmailNotificationService::buildHtml()`
 * card wrapper (header/footer/branding), which is deliberately left
 * untouched. These are plain static string builders, not a template engine —
 * matching this codebase's existing hand-rolled-HTML convention (see
 * `EmailNotificationService::buildHtml()`, `AppointmentIcsService`) rather
 * than introducing Blade views or a third-party email framework.
 *
 * Every method escapes untrusted content itself (customer names, service
 * titles, enquiry text can all reach here) — callers must never
 * pre-escape and must never pass raw HTML through as "safe".
 *
 * Button hierarchy (approved): primary CTA gets the SureSign mint fill;
 * secondary gets an outlined treatment; tertiary actions (reschedule/
 * cancel) are plain underlined text links, deliberately never styled as
 * buttons, so a destructive/lower-priority action never competes visually
 * with the primary CTA.
 *
 * Theme: forest ink, quiet mineral neutrals and one mint brand accent.
 * Semantic status colours remain reserved for actual status information.
 */
class EmailComponents
{
    private const ACCENT = '#9ee5b5';
    private const INK    = '#18211d';
    private const CONTACT_URL = 'https://suresigncontracts.app/contact';

    /**
     * @param  string  $variant  'primary' | 'secondary'
     */
    public static function button(string $label, string $url, string $variant = 'primary'): string
    {
        $label = e($label);
        $url   = e($url);

        $isPrimary = $variant === 'primary';
        $bg     = $isPrimary ? self::ACCENT : '#ffffff';
        $color  = self::INK;
        $border = $isPrimary ? self::ACCENT : '#cfd8d2';

        // Table-based "bulletproof button" pattern — renders correctly
        // across Outlook/Gmail/Apple Mail without relying on unsupported
        // CSS (padding on <a> is inconsistent in Outlook's Word rendering
        // engine, hence the table cell carrying the padding instead).
        return <<<HTML
<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:22px 0 8px;">
  <tr>
    <td align="center" bgcolor="{$bg}" style="border-radius:10px;border:1px solid {$border};box-shadow:0 8px 18px rgba(24,33,29,0.10);">
      <a href="{$url}" target="_blank" rel="noopener noreferrer"
         style="display:inline-block;padding:14px 24px;font-family:Arial,Helvetica,sans-serif;font-size:14px;font-weight:700;color:{$color};text-decoration:none;min-width:150px;text-align:center;letter-spacing:0.1px;">
        {$label}
      </a>
    </td>
  </tr>
</table>
HTML;
    }

    /**
     * Tertiary, plain-text action links (Reschedule / Cancel) — deliberately
     * NOT rendered as buttons, so they never visually compete with a
     * primary/secondary CTA above them.
     *
     * @param  array<int, array{label: string, url: string}>  $actions
     */
    public static function textActions(array $actions): string
    {
        if (empty($actions)) {
            return '';
        }

        $links = array_map(
            fn (array $a) => '<a href="' . e($a['url']) . '" style="color:#53615a;text-decoration:underline;font-family:Arial,sans-serif;font-size:13px;">' . e($a['label']) . '</a>',
            $actions,
        );

        return '<p style="margin:16px 0 0;font-size:13px;">' . implode('&nbsp;&nbsp;|&nbsp;&nbsp;', $links) . '</p>';
    }

    /**
     * A labelled key/value details block (reference, date, time, timezone,
     * duration, etc.) — one row per fact, rendered as a simple two-column
     * table for consistent alignment across email clients.
     *
     * @param  array<string, string>  $rows  label => value, insertion order preserved
     */
    public static function detailsTable(array $rows): string
    {
        $tr = '';
        foreach ($rows as $label => $value) {
            $tr .= '<tr>'
                . '<td style="padding:8px 14px 8px 0;font-family:Arial,sans-serif;font-size:12px;color:#748078;white-space:nowrap;vertical-align:top;">' . e($label) . '</td>'
                . '<td style="padding:8px 0;font-family:Arial,sans-serif;font-size:14px;color:' . self::INK . ';font-weight:600;">' . e($value) . '</td>'
                . '</tr>';
        }

        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:8px 0 4px;border-collapse:collapse;">' . $tr . '</table>';
    }

    /**
     * A single-line status notice — used for the customer-safe Meet-pending/
     * available/cancelled wording. `$tone` only changes the accent bar
     * colour; never relies on colour alone to convey meaning (the text
     * itself always states the status in words).
     *
     * @param  string  $tone  'info' | 'success' | 'muted'
     */
    public static function statusCallout(string $text, string $tone = 'info'): string
    {
        $accent = match ($tone) {
            'success' => '#2e7d32',
            'muted'   => '#8a8a8a',
            default   => self::ACCENT,
        };

        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:18px 0;background:#f3f7f4;border-left:3px solid ' . $accent . ';border-radius:0 10px 10px 0;">'
            . '<tr><td style="padding:14px 16px;font-family:Arial,sans-serif;font-size:14px;color:' . self::INK . ';line-height:1.6;">' . e($text) . '</td></tr>'
            . '</table>';
    }

    public static function paragraph(string $text): string
    {
        return '<p style="margin:0 0 15px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.72;color:#46524c;">' . e($text) . '</p>';
    }

    public static function heading(string $text): string
    {
        return '<p style="margin:0 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#46524c;">' . e($text) . '</p>';
    }

    public static function supportBlock(?string $supportEmail): string
    {
        $url = e(self::CONTACT_URL);

        // Deliberately compact and outlined: this is a supporting action,
        // not a second primary CTA competing with Reset Password / Verify.
        return <<<HTML
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;border-top:1px solid #e3e9e5;">
  <tr>
    <td style="padding-top:18px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.6;color:#748078;">
      <span style="display:inline-block;margin:0 10px 8px 0;vertical-align:middle;">Questions about your account?</span>
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="display:inline-table;vertical-align:middle;margin:0 0 8px;">
        <tr>
          <td align="center" bgcolor="#ffffff" style="border:1px solid #cfd8d2;border-radius:8px;">
            <a href="{$url}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:8px 13px;font-family:Arial,Helvetica,sans-serif;font-size:12px;font-weight:700;line-height:1;color:#18211d;text-decoration:none;white-space:nowrap;">Contact us</a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Batch 3 (Consultancy Communications & Global Email Experience
    // Upgrade) — additive, premium-tier components for the two new emails
    // this batch introduces (follow-up, summary-published). The existing
    // components above are deliberately left untouched and are still used
    // as-is (button(), paragraph(), supportBlock()) — this batch is not a
    // redesign of the five existing templates, and reusing rather than
    // forking the button/paragraph primitives is exactly what lets Batch 4
    // migrate those templates onto this same visual language later without
    // a second button style to reconcile.
    //
    // The design intent here is the opposite of detailsTable()/statusCallout()
    // above: no visible table borders, no coloured/bordered box — just
    // generous vertical rhythm, a muted small-caps label over a plain value,
    // and hairline dividers instead of boxes. This is the "Stripe receipt"
    // register the redesign brief asked for, kept deliberately spare so it
    // reads as premium rather than templated.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A single thin full-width rule with generous vertical margin —
     * replaces a boxed section break with the smallest possible visual
     * device, matching the "plenty of whitespace, no unnecessary boxes"
     * brief.
     */
    public static function hairline(): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0;"><tr><td style="border-top:1px solid #e3e9e5;font-size:0;line-height:0;">&nbsp;</td></tr></table>';
    }

    /**
     * The premium counterpart to detailsTable() — one row per fact, each
     * rendered as a muted uppercase label over a plain, larger value
     * (never side-by-side, never a visible table border), separated by
     * hairline dividers rather than boxed cells. Intended for a short list
     * of facts (3–5 rows) on a summary/follow-up email, not a dense grid.
     *
     * @param  array<string, string>  $rows  label => value, insertion order preserved
     */
    public static function meta(array $rows): string
    {
        $cells = [];
        $count = count($rows);
        $i = 0;
        foreach ($rows as $label => $value) {
            $i++;
            $borderStyle = $i < $count ? 'border-bottom:1px solid #e3e9e5;' : '';
            $cells[] = '<tr><td style="padding:14px 0;' . $borderStyle . '">'
                . '<p style="margin:0;font-family:Arial,sans-serif;font-size:10px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#7b8880;">' . e($label) . '</p>'
                . '<p style="margin:5px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:600;color:' . self::INK . ';">' . e($value) . '</p>'
                . '</td></tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0;border-collapse:collapse;">' . implode('', $cells) . '</table>';
    }

    /**
     * A quiet, low-emphasis note — no background fill, no coloured border
     * (unlike statusCallout(), which is deliberately kept for the existing
     * templates' pending/available Meet wording). Used for supporting
     * context that shouldn't visually compete with the primary message
     * (e.g. "you'll receive a written summary within a few days").
     */
    public static function quietNote(string $text): string
    {
        return '<p style="margin:18px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.7;color:#748078;">' . e($text) . '</p>';
    }
}
