<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SuresignSetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SuresignSettingController extends Controller
{
    // ─── GET /settings (public — all authenticated users) ────────────────────

    public function publicShow()
    {
        $settings = SuresignSetting::instance();
        return response()->json([
            'data' => [
                'currency'        => $settings->currency        ?? 'GBP',
                'currency_symbol' => $settings->currency_symbol ?? '£',
                'date_format'     => $settings->date_format     ?? 'DD/MM/YYYY',
                'timezone'        => $settings->timezone        ?? 'Europe/London',
                'hidden_pages'    => $settings->hidden_pages    ?? [],
            ],
        ]);
    }

    // ─── GET /admin/suresign-settings ────────────────────────────────────────

    public function show()
    {
        $settings = SuresignSetting::instance();

        // Expose brevo_api_key presence (but not the value) for the UI
        $data                    = $settings->toArray();
        $data['has_brevo_key']   = !empty($settings->brevo_api_key);
        $data['brevo_api_key']   = $settings->brevo_api_key ? '••••••••' : '';

        return response()->json(['data' => $data]);
    }

    // ─── PUT /admin/suresign-settings ─────────────────────────────────────────

    public function update(Request $request)
    {
        $validated = $request->validate([
            'email_sender_email'  => 'nullable|email|max:255',
            'email_sender_name'   => 'nullable|string|max:255',
            'email_reply_to'      => 'nullable|email|max:255',
            'email_subject_line'  => 'nullable|string|max:500',
            'email_body_template' => 'nullable|string',
            'brevo_api_key'       => 'nullable|string|max:500',
            'currency'            => 'nullable|string|max:10',
            'currency_symbol'     => 'nullable|string|max:10',
            'date_format'         => 'nullable|string|max:50',
            'timezone'            => 'nullable|string|max:100',
        ]);

        $settings = SuresignSetting::instance();

        // Only update brevo_api_key if a real (unmasked) value is provided
        if (isset($validated['brevo_api_key'])) {
            $key = trim($validated['brevo_api_key']);
            if (empty($key) || preg_match('/^[\x{2022}•]+$/u', $key)) {
                unset($validated['brevo_api_key']);
            } else {
                $validated['brevo_api_key'] = $key;
            }
        }

        $settings->update($validated);

        return response()->json(['data' => $settings->fresh()->toArray(), 'message' => 'Settings saved.']);
    }

    // ─── PUT /admin/suresign-settings/branding ────────────────────────────────

    public function updateBranding(Request $request)
    {
        // Currently branding is upload-only; placeholder for future text fields.
        return response()->json(['message' => 'Branding saved.']);
    }

    // ─── PUT /admin/suresign-settings/document ────────────────────────────────

    public function updateDocument(Request $request)
    {
        return response()->json(['message' => 'Document settings saved.']);
    }

    // ─── PUT /admin/suresign-settings/email ──────────────────────────────────

    public function updateEmail(Request $request)
    {
        $validated = $request->validate([
            'email_sender_email'  => 'nullable|email|max:255',
            'email_sender_name'   => 'nullable|string|max:255',
            'email_reply_to'      => 'nullable|email|max:255',
            'email_subject_line'  => 'nullable|string|max:500',
            'email_body_template' => 'nullable|string',
            'brevo_api_key'       => 'nullable|string|max:500',
        ]);

        $settings = SuresignSetting::instance();

        if (isset($validated['brevo_api_key'])) {
            $key = trim($validated['brevo_api_key']);
            if (empty($key) || preg_match('/^[\x{2022}•]+$/u', $key)) {
                unset($validated['brevo_api_key']);
            } else {
                $validated['brevo_api_key'] = $key;
            }
        }

        $settings->update($validated);

        return response()->json(['message' => 'Email settings saved.']);
    }

    // ─── PUT /admin/suresign-settings/site ───────────────────────────────────

    public function updateSite(Request $request)
    {
        $validated = $request->validate([
            'currency'        => 'nullable|string|max:10',
            'currency_symbol' => 'nullable|string|max:10',
            'date_format'     => 'nullable|string|max:50',
            'timezone'        => 'nullable|string|max:100',
            'hidden_pages'    => 'nullable|array',
            'hidden_pages.*'  => 'string|max:100',
        ]);

        $settings = SuresignSetting::instance();
        $settings->update($validated);

        return response()->json(['message' => 'Site settings saved.']);
    }

    // ─── File uploads ─────────────────────────────────────────────────────────

    public function uploadLogo(Request $request)
    {
        $request->validate(['logo' => 'required|image|max:5120']);
        $path = $request->file('logo')->store('suresign/branding', 'public');
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->logo_path);
        $settings->update(['logo_path' => $path]);
        return response()->json(['data' => ['logo_url' => Storage::disk('public')->url($path)]]);
    }

    public function uploadLetterheadHeader(Request $request)
    {
        $request->validate(['header' => 'required|image|max:10240']);
        $path = $request->file('header')->store('suresign/letterhead', 'public');
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->letterhead_header_path);
        $settings->update(['letterhead_header_path' => $path]);
        return response()->json(['data' => ['letterhead_header_url' => Storage::disk('public')->url($path)]]);
    }

    public function uploadLetterheadFooter(Request $request)
    {
        $request->validate(['footer' => 'required|image|max:10240']);
        $path = $request->file('footer')->store('suresign/letterhead', 'public');
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->letterhead_footer_path);
        $settings->update(['letterhead_footer_path' => $path]);
        return response()->json(['data' => ['letterhead_footer_url' => Storage::disk('public')->url($path)]]);
    }

    public function uploadLetterheadPdf(Request $request)
    {
        $request->validate(['pdf' => 'required|mimes:pdf|max:10240']);
        $path = $request->file('pdf')->store('suresign/letterhead', 'public');
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->letterhead_pdf_path);
        $settings->update(['letterhead_pdf_path' => $path]);
        return response()->json(['data' => ['letterhead_pdf_url' => Storage::disk('public')->url($path)]]);
    }

    public function uploadEmailHeader(Request $request)
    {
        $request->validate(['header' => 'required|image|max:5120']);
        $path = $request->file('header')->store('suresign/email', 'public');
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->email_header_path);
        $settings->update(['email_header_path' => $path]);
        return response()->json(['data' => ['email_header_url' => Storage::disk('public')->url($path)]]);
    }

    public function uploadEmailFooter(Request $request)
    {
        $request->validate(['footer' => 'required|image|max:5120']);
        $path = $request->file('footer')->store('suresign/email', 'public');
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->email_footer_path);
        $settings->update(['email_footer_path' => $path]);
        return response()->json(['data' => ['email_footer_url' => Storage::disk('public')->url($path)]]);
    }

    // ─── Remove asset files ───────────────────────────────────────────────────

    public function removeLogo()
    {
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->logo_path);
        $settings->update(['logo_path' => null]);
        return response()->json(['data' => ['logo_url' => null]]);
    }

    public function removeLetterheadHeader()
    {
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->letterhead_header_path);
        $settings->update(['letterhead_header_path' => null]);
        return response()->json(['data' => ['letterhead_header_url' => null]]);
    }

    public function removeLetterheadFooter()
    {
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->letterhead_footer_path);
        $settings->update(['letterhead_footer_path' => null]);
        return response()->json(['data' => ['letterhead_footer_url' => null]]);
    }

    public function removeLetterheadPdf()
    {
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->letterhead_pdf_path);
        $settings->update(['letterhead_pdf_path' => null]);
        return response()->json(['data' => ['letterhead_pdf_url' => null]]);
    }

    public function removeEmailHeader()
    {
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->email_header_path);
        $settings->update(['email_header_path' => null]);
        return response()->json(['data' => ['email_header_url' => null]]);
    }

    public function removeEmailFooter()
    {
        $settings = SuresignSetting::instance();
        $this->deleteOld($settings->email_footer_path);
        $settings->update(['email_footer_path' => null]);
        return response()->json(['data' => ['email_footer_url' => null]]);
    }

    // ─── Test PDF ─────────────────────────────────────────────────────────────

    public function testPdf()
    {
        $settings = SuresignSetting::instance();

        // Resolve absolute paths for canvas drawing (not used in the HTML template)
        $headerAbsPath = $this->resolveAbsPath($settings->letterhead_header_path);
        $footerAbsPath = $this->resolveAbsPath($settings->letterhead_footer_path);

        $html = view('pdf.letterhead-test', [
            'settings'       => $settings,
            'hasHeader'      => (bool) $headerAbsPath,
            'hasFooter'      => (bool) $footerAbsPath,
        ])->render();

        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled'      => false,
                'defaultFont'          => 'DejaVu Sans',
            ]);

        // Render first, then draw header/footer images directly onto the canvas.
        // This is more reliable than CSS position:fixed with negative top values.
        $pdf->render();
        $canvas = $pdf->getDomPDF()->getCanvas();
        $pageW  = $canvas->get_width();
        $pageH  = $canvas->get_height();

        // @page margin-top:145px and margin-bottom:110px are set in the template.
        // Convert CSS pixels (96 dpi) → PDF points (72 dpi) for canvas coordinates.
        $headerH = 145 * (72 / 96); // ≈ 108.75 pts
        $footerH = 110 * (72 / 96); // ≈ 82.50 pts

        if ($headerAbsPath || $footerAbsPath) {
            $canvas->page_script(function (int $pageNum, int $pageCount, $canvas) use (
                $headerAbsPath, $footerAbsPath, $pageW, $pageH, $headerH, $footerH
            ) {
                if ($headerAbsPath) {
                    $canvas->image($headerAbsPath, 0, 0, $pageW, $headerH);
                }
                if ($footerAbsPath) {
                    $canvas->image($footerAbsPath, 0, $pageH - $footerH, $pageW, $footerH);
                }
            });
        }

        return $pdf->download('suresign-letterhead-test.pdf');
    }

    // ─── Test Email ───────────────────────────────────────────────────────────

    public function testEmail(Request $request)
    {
        $request->validate(['to' => 'required|email']);

        $settings = SuresignSetting::instance();

        if (empty($settings->brevo_api_key)) {
            return response()->json(['message' => 'No Brevo API key configured.'], 422);
        }

        $subject = $settings->email_subject_line ?: 'SureSign — Test Email';
        $body    = $settings->email_body_template
            ?: "Dear Test User,\n\nThis is a test email from SureSign.\n\nKind regards,\nThe SureSign Team";

        // Replace template placeholders with sample data
        $bodyHtml = nl2br(e(str_replace(
            ['{{recipient_name}}', '{{document_name}}', '{{company_name}}', '{{sign_link}}'],
            ['Test User',          'Sample Document',   'SureSign',          '#test-link'],
            $body
        )));

        $emailHtml = $this->buildEmailHtml($settings, $subject, $bodyHtml);

        try {
            $response = Http::withHeaders([
                'api-key'      => $settings->brevo_api_key,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])->post('https://api.brevo.com/v3/smtp/email', [
                'sender'      => [
                    'name'  => $settings->email_sender_name ?: 'SureSign',
                    'email' => $settings->email_sender_email ?: ($settings->email_reply_to ?: 'noreply@suresign.io'),
                ],
                'to'          => [['email' => $request->to]],
                'replyTo'     => ['email' => $settings->email_reply_to ?: ($settings->email_sender_email ?: 'noreply@suresign.io')],
                'subject'     => '[TEST] ' . $subject,
                'htmlContent' => $emailHtml,
            ]);

            if ($response->successful()) {
                $messageId = $response->json('messageId') ?? null;
                $msg = 'Test email sent to ' . $request->to . '.';
                if ($messageId) {
                    $msg .= ' Brevo message ID: ' . $messageId;
                }
                $msg .= ' If it doesn\'t arrive within a minute, check your spam/junk folder and verify your sender domain in Brevo (Senders & IP → Domains).';
                return response()->json(['message' => $msg, 'messageId' => $messageId]);
            }

            Log::warning('Brevo test email failed', ['status' => $response->status(), 'body' => $response->body()]);

            $brevoBody = $response->json();
            $brevoCode = $brevoBody['code'] ?? '';
            $status    = $response->status();

            if ($status === 401 || $brevoCode === 'unauthorized') {
                // 'Key not found' means the key was deleted/revoked in Brevo, not that the format is wrong
                $detail = ($brevoBody['message'] ?? '') === 'Key not found'
                    ? 'The API key was not found in Brevo — it may have been deleted or revoked. Please go to app.brevo.com → Settings → API Keys, delete the old key, create a new one, and paste it here.'
                    : 'Brevo API key is invalid or unauthorized. Go to app.brevo.com → Settings → API Keys and copy a v3 key (starts with xkeysib-).';
                $userMsg = $detail;
            } elseif ($status === 404 || $brevoCode === 'not_found') {
                $userMsg = 'Brevo API key not found. Please check the key is correct and active.';
            } elseif ($status === 400) {
                $userMsg = 'Brevo rejected the request: ' . ($brevoBody['message'] ?? 'Bad request');
            } else {
                $userMsg = 'Brevo error (' . $status . '): ' . ($brevoBody['message'] ?? $response->body());
            }

            return response()->json(['message' => $userMsg], 422);
        } catch (\Exception $e) {
            Log::error('Brevo test email exception', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to send email: ' . $e->getMessage()], 500);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Return the absolute filesystem path for a public-disk stored file,
     * or null if the path is empty or the file doesn't exist.
     */
    private function resolveAbsPath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        $absPath = Storage::disk('public')->path($path);
        return file_exists($absPath) ? $absPath : null;
    }

    /**
     * Return a file:// URI for a public-disk stored image.
     * DomPDF renders local file:// paths reliably without needing isRemoteEnabled.
     */
    private function pathToFileUri(?string $path): ?string
    {
        if (!$path) {
            return null;
        }
        $absPath = Storage::disk('public')->path($path);
        if (!file_exists($absPath)) {
            return null;
        }
        return 'file://' . $absPath;
    }

    /** @deprecated Use pathToFileUri() instead */
    private function pathToBase64(?string $path): ?string
    {
        if (!$path || !Storage::disk('public')->exists($path)) {
            return null;
        }
        $content = Storage::disk('public')->get($path);
        $mime    = Storage::disk('public')->mimeType($path) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode($content);
    }

    private function deleteOld(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function buildEmailHtml(SuresignSetting $settings, string $subject, string $bodyHtml): string
    {
        $headerImg = $settings->email_header_url
            ? '<img src="' . e($settings->email_header_url) . '" style="width:100%;max-width:600px;display:block;" alt="Header" />'
            : '';
        $footerImg = $settings->email_footer_url
            ? '<img src="' . e($settings->email_footer_url) . '" style="width:100%;max-width:600px;display:block;" alt="Footer" />'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{$subject}</title></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:20px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td>{$headerImg}</td></tr>
        <tr><td style="padding:32px 40px;color:#333333;font-size:15px;line-height:1.6;">
          {$bodyHtml}
        </td></tr>
        <tr><td>{$footerImg}</td></tr>
        <tr><td style="padding:16px 40px;background:#f9f9f9;font-size:11px;color:#999999;text-align:center;">
          This email was sent by SureSign. Please do not reply directly to this message.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * POST /api/admin/suresign-settings/sync-from-mirror
     * Trigger sync from local Windows Documents mirror to SureSign.
     * Runs the import command and returns the summary.
     */
    public function syncFromMirror(Request $request)
    {
        try {
            $exitCode = \Artisan::call('suresign:import-from-mirror');
            
            return response()->json([
                'message' => 'Files synced successfully from Windows Documents.',
                'exit_code' => $exitCode,
            ]);
        } catch (\Exception $e) {
            \Log::error('Sync from mirror failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to sync files: ' . $e->getMessage(),
            ], 500);
        }
    }
}
