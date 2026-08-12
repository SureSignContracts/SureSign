<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountAccessEnquiryRequest;
use App\Services\Support\SendAccountAccessEnquiryService;

/**
 * The public, unauthenticated "Contact your administrator" page
 * (frontend/src/app/contact-administrator/page.tsx) — mirrors
 * MarketingContactController's shape exactly, deliberately kept as its own
 * separate controller/service/request trio rather than folded into the
 * marketing enquiry pipeline (see StoreAccountAccessEnquiryRequest's
 * docblock for why).
 */
class AccountAccessEnquiryController extends Controller
{
    public function store(
        StoreAccountAccessEnquiryRequest $request,
        SendAccountAccessEnquiryService $service,
    ) {
        $validated = $request->validated();

        // Silently accept honeypot submissions so bots receive no useful
        // signal and no email is sent.
        if (! empty($validated['website'])) {
            return response()->json(['message' => 'Enquiry received.'], 202);
        }

        if (! $service->send($validated, now())) {
            return response()->json([
                'message' => 'We could not send your enquiry right now. Please try again shortly.',
            ], 503);
        }

        return response()->json(['message' => 'Enquiry received.'], 201);
    }
}
