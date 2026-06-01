<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>SureSign Letterhead Test</title>
    <style>
        /*
         * @page margins reserve space for the header/footer images
         * that are drawn directly onto the canvas by the controller (PHP side).
         * margin-top: 145px  → header height  (≈108.75 PDF pts at 72 dpi)
         * margin-bottom: 110px → footer height (≈82.50 PDF pts at 72 dpi)
         */
        @page {
            margin-top:    145px;
            margin-bottom: 110px;
            margin-left:   0;
            margin-right:  0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11pt;
            color: #222222;
            background: #ffffff;
        }

        /* ── Body content ── */
        .body-content {
            padding: 24px 48px 28px;
        }

        .title-row {
            display: table;
            width: 100%;
            margin-bottom: 24px;
        }
        .title-cell {
            display: table-cell;
            vertical-align: bottom;
        }
        .badge {
            display: inline-block;
            background: #b99566;
            color: #ffffff;
            font-size: 8pt;
            font-weight: bold;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 3px 10px;
            border-radius: 4px;
            margin-bottom: 8px;
        }
        h1 {
            font-size: 18pt;
            font-weight: bold;
            color: #111111;
            margin-bottom: 4px;
        }
        .meta {
            font-size: 9pt;
            color: #888888;
            margin-bottom: 24px;
        }

        /* ── Divider ── */
        hr {
            border: none;
            border-top: 1.5px solid #e8e8e8;
            margin: 20px 0;
        }

        /* ── Info grid ── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .info-table td {
            padding: 8px 12px;
            font-size: 10pt;
            vertical-align: top;
        }
        .info-table td:first-child {
            width: 40%;
            background: #f8f8f8;
            font-weight: bold;
            color: #555555;
            border-right: 1px solid #eeeeee;
        }
        .info-table td:last-child {
            color: #333333;
        }
        .info-table tr:nth-child(even) td { background: #f0f0f0; }
        .info-table tr:nth-child(even) td:first-child { background: #ebebeb; }

        /* ── Section heading ── */
        .section-heading {
            font-size: 10pt;
            font-weight: bold;
            color: #b99566;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin: 20px 0 8px;
        }

        /* ── Sample paragraph ── */
        p { font-size: 10.5pt; line-height: 1.65; color: #444444; margin-bottom: 12px; }

        .notice {
            background: #fff8e1;
            border-left: 3px solid #e0c89a;
            padding: 8px 14px;
            font-size: 9pt;
            color: #888;
            margin-bottom: 12px;
        }
    </style>
</head>
<body>

    {{-- ── Body content ── --}}
    {{-- Header/footer images are drawn onto the canvas by the controller.   --}}
    {{-- The @page margins above reserve the space for those images.         --}}
    <div class="body-content">

        @if(!$hasHeader || !$hasFooter)
            <div class="notice">
                @if(!$hasHeader && !$hasFooter)
                    No letterhead header or footer uploaded yet. Upload images in Admin → Settings → Document Settings.
                @elseif(!$hasHeader)
                    No letterhead header uploaded. Upload an image in Admin → Settings → Document Settings.
                @else
                    No letterhead footer uploaded. Upload an image in Admin → Settings → Document Settings.
                @endif
            </div>
        @endif

        <div class="title-row">
            <div class="title-cell">
                <div class="badge">Test Document</div>
                <h1>SureSign Letterhead Test</h1>
                <div class="meta">Generated: {{ now()->format('d M Y, H:i') }} &nbsp;|&nbsp; Ref: TEST-{{ strtoupper(substr(md5(now()), 0, 8)) }}</div>
            </div>
        </div>

        <hr>

        <div class="section-heading">Platform Settings</div>
        <table class="info-table">
            <tr>
                <td>Currency</td>
                <td>{{ $settings->currency_symbol }}1,234.56 {{ $settings->currency }}</td>
            </tr>
            <tr>
                <td>Date Format</td>
                <td>{{ $settings->date_format }}</td>
            </tr>
            <tr>
                <td>Timezone</td>
                <td>{{ $settings->timezone }}</td>
            </tr>
            <tr>
                <td>Reply-To Email</td>
                <td>{{ $settings->email_reply_to ?: '(not set)' }}</td>
            </tr>
            <tr>
                <td>Brevo Integration</td>
                <td>{{ $settings->brevo_api_key ? 'Configured ✓' : 'Not configured' }}</td>
            </tr>
            <tr>
                <td>Letterhead Header</td>
                <td>{{ $settings->letterhead_header_path ? 'Uploaded ✓' : 'Not uploaded' }}</td>
            </tr>
            <tr>
                <td>Letterhead Footer</td>
                <td>{{ $settings->letterhead_footer_path ? 'Uploaded ✓' : 'Not uploaded' }}</td>
            </tr>
            <tr>
                <td>Letterhead PDF</td>
                <td>{{ $settings->letterhead_pdf_path ? 'Uploaded ✓' : 'Not uploaded' }}</td>
            </tr>
        </table>

        <hr>

        <div class="section-heading">Sample Content</div>
        <p>
            This is a sample paragraph demonstrating how body text appears on a SureSign-generated document.
            The font, spacing, and layout above will be used for all contracts, RFIs, payment applications,
            site instructions and other documents produced by the platform.
        </p>
        <p>
            Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore
            et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi
            ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse
            cillum dolore eu fugiat nulla pariatur.
        </p>
        <p>
            Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium,
            totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae
            dicta sunt explicabo.
        </p>

        <hr>

        <div class="section-heading">Signature Block</div>
        <p>Yours sincerely,</p>
        <p style="margin-top:24px;">
            <strong>SureSign Platform</strong><br>
            <span style="color:#888888;">Automated Document Management</span>
        </p>

    </div>

</body>
</html>
