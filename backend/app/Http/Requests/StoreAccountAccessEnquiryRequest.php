<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public, unauthenticated "Contact your administrator" page's enquiry
 * form (frontend/src/app/contact-administrator/page.tsx) — deliberately a
 * separate request/service pair from StoreMarketingContactRequest /
 * SendMarketingContactEnquiryService, even though the shape is similar.
 * That form is a sales/marketing lead; this one is someone who can't get
 * into their SureSign account, so it asks for less (no company/subject/
 * phone) and must never be filed or emailed as a "marketing enquiry".
 */
class StoreAccountAccessEnquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            // Honeypot — same bot-deterrence convention as
            // StoreMarketingContactRequest. Never rendered visibly on the
            // real form; a bot filling every field fills this too.
            'website' => ['nullable', 'string', 'max:255'],
        ];
    }
}
