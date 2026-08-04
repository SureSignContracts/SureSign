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
 * Button hierarchy (approved): primary CTA gets solid black fill;
 * secondary gets an outlined treatment; tertiary actions (reschedule/
 * cancel) are plain underlined text links, deliberately never styled as
 * buttons, so a destructive/lower-priority action never competes visually
 * with the primary CTA.
 *
 * Theme (revised, post Communications Platform Batch 4): black/white/grey
 * only — no brand-gold accent anywhere in this file or in
 * `EmailNotificationService::buildHtml()`'s wrapper. `success`/`muted`
 * status tones are the one deliberate exception (a semantic colour, not a
 * brand accent) — green still means "available/good news" the way it
 * would in any product UI; nothing else in the system uses colour to
 * carry meaning.
 */
class EmailComponents
{
    private const ACCENT = '#111111';
    private const INK    = '#1a1a1a';

    /**
     * @param  string  $variant  'primary' | 'secondary'
     */
    public static function button(string $label, string $url, string $variant = 'primary'): string
    {
        $label = e($label);
        $url   = e($url);

        $isPrimary = $variant === 'primary';
        $bg     = $isPrimary ? self::ACCENT : '#ffffff';
        $color  = $isPrimary ? '#ffffff' : self::INK;
        $border = $isPrimary ? self::ACCENT : '#d4d4d4';

        // Table-based "bulletproof button" pattern — renders correctly
        // across Outlook/Gmail/Apple Mail without relying on unsupported
        // CSS (padding on <a> is inconsistent in Outlook's Word rendering
        // engine, hence the table cell carrying the padding instead).
        return <<<HTML
<table cellpadding="0" cellspacing="0" border="0" role="presentation" style="margin:4px 0;">
  <tr>
    <td align="center" bgcolor="{$bg}" style="border-radius:4px;border:1px solid {$border};">
      <a href="{$url}" target="_blank" rel="noopener noreferrer"
         style="display:inline-block;padding:13px 28px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;color:{$color};text-decoration:none;min-width:120px;text-align:center;">
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
            fn (array $a) => '<a href="' . e($a['url']) . '" style="color:#6b6b6b;text-decoration:underline;font-family:Arial,sans-serif;font-size:13px;">' . e($a['label']) . '</a>',
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
                . '<td style="padding:6px 12px 6px 0;font-family:Arial,sans-serif;font-size:13px;color:#8a8a8a;white-space:nowrap;vertical-align:top;">' . e($label) . '</td>'
                . '<td style="padding:6px 0;font-family:Arial,sans-serif;font-size:14px;color:' . self::INK . ';font-weight:600;">' . e($value) . '</td>'
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

        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:16px 0;background:#f5f5f5;border-left:3px solid ' . $accent . ';">'
            . '<tr><td style="padding:12px 16px;font-family:Arial,sans-serif;font-size:14px;color:' . self::INK . ';line-height:1.5;">' . e($text) . '</td></tr>'
            . '</table>';
    }

    public static function paragraph(string $text): string
    {
        return '<p style="margin:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#3a3a3a;">' . e($text) . '</p>';
    }

    public static function heading(string $text): string
    {
        return '<p style="margin:0 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.7;color:#3a3a3a;">' . e($text) . '</p>';
    }

    public static function supportBlock(?string $supportEmail): string
    {
        $text = $supportEmail
            ? "Questions? Contact us at {$supportEmail}."
            : 'Questions? Please get in touch with us.';

        return '<p style="margin:20px 0 0;font-family:Arial,sans-serif;font-size:13px;color:#8a8a8a;">' . e($text) . '</p>';
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
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:28px 0;"><tr><td style="border-top:1px solid #ececec;font-size:0;line-height:0;">&nbsp;</td></tr></table>';
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
            $borderStyle = $i < $count ? 'border-bottom:1px solid #eeeeee;' : '';
            $cells[] = '<tr><td style="padding:14px 0;' . $borderStyle . '">'
                . '<p style="margin:0;font-family:Arial,sans-serif;font-size:11px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#a3a3a3;">' . e($label) . '</p>'
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
        return '<p style="margin:16px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#7a7a7a;">' . e($text) . '</p>';
    }
}
