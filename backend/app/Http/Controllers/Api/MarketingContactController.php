<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMarketingContactRequest;
use App\Services\Marketing\SendMarketingContactEnquiryService;

class MarketingContactController extends Controller
{
    public function store(
        StoreMarketingContactRequest $request,
        SendMarketingContactEnquiryService $service,
    ) {
        $validated = $request->validated();

        // Silently accept honeypot submissions so bots receive no useful
        // signal and no email is sent.
        if (! empty($validated['website'])) {
            return response()->json(['message' => 'Enquiry received.'], 202);
        }

        if (! $service->send($validated, now())) {
            return response()->json([
                'message' => 'We could not send your enquiry right now. Please email tech@suresigncontracts.com.',
            ], 503);
        }

        return response()->json(['message' => 'Enquiry received.'], 201);
    }
}
