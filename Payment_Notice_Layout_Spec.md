# SureSign Payment Notice — Layout Specification
**Reference document:** BMR Payment Notice, March'26  
**Target implementation:** Laravel Blade + DomPDF  
**Version:** 1.0 | 2026-06-26

---

## Overview

The Payment Notice is a single A4 page. All visible content sits inside a full-page outer border box. There is **no standard document header or footer** — the issuing company logo appears inside the notice layout, top-right. The footer reference sits outside (below) the outer box, bottom-right of the page.

---

## 1. Page Layout & Margins

| Property | Value |
|---|---|
| Paper size | A4 (210mm × 297mm) |
| Page margin (all sides) | ~12mm |
| Outer box starts at | ~12mm from each page edge |
| Outer box width | ~186mm (210 − 24mm) |
| Outer box height | ~273mm (297 − 24mm) |
| Inner padding (all sides) | ~8–10mm |

The page background is white. No watermark, no background image.

---

## 2. Outer Border Structure

- Single solid black border, approximately **1pt (0.75px)** weight
- Rectangular, spanning the full usable area inside page margins
- No double border, no rounded corners
- All content is inside this box
- The footer reference (`MQS 8.4.1 | 02/13 rev A`) sits **outside** the box at the bottom-right of the page

**CSS:**
```css
.notice-wrapper {
    border: 1pt solid #000000;
    width: 186mm;
    min-height: 273mm;
    padding: 8mm 10mm;
    box-sizing: border-box;
    position: relative;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 8.5pt;
}
```

---

## 3. Header Placement

The header is a **two-column layout** spanning the full width inside the box:

| Column | Content | Approx. width |
|---|---|---|
| Left | Recipient email + recipient address + "PAYMENT NOTICE" title | ~55% |
| Right | Logo block (top-right) + Contractor/company address | ~45% |

The two columns sit side by side at the top of the notice. There is no border or divider between them; they are separated by whitespace only.

**Vertical order (left column):**
1. Recipient email
2. Recipient name ("For the Attention of - …") — bold, underlined
3. Recipient address lines
4. **"PAYMENT NOTICE"** title — bold, underlined, ~12pt

**Vertical order (right column):**
1. Logo block — top-right corner, flush right
2. Contractor address — positioned roughly mid-right, below the logo, right-aligned text
3. Tel / Fax lines — below contractor address, right-aligned

**CSS:**
```css
.notice-header {
    display: table;
    width: 100%;
    margin-bottom: 6mm;
}
.header-left {
    display: table-cell;
    width: 55%;
    vertical-align: top;
}
.header-right {
    display: table-cell;
    width: 45%;
    vertical-align: top;
    text-align: right;
}
```

> **DomPDF note:** Use `display: table` / `display: table-cell` for columns. Flexbox and CSS Grid are not reliably supported.

---

## 4. Logo Placement Inside the Notice Body

- Positioned at the **top-right** of the notice, inside the outer border box
- Flush to the right edge of the inner padding
- The logo is a **black-filled rectangle** with white reversed text (company name in large serif font)
- Approximate logo dimensions: **38mm wide × 16mm tall**
- No border on the logo itself — the black fill provides the visual block
- Dynamic: the logo image URL/path is a variable; the black background is part of the logo asset itself (not CSS-generated)

**Blade snippet:**
```blade
<div class="header-right">
    <div class="logo-block">
        <img src="{{ $company_logo_url }}" alt="{{ $company_name }}" style="width:38mm; height:auto; display:block; margin-left:auto;">
    </div>
    ...
</div>
```

> **Note:** Do not attempt to recreate the logo in CSS. The black rectangle + white text is the logo image asset. Supply as PNG with transparent or black background, minimum 300dpi for print quality.

---

## 5. Recipient Block

Positioned in the **upper-left** of the header, stacked vertically:

```
{recipient_email}                                     ← 8pt, normal weight

For the Attention of - {recipient_contact_name}       ← 9pt, bold, underlined
{recipient_address_line_1},
{recipient_address_line_2},
{recipient_postcode}

PAYMENT NOTICE                                        ← 12pt, bold, underlined
```

- Email is the first line, above the attention line
- ~5mm gap between email and attention line
- ~4mm gap between address block and "PAYMENT NOTICE" title
- "PAYMENT NOTICE" is the most prominent text in this column

**CSS:**
```css
.recipient-email { font-size: 8pt; margin-bottom: 3mm; }
.recipient-attention { font-size: 9pt; font-weight: bold; text-decoration: underline; }
.recipient-address { font-size: 8.5pt; line-height: 1.4; }
.payment-notice-title {
    font-size: 12pt;
    font-weight: bold;
    text-decoration: underline;
    margin-top: 4mm;
    display: block;
}
```

---

## 6. Contractor / Company Address Block

Positioned in the **upper-right**, below the logo, right-aligned:

```
{contractor_company_name}        ← 8.5pt, normal
{contractor_address_line_1}
{contractor_address_line_2}
{contractor_city}
{contractor_postcode}

Tel: {contractor_tel}
Fax: {contractor_fax}
```

- Right-aligned text
- ~4mm gap between logo and contractor address
- Tel/Fax on separate lines, right-aligned
- ~5mm gap between contractor address and the contract info box below

**CSS:**
```css
.contractor-address {
    font-size: 8.5pt;
    text-align: right;
    line-height: 1.5;
    margin-top: 3mm;
}
.contractor-tel-fax {
    font-size: 8.5pt;
    text-align: right;
    margin-top: 2mm;
}
```

---

## 7. Payment Notice Title Placement

`PAYMENT NOTICE` appears in the **lower portion of the left header column**, below the recipient address. It is:

- Bold
- Underlined
- ~12pt
- Left-aligned (within the left column)
- Sits above (and separate from) the contract info box

This is the document title and should stand out clearly. It is NOT centred across the full page width — it aligns to the left column only.

---

## 8. Contract Information Boxed Section

A bordered box, full-width within the outer notice, containing contract metadata. Each row is centred.

**Structure:** Single-border box (~0.75pt solid black), ~6–8mm of internal top/bottom padding per row.

| Row | Label | Value |
|---|---|---|
| 1 | `Date :` | `{notification_date}` |
| 2 | `Contract Name:` | `{contract_name}` |
| 3 | `Contract Number:` | `{contract_number}` |
| 4 | `Subcontractor:` | `{subcontractor_name}` |
| 5 | `Trade:` | `{trade}` |
| 6 | `Subcontractor order/LOI ref:` | `{subcontract_reference}` |

- Labels are bold; values are bold (the entire line is bold in the reference)
- Each row has a subtle horizontal rule between it (implied by spacing, not always a visible line in the reference — the box has no internal row borders)
- ~4pt spacing between rows
- Font: 9–9.5pt

**CSS:**
```css
.contract-info-box {
    border: 0.75pt solid #000000;
    padding: 4mm 6mm;
    margin: 4mm 0;
    text-align: center;
    font-size: 9pt;
}
.contract-info-row {
    font-weight: bold;
    padding: 2pt 0;
    line-height: 1.6;
}
```

**Blade:**
```blade
<div class="contract-info-box">
    <div class="contract-info-row">Date : {{ $notification_date }}</div>
    <div class="contract-info-row">Contract Name: {{ $contract_name }}</div>
    <div class="contract-info-row">Contract Number: {{ $contract_number }}</div>
    <div class="contract-info-row">Subcontractor: {{ $subcontractor_name }}</div>
    <div class="contract-info-row">Trade: {{ $trade }}</div>
    <div class="contract-info-row">Subcontractor order/LOI ref: {{ $subcontract_reference }}</div>
</div>
```

---

## 9. Notification / Payment Date Boxes

A **3-column bordered row** immediately below the contract info box. Thin borders (~0.75pt) on all cells.

| Column | Labels (stacked) | Values |
|---|---|---|
| Left (~45%) | Notification Period (month end) / Subcontractor Application/Invoice ref: / Notification Number: | — |
| Centre (~30%) | — | `{notification_period}` / `{application_reference}` / `{notification_number}` |
| Right (~25%) | Final Date for Payment: | `{final_payment_date}` |

- Font: 8–8.5pt
- The left column contains the labels, the centre column contains the corresponding values
- The right column contains "Final Date for Payment:" as label with the date below it
- All cells have ~2mm padding
- The right cell has "Final Date for Payment:" label in normal weight, date value below in normal weight

**CSS:**
```css
.notification-row {
    display: table;
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 5mm;
    font-size: 8pt;
}
.notification-row td {
    border: 0.75pt solid #000000;
    padding: 2mm;
    vertical-align: top;
}
.notification-label-cell { width: 45%; }
.notification-value-cell { width: 30%; }
.notification-date-cell { width: 25%; }
```

**Blade:**
```blade
<table class="notification-row">
    <tr>
        <td class="notification-label-cell">
            Notification Period (month end)<br>
            Subcontractor Application/Invoice ref:<br>
            Notification Number:
        </td>
        <td class="notification-value-cell">
            {{ $notification_period }}<br>
            {{ $application_reference }}<br>
            {{ $notification_number }}
        </td>
        <td class="notification-date-cell">
            Final Date for Payment:<br>
            {{ $final_payment_date }}
        </td>
    </tr>
</table>
```

---

## 10. Main Explanatory Wording Placement

Two blocks of standard text, left-aligned, between the notification row and the valuation table.

**Block 1** (standard notice paragraph):
```
We enclose our Notice of Valuation of Sub-Contract Works, together with our detailed 
valuations of your measured works and variations account attached. This is Issued under 
the terms of the Sub-Contract
```
- Font: 8.5–9pt, normal weight
- ~4mm top margin from notification row

**Block 2** (payment amount statement):
```
We propose to pay the sum of:    £    {net_payment_amount}    this month, which amount 
we have calculated on the following basis:
```
- "£ {net_payment_amount}" is displayed in **bold, larger (~11pt)** inline with the sentence
- The sentence text is 8.5pt normal weight
- ~3mm gap between Block 1 and Block 2

**Block 3** (italic disclaimer, immediately below Block 2):
```
NB: the above sum is nett of previous payments, tax and VAT
```
- Font: 8pt, **italic**
- ~2mm gap from Block 2

**CSS:**
```css
.notice-paragraph { font-size: 8.5pt; margin-bottom: 3mm; line-height: 1.5; }
.payment-sum-statement { font-size: 8.5pt; margin-bottom: 1mm; }
.payment-sum-amount { font-size: 11pt; font-weight: bold; }
.payment-sum-note { font-size: 8pt; font-style: italic; margin-bottom: 4mm; }
```

---

## 11. Valuation Table Structure

Full-width table, no outer border, with subtle row separators (implied by spacing). Two columns: description (left) and amount (right).

| Row | Description | £ Column | Style |
|---|---|---|---|
| 1 | Lump Sum Works | `{lump_sum_works}` | Normal |
| 2 | Variation Works | `{variation_works}` | Normal |
| 3 | Materials | `{materials}` | Normal |
| 4 | Dayworks | `{dayworks}` | Normal |
| 5 | **Gross Cumulative Valuation** | `{gross_cumulative_valuation}` | **Bold** |
| 6 | Less Agreed Discount | `{less_agreed_discount}` | Normal |
| 7 | Less Contra Charges | `{less_contra_charges}` | Normal |
| 8 | **Net Valuation** | `{net_valuation}` | **Bold** |
| 9 | Less Retention | `({less_retention})` | Normal, **orange/red** |
| 10 | Less Previous Net Valuation | `({less_previous_valuation})` | Normal, **orange/red** |
| 11 | **Net Payment (Subject to VAT)** | `{net_payment}` | **Bold, yellow background** |

**Column widths:**
- Description column: ~80% of table width
- Amount column: ~20%, right-aligned

**Amount column structure:** The `£` symbol sits in a narrow sub-column, the numeric value is right-aligned. In practice, implement as a single right-aligned cell prefixed with `£`.

**Row height:** ~5–6mm per row (4pt top/bottom padding)

**Horizontal rules:** Thin 0.5pt lines below each row (the table has no outer border; only row separators)

**Negative/deduction values** (Retention, Previous Net Valuation): displayed as `(value)` in **orange** (`#E07000` approx.)

**Net Payment row**: **Yellow background** (`#FFFF00`), bold text, no parentheses

**CSS:**
```css
.valuation-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8.5pt;
    margin-bottom: 4mm;
}
.valuation-table td {
    padding: 2pt 3pt;
    border-bottom: 0.5pt solid #000000;
    vertical-align: middle;
}
.valuation-table .col-desc { width: 80%; }
.valuation-table .col-amount {
    width: 20%;
    text-align: right;
    white-space: nowrap;
}
.valuation-table .row-bold td { font-weight: bold; }
.valuation-table .row-negative .col-amount { color: #CC5500; }
.valuation-table .row-net-payment {
    background-color: #FFFF00;
}
.valuation-table .row-net-payment td { font-weight: bold; }
```

---

## 12. Highlighting of Net Payment Row

The **Net Payment (Subject to VAT)** row has a **solid yellow background** covering the full row width.

- Background colour: `#FFFF00` (pure yellow)
- The text remains black and bold
- The yellow extends across both the description and amount cells
- Amount is positive (no parentheses), right-aligned
- This is the only coloured row in the table

**Blade:**
```blade
<tr class="row-bold row-net-payment">
    <td class="col-desc">Net Payment (Subject to VAT)</td>
    <td class="col-amount">£ {{ number_format($net_payment, 2) }}</td>
</tr>
```

> **DomPDF note:** `background-color` on `<tr>` is not reliably supported in DomPDF. Apply the background to each `<td>` in that row individually.

---

## 13. Signature Block

Below the valuation table, approximately 8–10mm of vertical space, then:

**Two centred explanatory lines** (8.5pt, centred):
```
With regards to any alterations on your account, please note that the deductions are 
made in accordance with the terms and conditions of your Sub-Contract.

Should you have any queries regarding this certificate please contact the undersigned 
at the above address.
```

Then, left-aligned signature block (~8mm below):
```
Yours faithfully,
for and on Behalf of {contractor_company_name}    ← "for and on Behalf of" normal; company name bold

[signature space — approximately 10mm vertical gap]

{signatory_name}
{signatory_title}                                  ← bold
```

**CSS:**
```css
.post-table-text {
    font-size: 8.5pt;
    text-align: center;
    margin: 4mm 0;
    line-height: 1.6;
}
.signature-block {
    font-size: 8.5pt;
    margin-top: 5mm;
}
.signature-space { height: 10mm; display: block; }
.signatory-name { font-size: 8.5pt; }
.signatory-title { font-size: 8.5pt; font-weight: bold; }
```

**Blade:**
```blade
<div class="signature-block">
    Yours faithfully,<br>
    for and on Behalf of <strong>{{ $contractor_company_name }}</strong>
    <span class="signature-space"></span>
    {{ $signatory_name }}<br>
    <strong>{{ $signatory_title }}</strong>
</div>
```

---

## 14. VAT Receipt Note

Centred block at the bottom of the notice, inside the outer border box. Approximately 8mm above the bottom border.

**Structure:**
```
VAT RECEIPTS:                          ← bold, underlined, centred
Where VAT is applicable, please ensure that a valid VAT Invoice is issued for the 
agreed amount is returned to ourselves within 21 days of the last payment.
Failure to comply with the above will result in the withholding of further payments 
until duly received.
```

- Title "VAT RECEIPTS:" is bold and underlined, ~9pt
- Body text: 8–8.5pt, centred, normal weight
- All three lines centred

**CSS:**
```css
.vat-receipt-block {
    font-size: 8pt;
    text-align: center;
    margin-top: 5mm;
    line-height: 1.6;
}
.vat-receipt-title {
    font-weight: bold;
    text-decoration: underline;
    font-size: 9pt;
    display: block;
    margin-bottom: 2pt;
}
```

**Blade:**
```blade
<div class="vat-receipt-block">
    <span class="vat-receipt-title">VAT RECEIPTS:</span>
    Where VAT is applicable, please ensure that a valid VAT Invoice is issued for the agreed amount
    is returned to ourselves within 21 days of the last payment.<br>
    Failure to comply with the above will result in the withholding of further payments until duly received.
</div>
```

---

## 15. Footer Reference Placement

The document reference **sits outside the outer border box**, in the bottom-right corner of the page.

```
MQS 8.4.1 | 02/13 rev A          ← dynamic: {document_reference}
```

- Font: 7–8pt, normal weight, grey or black
- Right-aligned
- Approximately 4–5mm below the bottom edge of the outer box
- NOT inside the notice content area

**CSS:**
```css
.page-footer-reference {
    position: fixed;
    bottom: 5mm;
    right: 12mm;
    font-size: 7.5pt;
    color: #444444;
    text-align: right;
}
```

> **DomPDF note:** Use `position: fixed` with `bottom` and `right` for the footer reference. This is one of the few cases in DomPDF where fixed positioning works reliably — DomPDF treats `position: fixed` as page-fixed.

---

---

# Dynamic Field Mapping

| Field Variable | Description | Example Value |
|---|---|---|
| `$company_logo_url` | Issuing company logo image path | `/storage/logos/company.png` |
| `$company_name` | Issuing company full name | `Curo Construction Limited` |
| `$company_address_line_1` | Issuing company address | `3-4 New Street` |
| `$company_address_line_2` | Issuing company city | `London` |
| `$company_postcode` | Issuing company postcode | `EC2M 4TP` |
| `$company_tel` | Issuing company telephone | `07595 290 289` |
| `$company_fax` | Issuing company fax | `N/A` |
| `$recipient_email` | Subcontractor email | `info@bespokemetalroofing.co.uk` |
| `$recipient_contact_name` | Subcontractor contact name | `Lewis Chenery` |
| `$recipient_address_line_1` | Subcontractor address line 1 | `8 Humbleward Place,` |
| `$recipient_address_line_2` | Subcontractor address line 2 | `Romford,` |
| `$recipient_postcode` | Subcontractor postcode | `RM3 9FN` |
| `$notification_date` | Date of the Payment Notice | `12-Apr-2026` |
| `$contract_name` | Contract/project name | `South Molton Street` |
| `$contract_number` | Contract number | `CC1085` |
| `$subcontractor_name` | Subcontractor company name | `Bespoke Metal Roofing Limited` |
| `$trade` | Trade description | `Pitched Roofing` |
| `$subcontract_reference` | Subcontract order/LOI reference | `CC1085 Bespoke Metal Roofing 241220 For Execution` |
| `$notification_period` | Notification period (month end) | `Mar-26` |
| `$application_reference` | Subcontractor application/invoice ref | `Application 12` |
| `$notification_number` | Notification number | `Nr 12` |
| `$final_payment_date` | Final date for payment | `12/05/2026` |
| `$lump_sum_works` | Lump sum works value | `184,977.00` |
| `$variation_works` | Variation works value | `110,189.62` |
| `$materials` | Materials value | `6,123.51` |
| `$dayworks` | Dayworks value | `23,016.00` |
| `$gross_cumulative_valuation` | Gross cumulative valuation total | `324,306.13` |
| `$less_agreed_discount` | Agreed discount deduction | `0.00` |
| `$less_contra_charges` | Contra charges deduction | `0.00` |
| `$net_valuation` | Net valuation subtotal | `324,306.13` |
| `$less_retention` | Retention deduction (positive number, displayed in parentheses) | `9,729.18` |
| `$less_previous_valuation` | Previous net valuation (positive number, displayed in parentheses) | `300,576.94` |
| `$net_payment` | Net payment amount (the final sum to be paid) | `14,000.00` |
| `$signatory_name` | Name of signatory | `Frank Muir` |
| `$signatory_title` | Title of signatory | `Senior Quantity Surveyor` |
| `$contractor_company_name` | Contractor company name (for signature line) | `Curo Construction Limited` |
| `$document_reference` | Footer document reference code | `MQS 8.4.1 \| 02/13 rev A` |

**Formatting note:** All monetary values should be formatted as `number_format($value, 2)` in Blade. Deduction values (retention, previous valuation) should be displayed as `({{ number_format($value, 2) }})`.

---

---

# Blade / DomPDF Implementation Notes

## Recommended Blade Structure

```blade
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        /* All CSS inline or in <style> block — DomPDF does not load external stylesheets reliably */
        @page {
            size: A4;
            margin: 12mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8.5pt;
            margin: 0;
            padding: 0;
            color: #000000;
        }
        /* ... all styles ... */
    </style>
</head>
<body>

    {{-- OUTER NOTICE BOX --}}
    <div class="notice-wrapper">

        {{-- HEADER: two-column --}}
        <table class="notice-header-table">
            <tr>
                <td class="header-left-cell">
                    <span class="recipient-email">{{ $recipient_email }}</span>
                    <br><br>
                    <span class="recipient-attention">For the Attention of - {{ $recipient_contact_name }}</span><br>
                    <span class="recipient-address">
                        {{ $recipient_address_line_1 }}<br>
                        {{ $recipient_address_line_2 }}<br>
                        {{ $recipient_postcode }}
                    </span>
                    <br><br>
                    <span class="payment-notice-title">PAYMENT NOTICE</span>
                </td>
                <td class="header-right-cell">
                    <img src="{{ $company_logo_url }}" class="company-logo" alt="{{ $company_name }}">
                    <div class="contractor-address">
                        {{ $company_name }}<br>
                        {{ $company_address_line_1 }}<br>
                        {{ $company_address_line_2 }}<br>
                        {{ $company_postcode }}
                    </div>
                    <div class="contractor-tel-fax">
                        Tel: {{ $company_tel }}<br>
                        Fax: {{ $company_fax }}
                    </div>
                </td>
            </tr>
        </table>

        {{-- CONTRACT INFO BOX --}}
        <div class="contract-info-box">
            <div class="contract-info-row">Date : {{ $notification_date }}</div>
            <div class="contract-info-row">Contract Name: {{ $contract_name }}</div>
            <div class="contract-info-row">Contract Number: {{ $contract_number }}</div>
            <div class="contract-info-row">Subcontractor: {{ $subcontractor_name }}</div>
            <div class="contract-info-row">Trade: {{ $trade }}</div>
            <div class="contract-info-row">Subcontractor order/LOI ref: {{ $subcontract_reference }}</div>
        </div>

        {{-- NOTIFICATION ROW --}}
        <table class="notification-row">
            <tr>
                <td class="notification-label-cell">
                    Notification Period (month end)<br>
                    Subcontractor Application/Invoice ref:<br>
                    Notification Number:
                </td>
                <td class="notification-value-cell">
                    {{ $notification_period }}<br>
                    {{ $application_reference }}<br>
                    {{ $notification_number }}
                </td>
                <td class="notification-date-cell">
                    Final Date for Payment:<br>
                    {{ $final_payment_date }}
                </td>
            </tr>
        </table>

        {{-- EXPLANATORY TEXT --}}
        <p class="notice-paragraph">We enclose our Notice of Valuation of Sub-Contract Works, together with our detailed valuations of your measured works and variations account attached. This is Issued under the terms of the Sub-Contract</p>

        <p class="payment-sum-statement">We propose to pay the sum of: &nbsp;&nbsp;&nbsp;
            <strong><span class="payment-sum-amount">&pound; {{ number_format($net_payment, 2) }}</span></strong>
            this month, which amount we have calculated on the following basis:
        </p>
        <p class="payment-sum-note"><em>NB: the above sum is nett of previous payments, tax and VAT</em></p>

        {{-- VALUATION TABLE --}}
        <table class="valuation-table">
            <tr>
                <td class="col-desc">Lump Sum Works</td>
                <td class="col-amount">&pound; {{ number_format($lump_sum_works, 2) }}</td>
            </tr>
            <tr>
                <td class="col-desc">Variation Works</td>
                <td class="col-amount">&pound; {{ number_format($variation_works, 2) }}</td>
            </tr>
            <tr>
                <td class="col-desc">Materials</td>
                <td class="col-amount">&pound; {{ number_format($materials, 2) }}</td>
            </tr>
            <tr>
                <td class="col-desc">Dayworks</td>
                <td class="col-amount">&pound; {{ number_format($dayworks, 2) }}</td>
            </tr>
            <tr class="row-bold">
                <td class="col-desc"><strong>Gross Cumulative Valuation</strong></td>
                <td class="col-amount"><strong>&pound; {{ number_format($gross_cumulative_valuation, 2) }}</strong></td>
            </tr>
            <tr>
                <td class="col-desc">Less Agreed Discount</td>
                <td class="col-amount">&pound; {{ number_format($less_agreed_discount, 2) }}</td>
            </tr>
            <tr>
                <td class="col-desc">Less Contra Charges</td>
                <td class="col-amount">&pound; {{ number_format($less_contra_charges, 2) }}</td>
            </tr>
            <tr class="row-bold">
                <td class="col-desc"><strong>Net Valuation</strong></td>
                <td class="col-amount"><strong>&pound; {{ number_format($net_valuation, 2) }}</strong></td>
            </tr>
            <tr class="row-negative">
                <td class="col-desc">Less Retention</td>
                <td class="col-amount" style="color:#CC5500;">&pound; ({{ number_format($less_retention, 2) }})</td>
            </tr>
            <tr class="row-negative">
                <td class="col-desc">Less Previous Net Valuation</td>
                <td class="col-amount" style="color:#CC5500;">&pound; ({{ number_format($less_previous_valuation, 2) }})</td>
            </tr>
            <tr>
                <td class="col-desc" style="font-weight:bold; background-color:#FFFF00;"><strong>Net Payment (Subject to VAT)</strong></td>
                <td class="col-amount" style="font-weight:bold; background-color:#FFFF00;"><strong>&pound; {{ number_format($net_payment, 2) }}</strong></td>
            </tr>
        </table>

        {{-- POST-TABLE TEXT --}}
        <p class="post-table-text">With regards to any alterations on your account, please note that the deductions are made in accordance with the terms and conditions of your Sub-Contract.</p>
        <p class="post-table-text">Should you have any queries regarding this certificate please contact the undersigned at the above address.</p>

        {{-- SIGNATURE BLOCK --}}
        <div class="signature-block">
            Yours faithfully,<br>
            for and on Behalf of <strong>{{ $contractor_company_name }}</strong>
            <div class="signature-space"></div>
            {{ $signatory_name }}<br>
            <strong>{{ $signatory_title }}</strong>
        </div>

        {{-- VAT RECEIPTS --}}
        <div class="vat-receipt-block">
            <span class="vat-receipt-title">VAT RECEIPTS:</span>
            Where VAT is applicable, please ensure that a valid VAT Invoice is issued for the agreed amount
            is returned to ourselves within 21 days of the last payment.<br>
            Failure to comply with the above will result in the withholding of further payments until duly received.
        </div>

    </div>{{-- end .notice-wrapper --}}

    {{-- PAGE FOOTER REFERENCE (outside the box) --}}
    <div class="page-footer-reference">{{ $document_reference }}</div>

</body>
</html>
```

---

---

# CSS Guidance (Complete)

```css
@page {
    size: A4;
    margin: 12mm;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 8.5pt;
    color: #000000;
    margin: 0;
    padding: 0;
}

/* ── OUTER BOX ── */
.notice-wrapper {
    border: 1pt solid #000000;
    padding: 8mm 10mm;
    box-sizing: border-box;
    min-height: 260mm; /* leaves room for footer below box */
}

/* ── HEADER ── */
.notice-header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 5mm;
}
.header-left-cell {
    width: 55%;
    vertical-align: top;
    padding: 0;
}
.header-right-cell {
    width: 45%;
    vertical-align: top;
    text-align: right;
    padding: 0;
}
.company-logo {
    width: 38mm;
    height: auto;
    display: block;
    margin-left: auto;
}
.recipient-email { font-size: 8pt; }
.recipient-attention {
    font-size: 9pt;
    font-weight: bold;
    text-decoration: underline;
}
.recipient-address { font-size: 8.5pt; line-height: 1.4; }
.payment-notice-title {
    font-size: 12pt;
    font-weight: bold;
    text-decoration: underline;
    margin-top: 4mm;
    display: block;
}
.contractor-address {
    font-size: 8.5pt;
    text-align: right;
    line-height: 1.5;
    margin-top: 3mm;
}
.contractor-tel-fax {
    font-size: 8.5pt;
    text-align: right;
    margin-top: 2mm;
}

/* ── CONTRACT INFO BOX ── */
.contract-info-box {
    border: 0.75pt solid #000000;
    padding: 3mm 6mm;
    margin: 0 0 3mm 0;
    text-align: center;
    font-size: 9pt;
}
.contract-info-row {
    font-weight: bold;
    padding: 2pt 0;
    line-height: 1.6;
}

/* ── NOTIFICATION ROW ── */
.notification-row {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4mm;
    font-size: 8pt;
}
.notification-row td {
    border: 0.75pt solid #000000;
    padding: 2mm;
    vertical-align: top;
}
.notification-label-cell { width: 45%; }
.notification-value-cell { width: 30%; }
.notification-date-cell  { width: 25%; }

/* ── BODY TEXT ── */
.notice-paragraph {
    font-size: 8.5pt;
    margin: 0 0 3mm 0;
    line-height: 1.5;
}
.payment-sum-statement {
    font-size: 8.5pt;
    margin: 0 0 1mm 0;
}
.payment-sum-amount {
    font-size: 11pt;
    font-weight: bold;
}
.payment-sum-note {
    font-size: 8pt;
    font-style: italic;
    margin: 0 0 4mm 0;
}

/* ── VALUATION TABLE ── */
.valuation-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 8.5pt;
    margin-bottom: 4mm;
}
.valuation-table td {
    padding: 2pt 3pt;
    border-bottom: 0.5pt solid #000000;
    vertical-align: middle;
}
.valuation-table .col-desc  { width: 80%; }
.valuation-table .col-amount {
    width: 20%;
    text-align: right;
    white-space: nowrap;
}

/* ── POST-TABLE TEXT ── */
.post-table-text {
    font-size: 8.5pt;
    text-align: center;
    margin: 2mm 0;
    line-height: 1.6;
}

/* ── SIGNATURE ── */
.signature-block {
    font-size: 8.5pt;
    margin-top: 4mm;
}
.signature-space {
    height: 10mm;
    display: block;
}

/* ── VAT RECEIPT ── */
.vat-receipt-block {
    font-size: 8pt;
    text-align: center;
    margin-top: 5mm;
    line-height: 1.6;
}
.vat-receipt-title {
    font-weight: bold;
    text-decoration: underline;
    font-size: 9pt;
    display: block;
    margin-bottom: 2pt;
}

/* ── PAGE FOOTER (outside box) ── */
.page-footer-reference {
    position: fixed;
    bottom: 4mm;
    right: 0;
    font-size: 7.5pt;
    color: #444444;
    text-align: right;
}
```

---

---

# Risks & Limitations with DomPDF

## Critical Limitations

| Issue | Detail | Workaround |
|---|---|---|
| **No CSS Grid / Flexbox** | DomPDF does not support `display: flex` or `display: grid` | Use `display: table` / `table-cell` for all multi-column layouts |
| **`background-color` on `<tr>` ignored** | DomPDF ignores background-color set on `<tr>` elements | Apply `background-color` to each `<td>` individually |
| **No `box-sizing: border-box` support** | Width calculations can overflow | Test widths in percentages; avoid mixing `mm` and `%` |
| **External fonts / Google Fonts unreliable** | DomPDF may fail to load web fonts | Use system fonts: `Arial`, `Helvetica`, `Times New Roman`, `Courier New`; or embed fonts as base64 |
| **External CSS files ignored** | `<link rel="stylesheet">` may not load in server context | Inline all CSS in a `<style>` block in the Blade template |
| **`position: absolute` unreliable** | Absolute positioning is poorly supported except in limited cases | Use table layout for structure; use `position: fixed` only for page-fixed elements (header/footer) |
| **Image rendering** | Remote image URLs may fail if DomPDF cannot resolve them | Use absolute server paths or base64-encoded images for logos |
| **Border on `<div>` vs `<table>`** | Borders on div elements can sometimes render inconsistently | Prefer `<table>` with `border` attributes for critical borders (e.g., contract info box, notification row) |
| **Page overflow / orphaned rows** | If content exceeds one page, rows can split mid-row | Keep the notice compact; test with long contract names/references |
| **`min-height` on wrapper** | `min-height` is respected but can cause overflow onto page 2 | If content is too long, reduce padding or font sizes slightly |

## Medium-Risk Issues

| Issue | Detail | Mitigation |
|---|---|---|
| **Colour rendering** | Yellow `#FFFF00` may print faintly on some printers | Accept as standard — it matches the reference document |
| **Orange/red deduction values** | `color: #CC5500` renders correctly but exact shade may differ | Adjust hex value to taste; `#E07000` is another option |
| **`text-decoration: underline` on bold** | Generally works in DomPDF but can appear thin | Test output; add a bottom-border alternative if underline is invisible |
| **`<br>` spacing** | `<br>` line height may vary | Use `line-height` on parent elements to control spacing |
| **Table cell `vertical-align`** | `vertical-align: middle` is supported but can be inconsistent in nested tables | Prefer `vertical-align: top` where alignment isn't critical |

## Recommended DomPDF Configuration (Laravel)

```php
// config/dompdf.php or inline
$options = new Options();
$options->set('defaultFont', 'Arial');
$options->set('isRemoteEnabled', true);   // only if using remote image URLs
$options->set('isHtml5ParserEnabled', true);
$options->set('isFontSubsettingEnabled', true);
$options->set('defaultPaperSize', 'A4');
$options->set('defaultPaperOrientation', 'portrait');
```

Or in `config/dompdf.php`:
```php
'options' => [
    'defaultFont'           => 'arial',
    'isRemoteEnabled'       => env('DOMPDF_REMOTE_ENABLED', true),
    'isHtml5ParserEnabled'  => true,
    'isFontSubsettingEnabled' => true,
    'defaultPaperSize'      => 'A4',
    'chroot'                => realpath(base_path()),
],
```

---

*This specification should be reviewed alongside the reference PDF before implementation. Pixel-perfect replication in DomPDF is not achievable; the target is visual fidelity within DomPDF's rendering constraints.*
